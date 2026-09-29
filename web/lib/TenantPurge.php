<?php
/**
 * テナントの完全削除(T3)。論理削除から保持期間(90日)を過ぎたテナントだけを、superadmin の操作で消す。自動では消さない。
 *
 * 手順と、失敗した時の戻し方:
 *   1. 条件を確かめる(削除済み、保持期間を過ぎた、確認の slug が一致、送信の予定がない、知らないテーブルがない)。
 *   2. テナントのファイル(tenants.data_dir)を消さずに <data_root>/_deleted/<slug>-<日時>/ へ移す(rename)。
 *      ファイルがなければ退避のフォルダだけ作る。移せなければ、DB には触れずに中止する。
 *   3. DB 全体のバックアップを VACUUM INTO で退避のフォルダに作り、権限を 0600 にする。
 *   4. tenant_id を持つ全テーブルと、親を通してつながる子のテーブルから、そのテナントの行を1つのトランザクションで消す。
 *      外部キーを一時的に外して子から順に明示的に消し(CASCADE でほかのテナントの行が黙って消えるのを防ぐ)、
 *      最後に「新しくできた参照切れ」があれば、ほかのテナントの行が参照していたとみなして取り消す。
 *      audit_log は消さない。所属テナントを持つ superadmin は消さずに tenant_id を外す。
 *   5. 3〜4 が失敗したら、バックアップを消し、ファイルを元の場所へ戻す。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/TenantStatus.php';

/** 完全削除できない理由。code は API がそのまま返す HTTP の状態コード。 */
final class TenantPurgeError extends RuntimeException
{
}

final class TenantPurge
{
    /**
     * 消す順(子から親)。[テーブル, WHERE 句]。WHERE 句の ? はすべて消すテナントの id。
     * テーブルを足したら、ここか GLOBAL_TABLES のどちらかに入れる(入れないと完全削除は中止される)。
     */
    private const STEPS = [
        ['delivery_log', 'campaign_id IN (SELECT id FROM campaigns WHERE tenant_id = ?)'],
        ['send_schedule', 'campaign_id IN (SELECT id FROM campaigns WHERE tenant_id = ?)'],
        ['campaign_automation_runs', 'automation_id IN (SELECT id FROM campaign_automations WHERE tenant_id = ?)'],
        ['campaign_automation_groups', 'automation_id IN (SELECT id FROM campaign_automations WHERE tenant_id = ?)'],
        ['campaign_automations', 'tenant_id = ?'],
        ['report_mail_matches', 'tenant_id = ?'],
        ['suspicious_mail_history', 'suspicious_mail_id IN (SELECT id FROM suspicious_mails WHERE tenant_id = ?)'],
        ['suspicious_mails', 'tenant_id = ?'],
        ['suspicious_mail_rules', 'tenant_id = ?'],
        ['credential_captures', 'tenant_id = ?'],
        ['campaign_template_snapshots', 'tenant_id = ?'],
        ['campaign_report_snapshots', 'tenant_id = ?'],
        ['events', 'tenant_id = ?'],
        ['edu_attempts', 'tenant_id = ?'],
        ['edu_answer_locks', 'assignment_id IN (SELECT id FROM edu_assignments WHERE tenant_id = ?)'],
        ['edu_response_answers', 'response_id IN (SELECT id FROM edu_responses WHERE tenant_id = ?)'],
        ['edu_responses', 'tenant_id = ?'],
        ['edu_assignments', 'tenant_id = ?'],
        ['edu_delivery_questions', 'delivery_id IN (SELECT id FROM edu_deliveries WHERE tenant_id = ?)'],
        ['edu_delivery_targets', 'delivery_id IN (SELECT id FROM edu_deliveries WHERE tenant_id = ?)'],
        ['edu_score_snapshots', 'tenant_id = ?'],
        ['edu_auto_enroll_runs', 'tenant_id = ?'],
        ['edu_deliveries', 'tenant_id = ?'],
        ['edu_delivery_series', 'tenant_id = ?'],
        ['edu_questions', 'tenant_id = ?'],
        ['edu_categories', 'tenant_id = ?'],
        ['edu_material_pages', 'material_id IN (SELECT id FROM edu_materials WHERE tenant_id = ?)'],
        ['edu_materials', 'tenant_id = ?'],
        ['survey_answers', 'response_id IN (SELECT id FROM survey_responses WHERE tenant_id = ?)'],
        ['survey_responses', 'tenant_id = ?'],
        ['survey_assignments', 'tenant_id = ?'],
        ['survey_deliveries', 'tenant_id = ?'],
        ['survey_questions', 'survey_id IN (SELECT id FROM surveys WHERE tenant_id = ?)'],
        ['surveys', 'tenant_id = ?'],
        ['human_risk_scores', 'tenant_id = ?'],
        ['campaign_targets', 'campaign_id IN (SELECT id FROM campaigns WHERE tenant_id = ?)'],
        ['campaign_contents', 'campaign_id IN (SELECT id FROM campaigns WHERE tenant_id = ?)'],
        ['campaigns', 'tenant_id = ?'],
        ['target_group', 'group_id IN (SELECT id FROM groups WHERE tenant_id = ?)'],
        ['groups', 'tenant_id = ?'],
        ['targets', 'tenant_id = ?'],
        ['templates', 'tenant_id = ?'],
        ['position_masters', 'tenant_id = ?'],
        ['integration_idempotency_keys', 'tenant_id = ?'],
        // パスワード設定のトークン(外部キーの CASCADE でも消えるが、消した行数を数えるため明示する)
        ['user_password_tokens', 'user_id IN (SELECT id FROM users WHERE tenant_id = ?)'],
        // 多要素認証の回復コードとテナントの方針(段階1)。同じく明示して消す
        ['user_mfa_recovery_codes', 'user_id IN (SELECT id FROM users WHERE tenant_id = ?)'],
        ['tenant_security_policies', 'tenant_id = ?'],
        ['users', 'tenant_id = ?'],
    ];

    /**
     * STEPS の外で扱うテーブル。
     * audit_log は残す。report_mails は、このテナントの報告の候補か不審メールにだけつながっていた行を消す(reportMailCandidates)。
     * reputation_cache は URL などの評判のキャッシュで、どのテナントのものでもない。
     */
    private const GLOBAL_TABLES = ['audit_log', 'tenants', 'report_mails', 'reputation_cache', 'schema_migrations', 'sqlite_sequence'];

    private string $dataRoot;

    public function __construct(?string $dataRoot = null)
    {
        $root = rtrim($dataRoot ?? TenantStatus::dataRoot(), '/');
        if ($root === '' || !str_starts_with($root, '/')) {
            throw new InvalidArgumentException('データの置き場は絶対パスで指定してください');
        }
        $this->dataRoot = $root;
    }

    /** 完全削除の手順が扱うテーブルの一覧(テスト用)。 */
    public static function handledTables(): array
    {
        return array_values(array_unique(array_merge(array_column(self::STEPS, 0), self::GLOBAL_TABLES)));
    }

    /**
     * @return array{rows:array<string,int>, total:int, detached_superadmins:int, evacuated_to:string, backup:string, files_moved:bool}
     * @throws TenantPurgeError 条件を満たさない、または途中で失敗したとき(DB とファイルは元に戻す)
     */
    public function purge(int $tenantId, string $confirmSlug, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
        $tenant = $this->assertPurgeable($tenantId, $confirmSlug, $now);
        $slug = (string) $tenant['slug'];
        $source = $this->resolveDataDir($tenant);

        $stamp = $now->format('Ymd-His');
        $deletedRoot = $this->dataRoot . '/_deleted';
        $evacuated = $deletedRoot . '/' . $slug . '-' . $stamp;
        if (!is_dir($deletedRoot) && !@mkdir($deletedRoot, 0700, true) && !is_dir($deletedRoot)) {
            throw new TenantPurgeError('退避のフォルダを作れないため、完全削除を中止しました', 500);
        }
        if (file_exists($evacuated)) {
            throw new TenantPurgeError('同じ名前の退避のフォルダがあります。少し待ってからやり直してください', 409);
        }
        // 2. ファイルを先に移す。移せなければ DB には触れない。
        if ($source !== null) {
            if (!@rename($source, $evacuated)) {
                throw new TenantPurgeError('テナントのファイルを退避できないため、完全削除を中止しました', 500);
            }
        } elseif (!@mkdir($evacuated, 0700)) {
            throw new TenantPurgeError('退避のフォルダを作れないため、完全削除を中止しました', 500);
        }

        $backup = $evacuated . '/tet2-before-purge-' . $slug . '-' . $stamp . '.sqlite';
        try {
            // 3. DB 全体のバックアップ。VACUUM はトランザクションの中では動かないので、削除の前に単独で行う。
            Db::run('VACUUM INTO ?', [$backup]);
            if (!is_file($backup) || !@chmod($backup, 0600)) {
                throw new TenantPurgeError('バックアップを作れないため、完全削除を中止しました', 500);
            }
            // 4. 行の削除(1つのトランザクション)
            $result = $this->deleteRows($tenantId);
        } catch (Throwable $error) {
            // 5. 戻す: バックアップを消し、ファイルを元の場所へ戻す
            @unlink($backup);
            $restored = $source !== null ? @rename($evacuated, $source) : @rmdir($evacuated);
            if (!$restored) {
                error_log('tenant purge: 退避したファイルを戻せませんでした: ' . $evacuated);
            }
            if ($error instanceof TenantPurgeError) {
                throw $error;
            }
            error_log('tenant purge failed: ' . $error->getMessage());
            throw new TenantPurgeError('完全削除に失敗したため、元に戻しました', 500, $error);
        }

        return $result + [
            'evacuated_to' => $evacuated,
            'backup' => $backup,
            'files_moved' => $source !== null,
        ];
    }

    /** @return array<string,mixed> テナントの行 */
    private function assertPurgeable(int $tenantId, string $confirmSlug, DateTimeImmutable $now): array
    {
        $tenant = Db::one('SELECT * FROM tenants WHERE id = ?', [$tenantId]);
        if ($tenant === null) {
            throw new TenantPurgeError('テナントが見つかりません', 404);
        }
        if ((string) $tenant['status'] !== TenantStatus::DELETED) {
            throw new TenantPurgeError('削除済みのテナントだけを完全削除できます', 409);
        }
        if (!hash_equals((string) $tenant['slug'], $confirmSlug)) {
            throw new TenantPurgeError('確認のために入力した slug が一致しません', 400);
        }
        if (!TenantStatus::isValidSlug((string) $tenant['slug'])) {
            throw new TenantPurgeError('slug の形が不正なため、ファイルの退避先を決められません', 409);
        }
        $left = TenantStatus::retentionDaysLeft($tenant['deleted_at'] ?? null, $now);
        if ($left === null) {
            throw new TenantPurgeError('削除の日時が分からないため、完全削除できません', 409);
        }
        if ($left > 0) {
            throw new TenantPurgeError('保持期間(' . TenantStatus::RETENTION_DAYS . '日)が過ぎていません。あと ' . $left . ' 日で完全削除できます', 409);
        }
        $blockers = TenantStatus::activeWorkBlockers($tenantId);
        if ($blockers !== []) {
            throw new TenantPurgeError($blockers[0], 409);
        }
        $this->assertKnownTables();
        return $tenant;
    }

    /** 手順が知らないテーブルがあれば中止する(新しいテーブルの行を消し忘れないため)。 */
    private function assertKnownTables(): void
    {
        $known = self::handledTables();
        $tables = Db::all("SELECT name FROM sqlite_master WHERE type = 'table'");
        foreach ($tables as $row) {
            $name = (string) $row['name'];
            if (!in_array($name, $known, true)) {
                throw new TenantPurgeError('完全削除の手順が扱っていないテーブル(' . $name . ')があるため、中止しました。プログラムの更新が必要です', 409);
            }
        }
    }

    /** 移すべきテナントのファイルの場所。なければ null。データの置き場の直下でなければ中止する。 */
    private function resolveDataDir(array $tenant): ?string
    {
        $dir = rtrim((string) ($tenant['data_dir'] ?? ''), '/');
        if ($dir === '' || !file_exists($dir)) {
            return null;
        }
        if (is_link($dir) || !is_dir($dir)) {
            throw new TenantPurgeError('テナントのファイルの置き場がフォルダではないため、完全削除を中止しました', 409);
        }
        $real = realpath($dir);
        $root = realpath($this->dataRoot);
        if ($real === false || $root === false || dirname($real) !== $root || basename($real) === '_deleted') {
            throw new TenantPurgeError('テナントのファイルの置き場がデータの置き場(' . $this->dataRoot . ')の直下にないため、完全削除を中止しました', 409);
        }
        return $real;
    }

    /** @return array{rows:array<string,int>, total:int, detached_superadmins:int} */
    private function deleteRows(int $tenantId): array
    {
        $pdo = Db::pdo();
        // CASCADE で黙ってほかの行が消えないよう、外部キーを外して明示的に消す(トランザクションの外でしか切り替えられない)。
        $pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            return Db::txImmediate(function () use ($tenantId): array {
                // 退避とバックアップの間に状態が変わっていないかを、書き込みの鍵を取った後にもう一度確かめる
                $tenant = Db::one('SELECT status FROM tenants WHERE id = ?', [$tenantId]);
                if ($tenant === null || $tenant['status'] !== 'deleted') {
                    throw new TenantPurgeError('テナントの状態が変わったため、完全削除を中止しました', 409);
                }
                $blockers = TenantStatus::activeWorkBlockers($tenantId);
                if ($blockers !== []) {
                    throw new TenantPurgeError(implode('。', $blockers), 409);
                }
                $before = $this->foreignKeyViolations();
                $reportMailIds = array_map('intval', array_column(Db::all(
                    'SELECT report_mail_id AS id FROM report_mail_matches WHERE tenant_id = ?
                     UNION SELECT report_mail_id AS id FROM suspicious_mails WHERE tenant_id = ? AND report_mail_id IS NOT NULL',
                    [$tenantId, $tenantId]
                ), 'id'));

                $rows = [];
                $detached = Db::run(
                    "UPDATE users SET tenant_id = NULL WHERE tenant_id = ? AND role = 'superadmin'",
                    [$tenantId]
                );
                foreach (self::STEPS as [$table, $where]) {
                    $params = array_fill(0, substr_count($where, '?'), $tenantId);
                    $rows[$table] = Db::run("DELETE FROM {$table} WHERE {$where}", $params);
                }
                // このテナントの報告にだけつながっていた報告メール(ほかのテナントの候補や不審メールが残るものは触らない)。
                // 元のメールは全テナント共有のメールボックスに残るので、行を消すと5分ごとの取込が同じメールを取り込み直し、
                // 個人の情報が戻ってしまう。行は残し、取込が「取り込み済み」と判断する列(hash、ファイル)以外を空にする。
                $rows['report_mails'] = 0;
                foreach ($reportMailIds as $id) {
                    $rows['report_mails'] += Db::run(
                        "UPDATE report_mails SET message_id = NULL, date_header = NULL, from_email = NULL, subject_head = NULL,
                                parse_error = NULL, parse_status = 'purged'
                         WHERE id = ?
                           AND NOT EXISTS (SELECT 1 FROM report_mail_matches WHERE report_mail_id = ?)
                           AND NOT EXISTS (SELECT 1 FROM suspicious_mails WHERE report_mail_id = ?)",
                        [$id, $id, $id]
                    );
                }
                $rows['tenants'] = Db::run('DELETE FROM tenants WHERE id = ?', [$tenantId]);

                // ほかのテナントの行が、消した行を参照していたら取り消す
                $new = array_diff_key($this->foreignKeyViolations(), $before);
                if ($new !== []) {
                    $tables = array_values(array_unique(array_map(static fn(array $v): string => $v['table'], $new)));
                    throw new TenantPurgeError('ほかのテナントなどから参照されている行があるため、完全削除を取り消しました(' . implode(', ', $tables) . ')', 409);
                }
                // tenant_id を持つテーブルに、そのテナントの行が残っていないこと(audit_log は残す)
                foreach (Db::all("SELECT name FROM sqlite_master WHERE type = 'table'") as $row) {
                    $table = (string) $row['name'];
                    if ($table === 'audit_log' || !self::hasColumn($table, 'tenant_id')) {
                        continue;
                    }
                    $left = Db::one("SELECT COUNT(*) AS c FROM {$table} WHERE tenant_id = ?", [$tenantId]);
                    if ((int) ($left['c'] ?? 0) !== 0) {
                        throw new TenantPurgeError('消し残しの行(' . $table . ')があるため、完全削除を取り消しました', 500);
                    }
                }
                return ['rows' => $rows, 'total' => array_sum($rows), 'detached_superadmins' => $detached];
            });
        } finally {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    /** @return array<string, array{table:string}> 参照切れの一覧(キーは table:rowid:parent:fkid) */
    private function foreignKeyViolations(): array
    {
        $out = [];
        foreach (Db::all('PRAGMA foreign_key_check') as $row) {
            $key = $row['table'] . ':' . ($row['rowid'] ?? '') . ':' . $row['parent'] . ':' . $row['fkid'];
            $out[$key] = ['table' => (string) $row['table']];
        }
        return $out;
    }

    private static function hasColumn(string $table, string $column): bool
    {
        foreach (Db::all('SELECT name FROM pragma_table_info(?)', [$table]) as $info) {
            if ($info['name'] === $column) {
                return true;
            }
        }
        return false;
    }
}

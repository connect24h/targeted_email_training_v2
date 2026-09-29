<?php
/**
 * 自動の教育配信の実行履歴(段B2 の G61)。
 * 自動の投入(訓練の失敗の EduAutoEnroll、新入社員の EduScheduler)が配信1件を処理するたびに1行を残す。
 * 誰を入れるかはここでは決めない(記録と一覧だけ)。
 *
 * 監査ログは、新しく入れた人がいた実行と、失敗した実行だけに残す(timer で何度も動くので、0件の実行まで積むと監査ログが埋まる)。
 * user_id は NULL にして、自動の処理であることが分かるようにする(EduScheduler::audit と同じ)。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class EduAutoEnrollRuns
{
    public const SOURCES = ['phishing_failure', 'new_target'];

    /** 画面に出す実行の数(新しい順)。 */
    public const LIST_LIMIT = 100;

    /** 失敗の理由として残す文字数。 */
    private const ERROR_MAX = 500;

    public static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
    }

    /**
     * 実行を1行残す。$error は画面に出す理由(担当者向けの文)。内部の例外の文は入れない。
     */
    public static function record(int $tenantId, int $deliveryId, string $source, string $startedAt,
        int $matched, int $enrolled, ?string $error = null): void
    {
        if (!in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('自動の投入の種類が不正です');
        }
        $error = $error === null ? null : mb_substr($error, 0, self::ERROR_MAX);
        Db::run(
            'INSERT INTO edu_auto_enroll_runs (tenant_id, delivery_id, source, started_at, finished_at, matched_count, enrolled_count, error)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $deliveryId, $source, $startedAt, self::now(), $matched, $enrolled, $error]
        );
        if ($enrolled > 0 || $error !== null) {
            Db::run(
                'INSERT INTO audit_log (tenant_id, user_id, action, detail, ip) VALUES (?, NULL, ?, ?, ?)',
                [$tenantId, 'edu_auto_enroll.run',
                 'delivery_id=' . $deliveryId . ',source=' . $source . ',matched=' . $matched . ',enrolled=' . $enrolled
                 . ($error !== null ? ',error=1' : ''), '']
            );
        }
    }

    /**
     * 配信1件の実行(新しい順、LIST_LIMIT 件まで)と、当てはまって入った人(入った日時は割当を作った日時)。
     * @return array{runs: list<array<string,mixed>>, learners: list<array<string,mixed>>}
     */
    public static function forDelivery(int $tenantId, int $deliveryId): array
    {
        $runs = Db::all(
            'SELECT id, source, started_at, finished_at, matched_count, enrolled_count, error
             FROM edu_auto_enroll_runs WHERE tenant_id = ? AND delivery_id = ?
             ORDER BY id DESC LIMIT ' . self::LIST_LIMIT,
            [$tenantId, $deliveryId]
        );
        $learners = Db::all(
            "SELECT a.target_id, a.status, a.created_at AS matched_at, a.completed_at,
                    t.name, t.email, t.employee_no, t.department, t.is_test, t.status AS target_status
             FROM edu_assignments a
             INNER JOIN targets t ON t.id = a.target_id AND t.tenant_id = a.tenant_id
             WHERE a.tenant_id = ? AND a.delivery_id = ?
             ORDER BY a.created_at DESC, a.id DESC",
            [$tenantId, $deliveryId]
        );
        return [
            'runs' => array_map(static fn(array $r): array => [
                'id' => (int) $r['id'],
                'source' => (string) $r['source'],
                'started_at' => $r['started_at'],
                'finished_at' => $r['finished_at'],
                'matched_count' => (int) $r['matched_count'],
                'enrolled_count' => (int) $r['enrolled_count'],
                'error' => $r['error'],
            ], $runs),
            'learners' => array_map(static fn(array $r): array => [
                'target_id' => (int) $r['target_id'],
                'name' => (string) ($r['name'] ?? ''),
                'email' => (string) $r['email'],
                'employee_no' => $r['employee_no'],
                'department' => trim((string) $r['department']) === '' ? '(未設定)' : (string) $r['department'],
                'is_test' => (int) $r['is_test'],
                'archived' => (string) $r['target_status'] !== 'active',
                'status' => (string) $r['status'],
                'matched_at' => $r['matched_at'],
                'completed_at' => $r['completed_at'],
            ], $learners),
        ];
    }
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/ReportMailParser.php';
require_once __DIR__ . '/SuspiciousMailAnalyzer.php';

/**
 * 不審メール（suspicious_mails）の保存・再解析・更新・外部評判照会。
 *
 * raw .eml はテナントの data_dir/suspicious/<sha256>.eml（テナント未確定は
 * TET2_SUSPICIOUS_BASE または /opt/training/tet2-data/suspicious-unassigned/）に置き、DB にはパスだけ持つ。
 * API（アップロード）と ReportMailIngest（報告用アドレス）の両方からここを通る。
 */
final class SuspiciousMailStore
{
    public const MAX_RAW_BYTES = 2 * 1024 * 1024;
    public const STATUSES = ['open', 'in_progress', 'resolved'];
    public const PRIORITIES = ['low', 'normal', 'high'];
    public const EDITABLE = ['category', 'status', 'priority', 'assigned_to', 'note'];
    /** 評判照会の対象にする URL / ハッシュの上限（1 メールあたり）。 */
    public const REPUTATION_TARGETS = 20;
    public const REPUTATION_TTL_DAYS = 7;
    private const UNASSIGNED_BASE = '/opt/training/tet2-data/suspicious-unassigned';

    /**
     * raw を解析して所見まで出す（保存しない）。
     * @return array{parsed:array, analysis:array, training_ids:array, result:array}
     * @throws InvalidArgumentException MIME として読めない場合
     */
    public static function analyzeRaw(string $raw, ?int $tenantId, array $reputation = []): array
    {
        if (strlen($raw) > self::MAX_RAW_BYTES) {
            throw new InvalidArgumentException('メールが大きすぎます（2MB 以内）');
        }
        $parsed = ReportMailParser::parse($raw, ['collect' => true]);
        if (!$parsed['ok']) {
            throw new InvalidArgumentException('メールとして解析できません: ' . ($parsed['error'] ?? 'unknown'));
        }
        $trainingIds = self::trainingIds(array_unique(array_merge($parsed['tracking_ids_from_msgid'], $parsed['tracking_ids_from_body'])), $tenantId);
        $context = ['own_domains' => self::ownDomains($tenantId), 'training_tracking_ids' => $trainingIds];
        $analysis = self::compactAnalysis($parsed['analysis']);
        $result = SuspiciousMailAnalyzer::analyze($analysis, $context, $reputation);
        return ['parsed' => $parsed, 'analysis' => $analysis, 'training_ids' => $trainingIds, 'result' => $result];
    }

    /**
     * 解析して保存する。同一テナント・同一 sha256 は保存せず既存 id を返す。
     * @param array $meta tenant_id, source(upload|maildir), report_mail_id?, reporter_email?, uploaded_by?, received_at?
     * @return array{id:int, duplicate:bool}
     */
    public static function create(string $raw, array $meta): array
    {
        $tenantId = $meta['tenant_id'] ?? null;
        $sha = hash('sha256', $raw);
        $existing = self::findBySha($tenantId, $sha);
        if ($existing !== null) {
            return ['id' => (int) $existing['id'], 'duplicate' => true];
        }
        $done = self::analyzeRaw($raw, $tenantId);
        $target = $done['analysis']['messages'][$done['result']['target_index']] ?? null;
        $outer = $done['analysis']['messages'][0] ?? null;
        $path = self::storeRaw($tenantId, $sha, $raw);
        $now = date('Y-m-d H:i:s');
        $receivedAt = $meta['received_at'] ?? self::dateFrom($outer['date'] ?? null) ?? $now;
        $id = Db::insert('INSERT INTO suspicious_mails (tenant_id, source, report_mail_id, reporter_email, uploaded_by, raw_path, sha256, raw_bytes,
                message_id, subject, from_email, from_name, received_at, is_training, tracking_id, analysis_json, findings_json, score,
                suggested_category, category, analyzer_version, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $tenantId, $meta['source'] ?? 'upload', $meta['report_mail_id'] ?? null, $meta['reporter_email'] ?? null, $meta['uploaded_by'] ?? null,
            $path, $sha, strlen($raw),
            $target['message_id'] ?? null, mb_substr((string) ($target['subject'] ?? ''), 0, 300), $target['from_email'] ?? null,
            mb_substr((string) ($target['from_name'] ?? ''), 0, 200) ?: null, $receivedAt,
            $done['training_ids'] !== [] ? 1 : 0, $done['training_ids'][0] ?? null,
            self::json($done['analysis']), self::json($done['result']['findings']), $done['result']['score'],
            $done['result']['suggested_category'], $done['result']['suggested_category'] === 'training' ? 'training' : 'undetermined',
            SuspiciousMailAnalyzer::VERSION, $now, $now,
        ]);
        return ['id' => $id, 'duplicate' => false];
    }

    /** ReportMailIngest から呼ぶ。訓練メールでない報告を不審メールとして登録する。失敗は呼び出し側で握る。 */
    public static function fromReportMail(array $mail, string $raw): ?int
    {
        $tenantId = self::tenantOfReporter($mail['from_email'] ?? null);
        $result = self::create($raw, ['tenant_id' => $tenantId, 'source' => 'maildir', 'report_mail_id' => $mail['id'] ?? null,
            'reporter_email' => $mail['from_email'] ?? null, 'received_at' => $mail['received_at'] ?? null]);
        return $result['duplicate'] ? null : $result['id'];
    }

    public static function find(int $id, ?int $tenantId): ?array
    {
        return Db::one('SELECT * FROM suspicious_mails WHERE id=?' . self::scope($tenantId), self::scopeParams([$id], $tenantId));
    }

    /** 詳細表示用。analysis / findings を配列に戻し、履歴と評判を付ける。 */
    public static function detail(int $id, ?int $tenantId): ?array
    {
        $row = self::find($id, $tenantId);
        if ($row === null) {
            return null;
        }
        $row['analysis'] = json_decode((string) $row['analysis_json'], true) ?: ['messages' => []];
        $row['findings'] = json_decode((string) $row['findings_json'], true) ?: [];
        unset($row['analysis_json'], $row['findings_json'], $row['raw_path']);
        $row['history'] = Db::all('SELECT actor_email, field, old_value, new_value, created_at FROM suspicious_mail_history WHERE suspicious_mail_id=? ORDER BY id', [$id]);
        $row['reputation'] = self::loadReputation($row['analysis']);
        $row['reputation_targets'] = count(self::reputationTargets($row['analysis']));
        return $row;
    }

    /** category / status / priority / assigned_to / note を更新し、変わった項目を履歴に残す。 */
    public static function update(int $id, ?int $tenantId, array $fields, string $actor): array
    {
        $row = self::find($id, $tenantId);
        if ($row === null) {
            throw new DomainException('不審メールが見つかりません', 404);
        }
        $changed = [];
        $sets = [];
        $params = [];
        foreach (self::EDITABLE as $field) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            $value = $fields[$field];
            if ($value !== null && !is_string($value)) {
                throw new DomainException($field . ' が不正です', 400);
            }
            $value = $value === null ? null : trim($value);
            if ($value === '') {
                $value = null;
            }
            if ($field === 'category' && !in_array($value, SuspiciousMailAnalyzer::CATEGORIES, true)) {
                throw new DomainException('分類が不正です', 400);
            }
            if ($field === 'status' && !in_array($value, self::STATUSES, true)) {
                throw new DomainException('状態が不正です', 400);
            }
            if ($field === 'priority' && !in_array($value, self::PRIORITIES, true)) {
                throw new DomainException('優先度が不正です', 400);
            }
            if (in_array($field, ['assigned_to', 'note'], true) && $value !== null) {
                if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
                    throw new DomainException($field . ' に使用できない文字が含まれています', 400);
                }
                $max = $field === 'note' ? 4000 : 200;
                if (mb_strlen($value) > $max) {
                    throw new DomainException($field . ' が長すぎます（最大' . $max . '文字）', 400);
                }
            }
            if ((string) ($row[$field] ?? '') === (string) ($value ?? '')) {
                continue;
            }
            $changed[$field] = ['old' => $row[$field], 'new' => $value];
            $sets[] = "$field=?";
            $params[] = $value;
        }
        if ($changed === []) {
            return ['id' => $id, 'changed' => []];
        }
        $now = date('Y-m-d H:i:s');
        $sets[] = 'updated_at=?';
        $params[] = $now;
        $params[] = $id;
        Db::run('UPDATE suspicious_mails SET ' . implode(',', $sets) . ' WHERE id=?', $params);
        foreach ($changed as $field => $diff) {
            Db::run('INSERT INTO suspicious_mail_history (suspicious_mail_id, actor_email, field, old_value, new_value, created_at) VALUES (?,?,?,?,?,?)',
                [$id, $actor, $field, $diff['old'], $diff['new'], $now]);
        }
        return ['id' => $id, 'changed' => array_keys($changed)];
    }

    /** raw を読み直して解析し直す。手動で入れた分類・状態は保持する。 */
    public static function reanalyze(int $id, ?int $tenantId): array
    {
        $row = self::find($id, $tenantId);
        if ($row === null) {
            throw new DomainException('不審メールが見つかりません', 404);
        }
        $raw = self::readRaw($row);
        $analysis = json_decode((string) $row['analysis_json'], true) ?: ['messages' => []];
        $done = self::analyzeRaw($raw, $row['tenant_id'] === null ? null : (int) $row['tenant_id'], self::loadReputation($analysis));
        return self::saveAnalysis($row, $done);
    }

    /**
     * 未照会（または期限切れ）の添付ハッシュ・URL を VirusTotal で最大 $max 件照会し、所見を再計算する。
     * @return array{done:int, remaining:int, rate_limited:bool, unauthorized:bool}
     */
    public static function runReputation(int $id, ?int $tenantId, VirusTotalClient $client, int $max = 4): array
    {
        $row = self::find($id, $tenantId);
        if ($row === null) {
            throw new DomainException('不審メールが見つかりません', 404);
        }
        $analysis = json_decode((string) $row['analysis_json'], true) ?: ['messages' => []];
        $targets = self::reputationTargets($analysis);
        $cached = self::loadReputation($analysis, true);
        $pending = array_values(array_filter($targets, static fn(array $t): bool => !isset($cached[$t['kind']][$t['key']])));
        $summary = ['done' => 0, 'remaining' => count($pending), 'rate_limited' => false, 'unauthorized' => false];
        foreach (array_slice($pending, 0, $max) as $target) {
            $outcome = $target['kind'] === 'file' ? $client->lookupFile($target['key']) : $client->lookupUrl($target['key']);
            if ($outcome['status'] === 'rate_limited') {
                $summary['rate_limited'] = true;
                break;
            }
            if ($outcome['status'] === 'unauthorized') {
                $summary['unauthorized'] = true;
                break;
            }
            if ($outcome['status'] === 'error') {
                continue;
            }
            Db::run('INSERT INTO reputation_cache (provider, kind, lookup_key, found, malicious, suspicious, harmless, undetected, result_json, fetched_at)
                     VALUES (\'virustotal\',?,?,?,?,?,?,?,?,?)
                     ON CONFLICT(provider, kind, lookup_key) DO UPDATE SET found=excluded.found, malicious=excluded.malicious,
                     suspicious=excluded.suspicious, harmless=excluded.harmless, undetected=excluded.undetected,
                     result_json=excluded.result_json, fetched_at=excluded.fetched_at',
                [$target['kind'], $target['key'], $outcome['found'], $outcome['malicious'], $outcome['suspicious'], $outcome['harmless'],
                 $outcome['undetected'], self::json($outcome['raw']), date('Y-m-d H:i:s')]);
            $summary['done']++;
            $summary['remaining']--;
        }
        if ($summary['done'] > 0) {
            $done = self::analyzeRaw(self::readRaw($row), $row['tenant_id'] === null ? null : (int) $row['tenant_id'], self::loadReputation($analysis));
            self::saveAnalysis($row, $done);
            Db::run('UPDATE suspicious_mails SET reputation_checked_at=? WHERE id=?', [date('Y-m-d H:i:s'), $id]);
        }
        return $summary;
    }

    /** 照会対象（判定対象メッセージの添付 sha256 と展開後 URL）。 */
    public static function reputationTargets(array $analysis): array
    {
        $messages = $analysis['messages'] ?? [];
        if ($messages === []) {
            return [];
        }
        $index = 0;
        foreach ($messages as $i => $m) {
            if ($m['depth'] >= $messages[$index]['depth']) {
                $index = $i;
            }
        }
        $targets = [];
        $seen = [];
        foreach ($messages[$index]['attachments'] ?? [] as $att) {
            if (!isset($seen['file:' . $att['sha256']])) {
                $seen['file:' . $att['sha256']] = true;
                $targets[] = ['kind' => 'file', 'key' => $att['sha256']];
            }
        }
        foreach ($messages[$index]['urls'] ?? [] as $url) {
            $key = $url['unwrapped'];
            if (!isset($seen['url:' . $key]) && preg_match('~^https?://~i', $key)) {
                $seen['url:' . $key] = true;
                $targets[] = ['kind' => 'url', 'key' => $key];
            }
        }
        return array_slice($targets, 0, self::REPUTATION_TARGETS);
    }

    /** キャッシュ済みの評判を ['file'=>[sha=>row], 'url'=>[url=>row]] で返す。$fresh=true なら TTL 内だけ。 */
    public static function loadReputation(array $analysis, bool $fresh = false): array
    {
        $result = ['file' => [], 'url' => []];
        foreach (self::reputationTargets($analysis) as $target) {
            $row = Db::one('SELECT * FROM reputation_cache WHERE provider=\'virustotal\' AND kind=? AND lookup_key=?', [$target['kind'], $target['key']]);
            if ($row === null) {
                continue;
            }
            if ($fresh && strtotime((string) $row['fetched_at']) < time() - self::REPUTATION_TTL_DAYS * 86400) {
                continue;
            }
            $row['result'] = json_decode((string) $row['result_json'], true);
            unset($row['result_json']);
            $result[$target['kind']][$target['key']] = $row;
        }
        return $result;
    }

    /* ---------- 内部 ---------- */

    private static function saveAnalysis(array $row, array $done): array
    {
        $result = $done['result'];
        Db::run('UPDATE suspicious_mails SET analysis_json=?, findings_json=?, score=?, suggested_category=?, is_training=?, tracking_id=?,
                 analyzer_version=?, updated_at=? WHERE id=?',
            [self::json($done['analysis']), self::json($result['findings']), $result['score'], $result['suggested_category'],
             $done['training_ids'] !== [] ? 1 : 0, $done['training_ids'][0] ?? null, SuspiciousMailAnalyzer::VERSION, date('Y-m-d H:i:s'), $row['id']]);
        return ['id' => (int) $row['id'], 'score' => $result['score'], 'suggested_category' => $result['suggested_category'], 'findings' => $result['findings']];
    }

    private static function findBySha(?int $tenantId, string $sha): ?array
    {
        return Db::one('SELECT id FROM suspicious_mails WHERE sha256=? AND ' . ($tenantId === null ? 'tenant_id IS NULL' : 'tenant_id=?'),
            $tenantId === null ? [$sha] : [$sha, $tenantId]);
    }

    private static function scope(?int $tenantId): string
    {
        return $tenantId === null ? '' : ' AND tenant_id=?';
    }

    private static function scopeParams(array $params, ?int $tenantId): array
    {
        return $tenantId === null ? $params : [...$params, $tenantId];
    }

    /** 追跡 ID のうち実在し、テナントが一致するもの。テナント未確定なら実在すれば可。 */
    private static function trainingIds(array $ids, ?int $tenantId): array
    {
        $found = [];
        foreach ($ids as $id) {
            $row = Db::one('SELECT c.tenant_id FROM campaign_targets ct JOIN campaigns c ON c.id=ct.campaign_id WHERE ct.tracking_id=?', [$id]);
            if ($row !== null && ($tenantId === null || (int) $row['tenant_id'] === $tenantId)) {
                $found[] = $id;
            }
        }
        return $found;
    }

    /** テナントの対象者メールアドレスから自社ドメイン（多い順に最大 10）。 */
    private static function ownDomains(?int $tenantId): array
    {
        if ($tenantId === null) {
            return [];
        }
        $rows = Db::all("SELECT lower(substr(email, instr(email, '@') + 1)) AS d, count(*) AS n FROM targets
                         WHERE tenant_id=? AND email LIKE '%@%' GROUP BY d ORDER BY n DESC LIMIT 10", [$tenantId]);
        return array_values(array_filter(array_column($rows, 'd'), static fn($d): bool => is_string($d) && $d !== ''));
    }

    private static function tenantOfReporter(?string $email): ?int
    {
        if ($email === null) {
            return null;
        }
        $rows = Db::all("SELECT DISTINCT tenant_id FROM targets WHERE email=? COLLATE NOCASE AND status='active'", [$email]);
        return count($rows) === 1 ? (int) $rows[0]['tenant_id'] : null;
    }

    /** parser の analysis から生 HTML を落とし、保存サイズを抑える（html_text は保持）。 */
    private static function compactAnalysis(array $analysis): array
    {
        foreach ($analysis['messages'] as &$m) {
            // 全ヘッダーは残すが、極端に長い値は切る（DKIM 署名など）。
            foreach ($m['headers'] as $name => &$values) {
                foreach ($values as &$v) {
                    if (strlen($v) > 2000) {
                        $v = mb_strcut($v, 0, 2000, 'UTF-8') . '…';
                    }
                }
                unset($v);
            }
            unset($values);
        }
        unset($m);
        return $analysis;
    }

    private static function dateFrom(?string $header): ?string
    {
        if ($header === null) {
            return null;
        }
        // 曜日名が実際の日付と食い違うと strtotime が「次のその曜日」へ進めてしまうので、曜日は無視する。
        $ts = strtotime((string) preg_replace('/^\s*[A-Za-z]{3},\s*/', '', $header));
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: 'null';
    }

    private static function baseDir(?int $tenantId): string
    {
        $override = getenv('TET2_SUSPICIOUS_BASE');
        if (is_string($override) && $override !== '') {
            return rtrim($override, '/') . '/' . ($tenantId === null ? 'unassigned' : (string) $tenantId);
        }
        if ($tenantId !== null) {
            $tenant = Db::one('SELECT data_dir FROM tenants WHERE id=?', [$tenantId]);
            $dataDir = rtrim((string) ($tenant['data_dir'] ?? ''), '/');
            if ($dataDir !== '' && is_dir($dataDir)) {
                return $dataDir . '/suspicious';
            }
        }
        return self::UNASSIGNED_BASE;
    }

    private static function storeRaw(?int $tenantId, string $sha, string $raw): string
    {
        $dir = self::baseDir($tenantId);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('不審メールの保存先を作成できません');
        }
        $path = $dir . '/' . $sha . '.eml';
        if (!is_file($path)) {
            if (@file_put_contents($path, $raw, LOCK_EX) === false) {
                throw new RuntimeException('不審メールを保存できません');
            }
            @chmod($path, 0640);
        }
        return $path;
    }

    private static function readRaw(array $row): string
    {
        $path = (string) $row['raw_path'];
        if (!is_file($path) || !is_readable($path)) {
            throw new DomainException('保存された .eml が見つかりません', 409);
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new DomainException('保存された .eml を読めません', 409);
        }
        return $raw;
    }
}

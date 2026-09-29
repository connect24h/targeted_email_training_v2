<?php declare(strict_types=1);

/**
 * 訓練のレポートの「行動履歴」と「利用者ごと」のタブ、判定の修正(段B1、G01 と G57)。
 *
 * 行動履歴は events を1行動1行で出す。集計に入らない装置の行(verdict='scanner')も出し、担当者が判定を直せる。
 * 利用者ごとは宛先1件を1行にし、報告、返信、初回クリック、配信エラーをまとめる(集計と同じく利用者の行動だけ)。
 * api/report.php は末尾でディスパッチが走るため、テストから関数を呼べるよう lib/ に置く(TrainingLogRows.php と同じ流儀)。
 */

/** 行動履歴に出す行動の種類。click_bot は 2026-08 の Slackbot の事故の残りで、装置のクリックとして読むだけにする。 */
const TRAINING_ACTION_TYPES = ['open', 'click', 'auth', 'report', 'reply', 'click_bot'];
/** 担当者が判定を直せる種類(click_bot は集計の種類が違うので直せない)。 */
const TRAINING_VERDICT_EDITABLE_TYPES = ['open', 'click', 'auth', 'report', 'reply'];
/** 行動履歴の最大件数。多い時は新しい順にここまで出す。 */
const TRAINING_ACTIONS_LIMIT = 2000;

/**
 * 1キャンペーンの行動を新しい順に返す。テナントとキャンペーンの所有は呼び出し側で確かめる。
 * @return array{rows:list<array<string,mixed>>, counts:array{user:int, scanner:int}, truncated:bool}
 */
function training_actions_rows(int $campaignId, int $tenantId): array
{
    $placeholders = implode(',', array_fill(0, count(TRAINING_ACTION_TYPES), '?'));
    $rows = Db::all(
        "SELECT e.id, e.tracking_id, e.event_type, e.auth_variant, e.occurred_at, e.source, e.raw,
                e.verdict, e.verdict_reason, e.verdict_source, e.verdict_at,
                t.name AS target_name, t.email AS target_email, t.is_test
         FROM events e
         LEFT JOIN campaign_targets ct ON ct.tracking_id = e.tracking_id AND ct.campaign_id = e.campaign_id
         LEFT JOIN targets t ON t.id = ct.target_id AND t.tenant_id = e.tenant_id
         WHERE e.tenant_id = ? AND e.campaign_id = ? AND e.event_type IN ($placeholders)
         ORDER BY e.occurred_at DESC, e.id DESC
         LIMIT " . (TRAINING_ACTIONS_LIMIT + 1),
        array_merge([$tenantId, $campaignId], TRAINING_ACTION_TYPES)
    );
    $truncated = count($rows) > TRAINING_ACTIONS_LIMIT;
    $out = [];
    foreach (array_slice($rows, 0, TRAINING_ACTIONS_LIMIT) as $r) {
        $out[] = training_action_shape($r);
    }
    $counts = ['user' => 0, 'scanner' => 0];
    foreach (Db::all(
        "SELECT CASE WHEN event_type = 'click_bot' THEN 'scanner' ELSE verdict END AS v, COUNT(*) AS n
         FROM events WHERE tenant_id = ? AND campaign_id = ? AND event_type IN ($placeholders) GROUP BY v",
        array_merge([$tenantId, $campaignId], TRAINING_ACTION_TYPES)
    ) as $c) {
        $counts[(string) $c['v']] = (int) $c['n'];
    }
    return ['rows' => $out, 'counts' => $counts, 'truncated' => $truncated];
}

/** events の1行を画面の1行にする。raw は返さず、IP と端末の概要だけを取り出す。 */
function training_action_shape(array $r): array
{
    $legacy = $r['event_type'] === 'click_bot';
    [$ip, $ua] = training_action_ip_ua((string) ($r['source'] ?? ''), (string) ($r['raw'] ?? ''));
    return [
        'id' => (int) $r['id'],
        'occurred_at' => (string) $r['occurred_at'],
        'event_type' => $legacy ? 'click' : (string) $r['event_type'],
        'auth_variant' => $r['auth_variant'],
        'verdict' => $legacy ? 'scanner' : (string) $r['verdict'],
        'verdict_reason' => $legacy ? '2026-08 の Slackbot の事故の記録(集計の対象外)' : $r['verdict_reason'],
        'verdict_source' => $legacy ? 'legacy' : (string) $r['verdict_source'],
        'verdict_at' => $r['verdict_at'],
        'editable' => !$legacy,
        'ip' => $ip,
        'device' => training_device_summary($ua, (string) ($r['source'] ?? '')),
        'tracking_id' => (string) $r['tracking_id'],
        'target_name' => (string) ($r['target_name'] ?? ''),
        'target_email' => (string) ($r['target_email'] ?? ''),
        'is_test' => (int) ($r['is_test'] ?? 0),
    ];
}

/**
 * 取込の元の行から IP と User-Agent を取り出す。
 * Apache combined は先頭が IP、末尾の "..." が User-Agent。偽ログインの記録は "IP: x | ... | UserAgent: y"。
 * メール(報告、返信)は IP を持たない。途中で切れた行の User-Agent は null(読めない)にする。
 * @return array{0:string, 1:?string}
 */
function training_action_ip_ua(string $source, string $raw): array
{
    if ($source === 'apache_access') {
        $ip = preg_match('/^(\S+)\s/', $raw, $m) ? $m[1] : '';
        $ua = preg_match('/"\s*"([^"]*)"\s*$/', rtrim($raw), $u) ? $u[1] : null;
        return [training_action_valid_ip($ip), $ua];
    }
    if ($source === 'text_log') {
        $ip = preg_match('/IP:\s*([^|\s]+)/', $raw, $m) ? $m[1] : '';
        $ua = preg_match('/UserAgent:\s*([^|]+)/', $raw, $u) ? trim($u[1]) : null;
        return [training_action_valid_ip($ip), $ua];
    }
    return ['', null];
}

function training_action_valid_ip(string $ip): string
{
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
}

/** User-Agent から端末の概要(「Windows / Chrome」など)を作る。細かい版は出さない。 */
function training_device_summary(?string $ua, string $source = ''): string
{
    if (in_array($source, ['report_mail', 'reply_mail'], true)) {
        return 'メール';
    }
    if ($ua === null || $ua === '' || $ua === '-') {
        return '不明';
    }
    $bots = ['Slackbot' => 'Slack のリンク展開', 'Proofpoint' => 'Proofpoint', 'Mimecast' => 'Mimecast', 'Barracuda' => 'Barracuda',
        'SafeLinks' => 'Microsoft Safe Links', 'Microsoft-' => 'Microsoft のサービス', 'Googlebot' => 'Googlebot',
        'curl' => 'curl', 'python-requests' => 'Python', 'Go-http-client' => 'Go', 'WindowsPowerShell' => 'PowerShell'];
    foreach ($bots as $needle => $label) {
        if (stripos($ua, $needle) !== false) {
            return $label;
        }
    }
    $os = match (true) {
        stripos($ua, 'iPhone') !== false => 'iPhone',
        stripos($ua, 'iPad') !== false => 'iPad',
        stripos($ua, 'Android') !== false => 'Android',
        stripos($ua, 'Windows') !== false => 'Windows',
        stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false => 'Mac',
        stripos($ua, 'Linux') !== false => 'Linux',
        default => 'その他',
    };
    $browser = match (true) {
        stripos($ua, 'Edg/') !== false => 'Edge',
        stripos($ua, 'Firefox/') !== false => 'Firefox',
        stripos($ua, 'Chrome/') !== false || stripos($ua, 'CriOS/') !== false => 'Chrome',
        stripos($ua, 'Safari/') !== false => 'Safari',
        default => '',
    };
    return $browser === '' ? $os : $os . ' / ' . $browser;
}

/**
 * 担当者が行動1件の判定を直す。直した行は取込で上書きしない(verdict_source='manual')。
 * テナントの外の行とクローズ済みの訓練の行は直さない。
 * @return array{event_id:int, campaign_id:int, from:string, to:string, changed:bool}
 * @throws DomainException 見つからない(404)、直せない(409)
 */
function training_set_verdict(int $eventId, int $tenantId, string $verdict, int $userId): array
{
    return Db::txImmediate(static function () use ($eventId, $tenantId, $verdict, $userId): array {
        $event = Db::one(
            'SELECT e.id, e.campaign_id, e.tracking_id, e.event_type, e.verdict, c.closed_at
             FROM events e
             INNER JOIN campaigns c ON c.id = e.campaign_id AND c.tenant_id = e.tenant_id AND c.deleted_at IS NULL
             WHERE e.id = ? AND e.tenant_id = ?',
            [$eventId, $tenantId]
        );
        if ($event === null) {
            throw new DomainException('行動が見つかりません', 404);
        }
        if (!in_array($event['event_type'], TRAINING_VERDICT_EDITABLE_TYPES, true)) {
            throw new DomainException('この記録の判定は直せません', 409);
        }
        if ($event['closed_at'] !== null) {
            throw new DomainException('クローズ済みの訓練の判定は直せません', 409);
        }
        $result = ['event_id' => $eventId, 'campaign_id' => (int) $event['campaign_id'], 'tracking_id' => (string) $event['tracking_id'],
            'event_type' => (string) $event['event_type'], 'from' => (string) $event['verdict'], 'to' => $verdict, 'changed' => false];
        if ($event['verdict'] === $verdict) {
            return $result;
        }
        Db::run(
            "UPDATE events SET verdict = ?, verdict_source = 'manual', verdict_reason = ?, verdict_by = ?, verdict_at = datetime('now','localtime')
             WHERE id = ? AND tenant_id = ?",
            [$verdict, $verdict === 'user' ? '担当者が利用者の行動に直した' : '担当者が装置の行動に直した', $userId, $eventId, $tenantId]
        );
        return ['changed' => true] + $result;
    });
}

/**
 * 利用者ごとの1行(宛先1件を1行)。報告、返信、初回クリック、認証、配信エラー、届かない宛先をまとめる。
 * 行動は集計と同じく利用者の行動(verdict='user')だけ。テストの対象者も出し、is_test で見分ける。
 */
function training_people_rows(int $campaignId, int $tenantId): array
{
    $rows = Db::all(
        "SELECT ct.id, ct.tracking_id, ct.content_no, ct.send_status, ct.sent_at,
                ct.delivery_state, ct.delivery_state_at, ct.delivery_detail,
                t.name, t.email, t.company, t.department, t.is_test,
                MIN(CASE WHEN e.event_type = 'click' THEN e.occurred_at END) AS first_click_at,
                MIN(CASE WHEN e.event_type = 'auth' THEN e.occurred_at END) AS auth_at,
                MIN(CASE WHEN e.event_type = 'report' THEN e.occurred_at END) AS report_at,
                MIN(CASE WHEN e.event_type = 'reply' THEN e.occurred_at END) AS reply_at,
                (SELECT COUNT(*) FROM events s WHERE s.tracking_id = ct.tracking_id AND s.campaign_id = ct.campaign_id
                   AND s.tenant_id = c.tenant_id AND (s.verdict = 'scanner' OR s.event_type = 'click_bot')) AS scanner_count,
                " . report_delivery_error_sql() . " AS delivery_error
         FROM campaign_targets ct
         INNER JOIN campaigns c ON c.id = ct.campaign_id
         INNER JOIN targets t ON t.id = ct.target_id
         LEFT JOIN events e ON e.tracking_id = ct.tracking_id AND e.campaign_id = ct.campaign_id
               AND e.tenant_id = c.tenant_id AND e.verdict = 'user'
         WHERE c.tenant_id = ? AND ct.campaign_id = ?
         GROUP BY ct.id
         ORDER BY t.is_test, t.name, ct.content_no",
        [$tenantId, $campaignId]
    );
    return array_map(static fn (array $r): array => [
        'tracking_id' => (string) $r['tracking_id'],
        'content_no' => $r['content_no'] === null ? null : (int) $r['content_no'],
        'name' => (string) ($r['name'] ?? ''),
        'email' => (string) $r['email'],
        'company' => (string) ($r['company'] ?? ''),
        'department' => (string) ($r['department'] ?? ''),
        'is_test' => (int) $r['is_test'],
        'send_status' => (string) $r['send_status'],
        'sent_at' => $r['sent_at'],
        'delivery_state' => $r['delivery_state'],
        'delivery_state_at' => $r['delivery_state_at'],
        'delivery_detail' => $r['delivery_detail'],
        'delivery_error' => (int) $r['delivery_error'] === 1,
        'first_click_at' => $r['first_click_at'],
        'auth_at' => $r['auth_at'],
        'report_at' => $r['report_at'],
        'reply_at' => $r['reply_at'],
        'scanner_count' => (int) $r['scanner_count'],
    ], $rows);
}

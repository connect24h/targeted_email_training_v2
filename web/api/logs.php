<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";

/**
 * P8: ログ管理 API。
 * v2 の DB 正規化ログ(delivery_log / events / send_schedule / audit_log)を
 * テナント別・キャンペーン別に読み取り専用で提供する。
 * 生ログファイル(operation.log, mail.log, apache access.log)は扱わない
 * (マルチテナントのテナント越境リスクを避けるため = v1 と非対称の設計)。
 */

/** limit/offset をクランプして返す。 */
function logs_paging(): array
{
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
    if ($limit < 1) { $limit = 1; }
    if ($limit > 500) { $limit = 500; }
    $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
    if ($offset < 0) { $offset = 0; }
    return [$limit, $offset];
}

/** ?campaign_id= 指定があればテナント所有を検証して返す。無ければ null。 */
function logs_campaign_filter(int $tenantId): ?int
{
    if (!isset($_GET['campaign_id']) || $_GET['campaign_id'] === '') {
        return null;
    }
    $cid = (int) $_GET['campaign_id'];
    if ($cid < 1) {
        json_error('campaign_id が不正です', 400);
    }
    assert_campaign_owned($cid, $tenantId); // IDOR 防止(他テナントは 404)
    return $cid;
}

/** 送信ログ(delivery_log)。campaigns 経由でテナント絞り込み。 */
function logs_handle_delivery(int $tenantId): never
{
    [$limit, $offset] = logs_paging();
    $cid = logs_campaign_filter($tenantId);
    $where = 'c.tenant_id = ?';
    $params = [$tenantId];
    if ($cid !== null) { $where .= ' AND dl.campaign_id = ?'; $params[] = $cid; }
    $rows = Db::all(
        "SELECT dl.id, dl.campaign_id, c.name AS campaign_name, dl.tracking_id,
                dl.to_email, dl.result, dl.smtp_message, dl.occurred_at
         FROM delivery_log dl
         INNER JOIN campaigns c ON c.id = dl.campaign_id
         WHERE {$where}
         ORDER BY dl.id DESC
         LIMIT ? OFFSET ?",
        array_merge($params, [$limit, $offset])
    );
    json_out(['success' => true, 'rows' => $rows, 'limit' => $limit, 'offset' => $offset]);
}

/** 訓練イベント(events)。tenant_id 直持ち。event_type で任意絞り込み。 */
function logs_handle_events(int $tenantId): never
{
    [$limit, $offset] = logs_paging();
    $cid = logs_campaign_filter($tenantId);
    $where = 'e.tenant_id = ?';
    $params = [$tenantId];
    if ($cid !== null) { $where .= ' AND e.campaign_id = ?'; $params[] = $cid; }
    if (isset($_GET['event_type']) && $_GET['event_type'] !== '') {
        $et = (string) $_GET['event_type'];
        if (!in_array($et, ['open', 'click', 'auth', 'form_submit', 'reply', 'report'], true)) {
            json_error('event_type が不正です', 400);
        }
        $where .= ' AND e.event_type = ?'; $params[] = $et;
    }
    $rows = Db::all(
        "SELECT e.id, e.campaign_id, c.name AS campaign_name, e.tracking_id,
                e.event_type, e.auth_variant, e.source, e.occurred_at
         FROM events e
         LEFT JOIN campaigns c ON c.id = e.campaign_id
         WHERE {$where}
         ORDER BY e.id DESC
         LIMIT ? OFFSET ?",
        array_merge($params, [$limit, $offset])
    );
    json_out(['success' => true, 'rows' => $rows, 'limit' => $limit, 'offset' => $offset]);
}

/** 返信者(events の reply)。events ビューの event_type=reply 固定版。 */
function logs_handle_replies(int $tenantId): never
{
    [$limit, $offset] = logs_paging();
    $cid = logs_campaign_filter($tenantId);
    $where = "e.tenant_id = ? AND e.event_type = 'reply'";
    $params = [$tenantId];
    if ($cid !== null) { $where .= ' AND e.campaign_id = ?'; $params[] = $cid; }
    // 返信者は対象者情報(氏名/メール)を突き合わせて出す。tracking_id → campaign_targets → targets。
    $rows = Db::all(
        "SELECT e.id, e.campaign_id, c.name AS campaign_name, e.tracking_id,
                t.email, t.name AS target_name, t.company, e.occurred_at, e.raw
         FROM events e
         LEFT JOIN campaigns c ON c.id = e.campaign_id
         LEFT JOIN campaign_targets ct ON ct.tracking_id = e.tracking_id AND ct.campaign_id = e.campaign_id
         LEFT JOIN targets t ON t.id = ct.target_id
         WHERE {$where}
         ORDER BY e.id DESC
         LIMIT ? OFFSET ?",
        array_merge($params, [$limit, $offset])
    );
    json_out(['success' => true, 'rows' => $rows, 'limit' => $limit, 'offset' => $offset]);
}

/** スケジュールログ(send_schedule)。campaigns 経由でテナント絞り込み。 */
function logs_handle_schedule(int $tenantId): never
{
    [$limit, $offset] = logs_paging();
    $cid = logs_campaign_filter($tenantId);
    $where = 'c.tenant_id = ?';
    $params = [$tenantId];
    if ($cid !== null) { $where .= ' AND ss.campaign_id = ?'; $params[] = $cid; }
    $rows = Db::all(
        "SELECT ss.id, ss.campaign_id, c.name AS campaign_name, ss.batch_no,
                ss.scheduled_at, ss.koban_from, ss.koban_to, ss.interval_sec,
                ss.status, ss.claimed_at, ss.worker_pid, ss.attempts
         FROM send_schedule ss
         INNER JOIN campaigns c ON c.id = ss.campaign_id
         WHERE {$where}
         ORDER BY ss.id DESC
         LIMIT ? OFFSET ?",
        array_merge($params, [$limit, $offset])
    );
    json_out(['success' => true, 'rows' => $rows, 'limit' => $limit, 'offset' => $offset]);
}

/** 操作ログ(audit_log)。tenant_id 直持ち。operator 以上のみ。 */
function logs_handle_audit(int $tenantId): never
{
    [$limit, $offset] = logs_paging();
    $rows = Db::all(
        "SELECT al.id, al.user_id, u.email AS user_email, al.action, al.detail, al.ip, al.occurred_at
         FROM audit_log al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE al.tenant_id = ?
         ORDER BY al.id DESC
         LIMIT ? OFFSET ?",
        [$tenantId, $limit, $offset]
    );
    json_out(['success' => true, 'rows' => $rows, 'limit' => $limit, 'offset' => $offset]);
}

/** バイト数を人間可読(B/KB/MB/GB)に整形する。 */
function logs_format_bytes(int $size): string
{
    if ($size <= 0) { return '0 B'; }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int) floor(log($size, 1024));
    $i = max(0, min($i, count($units) - 1));
    return round($size / (1024 ** $i), 2) . ' ' . $units[$i];
}

/** actionごとの最低権限を返す。全テナント混在ログはsuperadminに限定する。 */
function logs_min_role_for_action(string $action): string
{
    $superadminActions = [
        'raw_mail',
        'raw_web',
        'reply_maildir',
        'reply_maildir_view',
        'reply_maildir_csv',
        'webaccess',
        'webaccess_geoip',
        'webaccess_csv',
    ];
    if (in_array($action, $superadminActions, true)) {
        return 'superadmin';
    }
    return $action === 'audit' ? 'operator' : 'viewer';
}

/**
 * ログファイルの末尾を読み、キーワードで絞って行配列を返す。
 * 大きいログでもメモリを食わないよう、末尾から一定バイトだけ読む。
 * 最新5000行を賄うため末尾最大 16MB まで読む(1行平均が大きくても足りるよう余裕を取る)。
 */
function logs_read_tail(string $path, int $maxLines, string $keyword): array
{
    if (!is_file($path) || !is_readable($path)) {
        return ['error' => 'ログが読めません（存在しないか権限不足）', 'lines' => []];
    }
    $size = filesize($path);
    $readBytes = min($size, 16 * 1024 * 1024); // 末尾 16MB まで
    $fp = fopen($path, 'r');
    if ($fp === false) {
        return ['error' => 'ログを開けません', 'lines' => []];
    }
    if ($readBytes < $size) {
        fseek($fp, $size - $readBytes);
        fgets($fp); // 途中行を捨てる
    }
    $lines = [];
    while (($line = fgets($fp)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            continue;
        }
        if ($keyword !== '' && stripos($line, $keyword) === false) {
            continue;
        }
        $lines[] = $line;
    }
    fclose($fp);
    // 末尾 maxLines 行だけ返す。
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, -$maxLines);
    }
    return ['error' => null, 'lines' => $lines];
}

/**
 * 生メールログ / 生Webログ(全テナント混在) → superadmin 限定。
 * action=raw_mail/raw_web: 最新 limit 行(既定5000, 上限5000)表示 + ファイルサイズ情報。
 * download=1 で全件ダウンロード(readfile ストリーミング)。
 */
function logs_handle_raw(string $which): never
{
    $path = $which === 'mail' ? '/var/log/mail.log' : '/var/log/apache2/access.log';

    // 全件ダウンロード。
    if (isset($_GET['download']) && $_GET['download'] === '1') {
        if (!is_file($path) || !is_readable($path)) {
            json_error('ログが読めません（存在しないか権限不足）', 404);
        }
        audit('logs.raw_' . $which . '.download', 'size=' . filesize($path));
        http_response_code(200);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . ($which === 'mail' ? 'mail.log' : 'access.log') . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    $maxLines = isset($_GET['limit']) ? max(1, min(5000, (int) $_GET['limit'])) : 5000;
    $keyword = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
    $res = logs_read_tail($path, $maxLines, $keyword);
    $fileInfo = (is_file($path) && is_readable($path))
        ? ['size' => filesize($path), 'size_human' => logs_format_bytes((int) filesize($path)),
           'modified' => date('Y-m-d H:i:s', filemtime($path) ?: time())]
        : null;
    audit('logs.raw_' . $which, 'lines=' . count($res['lines']) . ($keyword !== '' ? ',q=' . $keyword : ''));
    json_out(['success' => true, 'source' => $path, 'lines' => $res['lines'],
              'file_info' => $fileInfo, 'error' => $res['error']]);
}

/**
 * 訓練結果ログ: 対象者別に 送信/開封/クリック/認証 状況を一覧する(目視確認用)。
 * campaign_id で絞り込み可。フィルタ(status: opened/clicked/authed/none)。
 */
function logs_handle_training_results(int $tenantId): never
{
    $cid = logs_campaign_filter($tenantId);
    $where = 'c.tenant_id = ?';
    $params = [$tenantId];
    if ($cid !== null) { $where .= ' AND ct.campaign_id = ?'; $params[] = $cid; }
    $rows = Db::all(
        "SELECT ct.campaign_id, c.name AS campaign_name, ct.koban,
                t.email, t.name AS target_name, t.company, t.department, t.position_category,
                ct.send_status, ct.sent_at,
                MAX(CASE WHEN e.event_type='open'  THEN 1 ELSE 0 END) AS opened,
                MAX(CASE WHEN e.event_type='click' THEN 1 ELSE 0 END) AS clicked,
                MAX(CASE WHEN e.event_type='auth'  THEN 1 ELSE 0 END) AS authed,
                MIN(CASE WHEN e.event_type='open'  THEN e.occurred_at END) AS opened_at,
                MIN(CASE WHEN e.event_type='auth'  THEN e.occurred_at END) AS authed_at
         FROM campaign_targets ct
         INNER JOIN campaigns c ON c.id = ct.campaign_id
         INNER JOIN targets t   ON t.id = ct.target_id
         LEFT JOIN events e
                ON e.tracking_id = ct.tracking_id AND e.campaign_id = ct.campaign_id
               AND e.tenant_id = ? AND e.event_type IN ('open','click','auth')
         WHERE {$where}
         GROUP BY ct.id
         ORDER BY ct.campaign_id DESC, ct.koban",
        array_merge([$tenantId], $params)
    );
    // status フィルタ(サーバ側で絞る)。
    $status = isset($_GET['status']) ? (string) $_GET['status'] : '';
    if (in_array($status, ['opened', 'clicked', 'authed', 'none'], true)) {
        $rows = array_values(array_filter($rows, function ($r) use ($status) {
            return match ($status) {
                'opened'  => (int) $r['opened'] === 1,
                'clicked' => (int) $r['clicked'] === 1,
                'authed'  => (int) $r['authed'] === 1,
                'none'    => (int) $r['opened'] === 0 && (int) $r['clicked'] === 0 && (int) $r['authed'] === 0,
                default   => true,
            };
        }));
    }
    json_out(['success' => true, 'rows' => $rows, 'count' => count($rows)]);
}

/** 訓練結果ログを CSV でダウンロード(目視一覧の保存用)。 */
function logs_handle_training_results_csv(int $tenantId): never
{
    // training_results と同じ集計を CSV で。JSON でなく CSV を直接返すため、集計を再利用。
    $cid = logs_campaign_filter($tenantId);
    $where = 'c.tenant_id = ?';
    $params = [$tenantId];
    if ($cid !== null) { $where .= ' AND ct.campaign_id = ?'; $params[] = $cid; }
    $rows = Db::all(
        "SELECT ct.campaign_id, c.name AS campaign_name, ct.koban,
                t.email, t.name AS target_name, t.company, t.department, t.position_category,
                ct.send_status,
                MAX(CASE WHEN e.event_type='open'  THEN 1 ELSE 0 END) AS opened,
                MAX(CASE WHEN e.event_type='click' THEN 1 ELSE 0 END) AS clicked,
                MAX(CASE WHEN e.event_type='auth'  THEN 1 ELSE 0 END) AS authed
         FROM campaign_targets ct
         INNER JOIN campaigns c ON c.id = ct.campaign_id
         INNER JOIN targets t   ON t.id = ct.target_id
         LEFT JOIN events e ON e.tracking_id = ct.tracking_id AND e.campaign_id = ct.campaign_id
               AND e.tenant_id = ? AND e.event_type IN ('open','click','auth')
         WHERE {$where}
         GROUP BY ct.id
         ORDER BY ct.campaign_id DESC, ct.koban",
        array_merge([$tenantId], $params)
    );
    $out = fopen('php://temp', 'r+');
    fputcsv($out, ['キャンペーン', '項番', 'メール', '氏名', '会社', '部署', '役職カテゴリ', '送信', '開封', 'クリック', '認証']);
    foreach ($rows as $r) {
        // CSVインジェクション対策: ユーザ由来のテキスト列を tet2_csv_sanitize() で無害化。
        fputcsv($out, [
            tet2_csv_sanitize($r['campaign_name']), (string) $r['koban'], tet2_csv_sanitize($r['email']),
            tet2_csv_sanitize($r['target_name'] ?? ''), tet2_csv_sanitize($r['company'] ?? ''), tet2_csv_sanitize($r['department'] ?? ''),
            tet2_csv_sanitize($r['position_category'] ?? ''),
            $r['send_status'] === 'sent' ? '済' : tet2_csv_sanitize($r['send_status']),
            (int) $r['opened'] ? '○' : '', (int) $r['clicked'] ? '○' : '', (int) $r['authed'] ? '○' : '',
        ]);
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    audit('logs.training_results_csv', 'count=' . count($rows));
    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="training_results_' . date('Ymd_His') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";
    echo $csv;
    exit;
}

/* ============================================================
 * 訓練結果ログ(メール一覧) — v1 training_logs.php 相当。
 * events.raw(v1 training_log と同一形式: "Random: ... | Type: ... | Email: ... |
 * Password: ... | IP: ... | Country: ... | ... | UserAgent: ...")をパースし、
 * click(link_click)/auth の1行=1アクセスとして、乱数→対象者情報を結合した
 * 21カラムの明細を返す。キャンペーン別に厳密にフィルタする。
 * ============================================================ */

/** events.raw(パイプ区切りフィールド)を連想配列にパースする。 */
function training_log_parse_raw(string $raw): array
{
    $out = ['email' => '', 'password' => '', 'ip' => '', 'country' => '', 'location' => '',
            'isp' => '', 'org' => '', 'as' => '', 'hostname' => '', 'useragent' => ''];
    $map = ['Email' => 'email', 'Password' => 'password', 'IP' => 'ip', 'Country' => 'country',
            'Location' => 'location', 'ISP' => 'isp', 'Org' => 'org', 'AS' => 'as',
            'Hostname' => 'hostname', 'UserAgent' => 'useragent'];
    foreach ($map as $label => $key) {
        if ($key === 'useragent') {
            if (preg_match('/UserAgent:\s*(.+)$/s', $raw, $m)) { $out['useragent'] = trim($m[1]); }
        } elseif (preg_match('/' . $label . ':\s*([^|]*)/', $raw, $m)) {
            $out[$key] = trim($m[1]);
        }
    }
    return $out;
}

/**
 * 訓練結果ログの明細行を組み立てる(list/export 共通)。
 * events(click/auth) × campaign_targets × targets を結合し、raw をパースして
 * v1 の21カラム相当の配列にする。キャンペーン別・期間・タイプでフィルタ。
 */
function training_log_detail_rows(int $tenantId): array
{
    $cid = logs_campaign_filter($tenantId);
    $startDate = (isset($_GET['start_date']) && $_GET['start_date'] !== '') ? (string) $_GET['start_date'] : '';
    $endDate   = (isset($_GET['end_date'])   && $_GET['end_date']   !== '') ? (string) $_GET['end_date']   : '';
    $typeFilter = isset($_GET['type']) ? (string) $_GET['type'] : '';

    $where = "e.tenant_id = ? AND e.event_type IN ('click','auth')";
    $params = [$tenantId];
    if ($cid !== null) { $where .= ' AND e.campaign_id = ?'; $params[] = $cid; }
    if ($startDate !== '') { $where .= ' AND e.occurred_at >= ?'; $params[] = $startDate; }
    if ($endDate !== '')   { $where .= ' AND e.occurred_at <= ?'; $params[] = $endDate; }

    $events = Db::all(
        "SELECT e.campaign_id, c.name AS campaign_name, e.tracking_id, e.event_type,
                e.auth_variant, e.occurred_at, e.raw,
                t.email AS recipient_email, t.name AS fullname,
                t.company, t.title AS position, t.position_category
         FROM events e
         LEFT JOIN campaigns c ON c.id = e.campaign_id
         LEFT JOIN campaign_targets ct ON ct.tracking_id = e.tracking_id AND ct.campaign_id = e.campaign_id
         LEFT JOIN targets t ON t.id = ct.target_id
         WHERE {$where}
         ORDER BY e.occurred_at DESC, e.id DESC",
        $params
    );

    $rows = [];
    foreach ($events as $e) {
        $parsed = training_log_parse_raw((string) ($e['raw'] ?? ''));
        $type = $e['event_type'] === 'click' ? 'link_click' : (string) ($e['auth_variant'] ?? 'auth');
        $rows[] = [
            'timestamp' => (string) $e['occurred_at'],
            'random' => (string) $e['tracking_id'],
            'campaign_id' => (int) $e['campaign_id'],
            'campaign_name' => (string) ($e['campaign_name'] ?? ''),
            'type' => $type,
            'recipient_email' => (string) ($e['recipient_email'] ?? ''),
            'fullname' => (string) ($e['fullname'] ?? ''),
            // v2 targets に会社メール/略称の専用列はない。会社メール=対象者メール(会社アドレス)を流用、略称は空。
            'company_email' => (string) ($e['recipient_email'] ?? ''),
            'company' => (string) ($e['company'] ?? ''),
            'abbreviation' => '',
            'position' => (string) ($e['position'] ?? ''),
            'position_category' => (string) ($e['position_category'] ?? ''),
            'email' => $parsed['email'],
            'password' => $parsed['password'],
            'ip' => $parsed['ip'],
            'country' => $parsed['country'],
            'location' => $parsed['location'],
            'isp' => $parsed['isp'],
            'org' => $parsed['org'],
            'as' => $parsed['as'],
            'hostname' => $parsed['hostname'],
            'useragent' => $parsed['useragent'],
        ];
    }

    if ($typeFilter !== '') {
        $rows = array_values(array_filter($rows, fn ($r) => $r['type'] === $typeFilter));
    }

    // 乱数重複フラグ(同一乱数が複数行 = 複数回アクセス)。
    $counts = [];
    foreach ($rows as $r) {
        $rand = $r['random'];
        if ($rand !== '') { $counts[$rand] = ($counts[$rand] ?? 0) + 1; }
    }
    foreach ($rows as &$r) {
        $rand = $r['random'];
        $r['duplicate_count'] = ($rand !== '' && isset($counts[$rand])) ? $counts[$rand] : 0;
        $r['duplicate'] = $r['duplicate_count'] > 1;
    }
    unset($r);

    return $rows;
}

/** 訓練結果ログ(メール一覧)を JSON で返す。キャンペーン別フィルタ。 */
function logs_handle_training_log_detail(int $tenantId): never
{
    $rows = training_log_detail_rows($tenantId);
    $byType = [];
    foreach ($rows as $r) { $byType[$r['type']] = ($byType[$r['type']] ?? 0) + 1; }
    audit('logs.training_log_detail', 'rows=' . count($rows));
    json_out(['success' => true, 'rows' => $rows, 'count' => count($rows), 'by_type' => $byType]);
}

/** 訓練結果ログ(メール一覧)を CSV でダウンロード(v1 と同じ21カラム)。 */
function logs_handle_training_log_detail_csv(int $tenantId): never
{
    $rows = training_log_detail_rows($tenantId);
    $out = fopen('php://temp', 'r+');
    fputcsv($out, ['日時', '乱数', '重複', 'タイプ', '送信先メールアドレス', '表示氏名（姓名）',
                   'メールアドレス（会社）', '会社名', '略称', '本務役職名称', '役職カテゴリ',
                   '入力Email', 'Password/ID', 'IP', '国', '場所', 'ISP', '組織', 'AS', 'ホスト名', 'UserAgent']);
    foreach ($rows as $r) {
        // CSVインジェクション対策: 入力Email/Password/UserAgent 等の攻撃者由来値を含むため全列を無害化。
        fputcsv($out, [
            tet2_csv_sanitize($r['timestamp']), tet2_csv_sanitize($r['random']), ($r['duplicate'] ? $r['duplicate_count'] . '回' : ''),
            tet2_csv_sanitize($r['type']), tet2_csv_sanitize($r['recipient_email']), tet2_csv_sanitize($r['fullname']), tet2_csv_sanitize($r['company_email']), tet2_csv_sanitize($r['company']),
            tet2_csv_sanitize($r['abbreviation']), tet2_csv_sanitize($r['position']), tet2_csv_sanitize($r['position_category']), tet2_csv_sanitize($r['email']), tet2_csv_sanitize($r['password']),
            tet2_csv_sanitize($r['ip']), tet2_csv_sanitize($r['country']), tet2_csv_sanitize($r['location']), tet2_csv_sanitize($r['isp']), tet2_csv_sanitize($r['org']), tet2_csv_sanitize($r['as']), tet2_csv_sanitize($r['hostname']), tet2_csv_sanitize($r['useragent']),
        ]);
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    audit('logs.training_log_detail_csv', 'rows=' . count($rows));
    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="training_logs_' . date('Ymd_His') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";
    echo $csv;
    exit;
}

/* ============================================================
 * キャンペーン別 リンク/ビーコン ファイル一覧。
 * キャンペーン作成時、対象者ごとに tracking_id が振られ、リンク型なら
 *   {beacon_base}/link-{tracking_id}.html
 * 添付/開封追跡なら
 *   {beacon_base}/kunren-beacon-{tracking_id}.png
 * が生成される。この「対象者 × 生成ファイル」の全組み合わせを一覧/CSV で返す。
 * 用途: curl で外部から各 URL を叩き、ファイルの存在(疎通)を確認する。
 * beacon_base はキャンペーン別(P6)→config.ini→既定IP の順で解決する
 * (PipelineRunner::beaconUrlBase と同一ロジックを再利用)。
 * ============================================================ */

/**
 * キャンペーン別に「対象者 × (link HTML / beacon PNG)」の行を組み立てる。
 * link_mode に依らず両ファイル名パターンを出す(curl 存在チェックが用途のため、
 * 実際に生成された方だけが 200 を返す)。beacon_base はコンテンツ別 base があれば優先。
 * @return array{campaign_id:int,campaign_name:string,rows:array<int,array<string,string>>,beacon_base:string}
 */
function campaign_files_rows(int $tenantId): array
{
    require_once __DIR__ . '/../lib/PipelineRunner.php';

    $cid = logs_campaign_filter($tenantId);
    if ($cid === null) {
        json_error('キャンペーンを指定してください', 400);
    }
    // assert_campaign_owned は logs_campaign_filter 内で実施済み(他テナントは 404)。
    $c = Db::one('SELECT id, name, beacon_base FROM campaigns WHERE id = ?', [$cid]);
    if ($c === null) {
        json_error('キャンペーンが見つかりません', 404);
    }

    // content_no 別の beacon_base(P6 コンテンツ別 base)を先読み。
    $contentBase = [];
    foreach (Db::all('SELECT content_no, beacon_base FROM campaign_contents WHERE campaign_id = ?', [$cid]) as $cc) {
        $contentBase[(int) $cc['content_no']] = $cc['beacon_base'] ?? null;
    }

    $campaignBase = PipelineRunner::beaconUrlBase(['beacon_base' => $c['beacon_base'] ?? null]);

    $targets = Db::all(
        'SELECT ct.tracking_id, ct.koban, ct.content_no, ct.send_status,
                t.email AS to_email, t.name AS to_name, t.company
         FROM campaign_targets ct
         JOIN targets t ON t.id = ct.target_id
         WHERE ct.campaign_id = ?
         ORDER BY ct.koban, ct.id',
        [$cid]
    );

    $rows = [];
    foreach ($targets as $t) {
        $tid = (string) $t['tracking_id'];
        if ($tid === '') { continue; }
        // 対象者に割り当てられた content の base を優先、無ければキャンペーン base。
        $cno = isset($t['content_no']) ? (int) $t['content_no'] : 0;
        $base = ($cno > 0 && !empty($contentBase[$cno]))
            ? PipelineRunner::beaconUrlBase(['beacon_base' => $contentBase[$cno]])
            : $campaignBase;
        $baseNoSlash = rtrim($base, '/');
        $linkFile   = 'link-' . $tid . '.html';
        $beaconFile = 'kunren-beacon-' . $tid . '.png';
        $rows[] = [
            'koban'          => (string) ($t['koban'] ?? ''),
            'tracking_id'    => $tid,
            'recipient_email' => (string) ($t['to_email'] ?? ''),
            'fullname'       => (string) ($t['to_name'] ?? ''),
            'company'        => (string) ($t['company'] ?? ''),
            'send_status'    => (string) ($t['send_status'] ?? ''),
            'link_file'      => $linkFile,
            'link_url'       => $baseNoSlash . '/' . $linkFile,
            'beacon_file'    => $beaconFile,
            'beacon_url'     => $baseNoSlash . '/' . $beaconFile,
        ];
    }

    return [
        'campaign_id'   => (int) $c['id'],
        'campaign_name' => (string) $c['name'],
        'beacon_base'   => $campaignBase,
        'rows'          => $rows,
    ];
}

/** キャンペーン別 リンク/ビーコン ファイル一覧を JSON で返す。 */
function logs_handle_campaign_files(int $tenantId): never
{
    $data = campaign_files_rows($tenantId);
    audit('logs.campaign_files', 'campaign=' . $data['campaign_id'] . ',rows=' . count($data['rows']));
    json_out([
        'success'       => true,
        'campaign_id'   => $data['campaign_id'],
        'campaign_name' => $data['campaign_name'],
        'beacon_base'   => $data['beacon_base'],
        'rows'          => $data['rows'],
        'count'         => count($data['rows']),
    ]);
}

/** キャンペーン別 リンク/ビーコン ファイル一覧を CSV でダウンロード(curl 存在チェック用)。 */
function logs_handle_campaign_files_csv(int $tenantId): never
{
    $data = campaign_files_rows($tenantId);
    $out = fopen('php://temp', 'r+');
    fputcsv($out, ['項番', '乱数(tracking_id)', '送信先メールアドレス', '表示氏名', '会社名', '送信状況',
                   'リンクHTMLファイル名', 'リンクHTML URL', 'ビーコン画像ファイル名', 'ビーコン画像 URL']);
    foreach ($data['rows'] as $r) {
        // CSVインジェクション対策: メール/氏名/会社名等のユーザ由来列を無害化。
        fputcsv($out, [
            (string) $r['koban'], tet2_csv_sanitize($r['tracking_id']), tet2_csv_sanitize($r['recipient_email']), tet2_csv_sanitize($r['fullname']), tet2_csv_sanitize($r['company']), tet2_csv_sanitize($r['send_status']),
            tet2_csv_sanitize($r['link_file']), tet2_csv_sanitize($r['link_url']), tet2_csv_sanitize($r['beacon_file']), tet2_csv_sanitize($r['beacon_url']),
        ]);
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    audit('logs.campaign_files_csv', 'campaign=' . $data['campaign_id'] . ',rows=' . count($data['rows']));
    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="campaign_' . $data['campaign_id'] . '_files_' . date('Ymd_His') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";
    echo $csv;
    exit;
}

/* ============================================================
 * 返信者(Maildir パース) — v1 mail_replies.php 相当。
 * 訓練メール送信元アカウントの Maildir を直接読み、受信メール(=返信含む)を
 * 一覧・本文表示する。DB 正規化ログではなく実ファイル参照のため superadmin 限定。
 * メールサーバは参照のみ(書き込み・設定変更は一切しない)。
 * ============================================================ */

/** /etc/postfix/virtual_mailbox_maps を読み、user => email のマップを返す。 */
function mailbox_email_mapping(): array
{
    $mapping = [];
    $vmapFile = '/etc/postfix/virtual_mailbox_maps';
    if (is_file($vmapFile) && is_readable($vmapFile)) {
        $lines = file($vmapFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') { continue; }
            $parts = preg_split('/\s+/', $line, 2);
            if (count($parts) >= 2) {
                $email = $parts[0];
                $user = str_replace('/Maildir', '', rtrim($parts[1], '/'));
                $mapping[$user] = $email;
            }
        }
    }
    return $mapping;
}

/** /home 配下および /root の Maildir を検出し、[user => ['maildir','email']] を返す。 */
function discover_maildirs(): array
{
    $emailMapping = mailbox_email_mapping();
    $maildirs = [];
    foreach (glob('/home/*/Maildir', GLOB_ONLYDIR) ?: [] as $dir) {
        $user = basename(dirname($dir));
        // 読めないディレクトリはスキップ(mcp 等)。
        if (!is_readable($dir . '/new') && !is_readable($dir . '/cur')) { continue; }
        $email = $emailMapping[$user] ?? ($user . '@cojp.online');
        $maildirs[$user] = ['maildir' => $dir, 'email' => $email];
    }
    if (is_dir('/root/Maildir') && (is_readable('/root/Maildir/new') || is_readable('/root/Maildir/cur'))) {
        $maildirs['root'] = ['maildir' => '/root/Maildir', 'email' => 'root@cojp.online'];
    }
    ksort($maildirs);
    return $maildirs;
}

/** MIME エンコードされたヘッダー文字列(=?charset?B/Q?...?=)を UTF-8 にデコードする。 */
function decode_mime_header(string $string): string
{
    $decoded = preg_replace_callback('/=\?([^?]+)\?([BQbq])\?([^?]*)\?=/', function ($m) {
        $charset = $m[1];
        $encoding = strtoupper($m[2]);
        $text = $m[3];
        if ($encoding === 'B') {
            $decodedText = base64_decode($text);
        } elseif ($encoding === 'Q') {
            $decodedText = quoted_printable_decode(str_replace('_', ' ', $text));
        } else {
            $decodedText = $text;
        }
        if ($decodedText !== false && strtoupper($charset) !== 'UTF-8') {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $decodedText);
            if ($converted !== false) { return $converted; }
        }
        return $decodedText !== false ? $decodedText : $text;
    }, $string);
    return $decoded !== null ? $decoded : $string;
}

/** メールファイルからヘッダー(date/from/to/subject/添付有無)を解析する。 */
function maildir_parse_headers(string $filepath): ?array
{
    $content = @file_get_contents($filepath, false, null, 0, 256 * 1024); // 先頭 256KB でヘッダーは十分
    if ($content === false) { return null; }

    $headerEnd = strpos($content, "\r\n\r\n");
    if ($headerEnd === false) { $headerEnd = strpos($content, "\n\n"); }
    if ($headerEnd === false) { return null; }
    $headerSection = substr($content, 0, $headerEnd);

    $headers = [];
    $curName = '';
    $curVal = '';
    foreach (preg_split('/\r?\n/', $headerSection) as $line) {
        if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $mm)) {
            if ($curName !== '') { $headers[strtolower($curName)] = trim($curVal); }
            $curName = $mm[1];
            $curVal = $mm[2];
        } elseif (preg_match('/^\s+(.*)$/', $line, $mm)) {
            $curVal .= ' ' . trim($mm[1]);
        }
    }
    if ($curName !== '') { $headers[strtolower($curName)] = trim($curVal); }

    $res = [
        'date' => '', 'from' => '', 'from_email' => '', 'to' => '',
        'subject' => '', 'has_attachment' => false, 'filename' => basename($filepath),
        'unreadable' => false,
    ];
    if (isset($headers['date'])) {
        $ts = strtotime($headers['date']);
        $res['date'] = $ts !== false ? date('Y-m-d H:i:s', $ts) : $headers['date'];
    }
    if (isset($headers['from'])) {
        $from = decode_mime_header($headers['from']);
        $res['from'] = $from;
        if (preg_match('/<([^>]+)>/', $from, $mm)) {
            $res['from_email'] = $mm[1];
        } elseif (preg_match('/([^\s<]+@[^\s>]+)/', $from, $mm)) {
            $res['from_email'] = $mm[1];
        }
    }
    if (isset($headers['to']))      { $res['to'] = decode_mime_header($headers['to']); }
    if (isset($headers['subject'])) { $res['subject'] = decode_mime_header($headers['subject']); }
    if (isset($headers['content-type']) && stripos($headers['content-type'], 'multipart/mixed') !== false) {
        $res['has_attachment'] = true;
    }
    if (isset($headers['x-ms-has-attach']) && strtolower(trim($headers['x-ms-has-attach'])) === 'yes') {
        $res['has_attachment'] = true;
    }
    return $res;
}

/** Maildir の new/cur からメール一覧を取得する。
 *
 * 読めないファイルを黙って捨てない。Postfix virtual(8) は配送時に 0600 で
 * ファイルを作るため、www-data から読めないメールが混ざりうる。以前これを
 * スキップしていたせいで「サーバには届いているのに一覧に出ない」状態になり、
 * 返信の見落としに直結した。読めない場合はプレースホルダ行として残し、
 * 画面側で権限エラーと分かるようにする。
 */
function maildir_list(string $maildirPath): array
{
    $mails = [];
    foreach (['new', 'cur'] as $dir) {
        $path = $maildirPath . '/' . $dir;
        if (!is_dir($path)) { continue; }
        $files = @scandir($path);
        if ($files === false) { continue; }
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') { continue; }
            $filepath = $path . '/' . $file;
            if (!is_file($filepath)) { continue; }
            $mail = maildir_parse_headers($filepath);
            if ($mail === null) {
                $mail = maildir_unreadable_entry($filepath);
            }
            $mail['status'] = $dir;
            $mails[] = $mail;
        }
    }
    return $mails;
}

/** 読み取れなかったメールを一覧に残すためのプレースホルダを作る。
 *
 * 日時はファイルの更新時刻で代用する (ヘッダーが読めないため)。
 */
function maildir_unreadable_entry(string $filepath): array
{
    $mtime = @filemtime($filepath);
    $perm = @fileperms($filepath);
    $mode = $perm !== false ? substr(sprintf('%o', $perm), -4) : '????';
    return [
        // 一覧のソートは date の文字列比較なので、正常系と同じ書式に揃える。
        'date' => $mtime !== false ? date('Y-m-d H:i:s', $mtime) : '',
        'from' => '(読み取り不可)',
        'from_email' => '',
        'to' => '',
        'subject' => '(権限不足でヘッダーを読めません: mode ' . $mode . ')',
        'has_attachment' => false,
        'filename' => basename($filepath),
        'unreadable' => true,
    ];
}

/**
 * 返信者一覧を計算する(list/csv 共通)。$_GET の sender/start_date/end_date/q でフィルタ。
 * 戻り値: ['mails' => [...], 'counts' => [...], 'accounts' => [...]]。
 */
function reply_maildir_compute(): array
{
    $maildirs = discover_maildirs();
    $sender = isset($_GET['sender']) ? (string) $_GET['sender'] : '';
    $startTs = (isset($_GET['start_date']) && $_GET['start_date'] !== '') ? strtotime((string) $_GET['start_date']) : null;
    $endTs   = (isset($_GET['end_date'])   && $_GET['end_date']   !== '') ? strtotime((string) $_GET['end_date'])   : null;
    $keyword = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';

    $allMails = [];
    $counts = [];
    foreach ($maildirs as $key => $config) {
        $counts[$key] = 0;
        if ($sender !== '' && $sender !== $key) { continue; }
        $mails = maildir_list($config['maildir']);
        foreach ($mails as &$mail) {
            $mail['sender_account'] = $key;
            $mail['sender_email'] = $config['email'];
        }
        unset($mail);
        if ($startTs !== null || $endTs !== null) {
            $mails = array_values(array_filter($mails, function ($mail) use ($startTs, $endTs) {
                $mt = strtotime((string) $mail['date']);
                if ($mt === false) { return false; }
                if ($startTs !== null && $mt < $startTs) { return false; }
                if ($endTs !== null && $mt > $endTs) { return false; }
                return true;
            }));
        }
        if ($keyword !== '') {
            $mails = array_values(array_filter($mails, function ($mail) use ($keyword) {
                return stripos((string) $mail['from'], $keyword) !== false
                    || stripos((string) $mail['subject'], $keyword) !== false
                    || stripos((string) $mail['from_email'], $keyword) !== false;
            }));
        }
        $counts[$key] = count($mails);
        $allMails = array_merge($allMails, $mails);
    }
    usort($allMails, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));

    $accounts = [];
    foreach ($maildirs as $key => $config) { $accounts[$key] = $config['email']; }

    return ['mails' => $allMails, 'counts' => $counts, 'accounts' => $accounts];
}

/** 返信者一覧: 全(または指定)送信元アカウントの Maildir を横断して一覧する。 */
function logs_handle_reply_maildir(): never
{
    $r = reply_maildir_compute();
    audit('logs.reply_maildir', 'total=' . count($r['mails']));
    json_out(['success' => true, 'rows' => $r['mails'], 'counts' => $r['counts'],
              'total' => count($r['mails']), 'accounts' => $r['accounts']]);
}

/** 返信者一覧を CSV でダウンロードする。 */
function logs_handle_reply_maildir_csv(): never
{
    $r = reply_maildir_compute();
    $out = fopen('php://temp', 'r+');
    fputcsv($out, ['受信日時', '送信元アカウント', '送信元メール', '差出人', '差出人アドレス', '宛先', '件名', '添付']);
    foreach ($r['mails'] as $m) {
        fputcsv($out, [
            (string) ($m['date'] ?? ''), (string) ($m['sender_account'] ?? ''), (string) ($m['sender_email'] ?? ''),
            (string) ($m['from'] ?? ''), (string) ($m['from_email'] ?? ''), (string) ($m['to'] ?? ''),
            (string) ($m['subject'] ?? ''), !empty($m['has_attachment']) ? '有' : '',
        ]);
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    audit('logs.reply_maildir_csv', 'total=' . count($r['mails']));
    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mail_replies_' . date('Ymd_His') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";
    echo $csv;
    exit;
}

/** multipart から text/plain または text/html パートを再帰的に抽出する。 */
function maildir_find_text_parts(string $body, string $boundary): array
{
    $extract = function (string $partContent): ?array {
        $he = strpos($partContent, "\r\n\r\n");
        $sep = 4;
        if ($he === false) { $he = strpos($partContent, "\n\n"); $sep = 2; }
        if ($he === false) { return null; }
        $ph = substr($partContent, 0, $he);
        $pb = substr($partContent, $he + $sep);
        $ct = '';
        $full = '';
        $cs = '';
        $bd = '';
        $enc = '';
        if (preg_match('/Content-Type:\s*(.+?)(?:\r?\n(?!\s)|\r?\n$)/ims', $ph, $m)) { $full = trim($m[1]); }
        if (preg_match('/Content-Type:\s*([^;\r\n]+)/i', $ph, $m)) { $ct = strtolower(trim($m[1])); }
        if (preg_match('/charset="?([^";\s]+)"?/i', $full ?: $ph, $m)) { $cs = strtoupper($m[1]); }
        if (preg_match('/boundary="?([^";\s]+)"?/i', $full ?: $ph, $m)) { $bd = $m[1]; }
        if (preg_match('/Content-Transfer-Encoding:\s*(\S+)/i', $ph, $m)) { $enc = strtolower(trim($m[1])); }
        return ['content_type' => $ct, 'charset' => $cs, 'encoding' => $enc, 'boundary' => $bd, 'body' => $pb];
    };
    $textPart = null;
    $htmlPart = null;
    foreach (explode('--' . $boundary, $body) as $part) {
        $part = trim($part);
        if ($part === '' || $part === '--') { continue; }
        $ex = $extract($part);
        if ($ex === null) { continue; }
        if (strpos($ex['content_type'], 'multipart/') !== false && $ex['boundary'] !== '') {
            [$nt, $nh] = maildir_find_text_parts($ex['body'], $ex['boundary']);
            if ($nt !== null && $textPart === null) { $textPart = $nt; }
            if ($nh !== null && $htmlPart === null) { $htmlPart = $nh; }
        } elseif (strpos($ex['content_type'], 'text/plain') !== false && $textPart === null) {
            $textPart = $ex;
        } elseif (strpos($ex['content_type'], 'text/html') !== false && $htmlPart === null) {
            $htmlPart = $ex;
        }
    }
    return [$textPart, $htmlPart];
}

/** 返信者本文表示: 指定アカウント/ファイルのメール本文を UTF-8 プレーンテキストで返す。 */
function logs_handle_reply_maildir_view(): never
{
    $maildirs = discover_maildirs();
    $account = isset($_GET['account']) ? (string) $_GET['account'] : '';
    $filename = isset($_GET['filename']) ? (string) $_GET['filename'] : '';
    if (!isset($maildirs[$account])) {
        json_error('無効なアカウント', 400);
    }
    // ディレクトリトラバーサル防止。
    if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || $filename === '') {
        json_error('無効なファイル名', 400);
    }
    $maildir = $maildirs[$account]['maildir'];
    $filepath = null;
    foreach (['new', 'cur'] as $dir) {
        $test = $maildir . '/' . $dir . '/' . $filename;
        if (is_file($test)) { $filepath = $test; break; }
    }
    if ($filepath === null) {
        json_error('メールが見つかりません', 404);
    }
    $content = @file_get_contents($filepath);
    if ($content === false) {
        json_error('メールを読めません', 500);
    }

    $he = strpos($content, "\r\n\r\n");
    $sep = 4;
    if ($he === false) { $he = strpos($content, "\n\n"); $sep = 2; }
    $headerSection = $he !== false ? substr($content, 0, $he) : '';
    $rawBody = $he !== false ? substr($content, $he + $sep) : $content;

    $contentType = '';
    $transferEncoding = '';
    $charset = '';
    $boundary = '';
    if (preg_match('/^Content-Type:\s*(.+?)(?:\r?\n(?!\s)|\r?\n$)/ims', $headerSection, $m)) { $contentType = trim($m[1]); }
    if (preg_match('/^Content-Transfer-Encoding:\s*(\S+)/im', $headerSection, $m)) { $transferEncoding = strtolower(trim($m[1])); }
    if (preg_match('/charset="?([^";\s]+)"?/i', $contentType, $m)) { $charset = strtoupper($m[1]); }
    if (preg_match('/boundary="?([^";\s]+)"?/i', $contentType, $m)) { $boundary = $m[1]; }

    $bodyToProcess = $rawBody;
    $bodyCharset = $charset;
    $bodyEncoding = $transferEncoding;
    $isHtmlOnly = false;
    if ($boundary !== '') {
        [$textPart, $htmlPart] = maildir_find_text_parts($rawBody, $boundary);
        $chosen = $textPart ?: $htmlPart;
        if ($chosen) {
            $bodyToProcess = $chosen['body'];
            $bodyCharset = $chosen['charset'] ?: $bodyCharset;
            $bodyEncoding = $chosen['encoding'] ?: $bodyEncoding;
            $isHtmlOnly = ($textPart === null && $htmlPart !== null);
        }
    }

    if ($bodyEncoding === 'quoted-printable') {
        $bodyToProcess = quoted_printable_decode($bodyToProcess);
    } elseif ($bodyEncoding === 'base64') {
        $decoded = base64_decode($bodyToProcess);
        if ($decoded !== false) { $bodyToProcess = $decoded; }
    }
    if ($isHtmlOnly) {
        $bodyToProcess = strip_tags($bodyToProcess);
        $bodyToProcess = html_entity_decode($bodyToProcess, ENT_QUOTES, 'UTF-8');
    }

    $convertedBody = $bodyToProcess;
    if ($bodyCharset !== '' && $bodyCharset !== 'UTF-8') {
        $r = @iconv($bodyCharset, 'UTF-8//IGNORE', $bodyToProcess);
        if ($r !== false && strlen($r) > 0) { $convertedBody = $r; }
    } else {
        foreach (['UTF-8', 'ISO-2022-JP', 'SJIS', 'EUC-JP', 'ASCII'] as $enc) {
            $r = @iconv($enc, 'UTF-8//IGNORE', $bodyToProcess);
            if ($r !== false && strlen($r) > 0) { $convertedBody = $r; break; }
        }
    }
    $convertedBody = trim($convertedBody);

    audit('logs.reply_maildir_view', 'account=' . $account);
    json_out(['success' => true, 'body' => $convertedBody]);
}

/* ============================================================
 * WebAccessLog — v1 apache_logs.php 相当。
 * Apache access.log(+ローテーション .1/.gz)を Combined Log Format でパースし、
 * IP位置情報(ip-api.com + data/ip_cache.json キャッシュ)を付与して一覧・CSV出力。
 * 全テナント混在の生ログのため superadmin 限定。
 * ============================================================ */

// api/ の親(tet2/)配下の data/ に IP キャッシュを置く。dirname(__DIR__) で曖昧さを排除。
define('WEBLOG_IP_CACHE', dirname(__DIR__) . '/data/ip_cache.json');

/** IPキャッシュを読む。 */
function weblog_load_ip_cache(): array
{
    $f = WEBLOG_IP_CACHE;
    if (is_file($f) && is_readable($f)) {
        $c = json_decode((string) file_get_contents($f), true);
        if (is_array($c)) { return $c; }
    }
    return [];
}

/** IPキャッシュを保存する。 */
function weblog_save_ip_cache(array $cache): void
{
    $dir = dirname(WEBLOG_IP_CACHE);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    @file_put_contents(WEBLOG_IP_CACHE, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** IP → 位置情報。プライベートIPは即返し、公開IPは ip-api.com(3秒timeout)で解決。 */
function weblog_ip_info(string $ip): array
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return ['country' => 'Private/Local', 'location' => 'Private Network', 'isp' => 'Private Network',
                'org' => 'Private Network', 'as' => '', 'hostname' => 'localhost'];
    }
    $url = "http://ip-api.com/json/{$ip}?fields=status,country,countryCode,regionName,city,isp,org,as,query";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    $resp = curl_exec($ch);
    $err = curl_errno($ch);
    curl_close($ch);
    if (!$err && $resp) {
        $d = json_decode((string) $resp, true);
        if (is_array($d) && ($d['status'] ?? '') === 'success') {
            $country = $d['country'] ?? 'Unknown';
            $cc = $d['countryCode'] ?? '';
            $region = $d['regionName'] ?? '';
            $city = $d['city'] ?? '';
            $loc = trim("{$city}, {$region}");
            $loc = ($loc === '' || $loc === ', ') ? $country : $loc . ", {$country}";
            return ['country' => "{$country} ({$cc})", 'location' => $loc,
                    'isp' => $d['isp'] ?? 'Unknown', 'org' => $d['org'] ?? ($d['isp'] ?? 'Unknown'),
                    'as' => $d['as'] ?? '', 'hostname' => @gethostbyaddr($ip) ?: $ip];
        }
    }
    $hostname = @gethostbyaddr($ip);
    $org = 'Unknown';
    if ($hostname && $hostname !== $ip) {
        $parts = explode('.', $hostname);
        if (count($parts) >= 2) { $org = $parts[count($parts) - 2]; }
    }
    return ['country' => 'Unknown', 'location' => 'Unknown', 'isp' => 'Unknown',
            'org' => $org, 'as' => '', 'hostname' => $hostname ?: $ip];
}

/** access.log 群(新しい順: 本体→.1→.gz最新3)を返す。 */
function weblog_log_files(): array
{
    $dir = '/var/log/apache2';
    $files = [];
    foreach (["{$dir}/access.log", "{$dir}/access.log.1"] as $p) {
        if (is_file($p) && is_readable($p)) { $files[] = ['path' => $p, 'gz' => false]; }
    }
    $gz = glob("{$dir}/access.log.*.gz") ?: [];
    usort($gz, function ($a, $b) {
        preg_match('/access\.log\.(\d+)\.gz$/', $a, $ma);
        preg_match('/access\.log\.(\d+)\.gz$/', $b, $mb);
        return (int) ($ma[1] ?? 999) - (int) ($mb[1] ?? 999);
    });
    foreach (array_slice($gz, 0, 3) as $g) {
        if (is_readable($g)) { $files[] = ['path' => $g, 'gz' => true]; }
    }
    return $files;
}

/** ログ1行(Combined)をパースしフィルタ適用。マッチ&通過で配列、それ以外 null。 */
function weblog_parse_line(string $line, ?string $start, ?string $end, ?string $pathFilter, ?string $search, string $srcFile): ?array
{
    $pattern = '/^(\S+) (\S+) (\S+) \[([^\]]+)\] "([^"]*)" (\d+) (\S+) "([^"]*)" "([^"]*)"$/';
    if (!preg_match($pattern, $line, $m)) { return null; }
    $req = explode(' ', $m[5]);
    $method = $req[0] ?? '';
    $path = $req[1] ?? '';
    $protocol = $req[2] ?? '';
    $dt = DateTime::createFromFormat('d/M/Y:H:i:s O', $m[4]);
    $logDate = $dt !== false ? $dt->format('Y-m-d H:i:s') : null;
    if ($logDate !== null) {
        if ($start !== null && $start !== '' && $logDate < $start) { return null; }
        if ($end !== null && $end !== '' && $logDate > $end) { return null; }
    }
    if ($pathFilter !== null && $pathFilter !== '' && stripos($path, $pathFilter) === false) { return null; }
    if ($search !== null && $search !== '') {
        $found = stripos($m[1], $search) !== false || stripos($path, $search) !== false
              || stripos($m[6], $search) !== false || stripos($method, $search) !== false;
        if (!$found) { return null; }
    }
    return ['ip' => $m[1], 'timestamp' => $logDate ?: $m[4], 'method' => $method, 'path' => $path,
            'protocol' => $protocol, 'status' => $m[6], 'size' => $m[7] !== '-' ? $m[7] : '0',
            'referer' => $m[8] !== '-' ? $m[8] : '', 'useragent' => $m[9], 'source_file' => $srcFile];
}

/** access.log 群をパース。2パス(件数→該当ページ)で省メモリ。per_page<=0 は全件(export)。 */
function weblog_parse_access(?string $start, ?string $end, ?string $pathFilter, ?string $search, int $page, int $perPage): array
{
    $files = weblog_log_files();
    if (empty($files)) { return ['logs' => [], 'total_count' => 0]; }

    $readLines = function (array $f, callable $cb): void {
        if ($f['gz']) {
            $h = gzopen($f['path'], 'r');
            if ($h === false) { return; }
            while (!gzeof($h)) {
                $l = gzgets($h, 8192);
                if ($l !== false) { $l = trim($l); if ($l !== '' && $cb($l) === false) { break; } }
            }
            gzclose($h);
        } else {
            $h = fopen($f['path'], 'r');
            if ($h === false) { return; }
            while (($l = fgets($h, 8192)) !== false) {
                $l = trim($l); if ($l !== '' && $cb($l) === false) { break; }
            }
            fclose($h);
        }
    };

    // パス1: 件数カウント。
    $total = 0;
    foreach ($files as $f) {
        $readLines($f, function ($line) use ($f, $start, $end, $pathFilter, $search, &$total) {
            if (weblog_parse_line($line, $start, $end, $pathFilter, $search, basename($f['path'])) !== null) { $total++; }
            return true;
        });
    }
    if ($total === 0) { return ['logs' => [], 'total_count' => 0]; }

    // ページ範囲(降順=末尾から)。
    if ($perPage <= 0) { $skipEnd = 0; $take = $total; }
    else { $skipEnd = ($page - 1) * $perPage; $take = $perPage; }
    $startIdx = max(0, $total - $skipEnd - $take);
    $endIdx = $total - $skipEnd;

    // パス2: 該当範囲のみ収集。
    $pageLogs = [];
    $idx = 0;
    foreach ($files as $f) {
        $readLines($f, function ($line) use ($f, $start, $end, $pathFilter, $search, &$pageLogs, &$idx, $startIdx, $endIdx) {
            $e = weblog_parse_line($line, $start, $end, $pathFilter, $search, basename($f['path']));
            if ($e !== null) {
                if ($idx >= $startIdx && $idx < $endIdx) { $pageLogs[] = $e; }
                $idx++;
                if ($idx >= $endIdx) { return false; }
            }
            return true;
        });
        if ($idx >= $endIdx) { break; }
    }
    $pageLogs = array_reverse($pageLogs); // 降順。
    return ['logs' => $pageLogs, 'total_count' => $total];
}

/** ログ配列に GeoIP を付与(キャッシュ優先, 1回40件までAPI)。参照渡し。 */
function weblog_enrich_geoip(array &$logs, bool $allowApi = false): void
{
    // $allowApi=false(既定): 外部GeoIP API を一切呼ばず、キャッシュ済み情報だけ付与する。
    //   一覧表示のたびに ip-api.com へ問い合わせると、未知IPが多い時に
    //   「3秒×IP数」でリクエストがタイムアウトするため、一覧は必ずキャッシュのみ。
    // $allowApi=true: CSV出力や明示的な「GeoIP更新」操作でのみ外部APIを許可する。
    $cache = weblog_load_ip_cache();
    $updated = false;
    $apiCalls = 0;
    $maxApi = 40;
    foreach ($logs as &$log) {
        $ip = $log['ip'];
        $needs = (!isset($cache[$ip]) && $ip !== 'unknown')
              || (isset($cache[$ip]) && ($cache[$ip]['country'] ?? '') === 'Unknown');
        if ($allowApi && $needs && $apiCalls < $maxApi) {
            $cache[$ip] = weblog_ip_info($ip);
            $updated = true;
            $apiCalls++;
            usleep(100000);
        }
        $info = $cache[$ip] ?? [];
        $log['country'] = $info['country'] ?? '';
        $log['location'] = $info['location'] ?? '';
        $log['isp'] = $info['isp'] ?? '';
        $log['org'] = $info['org'] ?? '';
        $log['as'] = $info['as'] ?? '';
        $log['hostname'] = $info['hostname'] ?? '';
    }
    unset($log);
    if ($updated) { weblog_save_ip_cache($cache); }
}

/** ?start_date/?end_date の形式(空 or 'Y-m-d H:i:s')を検証。不正なら 400。 */
function weblog_validate_date(?string $v, string $label): void
{
    if ($v !== null && $v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) {
        json_error("{$label} の形式が不正です（例: 2026-04-12 16:31:05）", 400);
    }
}

/** WebAccessLog 一覧(GeoIP付き, ページング)。superadmin 限定。 */
function weblog_handle_list(): never
{
    $start = isset($_GET['start_date']) ? (string) $_GET['start_date'] : null;
    $end = isset($_GET['end_date']) ? (string) $_GET['end_date'] : null;
    $pathFilter = isset($_GET['path']) ? (string) $_GET['path'] : null;
    $search = isset($_GET['search']) ? (string) $_GET['search'] : null;
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 5000;
    if ($perPage > 5000) { $perPage = 5000; }
    if ($perPage < 1) { $perPage = 5000; }
    weblog_validate_date($start, '開始日時');
    weblog_validate_date($end, '終了日時');

    $res = weblog_parse_access($start, $end, $pathFilter, $search, $page, $perPage);
    $logs = $res['logs'];
    $total = $res['total_count'];
    weblog_enrich_geoip($logs);

    $files = weblog_log_files();
    $fileInfo = [];
    foreach ($files as $f) {
        $fileInfo[] = ['name' => basename($f['path']), 'compressed' => $f['gz'],
                       'size' => filesize($f['path']), 'size_human' => logs_format_bytes((int) filesize($f['path']))];
    }
    audit('logs.weblog', 'total=' . $total . ',page=' . $page);
    json_out(['success' => true, 'logs' => $logs, 'log_files' => $fileInfo,
              'pagination' => ['page' => $page, 'per_page' => $perPage, 'total_count' => $total,
                               'total_pages' => max(1, (int) ceil($total / $perPage))]]);
}

/**
 * WebAccessLog の GeoIP を後追い補完する。一覧と同じ条件で対象ログを取得し、
 * 未知IPだけ外部API(最大40件)で解決してキャッシュに保存、補完後の一覧を返す。
 * 一覧表示のタイムアウトを避けるため、外部通信はこの明示操作でのみ行う。
 */
function weblog_handle_geoip_refresh(): never
{
    $start = isset($_GET['start_date']) ? (string) $_GET['start_date'] : null;
    $end = isset($_GET['end_date']) ? (string) $_GET['end_date'] : null;
    $pathFilter = isset($_GET['path']) ? (string) $_GET['path'] : null;
    $search = isset($_GET['search']) ? (string) $_GET['search'] : null;
    $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
    $perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 5000;
    if ($perPage > 5000 || $perPage < 1) { $perPage = 5000; }
    weblog_validate_date($start, '開始日時');
    weblog_validate_date($end, '終了日時');

    $res = weblog_parse_access($start, $end, $pathFilter, $search, $page, $perPage);
    $logs = $res['logs'];
    weblog_enrich_geoip($logs, true); // 明示操作なので外部APIを許可(最大40件/回)
    audit('logs.weblog_geoip', 'page=' . $page);
    json_out(['success' => true, 'logs' => $logs,
              'pagination' => ['page' => $page, 'per_page' => $perPage, 'total_count' => $res['total_count'],
                               'total_pages' => max(1, (int) ceil($res['total_count'] / $perPage))]]);
}

/** WebAccessLog を CSV でダウンロード(全件, GeoIP付き)。 */
function weblog_handle_csv(): never
{
    $start = isset($_GET['start_date']) ? (string) $_GET['start_date'] : null;
    $end = isset($_GET['end_date']) ? (string) $_GET['end_date'] : null;
    $pathFilter = isset($_GET['path']) ? (string) $_GET['path'] : null;
    weblog_validate_date($start, '開始日時');
    weblog_validate_date($end, '終了日時');

    $res = weblog_parse_access($start, $end, $pathFilter, null, 1, 0); // 全件
    $logs = $res['logs'];
    weblog_enrich_geoip($logs, true); // CSV は時間をかけてよいので外部GeoIP APIを許可

    // CSVインジェクション対策は共通関数 tet2_csv_sanitize() に集約(bootstrap.php)。
    $san = 'tet2_csv_sanitize';
    $out = fopen('php://temp', 'r+');
    fputcsv($out, ['IP', 'Timestamp', 'Method', 'Path', 'Protocol', 'Status', 'Size', 'Referer',
                   'User-Agent', '国', '場所', 'ISP', '組織', 'AS', 'ホスト名', 'ログファイル']);
    foreach ($logs as $log) {
        fputcsv($out, [
            $san($log['ip'] ?? ''), $san($log['timestamp'] ?? ''), $san($log['method'] ?? ''),
            $san($log['path'] ?? ''), $san($log['protocol'] ?? ''), $san($log['status'] ?? ''),
            $san($log['size'] ?? ''), $san($log['referer'] ?? ''), $san($log['useragent'] ?? ''),
            $san($log['country'] ?? ''), $san($log['location'] ?? ''), $san($log['isp'] ?? ''),
            $san($log['org'] ?? ''), $san($log['as'] ?? ''), $san($log['hostname'] ?? ''), $san($log['source_file'] ?? ''),
        ]);
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    audit('logs.weblog_csv', 'rows=' . count($logs));
    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="apache_access_log_' . date('Ymd_His') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";
    echo $csv;
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? '';
    if ($method !== 'GET') {
        json_error('不正なアクション', 400);
    }
    // 生ログ・Maildir・WebAccessLog(全テナント混在)は superadmin 限定、操作ログは operator 以上、他は viewer。
    $user = require_role(logs_min_role_for_action($action));

    // 生ログ・Maildir・WebAccessLog(全テナント混在)は tenant_id を使わない。effective_tenant_id
    // (superadmin で tenant_id 未指定だと 400)を呼ぶ前に処理する。
    if ($action === 'raw_mail')          { logs_handle_raw('mail'); }
    if ($action === 'raw_web')           { logs_handle_raw('web'); }
    if ($action === 'reply_maildir')     { logs_handle_reply_maildir(); }
    if ($action === 'reply_maildir_view') { logs_handle_reply_maildir_view(); }
    if ($action === 'reply_maildir_csv') { logs_handle_reply_maildir_csv(); }
    if ($action === 'webaccess')         { weblog_handle_list(); }
    if ($action === 'webaccess_geoip')   { weblog_handle_geoip_refresh(); }
    if ($action === 'webaccess_csv')     { weblog_handle_csv(); }

    $tenantId = effective_tenant_id($user, isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : null);

    switch ($action) {
        case 'delivery': logs_handle_delivery($tenantId);
        case 'events':   logs_handle_events($tenantId);
        case 'replies':  logs_handle_replies($tenantId);
        case 'schedule': logs_handle_schedule($tenantId);
        case 'audit':    logs_handle_audit($tenantId);
        case 'training_results':     logs_handle_training_results($tenantId);
        case 'training_results_csv': logs_handle_training_results_csv($tenantId);
        case 'training_log_detail':     logs_handle_training_log_detail($tenantId);
        case 'training_log_detail_csv': logs_handle_training_log_detail_csv($tenantId);
        case 'campaign_files':          logs_handle_campaign_files($tenantId);
        case 'campaign_files_csv':      logs_handle_campaign_files_csv($tenantId);
        default: json_error('不正なアクション', 400);
    }
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}

<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";
require_once __DIR__."/../lib/SimpleXlsx.php";
require_once __DIR__."/../lib/ReportMailIngest.php";
// 訓練結果 / 訓練結果ログ(明細)の行生成。レポートExcel(api/report.php)と共有するため
// lib/ に切り出してある(2026-08-30)。logs_campaign_filter() もここに含まれる。
require_once __DIR__."/../lib/TrainingLogRows.php";
// 返信者(Maildir パース)の一覧計算。レポートExcelと共有するため lib/ に切り出してある
// (2026-08-31)。superadmin 限定・全テナント混在である点は同ファイルの冒頭に明記。
require_once __DIR__."/../lib/ReplyMaildir.php";

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
    $where = "e.tenant_id = ? AND e.verdict = 'user'";
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
    $where = "e.tenant_id = ? AND e.event_type = 'reply' AND e.verdict = 'user'";
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

/**
 * ログデータを XLSX で出力する汎用ヘルパー。
 * @param string $sheetName シート名
 * @param list<string> $headers ヘッダー行
 * @param list<list<scalar>> $dataRows データ行(ヘッダーなし)
 * @param string $filename ダウンロードファイル名
 */
function logs_xlsx_download(string $sheetName, array $headers, array $dataRows, string $filename): never
{
    $xlsx = new SimpleXlsx();
    $rows = [$headers];
    foreach ($dataRows as $r) {
        $rows[] = $r;
    }
    $xlsx->addSheet($sheetName, $rows);
    $xlsx->download($filename);
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
        'reply_maildir_xlsx',
        'webaccess',
        'webaccess_geoip',
        'webaccess_csv',
        'webaccess_xlsx',
    ];
    if (in_array($action, $superadminActions, true)) {
        return 'superadmin';
    }
    return in_array($action, ['audit', 'audit_xlsx', 'report_mail', 'report_mail_confirm', 'report_mail_reject'], true) ? 'operator' : 'viewer';
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
               AND e.tenant_id = ? AND e.event_type IN ('open','click','auth') AND e.verdict = 'user'
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
               AND e.tenant_id = ? AND e.event_type IN ('open','click','auth') AND e.verdict = 'user'
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

/** 訓練結果ログを XLSX でダウンロード。行生成は lib/TrainingLogRows.php と共有。 */
function logs_handle_training_results_xlsx(int $tenantId): never
{
    $data = training_results_rows($tenantId);
    audit('logs.training_results_xlsx', 'count=' . count($data));
    logs_xlsx_download('訓練結果', training_results_headers(),
        $data, 'training_results_' . date('Ymd_His') . '.xlsx');
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

/**
 * 訓練結果ログ(明細)の GeoIP を後追い補完する。
 *
 * click(apache_access 由来)の raw は国情報を持たず ip_cache.json 頼みだが、
 * WebアクセスLog 画面の「GeoIP取得」は superadmin 限定かつ access.log 側の IP しか
 * 対象にしないため、訓練結果ログにだけ現れる IP が未解決のまま残り、明細の
 * 国/場所/ISP 列が空になる事故が起きていた(2026-08-30)。
 * この action は明細と同じ条件で行を取り、未解決 IP だけ外部APIで解決して
 * キャッシュへ保存する。一覧表示のタイムアウトを避けるため外部通信はこの明示操作のみ。
 * 1リクエスト200件までに抑え、未解決の残数を返す(フロントは残数0まで繰り返す)。
 */
function logs_handle_training_log_geoip(int $tenantId): never
{
    $rows = training_log_detail_rows($tenantId);

    // 明細行の IP を weblog_enrich_geoip が期待する形(['ip'=>...])に詰め替える。
    // 同じ IP を何度も API に投げないよう、ユニークな IP だけを渡す。
    $seen = [];
    $ipRows = [];
    foreach ($rows as $r) {
        $ip = (string) ($r['ip'] ?? '');
        if ($ip === '' || $ip === 'unknown' || isset($seen[$ip])) { continue; }
        $seen[$ip] = true;
        $ipRows[] = ['ip' => $ip];
    }

    $batch = 200;
    weblog_enrich_geoip($ipRows, true, $batch);

    // 解決後のキャッシュで、まだ埋まっていない IP の残数を数える。
    $cache = weblog_load_ip_cache();
    $remaining = 0;
    foreach (array_keys($seen) as $ip) {
        if (!isset($cache[$ip]) || ($cache[$ip]['country'] ?? '') === 'Unknown') { $remaining++; }
    }

    audit('logs.training_log_geoip', 'ips=' . count($seen) . ',remaining=' . $remaining);
    json_out(['success' => true, 'geoip_remaining' => $remaining, 'geoip_batch' => $batch,
              'ip_count' => count($seen)]);
}

/** 訓練結果ログ(メール一覧)を CSV でダウンロード(既存21カラム + 報告)。 */
function logs_handle_training_log_detail_csv(int $tenantId): never
{
    $rows = training_log_detail_rows($tenantId);
    $out = fopen('php://temp', 'r+');
    fputcsv($out, ['日時', '乱数', '重複', 'タイプ', '送信先メールアドレス', '表示氏名（姓名）',
                   'メールアドレス（会社）', '会社名', '略称', '本務役職名称', '役職カテゴリ',
                   '入力Email', 'Password/ID', 'IP', '国', '場所', 'ISP', '組織', 'AS', 'ホスト名', 'UserAgent', '報告']);
    foreach ($rows as $r) {
        // 旧入力値の2列は互換のため空欄で残す。その他の攻撃者由来値はCSVインジェクション対策を適用。
        fputcsv($out, [
            tet2_csv_sanitize($r['timestamp']), tet2_csv_sanitize($r['random']), ($r['duplicate'] ? $r['duplicate_count'] . '回' : ''),
            tet2_csv_sanitize($r['type']), tet2_csv_sanitize($r['recipient_email']), tet2_csv_sanitize($r['fullname']), tet2_csv_sanitize($r['company_email']), tet2_csv_sanitize($r['company']),
            tet2_csv_sanitize($r['abbreviation']), tet2_csv_sanitize($r['position']), tet2_csv_sanitize($r['position_category']), '', '',
            tet2_csv_sanitize($r['ip']), tet2_csv_sanitize($r['country']), tet2_csv_sanitize($r['location']), tet2_csv_sanitize($r['isp']), tet2_csv_sanitize($r['org']), tet2_csv_sanitize($r['as']), tet2_csv_sanitize($r['hostname']), tet2_csv_sanitize($r['useragent']), tet2_csv_sanitize($r['report']),
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

/** 訓練結果ログ(明細)を XLSX でダウンロード。行生成は lib/TrainingLogRows.php と共有。 */
function logs_handle_training_log_detail_xlsx(int $tenantId): never
{
    $rows = training_log_detail_rows($tenantId);
    $data = training_log_detail_table_rows($rows);
    audit('logs.training_log_detail_xlsx', 'rows=' . count($rows));
    logs_xlsx_download('訓練結果ログ明細', training_log_detail_headers(), $data,
        'training_logs_' . date('Ymd_His') . '.xlsx');
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
        'SELECT ct.tracking_id, ct.koban, ct.content_no, ct.send_status, ct.resolved_beacon_base,
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
        // 送信時に確定した値(resolved_beacon_base)を最優先で使う。無い(古いキャンペーン)なら従来どおり再計算する。
        // これで送信・測定・表示のホストが必ず一致する(複数指定でも対象者ごとに確定済み)。
        if (!empty($t['resolved_beacon_base'])) {
            $base = (string) $t['resolved_beacon_base'];
        } else {
            // 対象者に割り当てられた content の base を優先、無ければキャンペーン base。
            $cno = isset($t['content_no']) ? (int) $t['content_no'] : 0;
            $base = ($cno > 0 && !empty($contentBase[$cno]))
                ? PipelineRunner::beaconUrlBase(['beacon_base' => $contentBase[$cno]])
                : $campaignBase;
        }
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

/** キャンペーン別 リンク/ビーコン ファイル一覧を XLSX でダウンロード。 */
function logs_handle_campaign_files_xlsx(int $tenantId): never
{
    $d = campaign_files_rows($tenantId);
    $headers = ['項番', '乱数(tracking_id)', '送信先メールアドレス', '表示氏名', '会社名', '送信状況',
                'リンクHTMLファイル名', 'リンクHTML URL', 'ビーコン画像ファイル名', 'ビーコン画像 URL'];
    $data = [];
    foreach ($d['rows'] as $r) {
        $data[] = [
            (int) $r['koban'], (string) $r['tracking_id'], (string) ($r['recipient_email'] ?? ''),
            (string) ($r['fullname'] ?? ''), (string) ($r['company'] ?? ''), (string) ($r['send_status'] ?? ''),
            (string) ($r['link_file'] ?? ''), (string) ($r['link_url'] ?? ''),
            (string) ($r['beacon_file'] ?? ''), (string) ($r['beacon_url'] ?? ''),
        ];
    }
    audit('logs.campaign_files_xlsx', 'campaign=' . $d['campaign_id'] . ',rows=' . count($d['rows']));
    logs_xlsx_download('リンク・ビーコンファイル', $headers, $data,
        'campaign_' . $d['campaign_id'] . '_files_' . date('Ymd_His') . '.xlsx');
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
    fputcsv($out, reply_maildir_headers());
    foreach (reply_maildir_table_rows($r['mails']) as $row) {
        fputcsv($out, $row);
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

/** 返信者一覧を XLSX でダウンロード。行生成は lib/ReplyMaildir.php と共有。 */
function logs_handle_reply_maildir_xlsx(): never
{
    $r = reply_maildir_compute();
    $data = reply_maildir_table_rows($r['mails']);
    audit('logs.reply_maildir_xlsx', 'total=' . count($r['mails']));
    logs_xlsx_download('返信者', reply_maildir_headers(), $data,
        'mail_replies_' . date('Ymd_His') . '.xlsx');
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

// IPキャッシュと ip-api.com 解決は lib/GeoIpCache.php に集約(WebアクセスLog画面と
// CLI一括解決 bin/geoip_resolve.php で共有。2026-08-23 切り出し)。
require_once __DIR__ . '/../lib/GeoIpCache.php';

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

/**
 * ログ配列に GeoIP を付与(キャッシュ優先)。参照渡し。
 * $allowApi=false(既定)は外部APIを呼ばずキャッシュのみ。$maxApi は1回に許可する
 * 外部API呼び出しの上限で、$maxApi<=0 なら無制限(明示refresh用)。
 */
function weblog_enrich_geoip(array &$logs, bool $allowApi = false, int $maxApi = 40): void
{
    // $allowApi=false(既定): 外部GeoIP API を一切呼ばず、キャッシュ済み情報だけ付与する。
    //   一覧表示のたびに ip-api.com へ問い合わせると、未知IPが多い時に
    //   「3秒×IP数」でリクエストがタイムアウトするため、一覧は必ずキャッシュのみ。
    // $allowApi=true: CSV出力や明示的な「GeoIP更新」操作でのみ外部APIを許可する。
    //   明示refresh は $maxApi=0(無制限)で呼び、未解決IPを一括で埋める(2026-08-23)。
    $cache = weblog_load_ip_cache();
    $updated = false;
    $apiCalls = 0;
    foreach ($logs as &$log) {
        $ip = $log['ip'];
        $needs = (!isset($cache[$ip]) && $ip !== 'unknown')
              || (isset($cache[$ip]) && ($cache[$ip]['country'] ?? '') === 'Unknown');
        if ($allowApi && $needs && ($maxApi <= 0 || $apiCalls < $maxApi)) {
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
    // 明示操作なので外部APIを許可。1リクエストの実行時間が延びすぎないよう1回200件までに
    // 抑え、未解決の残数を返す。フロントは残数>0の間このアクションを繰り返し叩いて埋める。
    $batch = 200;
    weblog_enrich_geoip($logs, true, $batch);
    // このページ内で解決を要するIP(未解決 or Unknown)の残数を数える。
    $cache = weblog_load_ip_cache();
    $remaining = 0;
    $seen = [];
    foreach ($logs as $log) {
        $ip = $log['ip'] ?? '';
        if ($ip === '' || $ip === 'unknown' || isset($seen[$ip])) { continue; }
        $seen[$ip] = true;
        if (!isset($cache[$ip]) || ($cache[$ip]['country'] ?? '') === 'Unknown') { $remaining++; }
    }
    audit('logs.weblog_geoip', 'page=' . $page . ',remaining=' . $remaining);
    json_out(['success' => true, 'logs' => $logs, 'geoip_remaining' => $remaining, 'geoip_batch' => $batch,
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

/** WebAccessLog を XLSX でダウンロード。 */
function weblog_handle_xlsx(): never
{
    $start = isset($_GET['start_date']) ? (string) $_GET['start_date'] : null;
    $end = isset($_GET['end_date']) ? (string) $_GET['end_date'] : null;
    $pathFilter = isset($_GET['path']) ? (string) $_GET['path'] : null;
    weblog_validate_date($start, '開始日時');
    weblog_validate_date($end, '終了日時');
    $res = weblog_parse_access($start, $end, $pathFilter, null, 1, 0);
    $logs = $res['logs'];
    weblog_enrich_geoip($logs, true);
    $headers = ['IP', 'Timestamp', 'Method', 'Path', 'Protocol', 'Status', 'Size', 'Referer',
                'User-Agent', '国', '場所', 'ISP', '組織', 'AS', 'ホスト名', 'ログファイル'];
    $data = [];
    foreach ($logs as $l) {
        $data[] = [
            (string) ($l['ip'] ?? ''), (string) ($l['timestamp'] ?? ''), (string) ($l['method'] ?? ''),
            (string) ($l['path'] ?? ''), (string) ($l['protocol'] ?? ''), (string) ($l['status'] ?? ''),
            (string) ($l['size'] ?? ''), (string) ($l['referer'] ?? ''), (string) ($l['useragent'] ?? ''),
            (string) ($l['country'] ?? ''), (string) ($l['location'] ?? ''), (string) ($l['isp'] ?? ''),
            (string) ($l['org'] ?? ''), (string) ($l['as'] ?? ''), (string) ($l['hostname'] ?? ''),
            (string) ($l['source_file'] ?? ''),
        ];
    }
    audit('logs.weblog_xlsx', 'rows=' . count($logs));
    logs_xlsx_download('WebアクセスLog', $headers, $data, 'apache_access_log_' . date('Ymd_His') . '.xlsx');
}

/** 送信ログを XLSX でダウンロード。 */
function logs_handle_delivery_xlsx(int $tenantId): never
{
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
         ORDER BY dl.id DESC",
        $params
    );
    $data = [];
    foreach ($rows as $r) {
        $data[] = [(int) $r['id'], (string) ($r['campaign_name'] ?? ''), (string) ($r['tracking_id'] ?? ''),
            (string) ($r['to_email'] ?? ''), (string) ($r['result'] ?? ''), (string) ($r['smtp_message'] ?? ''),
            (string) ($r['occurred_at'] ?? '')];
    }
    audit('logs.delivery_xlsx', 'rows=' . count($rows));
    logs_xlsx_download('送信ログ', ['#', 'キャンペーン', '追跡ID', '宛先', '結果', 'SMTPメッセージ', '日時'],
        $data, 'delivery_log_' . date('Ymd_His') . '.xlsx');
}

/** 訓練イベントを XLSX でダウンロード。 */
function logs_handle_events_xlsx(int $tenantId): never
{
    $cid = logs_campaign_filter($tenantId);
    $where = "e.tenant_id = ? AND e.verdict = 'user'";
    $params = [$tenantId];
    if ($cid !== null) { $where .= ' AND e.campaign_id = ?'; $params[] = $cid; }
    $rows = Db::all(
        "SELECT e.id, e.campaign_id, c.name AS campaign_name, e.tracking_id,
                e.event_type, e.auth_variant, e.source, e.occurred_at
         FROM events e
         LEFT JOIN campaigns c ON c.id = e.campaign_id
         WHERE {$where}
         ORDER BY e.id DESC",
        $params
    );
    $data = [];
    foreach ($rows as $r) {
        $data[] = [(int) $r['id'], (string) ($r['campaign_name'] ?? ''), (string) ($r['tracking_id'] ?? ''),
            (string) ($r['event_type'] ?? ''), (string) ($r['auth_variant'] ?? ''),
            (string) ($r['source'] ?? ''), (string) ($r['occurred_at'] ?? '')];
    }
    audit('logs.events_xlsx', 'rows=' . count($rows));
    logs_xlsx_download('訓練イベント', ['#', 'キャンペーン', '追跡ID', '種別', '認証種', 'source', '日時'],
        $data, 'events_' . date('Ymd_His') . '.xlsx');
}

/** スケジュールログを XLSX でダウンロード。 */
function logs_handle_schedule_xlsx(int $tenantId): never
{
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
         ORDER BY ss.id DESC",
        $params
    );
    $data = [];
    foreach ($rows as $r) {
        $data[] = [(int) $r['id'], (string) ($r['campaign_name'] ?? ''), (int) ($r['batch_no'] ?? 0),
            (string) ($r['scheduled_at'] ?? ''), (string) ($r['koban_from'] ?? ''), (string) ($r['koban_to'] ?? ''),
            (int) ($r['interval_sec'] ?? 0), (string) ($r['status'] ?? ''),
            (string) ($r['worker_pid'] ?? ''), (int) ($r['attempts'] ?? 0)];
    }
    audit('logs.schedule_xlsx', 'rows=' . count($rows));
    logs_xlsx_download('スケジュール', ['#', 'キャンペーン', 'バッチ', '予定時刻', '項番From', '項番To', '間隔(秒)', '状態', 'PID', '試行'],
        $data, 'schedule_' . date('Ymd_His') . '.xlsx');
}

/** 操作ログを XLSX でダウンロード。 */
function logs_handle_audit_xlsx(int $tenantId): never
{
    $rows = Db::all(
        "SELECT al.id, al.user_id, u.email AS user_email, al.action, al.detail, al.ip, al.occurred_at
         FROM audit_log al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE al.tenant_id = ?
         ORDER BY al.id DESC",
        [$tenantId]
    );
    $data = [];
    foreach ($rows as $r) {
        $data[] = [(int) $r['id'], (string) ($r['user_email'] ?? ''), (string) ($r['action'] ?? ''),
            (string) ($r['detail'] ?? ''), (string) ($r['ip'] ?? ''), (string) ($r['occurred_at'] ?? '')];
    }
    audit('logs.audit_xlsx', 'rows=' . count($rows));
    logs_xlsx_download('操作ログ', ['#', 'ユーザ', 'アクション', '詳細', 'IP', '日時'],
        $data, 'audit_log_' . date('Ymd_His') . '.xlsx');
}

/** nullスコープはsuperadminの全件表示専用。 */
function logs_handle_report_mail(?int $tenantId): never
{
    [$limit, $offset] = logs_paging();
    $status = (string) ($_GET['status'] ?? '');
    if (!in_array($status, ['', 'confirmed', 'pending', 'rejected'], true)) { json_error('不正な状態', 400); }
    $where = ['1=1']; $params = [];
    if ($tenantId !== null) { $where[] = 'm.tenant_id=?'; $params[] = $tenantId; }
    if ($status !== '') { $where[] = 'm.status=?'; $params[] = $status; }
    $where = implode(' AND ', $where);
    $total = (int) Db::one("SELECT COUNT(*) n FROM report_mail_matches m JOIN report_mails rm ON rm.id=m.report_mail_id WHERE $where", $params)['n'];
    $rows = Db::all("SELECT m.id,m.status,m.method,m.evidence,m.tracking_id,m.campaign_id,
        c.name AS campaign_name,t.name AS target_name,t.email AS target_email,
        rm.received_at,rm.from_email,rm.subject_head,m.decided_by,m.decided_at,m.event_id
        FROM report_mail_matches m JOIN report_mails rm ON rm.id=m.report_mail_id
        LEFT JOIN campaigns c ON c.id=m.campaign_id AND c.tenant_id=m.tenant_id
        LEFT JOIN campaign_targets ct ON ct.tracking_id=m.tracking_id AND ct.campaign_id=c.id
        LEFT JOIN targets t ON t.id=ct.target_id AND t.tenant_id=m.tenant_id
        WHERE $where ORDER BY rm.received_at DESC,m.id DESC LIMIT ? OFFSET ?", [...$params,$limit,$offset]);
    foreach ($rows as &$row) {
        $evidence = json_decode($row['evidence'] ?? '', true);
        $row['reason'] = is_string($evidence['reason'] ?? null) ? $evidence['reason'] : '';
        unset($row['evidence']);
    }
    unset($row);
    $result = ['success'=>true,'rows'=>$rows,'total'=>$total,'limit'=>$limit,'offset'=>$offset];
    if (current_user()['role'] === 'superadmin' && ($_GET['include_unmatched'] ?? '') === '1') {
        $result['unmatched'] = Db::all('SELECT rm.id,rm.received_at,rm.from_email,rm.subject_head,rm.parse_status,rm.parse_error
            FROM report_mails rm WHERE rm.parse_status <> \'purged\'
              AND NOT EXISTS (SELECT 1 FROM report_mail_matches m WHERE m.report_mail_id=rm.id)
            ORDER BY rm.received_at DESC,rm.id DESC');
    }
    json_out($result);
}

function logs_handle_report_mail_confirm(?int $tenantId): never
{
    tet2_require_csrf();
    logs_decide_report_mail($tenantId, true);
}

function logs_handle_report_mail_reject(?int $tenantId): never
{
    tet2_require_csrf();
    logs_decide_report_mail($tenantId, false);
}

function logs_decide_report_mail(?int $tenantId, bool $confirm): never
{
    if (current_user()['role'] === 'superadmin') { $tenantId = null; }
    $id = (int) (json_body()['id'] ?? 0);
    try {
        $result = Db::tx(static function () use ($id, $tenantId, $confirm): array {
            $match = Db::one('SELECT * FROM report_mail_matches WHERE id=?' . ($tenantId === null ? '' : ' AND tenant_id=?'),
                $tenantId === null ? [$id] : [$id,$tenantId]);
            if ($match === null) { throw new DomainException('報告メールが見つかりません', 404); }
            $actor = (string) current_user()['email'];
            if ($confirm) { $result = ReportMailIngest::confirmMatch($id, $actor); }
            else { ReportMailIngest::rejectMatch($id, $actor); $result = ['id'=>$id,'tracking_id'=>$match['tracking_id']]; }
            audit($confirm ? 'report_mail.confirm' : 'report_mail.reject', 'match_id=' . $id . ',tracking_id=' . $match['tracking_id']);
            return $result;
        });
    } catch (DomainException $e) { json_error($e->getMessage(), $e->getCode()); }
    json_out(['success'=>true] + $result);
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? '';
    $isDecision = in_array($action, ['report_mail_confirm', 'report_mail_reject'], true);
    if ($method !== ($isDecision ? 'POST' : 'GET')) {
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
    if ($action === 'reply_maildir_xlsx') { logs_handle_reply_maildir_xlsx(); }
    if ($action === 'webaccess_xlsx')     { weblog_handle_xlsx(); }

    $isReport = in_array($action, ['report_mail', 'report_mail_confirm', 'report_mail_reject'], true);
    $tenantId = $isReport && $user['role'] === 'superadmin' && !isset($_GET['tenant_id'])
        ? null : effective_tenant_id($user, isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : null);

    switch ($action) {
        case 'report_mail': logs_handle_report_mail($tenantId);
        case 'report_mail_confirm': logs_handle_report_mail_confirm($tenantId);
        case 'report_mail_reject': logs_handle_report_mail_reject($tenantId);
        case 'delivery': logs_handle_delivery($tenantId);
        case 'events':   logs_handle_events($tenantId);
        case 'replies':  logs_handle_replies($tenantId);
        case 'schedule': logs_handle_schedule($tenantId);
        case 'audit':    logs_handle_audit($tenantId);
        case 'training_results':     logs_handle_training_results($tenantId);
        case 'training_results_csv': logs_handle_training_results_csv($tenantId);
        case 'training_log_detail':     logs_handle_training_log_detail($tenantId);
        case 'training_log_detail_csv': logs_handle_training_log_detail_csv($tenantId);
        case 'training_log_geoip':      logs_handle_training_log_geoip($tenantId);
        case 'campaign_files':          logs_handle_campaign_files($tenantId);
        case 'campaign_files_csv':      logs_handle_campaign_files_csv($tenantId);
        case 'delivery_xlsx':           logs_handle_delivery_xlsx($tenantId);
        case 'events_xlsx':             logs_handle_events_xlsx($tenantId);
        case 'schedule_xlsx':           logs_handle_schedule_xlsx($tenantId);
        case 'audit_xlsx':              logs_handle_audit_xlsx($tenantId);
        case 'training_results_xlsx':       logs_handle_training_results_xlsx($tenantId);
        case 'training_log_detail_xlsx':    logs_handle_training_log_detail_xlsx($tenantId);
        case 'campaign_files_xlsx':         logs_handle_campaign_files_xlsx($tenantId);
        default: json_error('不正なアクション', 400);
    }
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}

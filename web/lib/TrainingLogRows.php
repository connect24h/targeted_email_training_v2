<?php declare(strict_types=1);

/**
 * 訓練結果 / 訓練結果ログ(明細)の行生成。
 *
 * 元は api/logs.php 内にあり、ログ管理画面の一覧・CSV・XLSX からのみ使われていた。
 * レポートの Excel(api/report.php の export_xlsx)にも同じ内容をシートとして
 * 載せることになり、両APIから同一ロジックを呼ぶために切り出した(2026-08-30)。
 * api/logs.php を require すると末尾のディスパッチが走って exit するため、
 * 共有は lib/ 経由でなければならない(GeoIpCache.php と同じ理由・同じ流儀)。
 *
 * フィルタ条件は $_GET から読む(campaign_id / start_date / end_date / type /
 * exclude_system)。呼び出し側のクエリがそのまま効くので、レポートから呼ぶと
 * campaign_id だけが効き、そのキャンペーンの全明細が返る。
 */

require_once __DIR__ . '/GeoIpCache.php';

/**
 * ?campaign_id= 指定があればテナント所有を検証して返す。無ければ null。
 * IDOR 防止のため assert_campaign_owned() を必ず通す(他テナント指定は 404)。
 * 元は api/logs.php にあったが、行生成関数がここへ移ったため一緒に移設した。
 */
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
    // 旧ログには入力済みの識別子・秘密値が残る。明細/API/エクスポートには出さない。
    $map = ['IP' => 'ip', 'Country' => 'country',
            'Location' => 'location', 'ISP' => 'isp', 'Org' => 'org', 'AS' => 'as',
            'Hostname' => 'hostname', 'UserAgent' => 'useragent'];
    foreach ($map as $label => $key) {
        if ($key === 'useragent') {
            if (preg_match('/UserAgent:\s*(.+)$/s', $raw, $m)) { $out['useragent'] = trim($m[1]); }
        } elseif (preg_match('/' . $label . ':\s*([^|]*)/', $raw, $m)) {
            $out[$key] = trim($m[1]);
        }
    }
    // click(apache_access 由来)の raw は v1 形式のラベルを持たず、Apache 生ログそのもの。
    // その場合 country 等が空になるので、生ログから IP と UA を抜き、WebアクセスLog と
    // 同じ ip_cache.json(GeoIpCache)を引いて国/ISP/組織/AS/ホスト名を補完する。
    // auth(text_log 由来。Country ラベルあり)は上のパースで埋まるのでここは素通りする。
    if ($out['country'] === '' && preg_match('/^(\S+) \S+ \S+ \[[^\]]+\] "/', $raw, $ma)) {
        $out = training_log_enrich_from_access_log($raw, $ma[1], $out);
    }
    return $out;
}

/**
 * Apache 生ログ由来(=click)の1行から IP/UA を抽出し、GeoIP キャッシュで
 * 国/場所/ISP/組織/AS/ホスト名を補完する。キャッシュに無い IP は空のままにする
 * (機械除外や再API通信はしない。キャッシュは bin/geoip_resolve.php や画面の
 *  GeoIP取得で事前に埋まっている前提)。
 */
function training_log_enrich_from_access_log(string $raw, string $ip, array $out): array
{
    $out['ip'] = $ip;
    // Apache combined の末尾フィールド "..." が UserAgent。
    if (preg_match('/"([^"]*)"\s*$/', rtrim($raw), $mu)) {
        $out['useragent'] = $mu[1] === '-' ? '' : $mu[1];
    }
    $cache = weblog_load_ip_cache();
    $info = $cache[$ip] ?? null;
    if (is_array($info)) {
        $out['country']  = (string) ($info['country']  ?? '');
        $out['location'] = (string) ($info['location'] ?? '');
        $out['isp']      = (string) ($info['isp']      ?? '');
        $out['org']      = (string) ($info['org']      ?? '');
        $out['as']       = (string) ($info['as']       ?? '');
        $out['hostname'] = (string) ($info['hostname'] ?? '');
    }
    return $out;
}

/**
 * 訓練結果ログの明細行を組み立てる(list/export 共通)。
 * events(click/auth/report) × campaign_targets × targets を結合し、raw をパースして
 * 既存21カラムに報告列を末尾追加した配列にする。キャンペーン別・期間・タイプでフィルタ。
 */
function training_log_detail_rows(int $tenantId): array
{
    $cid = logs_campaign_filter($tenantId);
    $startDate = (isset($_GET['start_date']) && $_GET['start_date'] !== '') ? (string) $_GET['start_date'] : '';
    $endDate   = (isset($_GET['end_date'])   && $_GET['end_date']   !== '') ? (string) $_GET['end_date']   : '';
    $typeFilter = isset($_GET['type']) ? (string) $_GET['type'] : '';

    $where = "e.tenant_id = ? AND e.event_type IN ('click','auth','report') AND e.verdict = 'user'";
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
        $parsed = training_log_parse_raw($e['event_type'] === 'report' ? '' : (string) ($e['raw'] ?? ''));
        $type = $e['event_type'] === 'click' ? 'link_click' : ($e['event_type'] === 'report' ? 'report' : (string) ($e['auth_variant'] ?? 'auth'));
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
            'report' => $e['event_type'] === 'report' ? (string) $e['occurred_at'] : '',
        ];
    }

    // type フィルタは human_total/human_seq 計算(下記)より前に適用する。よって type で
    // 絞り込むと、開封回数はその type 内(例: link_click だけ)の人間回数になる。
    // 「クリックと認証を跨いだ通算開封回数」ではない点に注意。
    if ($typeFilter !== '') {
        $rows = array_values(array_filter($rows, fn ($r) => $r['type'] === $typeFilter));
    }

    // システム/自動アクセス(サンドボックス・SWG・Teams 等)を判定して各行に付与する。
    // click(apache_access)は raw 先頭の IP を、auth は parsed['ip'] を使う。判定は
    // ip_cache.json の ISP/AS ベース(WARP/iCloud Relay は人間扱い)。
    foreach ($rows as &$r) {
        $r['is_system'] = ($r['ip'] !== '') ? weblog_ip_is_system((string) $r['ip']) : false;
    }
    unset($r);

    // 乱数重複フラグ(同一乱数が複数行 = 複数回アクセス)。
    $counts = [];
    foreach ($rows as $r) {
        $rand = $r['random'];
        if ($rand !== '' && $r['type'] !== 'report') { $counts[$rand] = ($counts[$rand] ?? 0) + 1; }
    }
    // 人間アクセスの通し番号: 同一乱数×人間(is_system=false)の行に、時刻昇順で
    // 「m回中n回目」を付ける。時間差で複数回開いた人間を可視化する(システム行は数えない)。
    $humanTotal = [];
    foreach ($rows as $r) {
        if (!$r['is_system'] && $r['random'] !== '' && $r['type'] !== 'report') {
            $humanTotal[$r['random']] = ($humanTotal[$r['random']] ?? 0) + 1;
        }
    }
    // $rows は occurred_at 降順。昇順の通し番号にするため乱数ごとに逆から数える。
    $humanSeen = [];
    for ($i = count($rows) - 1; $i >= 0; $i--) {
        $rand = $rows[$i]['random'];
        if (!$rows[$i]['is_system'] && $rand !== '' && $rows[$i]['type'] !== 'report') {
            $humanSeen[$rand] = ($humanSeen[$rand] ?? 0) + 1;
            $rows[$i]['human_seq'] = $humanSeen[$rand];
            $rows[$i]['human_total'] = $humanTotal[$rand] ?? 0;
        } else {
            $rows[$i]['human_seq'] = 0;
            $rows[$i]['human_total'] = 0;
        }
    }
    foreach ($rows as &$r) {
        $rand = $r['random'];
        $r['duplicate_count'] = ($r['type'] !== 'report' && $rand !== '' && isset($counts[$rand])) ? $counts[$rand] : 0;
        $r['duplicate'] = $r['duplicate_count'] > 1;
    }
    unset($r);

    // exclude_system=1(既定) はシステム行を除外。0 で全行返す(画面トグル用)。
    $excludeSystem = !isset($_GET['exclude_system']) || $_GET['exclude_system'] !== '0';
    if ($excludeSystem) {
        $rows = array_values(array_filter($rows, fn ($r) => !$r['is_system']));
    }

    return $rows;
}

/**
 * 訓練結果(対象者ごとの開封/クリック/認証)の行を組み立てる。
 * 元は logs_handle_training_results_xlsx() 内にインラインで書かれていたものを、
 * レポートExcelと共有するために切り出した(2026-08-30)。SQL・整形は当時のまま。
 *
 * @return list<array<int, string|int>> ヘッダを含まないデータ行。
 */
function training_results_rows(int $tenantId): array
{
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
                MAX(CASE WHEN e.event_type='auth'  THEN 1 ELSE 0 END) AS authed,
                MAX(CASE WHEN e.event_type='report' THEN 1 ELSE 0 END) AS reported
         FROM campaign_targets ct
         INNER JOIN campaigns c ON c.id = ct.campaign_id
         INNER JOIN targets t   ON t.id = ct.target_id
         LEFT JOIN events e ON e.tracking_id = ct.tracking_id AND e.campaign_id = ct.campaign_id
               AND e.tenant_id = ? AND e.event_type IN ('open','click','auth','report') AND e.verdict = 'user'
         WHERE {$where}
         GROUP BY ct.id
         ORDER BY ct.campaign_id DESC, ct.koban",
        array_merge([$tenantId], $params)
    );
    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            (string) ($r['campaign_name'] ?? ''), (int) $r['koban'], (string) $r['email'],
            (string) ($r['target_name'] ?? ''), (string) ($r['company'] ?? ''), (string) ($r['department'] ?? ''),
            (string) ($r['position_category'] ?? ''),
            $r['send_status'] === 'sent' ? '済' : (string) $r['send_status'],
            (int) $r['opened'] ? '○' : '—', (int) $r['clicked'] ? '○' : '—', (int) $r['authed'] ? '○' : '—',
            (int) $r['reported'] ? '○' : '—',
        ];
    }
    return $data;
}

/** 訓練結果シートのヘッダ(ログ管理のXLSX出力と同一)。 */
function training_results_headers(): array
{
    return ['キャンペーン', '項番', 'メール', '氏名', '会社', '部署', '役職カテゴリ', '送信', '開封', 'クリック', '認証', '報告'];
}

/** 訓練結果ログ(明細)シートのヘッダ(ログ管理のXLSX出力と同一)。 */
function training_log_detail_headers(): array
{
    return ['日時', '乱数', '重複', 'タイプ', '送信先メールアドレス', '表示氏名（姓名）',
            'メールアドレス（会社）', '会社名', '略称', '本務役職名称', '役職カテゴリ',
            '入力Email', 'Password/ID', 'IP', '国', '場所', 'ISP', '組織', 'AS', 'ホスト名', 'UserAgent', '報告'];
}

/**
 * 訓練結果ログ(明細)の行を、XLSX/CSV 用の平坦な配列に整形する。
 * training_log_detail_rows() の連想配列を training_log_detail_headers() の並びに合わせる。
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<int, string>>
 */
function training_log_detail_table_rows(array $rows): array
{
    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            (string) ($r['timestamp'] ?? ''), (string) ($r['random'] ?? ''),
            $r['duplicate'] ? ($r['duplicate_count'] . '回') : '',
            (string) ($r['type'] ?? ''), (string) ($r['recipient_email'] ?? ''), (string) ($r['fullname'] ?? ''),
            (string) ($r['company_email'] ?? ''), (string) ($r['company'] ?? ''), (string) ($r['abbreviation'] ?? ''),
            (string) ($r['position'] ?? ''), (string) ($r['position_category'] ?? ''),
            // 旧Excelの列数は維持するが、呼び出し側が値を渡しても入力値は出さない。
            '', '',
            (string) ($r['ip'] ?? ''), (string) ($r['country'] ?? ''), (string) ($r['location'] ?? ''),
            (string) ($r['isp'] ?? ''), (string) ($r['org'] ?? ''), (string) ($r['as'] ?? ''),
            (string) ($r['hostname'] ?? ''), (string) ($r['useragent'] ?? ''),
            (string) ($r['report'] ?? ''),
        ];
    }
    return $data;
}

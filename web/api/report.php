<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";
require_once __DIR__."/../lib/EventIngest.php";
require_once __DIR__."/../lib/EduAutoEnroll.php";

function report_json_body(): array
{
    static $body = null;
    if ($body === null) {
        $body = json_body();
    }
    return $body;
}

function report_query_int(string $key): ?int
{
    if (!array_key_exists($key, $_GET) || $_GET[$key] === '') {
        return null;
    }
    $value = intval($_GET[$key]);
    if ($value < 1) {
        json_error($key . ' が不正です', 400);
    }
    return $value;
}

function report_rate(int $count, int $targetCount): float
{
    if ($targetCount === 0) {
        return 0.0;
    }
    return round($count / $targetCount * 100, 1);
}

function report_summary_from_counts(array $row): array
{
    $targetCount = (int) ($row['target_count'] ?? 0);
    $sentCount = (int) ($row['sent_count'] ?? 0);
    $openCount = (int) ($row['open_count'] ?? 0);
    $clickCount = (int) ($row['click_count'] ?? 0);
    $authCount = (int) ($row['auth_count'] ?? 0);
    return [
        'target_count' => $targetCount,
        'sent_count' => $sentCount,
        'sent_rate' => report_rate($sentCount, $targetCount),
        'open_count' => $openCount,
        'open_rate' => report_rate($openCount, $targetCount),
        'click_count' => $clickCount,
        'click_rate' => report_rate($clickCount, $targetCount),
        'auth_count' => $authCount,
        'auth_rate' => report_rate($authCount, $targetCount),
    ];
}

/**
 * キャンペーン1件のサマリー(母数と open/click/auth)。
 *
 * テストユーザ(targets.is_test=1)は母数からもイベント数からも除外する。
 * イベント側は tracking_id が campaign_targets 経由でテストユーザに紐づく行を弾く
 * (events 自体は is_test を持たないため、campaign_targets→targets を辿って判定する)。
 */
function report_summary_row(int $campaignId, int $tenantId): array
{
    return Db::one(
        "SELECT
            (SELECT COUNT(*)
             FROM campaign_targets ct
             INNER JOIN campaigns c ON c.id = ct.campaign_id
             INNER JOIN targets t ON t.id = ct.target_id
             WHERE c.tenant_id = ? AND ct.campaign_id = ? AND t.is_test = 0) AS target_count,
            (SELECT COUNT(*)
             FROM campaign_targets ct
             INNER JOIN campaigns c ON c.id = ct.campaign_id
             INNER JOIN targets t ON t.id = ct.target_id
             WHERE c.tenant_id = ? AND ct.campaign_id = ? AND ct.send_status = 'sent' AND t.is_test = 0) AS sent_count,
            (SELECT COUNT(DISTINCT e.tracking_id)
             FROM events e
             WHERE e.tenant_id = ? AND e.campaign_id = ? AND e.event_type = 'open'
               AND NOT EXISTS (SELECT 1 FROM campaign_targets ct2
                               INNER JOIN targets t2 ON t2.id = ct2.target_id
                               WHERE ct2.tracking_id = e.tracking_id AND t2.is_test = 1)) AS open_count,
            (SELECT COUNT(DISTINCT e.tracking_id)
             FROM events e
             WHERE e.tenant_id = ? AND e.campaign_id = ? AND e.event_type = 'click'
               AND NOT EXISTS (SELECT 1 FROM campaign_targets ct2
                               INNER JOIN targets t2 ON t2.id = ct2.target_id
                               WHERE ct2.tracking_id = e.tracking_id AND t2.is_test = 1)) AS click_count,
            (SELECT COUNT(DISTINCT e.tracking_id)
             FROM events e
             WHERE e.tenant_id = ? AND e.campaign_id = ? AND e.event_type = 'auth'
               AND NOT EXISTS (SELECT 1 FROM campaign_targets ct2
                               INNER JOIN targets t2 ON t2.id = ct2.target_id
                               WHERE ct2.tracking_id = e.tracking_id AND t2.is_test = 1)) AS auth_count",
        [$tenantId, $campaignId, $tenantId, $campaignId, $tenantId, $campaignId, $tenantId, $campaignId, $tenantId, $campaignId]
    ) ?? [];
}

function report_handle_ingest(): never
{
    require_role('operator');
    tet2_require_csrf();
    $counts = EventIngest::ingestAll();
    audit('report.ingest', json_encode($counts, JSON_UNESCAPED_UNICODE) ?: '');
    // 訓練失敗の取込直後に、トリガー配信への自動連携を回す(ジャストインタイム教育)。
    $autoEnroll = EduAutoEnroll::run();
    if ($autoEnroll['assigned'] > 0) {
        audit('edu.auto_enroll', json_encode($autoEnroll, JSON_UNESCAPED_UNICODE) ?: '');
    }
    json_out(['success' => true, 'ingested' => $counts, 'auto_enroll' => $autoEnroll]);
}

function report_handle_summary(): never
{
    $user = require_role('viewer');
    $campaignId = report_query_int('campaign_id');
    if ($campaignId === null) {
        json_error('campaign_id は必須です', 400);
    }
    $tenantId = effective_tenant_id($user, isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : null);
    assert_campaign_owned($campaignId, $tenantId);
    json_out(['success' => true, 'summary' => report_summary_from_counts(report_summary_row($campaignId, $tenantId))]);
}

function report_campaign_rows(int $tenantId, string $testFilter = 'prod'): array
{
    // test_filter: 'prod'(既定)=本番のみ(is_test=0) / 'test'=テストのみ(is_test=1) / 'all'=全部。
    // 本番統計にテスト送信が混ざらないよう、既定は本番のみを返す。
    $testWhere = '';
    if ($testFilter === 'prod') {
        $testWhere = ' AND c.is_test = 0';
    } elseif ($testFilter === 'test') {
        $testWhere = ' AND c.is_test = 1';
    }
    return Db::all(
        "SELECT c.id, c.tenant_id, c.name, c.status, c.start_at, c.end_at, c.is_test, c.created_at,
                COALESCE(ct.target_count, 0) AS target_count,
                COALESCE(ct.sent_count, 0) AS sent_count,
                COALESCE(ev.open_count, 0) AS open_count,
                COALESCE(ev.click_count, 0) AS click_count,
                COALESCE(ev.auth_count, 0) AS auth_count
         FROM campaigns c
         LEFT JOIN (
             SELECT ct.campaign_id,
                    COUNT(*) AS target_count,
                    SUM(CASE WHEN ct.send_status = 'sent' THEN 1 ELSE 0 END) AS sent_count
             FROM campaign_targets ct
             INNER JOIN campaigns c2 ON c2.id = ct.campaign_id
             INNER JOIN targets t2 ON t2.id = ct.target_id
             WHERE c2.tenant_id = ? AND t2.is_test = 0
             GROUP BY ct.campaign_id
         ) ct ON ct.campaign_id = c.id
         LEFT JOIN (
             SELECT e.campaign_id,
                    COUNT(DISTINCT CASE WHEN e.event_type = 'open' THEN e.tracking_id END) AS open_count,
                    COUNT(DISTINCT CASE WHEN e.event_type = 'click' THEN e.tracking_id END) AS click_count,
                    COUNT(DISTINCT CASE WHEN e.event_type = 'auth' THEN e.tracking_id END) AS auth_count
             FROM events e
             WHERE e.tenant_id = ? AND e.event_type IN ('open', 'click', 'auth')
               AND NOT EXISTS (SELECT 1 FROM campaign_targets ct3
                               INNER JOIN targets t3 ON t3.id = ct3.target_id
                               WHERE ct3.tracking_id = e.tracking_id AND t3.is_test = 1)
             GROUP BY e.campaign_id
         ) ev ON ev.campaign_id = c.id
         WHERE c.tenant_id = ? AND c.deleted_at IS NULL" . $testWhere . "
         ORDER BY c.id DESC",
        [$tenantId, $tenantId, $tenantId]
    );
}

function report_handle_campaigns(): never
{
    $user = require_role('viewer');
    $tenantId = effective_tenant_id($user, isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : null);
    // test_filter: prod(既定,本番のみ) / test(テストのみ) / all(全部)。本番統計にテストを混ぜない。
    $tf = $_GET['test_filter'] ?? 'prod';
    if (!in_array($tf, ['prod', 'test', 'all'], true)) { $tf = 'prod'; }
    $campaigns = [];
    foreach (report_campaign_rows($tenantId, $tf) as $row) {
        $summary = report_summary_from_counts($row);
        $campaigns[] = array_merge([
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'name' => (string) $row['name'],
            'status' => (string) $row['status'],
            'start_at' => $row['start_at'],
            'end_at' => $row['end_at'],
            'is_test' => (int) $row['is_test'],
            'created_at' => (string) $row['created_at'],
        ], $summary);
    }
    json_out(['success' => true, 'campaigns' => $campaigns]);
}

// ---- P7: v1 同等の詳細レポート(会社別/役職別/コンテンツ別/日別タイムライン) ----

/**
 * v1 report.php の率定義を踏襲。
 * link_rate / beacon_rate は分母 = count(母数)、auth_rate は分母 = beacon_opened。
 */
function report_detail_rate(int $numerator, int $denominator): float
{
    if ($denominator === 0) {
        return 0.0;
    }
    return round($numerator / $denominator * 100, 2);
}

/**
 * 期間フィルタの SQL 断片とパラメータを組み立てる。
 * events.occurred_at に対して start_date / end_date (YYYY-MM-DD[THH:MM]) で範囲を絞る。
 * @return array{0:string,1:array} [WHERE 追加句, 追加バインド]
 */
function report_detail_period(): array
{
    $clause = '';
    $params = [];
    $start = isset($_GET['start_date']) ? str_replace('T', ' ', trim((string) $_GET['start_date'])) : '';
    $end = isset($_GET['end_date']) ? str_replace('T', ' ', trim((string) $_GET['end_date'])) : '';
    if ($start !== '') {
        $clause .= ' AND e.occurred_at >= ?';
        $params[] = $start;
    }
    if ($end !== '') {
        $clause .= ' AND e.occurred_at <= ?';
        $params[] = $end;
    }
    return [$clause, $params];
}

/**
 * campaign_targets を軸列(会社/役職/コンテンツ)ごとに集計し、events を突き合わせる。
 * open/click/auth は tracking_id の DISTINCT で数える(v1 の1人1回カウントに一致)。
 *
 * @param string $axisSelect 集計キーの SELECT 式(例: "COALESCE(t.company,'')")
 * @param string $axisGroup  GROUP BY 式(通常は $axisSelect と同じ)
 * @return array 各行 [key, count, link_clicked, beacon_opened, auth_count]
 */
function report_detail_axis(int $campaignId, int $tenantId, string $axisSelect, string $axisGroup, string $periodClause, array $periodParams, string $testFilter = 'prod'): array
{
    // テスト対象者(targets.is_test)の扱い。既定は本番のみ(prod)。
    //  - prod: 本番対象者のみ(is_test=0)。従来動作。
    //  - test: テスト対象者のみ(is_test=1)。all配信のテストパターン開封をビーコン別に確認する用途。
    //  - all : 両方。
    // ※開封数は元々 tracking_id 単位(=ビーコン単位)で数えるため、フィルタで対象者を
    //   絞るだけでコンテンツ別の開封が正しく見える。集計の粒度自体は変更しない。
    $testWhere = ' AND t.is_test = 0';
    if ($testFilter === 'test') {
        $testWhere = ' AND t.is_test = 1';
    } elseif ($testFilter === 'all') {
        $testWhere = '';
    }
    // campaign_targets を左、events を tracking_id で結合。event_type ごとに DISTINCT 集計。
    $sql =
        "SELECT {$axisSelect} AS axis_key,
                COUNT(DISTINCT ct.id) AS cnt,
                COUNT(DISTINCT CASE WHEN e.event_type = 'click' THEN e.tracking_id END) AS link_clicked,
                COUNT(DISTINCT CASE WHEN e.event_type = 'open'  THEN e.tracking_id END) AS beacon_opened,
                COUNT(DISTINCT CASE WHEN e.event_type = 'auth'  THEN e.tracking_id END) AS auth_count
         FROM campaign_targets ct
         INNER JOIN campaigns c ON c.id = ct.campaign_id
         INNER JOIN targets t   ON t.id = ct.target_id
         LEFT JOIN events e
                ON e.tracking_id = ct.tracking_id
               AND e.campaign_id = ct.campaign_id
               AND e.tenant_id = ?
               AND e.event_type IN ('open','click','auth')
               {$periodClause}
         WHERE c.tenant_id = ? AND ct.campaign_id = ?" . $testWhere . "
         GROUP BY {$axisGroup}
         ORDER BY cnt DESC";
    $params = array_merge([$tenantId], $periodParams, [$tenantId, $campaignId]);
    return Db::all($sql, $params);
}

/** 軸集計行を v1 レスポンス形状(率つき)に整形する。 */
function report_detail_shape(array $rows, string $keyName): array
{
    $out = [];
    foreach ($rows as $r) {
        $count = (int) $r['cnt'];
        $link = (int) $r['link_clicked'];
        $beacon = (int) $r['beacon_opened'];
        $auth = (int) $r['auth_count'];
        $out[] = [
            $keyName => (string) $r['axis_key'],
            'count' => $count,
            'link_clicked' => $link,
            'beacon_opened' => $beacon,
            'auth_count' => $auth,
            'opened' => $link,
            'link_rate' => report_detail_rate($link, $count),
            'beacon_rate' => report_detail_rate($beacon, $count),
            'auth_rate' => report_detail_rate($auth, $beacon),
            'open_rate' => report_detail_rate($link, $count),
        ];
    }
    return $out;
}

/** 行配列の合計を v1 の total_* 形状で返す。 */
function report_detail_totals(array $rows): array
{
    $count = 0;
    $link = 0;
    $beacon = 0;
    $auth = 0;
    foreach ($rows as $r) {
        $count += (int) $r['count'];
        $link += (int) $r['link_clicked'];
        $beacon += (int) $r['beacon_opened'];
        $auth += (int) $r['auth_count'];
    }
    return [
        'count' => $count,
        'link_clicked' => $link,
        'beacon_opened' => $beacon,
        'auth_count' => $auth,
        'link_rate' => report_detail_rate($link, $count),
        'beacon_rate' => report_detail_rate($beacon, $count),
        'auth_rate' => report_detail_rate($auth, $beacon),
    ];
}

/** 日別タイムライン(open/auth の日次件数 + 累積)。 */
function report_detail_timeline(int $campaignId, int $tenantId, string $periodClause, array $periodParams): array
{
    // occurred_at の日付部分で GROUP。open(=beacon) と auth を tracking_id DISTINCT で。
    $sql =
        "SELECT substr(e.occurred_at, 1, 10) AS d,
                COUNT(DISTINCT CASE WHEN e.event_type = 'open' THEN e.tracking_id END) AS beacon,
                COUNT(DISTINCT CASE WHEN e.event_type = 'auth' THEN e.tracking_id END) AS auth
         FROM events e
         WHERE e.tenant_id = ? AND e.campaign_id = ? AND e.event_type IN ('open','auth')
               {$periodClause}
         GROUP BY d
         ORDER BY d";
    $params = array_merge([$tenantId, $campaignId], $periodParams);
    $rows = Db::all($sql, $params);
    $timeline = [];
    $cumBeacon = 0;
    $cumAuth = 0;
    foreach ($rows as $r) {
        $b = (int) $r['beacon'];
        $a = (int) $r['auth'];
        $cumBeacon += $b;
        $cumAuth += $a;
        $timeline[] = [
            'date' => (string) $r['d'],
            'beacon' => $b,
            'auth' => $a,
            'cum_beacon' => $cumBeacon,
            'cum_auth' => $cumAuth,
        ];
    }
    return $timeline;
}

/**
 * detail の集計本体をリアルタイムに計算して配列で返す(副作用なし)。
 * detail 表示・commit スナップショット生成の双方から使う。
 */
function report_compute_detail(int $campaignId, int $tenantId, string $periodClause, array $periodParams, string $testFilter = 'prod'): array
{
    // 会社別: 空会社は '(未設定)' に寄せる。
    $companyRows = report_detail_axis(
        $campaignId, $tenantId,
        "CASE WHEN t.company IS NULL OR t.company = '' THEN '(未設定)' ELSE t.company END",
        "CASE WHEN t.company IS NULL OR t.company = '' THEN '(未設定)' ELSE t.company END",
        $periodClause, $periodParams, $testFilter
    );
    // 役職別: 正規値以外は 'その他'。正規値は TET2_POSITION_CATEGORIES(役員/管理職/一般従業員)。
    // 旧称「社員」のデータが残っていても 'その他' に落ちるだけで集計自体は壊れない。
    $positionAxis = "CASE WHEN t.position_category IN ('役員','管理職','一般従業員')"
        . " THEN t.position_category ELSE 'その他' END";
    $positionRows = report_detail_axis(
        $campaignId, $tenantId,
        $positionAxis,
        $positionAxis,
        $periodClause, $periodParams, $testFilter
    );
    // コンテンツ別: content_no(NULL は '(単一)')。all配信のビーコン別開封はここで見える。
    $contentRows = report_detail_axis(
        $campaignId, $tenantId,
        "CASE WHEN ct.content_no IS NULL THEN '(単一)' ELSE CAST(ct.content_no AS TEXT) END",
        "CASE WHEN ct.content_no IS NULL THEN '(単一)' ELSE CAST(ct.content_no AS TEXT) END",
        $periodClause, $periodParams, $testFilter
    );

    $company = report_detail_shape($companyRows, 'company');
    $position = report_detail_shape($positionRows, 'position');
    $content = report_detail_shape($contentRows, 'content_no');

    return [
        'campaign_id' => $campaignId,
        'summary' => report_detail_totals($company),
        'by_company' => $company,
        'by_position' => $position,
        'by_content' => $content,
        'timeline' => report_detail_timeline($campaignId, $tenantId, $periodClause, $periodParams),
        'generated_at' => date('Y-m-d H:i:s'),
    ];
}

/** キャンペーンの確定済みスナップショットを返す(なければ null)。 */
function report_snapshot_of(int $campaignId, int $tenantId): ?array
{
    return Db::one(
        'SELECT payload, committed_at, committed_by FROM campaign_report_snapshots WHERE campaign_id = ? AND tenant_id = ?',
        [$campaignId, $tenantId]
    );
}

function report_handle_detail(): never
{
    $user = require_role('viewer');
    $campaignId = report_query_int('campaign_id');
    if ($campaignId === null) {
        json_error('campaign_id は必須です', 400);
    }
    $tenantId = effective_tenant_id($user, isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : null);
    assert_campaign_owned($campaignId, $tenantId);

    // test_filter: prod(既定,本番のみ) / test(テストのみ) / all(全部)。
    // all配信をテストパターンで送った場合、テスト対象者の開封をビーコン(コンテンツ)別に
    // 見るために test/all を指定する。prod は従来動作(本番のみ)。
    $testFilter = $_GET['test_filter'] ?? 'prod';
    if (!in_array($testFilter, ['prod', 'test', 'all'], true)) { $testFilter = 'prod'; }

    // 確定済みなら固定スナップショットを返す(以後 EventIngest/対象者削除/ユーザ削除でも数値不変)。
    // 期間フィルタ指定時はリアルタイム集計を許す(確定値は全期間のため、部分期間の探索表示は別扱い)。
    // スナップショットは本番(prod)定義で確定するため、test/all の探索表示もリアルタイム集計を使う。
    $hasPeriod = (isset($_GET['start_date']) && $_GET['start_date'] !== '')
        || (isset($_GET['end_date']) && $_GET['end_date'] !== '');
    if (!$hasPeriod && $testFilter === 'prod') {
        $snap = report_snapshot_of($campaignId, $tenantId);
        if ($snap !== null) {
            $data = json_decode((string) $snap['payload'], true);
            if (is_array($data)) {
                $data['success'] = true;
                $data['is_committed'] = true;
                $data['committed_at'] = $snap['committed_at'];
                json_out($data);
            }
        }
    }

    [$periodClause, $periodParams] = report_detail_period();
    $data = report_compute_detail($campaignId, $tenantId, $periodClause, $periodParams, $testFilter);
    $data['success'] = true;
    $data['is_committed'] = false;
    json_out($data);
}

/**
 * 個人別統計(全キャンペーン横断)。対象者ごとに参加/開封/クリック/認証の回数と率を集計する。
 * 「よく開封する人」のワーストランキング用。アーカイブ済み対象者も含める(退職者も履歴に残す)。
 * open/click/auth は (campaign_id, tracking_id) 単位の DISTINCT = 1キャンペーン1人1回カウント。
 *
 * テストユーザ(is_test=1)は本番統計に混ぜないため既定で除外する。
 * ?include_test=1 で検証時のみ含められる(campaigns.is_test と同じ思想)。
 */
function report_handle_individuals(): never
{
    $user = require_role('viewer');
    $tenantId = effective_tenant_id($user, isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : null);
    $limit = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 100;
    $includeTest = isset($_GET['include_test']) && $_GET['include_test'] === '1';
    $testWhere = $includeTest ? '' : ' AND t.is_test = 0';

    $rows = Db::all(
        "SELECT t.id, t.email, t.name, t.company, t.department, t.position_category, t.status, t.is_test,
                COUNT(DISTINCT ct.campaign_id) AS campaigns,
                COUNT(DISTINCT CASE WHEN e.event_type = 'open'  THEN e.campaign_id END) AS opens,
                COUNT(DISTINCT CASE WHEN e.event_type = 'click' THEN e.campaign_id END) AS clicks,
                COUNT(DISTINCT CASE WHEN e.event_type = 'auth'  THEN e.campaign_id END) AS auths
         FROM targets t
         INNER JOIN campaign_targets ct ON ct.target_id = t.id
         LEFT JOIN events e
                ON e.tracking_id = ct.tracking_id
               AND e.campaign_id = ct.campaign_id
               AND e.tenant_id = ?
               AND e.event_type IN ('open','click','auth')
         WHERE t.tenant_id = ?" . $testWhere . "
         GROUP BY t.id
         HAVING campaigns > 0
         ORDER BY opens DESC, auths DESC, clicks DESC
         LIMIT ?",
        [$tenantId, $tenantId, $limit]
    );

    $out = [];
    foreach ($rows as $r) {
        $campaigns = (int) $r['campaigns'];
        $opens = (int) $r['opens'];
        $clicks = (int) $r['clicks'];
        $auths = (int) $r['auths'];
        $out[] = [
            'target_id' => (int) $r['id'],
            'email' => (string) $r['email'],
            'name' => (string) ($r['name'] ?? ''),
            'company' => (string) ($r['company'] ?? ''),
            'department' => (string) ($r['department'] ?? ''),
            'position_category' => $r['position_category'],
            'status' => (string) $r['status'],
            'is_test' => (int) $r['is_test'],
            'campaigns' => $campaigns,
            'opens' => $opens,
            'clicks' => $clicks,
            'auths' => $auths,
            'open_rate' => report_detail_rate($opens, $campaigns),
            'click_rate' => report_detail_rate($clicks, $campaigns),
            'auth_rate' => report_detail_rate($auths, $campaigns),
        ];
    }
    json_out(['success' => true, 'individuals' => $out, 'generated_at' => date('Y-m-d H:i:s')]);
}

/**
 * ビーコン(tracking_id)単位の開封明細。
 *
 * 配信方式=all(全員に全コンテンツ)では 1人×N コンテンツが別々の tracking_id で
 * 送られる。人物単位の集計では「1人が何パターン開いたか」が潰れるため、ここでは
 * tracking_id ごとに1行(対象者・コンテンツ番号・開封/クリック/認証の有無と日時)を返す。
 * テスト送信の全パターン確認が主用途なので is_test で絞らず全ビーコンを出す。
 */
function report_handle_beacons(): never
{
    $user = require_role('viewer');
    $campaignId = report_query_int('campaign_id');
    if ($campaignId === null) {
        json_error('campaign_id は必須です', 400);
    }
    $tenantId = effective_tenant_id($user, isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : null);
    assert_campaign_owned($campaignId, $tenantId);

    // tracking_id 単位。events を event_type ごとに有無(MAX)と最初の発生時刻(MIN)で集約。
    $rows = Db::all(
        "SELECT ct.tracking_id, ct.content_no, ct.send_status,
                t.name, t.email, t.company, t.department, t.position_category, t.is_test,
                MAX(CASE WHEN e.event_type = 'open'  THEN 1 ELSE 0 END) AS opened,
                MAX(CASE WHEN e.event_type = 'click' THEN 1 ELSE 0 END) AS clicked,
                MAX(CASE WHEN e.event_type = 'auth'  THEN 1 ELSE 0 END) AS authed,
                MIN(CASE WHEN e.event_type = 'open'  THEN e.occurred_at END) AS opened_at,
                MIN(CASE WHEN e.event_type = 'click' THEN e.occurred_at END) AS clicked_at,
                MIN(CASE WHEN e.event_type = 'auth'  THEN e.occurred_at END) AS authed_at
         FROM campaign_targets ct
         INNER JOIN campaigns c ON c.id = ct.campaign_id
         INNER JOIN targets t   ON t.id = ct.target_id
         LEFT JOIN events e
                ON e.tracking_id = ct.tracking_id
               AND e.campaign_id = ct.campaign_id
               AND e.tenant_id = ?
               AND e.event_type IN ('open','click','auth')
         WHERE c.tenant_id = ? AND ct.campaign_id = ?
         GROUP BY ct.tracking_id
         ORDER BY t.name, ct.content_no",
        [$tenantId, $tenantId, $campaignId]
    );

    $beacons = [];
    foreach ($rows as $r) {
        $beacons[] = [
            'tracking_id' => (string) $r['tracking_id'],
            'content_no' => $r['content_no'] === null ? null : (int) $r['content_no'],
            'send_status' => (string) $r['send_status'],
            'name' => (string) ($r['name'] ?? ''),
            'email' => (string) ($r['email'] ?? ''),
            'company' => (string) ($r['company'] ?? ''),
            'department' => (string) ($r['department'] ?? ''),
            'position_category' => $r['position_category'],
            'is_test' => (int) $r['is_test'],
            'opened' => (int) $r['opened'] === 1,
            'clicked' => (int) $r['clicked'] === 1,
            'authed' => (int) $r['authed'] === 1,
            'opened_at' => $r['opened_at'],
            'clicked_at' => $r['clicked_at'],
            'authed_at' => $r['authed_at'],
        ];
    }
    // 集計サマリ(ビーコン単位): 全ビーコン数と、開封/クリック/認証されたビーコン数。
    $total = count($beacons);
    $openedCount = 0; $clickedCount = 0; $authedCount = 0;
    foreach ($beacons as $b) {
        if ($b['opened']) { $openedCount++; }
        if ($b['clicked']) { $clickedCount++; }
        if ($b['authed']) { $authedCount++; }
    }
    json_out([
        'success' => true,
        'beacons' => $beacons,
        'summary' => [
            'total' => $total,
            'opened' => $openedCount,
            'clicked' => $clickedCount,
            'authed' => $authedCount,
            'open_rate' => report_detail_rate($openedCount, $total),
            'click_rate' => report_detail_rate($clickedCount, $total),
            'auth_rate' => report_detail_rate($authedCount, $total),
        ],
        'generated_at' => date('Y-m-d H:i:s'),
    ]);
}

/** レポートを確定(コミット)する。現時点の全期間集計をスナップショット保存し、以後値を固定する。 */
function report_handle_commit(): never
{
    $user = require_role('operator');
    tet2_require_csrf();
    $body = report_json_body();
    $campaignId = isset($body['campaign_id']) ? (int) $body['campaign_id'] : report_query_int('campaign_id');
    if ($campaignId === null || $campaignId < 1) {
        json_error('campaign_id は必須です', 400);
    }
    $tenantId = effective_tenant_id($user, isset($body['tenant_id']) ? (int) $body['tenant_id'] : null);
    assert_campaign_owned($campaignId, $tenantId);

    $force = !empty($body['force']);
    $existing = report_snapshot_of($campaignId, $tenantId);
    if ($existing !== null && !$force) {
        json_error('このレポートは既に確定済みです（値は固定されています）', 409);
    }

    // 全期間(期間フィルタなし)の集計を確定値とする。
    $payload = report_compute_detail($campaignId, $tenantId, '', []);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        json_error('スナップショットの生成に失敗しました', 500);
    }

    if ($existing !== null) {
        Db::run(
            'UPDATE campaign_report_snapshots
             SET payload = ?, committed_at = datetime(\'now\',\'localtime\'), committed_by = ?
             WHERE campaign_id = ? AND tenant_id = ?',
            [$json, $user['id'], $campaignId, $tenantId]
        );
    } else {
        Db::run(
            'INSERT INTO campaign_report_snapshots (campaign_id, tenant_id, payload, committed_by)
             VALUES (?, ?, ?, ?)',
            [$campaignId, $tenantId, $json, $user['id']]
        );
    }
    audit('report.commit', 'campaign_id=' . $campaignId . ($force ? ',force=1' : ''));
    json_out(['success' => true, 'is_committed' => true]);
}

/** レポート確定を解除する(誤確定の救済)。tenant_admin 以上。 */
function report_handle_uncommit(): never
{
    $user = require_role('tenant_admin');
    tet2_require_csrf();
    $body = report_json_body();
    $campaignId = isset($body['campaign_id']) ? (int) $body['campaign_id'] : report_query_int('campaign_id');
    if ($campaignId === null || $campaignId < 1) {
        json_error('campaign_id は必須です', 400);
    }
    $tenantId = effective_tenant_id($user, isset($body['tenant_id']) ? (int) $body['tenant_id'] : null);
    assert_campaign_owned($campaignId, $tenantId);
    Db::run('DELETE FROM campaign_report_snapshots WHERE campaign_id = ? AND tenant_id = ?', [$campaignId, $tenantId]);
    audit('report.uncommit', 'campaign_id=' . $campaignId);
    json_out(['success' => true, 'is_committed' => false]);
}

try {
    $body = report_json_body();
    $action = $_GET['action'] ?? ($body['action'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($action === 'ingest' && $method === 'POST') {
        report_handle_ingest();
    }
    if ($action === 'summary' && $method === 'GET') {
        report_handle_summary();
    }
    if ($action === 'campaigns' && $method === 'GET') {
        report_handle_campaigns();
    }
    if ($action === 'detail' && $method === 'GET') {
        report_handle_detail();
    }
    if ($action === 'individuals' && $method === 'GET') {
        report_handle_individuals();
    }
    if ($action === 'beacons' && $method === 'GET') {
        report_handle_beacons();
    }
    if ($action === 'commit' && $method === 'POST') {
        report_handle_commit();
    }
    if ($action === 'uncommit' && $method === 'POST') {
        report_handle_uncommit();
    }
    json_error('不正なアクション', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}

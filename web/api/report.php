<?php declare(strict_types=1); require __DIR__."/../lib/bootstrap.php";
require_once __DIR__."/../lib/EventIngest.php";
require_once __DIR__."/../lib/EduAutoEnroll.php";
require_once __DIR__."/../lib/SimpleXlsx.php";
// 訓練結果 / 訓練結果ログ(明細)の行生成。ログ管理(api/logs.php)と同一ロジックを共有する
// (api/logs.php は末尾でディスパッチが走るため直接 require できない。2026-08-30)。
require_once __DIR__."/../lib/TrainingLogRows.php";
// 返信者(Maildir パース)。ログ管理の同名タブと共有する(2026-08-31)。
// 全テナント混在の superadmin 限定機能のため、出力可否は reply_maildir_export_allowed() で判定する。
require_once __DIR__."/../lib/ReplyMaildir.php";
// 行動履歴と利用者ごとのタブ、判定の修正(段B1)。
require_once __DIR__."/../lib/TrainingActions.php";

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

/**
 * 防衛失敗 = 訓練メールのリンクを踏んで偽サイトを表示した(click)か、認証情報を入力した(auth)。
 * followup.php の「防衛失敗者」と再教育の推奨(report_retrain_rows)と同じ定義で、tracking_id ごとに1回だけ数える
 * (click と auth の両方がある人も1)。防衛失敗率と報告率の分母は対象数(target_count、テストの対象者を除く)。
 *
 * 配信エラー = 送信できなかった宛先。campaign_targets.send_status が failed か deferred、
 * または送信済みになっていない宛先に delivery_log の failed か deferred の記録があるもの。
 * いまのワーカーは成功(sent)だけを DB に書くので、多くの訓練では 0 になる(送信後のバウンスは取り込んでいない)。
 */
const REPORT_FAILURE_EVENTS_SQL = "('click', 'auth')";

function report_summary_from_counts(array $row): array
{
    $targetCount = (int) ($row['target_count'] ?? 0);
    $sentCount = (int) ($row['sent_count'] ?? 0);
    $openCount = (int) ($row['open_count'] ?? 0);
    $clickCount = (int) ($row['click_count'] ?? 0);
    $authCount = (int) ($row['auth_count'] ?? 0);
    $reportCount = (int) ($row['report_count'] ?? 0);
    $failureCount = (int) ($row['failure_count'] ?? 0);
    return [
        'target_count' => $targetCount,
        'sent_count' => $sentCount,
        'sent_rate' => report_rate($sentCount, $targetCount),
        'open_count' => $openCount,
        'open_rate' => report_rate($openCount, $targetCount),
        'click_count' => $clickCount,
        'click_rate' => report_rate($clickCount, $targetCount),
        'auth_count' => $authCount,
        'auth_rate' => report_rate($authCount, $clickCount),
        'report_count' => $reportCount,
        'report_rate' => report_rate($reportCount, $targetCount),
        'failure_count' => $failureCount,
        'failure_rate' => report_rate($failureCount, $targetCount),
        'delivery_error_count' => (int) ($row['delivery_error_count'] ?? 0),
    ];
}

/** 配信エラーの宛先か(campaign_targets の別名 ct に対する条件)。定義は report_summary_from_counts の注記。 */
function report_delivery_error_sql(): string
{
    return "(ct.send_status IN ('failed', 'deferred')
             OR (ct.send_status <> 'sent' AND EXISTS (SELECT 1 FROM delivery_log dl
                 WHERE dl.campaign_id = ct.campaign_id AND dl.tracking_id = ct.tracking_id AND dl.result IN ('failed', 'deferred'))))";
}

/**
 * キャンペーン1件のサマリー(母数と open/click/auth)。
 *
 * 【open の実態: メール開封ではない】
 * ビーコン kunren-beacon-{tracking_id}.png は偽サイトのHTML(bin/master*.html)に埋め込まれており、
 * 訓練メール本文には入っていない(bin/send_email.py は MIMEText(body,'plain') でプレーンテキスト固定。
 * 画像を埋め込めない)。したがって open が立つのは「リンクを踏んで偽サイトを表示した」時であり、
 * click とほぼ同じ事象を指す。実測でも open <= click になる(本来の開封計測なら open > click)。
 * UI では「開封」ではなく「サイト表示」と表示する(2026-08-19 に表記を実態へ修正)。
 * 本当のメール開封率が必要なら HTML メール化が前提になる(現時点では見送り)。
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
             WHERE e.tenant_id = ? AND e.campaign_id = ? AND e.verdict = 'user' AND e.event_type = 'open'
               AND NOT EXISTS (SELECT 1 FROM campaign_targets ct2
                               INNER JOIN targets t2 ON t2.id = ct2.target_id
                               WHERE ct2.tracking_id = e.tracking_id AND t2.is_test = 1)) AS open_count,
            (SELECT COUNT(DISTINCT e.tracking_id)
             FROM events e
             WHERE e.tenant_id = ? AND e.campaign_id = ? AND e.verdict = 'user' AND e.event_type = 'click'
               AND NOT EXISTS (SELECT 1 FROM campaign_targets ct2
                               INNER JOIN targets t2 ON t2.id = ct2.target_id
                               WHERE ct2.tracking_id = e.tracking_id AND t2.is_test = 1)) AS click_count,
            (SELECT COUNT(DISTINCT e.tracking_id)
             FROM events e
             WHERE e.tenant_id = ? AND e.campaign_id = ? AND e.verdict = 'user' AND e.event_type = 'auth'
               AND NOT EXISTS (SELECT 1 FROM campaign_targets ct2
                               INNER JOIN targets t2 ON t2.id = ct2.target_id
                               WHERE ct2.tracking_id = e.tracking_id AND t2.is_test = 1)) AS auth_count,
            (SELECT COUNT(DISTINCT e.tracking_id)
             FROM events e
             WHERE e.tenant_id = ? AND e.campaign_id = ? AND e.verdict = 'user' AND e.event_type = 'report'
               AND NOT EXISTS (SELECT 1 FROM campaign_targets ct2
                               INNER JOIN targets t2 ON t2.id = ct2.target_id
                               WHERE ct2.tracking_id = e.tracking_id AND t2.is_test = 1)) AS report_count,
            (SELECT COUNT(DISTINCT e.tracking_id)
             FROM events e
             WHERE e.tenant_id = ? AND e.campaign_id = ? AND e.verdict = 'user' AND e.event_type IN " . REPORT_FAILURE_EVENTS_SQL . "
               AND NOT EXISTS (SELECT 1 FROM campaign_targets ct2
                               INNER JOIN targets t2 ON t2.id = ct2.target_id
                               WHERE ct2.tracking_id = e.tracking_id AND t2.is_test = 1)) AS failure_count,
            (SELECT COUNT(*)
             FROM campaign_targets ct
             INNER JOIN campaigns c ON c.id = ct.campaign_id
             INNER JOIN targets t ON t.id = ct.target_id
             WHERE c.tenant_id = ? AND ct.campaign_id = ? AND t.is_test = 0 AND " . report_delivery_error_sql() . ") AS delivery_error_count",
        [$tenantId, $campaignId, $tenantId, $campaignId, $tenantId, $campaignId, $tenantId, $campaignId,
         $tenantId, $campaignId, $tenantId, $campaignId, $tenantId, $campaignId, $tenantId, $campaignId]
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
    $campaign = assert_campaign_owned($campaignId, $tenantId);
    $row = report_summary_row($campaignId, $tenantId);
    $summary = $campaign['closed_at'] !== null
        ? report_closed_summary(report_snapshot_of($campaignId, $tenantId), $row)
        : report_summary_from_counts($row);
    json_out(['success' => true, 'summary' => $summary]);
}

/** クローズ後は確定時点のサマリーを返す。旧版snapshotには詳細集計から補完する。 */
function report_closed_summary(?array $snapshot, array $liveRow): array
{
    $data = $snapshot === null ? null : json_decode((string) $snapshot['payload'], true);
    if (!is_array($data)) { throw new RuntimeException('クローズ済みレポートの確定値がありません'); }
    if (isset($data['campaign_summary']) && is_array($data['campaign_summary'])) {
        // 報告率、防衛失敗率、配信エラー(2026-10 に追加)を持たない旧版の確定値は、その項目だけ今の集計で補う
        return $data['campaign_summary'] + report_summary_from_counts($liveRow);
    }
    $detail = $data['summary'] ?? [];
    return report_summary_from_counts([
        'target_count' => (int) ($detail['count'] ?? 0),
        'sent_count' => (int) ($liveRow['sent_count'] ?? 0),
        'open_count' => (int) ($detail['beacon_opened'] ?? 0),
        'click_count' => (int) ($detail['link_clicked'] ?? 0),
        'auth_count' => (int) ($detail['auth_count'] ?? 0),
        'report_count' => (int) ($detail['report_count'] ?? ($liveRow['report_count'] ?? 0)),
        'failure_count' => (int) ($liveRow['failure_count'] ?? 0),
        'delivery_error_count' => (int) ($liveRow['delivery_error_count'] ?? 0),
    ]);
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
        "SELECT c.id, c.tenant_id, c.name, c.status, c.closed_at, c.start_at, c.end_at, c.is_test, c.created_at,
                rs.payload AS report_snapshot_payload,
                COALESCE(ct.target_count, 0) AS target_count,
                COALESCE(ct.sent_count, 0) AS sent_count,
                COALESCE(ev.open_count, 0) AS open_count,
                COALESCE(ev.click_count, 0) AS click_count,
                COALESCE(ev.auth_count, 0) AS auth_count,
                COALESCE(ev.report_count, 0) AS report_count,
                COALESCE(ev.failure_count, 0) AS failure_count,
                COALESCE(ct.delivery_error_count, 0) AS delivery_error_count
         FROM campaigns c
         LEFT JOIN campaign_report_snapshots rs ON rs.campaign_id=c.id AND rs.tenant_id=c.tenant_id AND c.closed_at IS NOT NULL
         LEFT JOIN (
             SELECT ct.campaign_id,
                    COUNT(*) AS target_count,
                    SUM(CASE WHEN ct.send_status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
                    SUM(CASE WHEN " . report_delivery_error_sql() . " THEN 1 ELSE 0 END) AS delivery_error_count
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
                    COUNT(DISTINCT CASE WHEN e.event_type = 'auth' THEN e.tracking_id END) AS auth_count,
                    COUNT(DISTINCT CASE WHEN e.event_type = 'report' THEN e.tracking_id END) AS report_count,
                    COUNT(DISTINCT CASE WHEN e.event_type IN " . REPORT_FAILURE_EVENTS_SQL . " THEN e.tracking_id END) AS failure_count
             FROM events e
             WHERE e.tenant_id = ? AND e.verdict = 'user' AND e.event_type IN ('open', 'click', 'auth', 'report')
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
        $summary = $row['closed_at'] !== null
            ? report_closed_summary(['payload' => $row['report_snapshot_payload']], $row)
            : report_summary_from_counts($row);
        $campaigns[] = array_merge([
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'name' => (string) $row['name'],
            'status' => (string) $row['status'],
            'closed_at' => $row['closed_at'],
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
 * link_rate / beacon_rate は分母 = count(母数)、auth_rate は分母 = link_clicked(クリック数)。
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
                COUNT(DISTINCT CASE WHEN e.event_type = 'auth'  THEN e.tracking_id END) AS auth_count,
                COUNT(DISTINCT CASE WHEN e.event_type = 'report' THEN e.tracking_id END) AS report_count
         FROM campaign_targets ct
         INNER JOIN campaigns c ON c.id = ct.campaign_id
         INNER JOIN targets t   ON t.id = ct.target_id
         LEFT JOIN events e
                ON e.tracking_id = ct.tracking_id
               AND e.campaign_id = ct.campaign_id
               AND e.tenant_id = ?
               AND e.event_type IN ('open','click','auth','report')
               AND e.verdict = 'user'
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
        $report = (int) ($r['report_count'] ?? 0);
        $out[] = [
            $keyName => (string) $r['axis_key'],
            'count' => $count,
            'link_clicked' => $link,
            'beacon_opened' => $beacon,
            'auth_count' => $auth,
            'report_count' => $report,
            'opened' => $link,
            'link_rate' => report_detail_rate($link, $count),
            'beacon_rate' => report_detail_rate($beacon, $count),
            'auth_rate' => report_detail_rate($auth, $link),
            'open_rate' => report_detail_rate($link, $count),
            'report_rate' => report_detail_rate($report, $count),
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
    $report = 0;
    foreach ($rows as $r) {
        $count += (int) $r['count'];
        $link += (int) $r['link_clicked'];
        $beacon += (int) $r['beacon_opened'];
        $auth += (int) $r['auth_count'];
        $report += (int) ($r['report_count'] ?? 0);
    }
    return [
        'count' => $count,
        'link_clicked' => $link,
        'beacon_opened' => $beacon,
        'auth_count' => $auth,
        'report_count' => $report,
        'link_rate' => report_detail_rate($link, $count),
        'beacon_rate' => report_detail_rate($beacon, $count),
        'auth_rate' => report_detail_rate($auth, $link),
        'report_rate' => report_detail_rate($report, $count),
        // 報告数 ÷ クリック数。1.0 を超えるほど「踏むより先に報告する」組織に近い。
        // 失敗率だけを見る従来の指標に対し、正しい行動の伸びを見るための比率。
        'resilience_ratio' => report_resilience_ratio($report, $link),
    ];
}

/**
 * 報告とクリックの比。クリックが0のときは null を返す。
 * 0.0 を返すと「クリック0で報告0(未計測)」と「クリック10で報告0(危険)」が
 * 同じ値になり、改善の判断を誤らせるため。
 */
function report_resilience_ratio(int $reportCount, int $clickCount): ?float
{
    if ($clickCount === 0) {
        return null;
    }
    return round($reportCount / $clickCount, 2);
}

/** 日別タイムライン(click/auth の日次件数 + 累積)。click = ボットUA除外済みのサイト表示。 */
function report_detail_timeline(int $campaignId, int $tenantId, string $periodClause, array $periodParams): array
{
    $sql =
        "SELECT substr(e.occurred_at, 1, 10) AS d,
                COUNT(DISTINCT CASE WHEN e.event_type = 'click' THEN e.tracking_id END) AS beacon,
                COUNT(DISTINCT CASE WHEN e.event_type = 'auth' THEN e.tracking_id END) AS auth
         FROM events e
         WHERE e.tenant_id = ? AND e.campaign_id = ? AND e.verdict = 'user' AND e.event_type IN ('click','auth')
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

    $data = [
        'campaign_id' => $campaignId,
        'summary' => report_detail_totals($company),
        'by_company' => $company,
        'by_position' => $position,
        'by_content' => $content,
        'timeline' => report_detail_timeline($campaignId, $tenantId, $periodClause, $periodParams),
        'generated_at' => date('Y-m-d H:i:s'),
    ];
    report_detail_postprocess($data, $campaignId);
    return $data;
}

/**
 * detail データの表示用後処理。リアルタイム集計と確定スナップショットの両方に適用する
 * (スナップショットは確定時点の構造のまま保存されており、後から足した並び順・件名を持たないため)。
 *  - 役職別: 役員→管理職→一般従業員→その他 の固定順
 *  - コンテンツ別: content_no 昇順 + 件名(subject)付与
 */
function report_detail_postprocess(array &$data, int $campaignId): void
{
    if (isset($data['by_position']) && is_array($data['by_position'])) {
        $posOrder = ['役員' => 0, '管理職' => 1, '一般従業員' => 2];
        usort($data['by_position'], fn ($a, $b) => ($posOrder[$a['position']] ?? 99) <=> ($posOrder[$b['position']] ?? 99));
    }
    if (isset($data['by_content']) && is_array($data['by_content'])) {
        usort($data['by_content'], function ($a, $b) {
            $an = ($a['content_no'] ?? '') === '(単一)' ? -1 : (int) ($a['content_no'] ?? 0);
            $bn = ($b['content_no'] ?? '') === '(単一)' ? -1 : (int) ($b['content_no'] ?? 0);
            return $an <=> $bn;
        });
        // 件名(Subject)を付与。subject_template の content が件名文字列。
        $subjectByNo = [];
        foreach (Db::all(
            'SELECT cc.content_no, tp.content AS subject
             FROM campaign_contents cc
             LEFT JOIN templates tp ON tp.id = cc.subject_template_id
             WHERE cc.campaign_id = ?',
            [$campaignId]
        ) as $r) {
            $subjectByNo[(string) $r['content_no']] = (string) ($r['subject'] ?? '');
        }
        foreach ($data['by_content'] as &$c) {
            $c['subject'] = $subjectByNo[$c['content_no'] ?? ''] ?? ($c['subject'] ?? '');
        }
        unset($c);
    }
}

/** キャンペーンの確定済みスナップショットを返す(なければ null)。 */
function report_snapshot_of(int $campaignId, int $tenantId): ?array
{
    return Db::one(
        'SELECT payload, committed_at, committed_by FROM campaign_report_snapshots WHERE campaign_id = ? AND tenant_id = ?',
        [$campaignId, $tenantId]
    );
}

/** Excel出力用の詳細データを、期間指定の有無に応じて解決する。 */
function report_export_resolve_detail(int $campaignId, int $tenantId): array
{
    [$periodClause, $periodParams] = report_detail_period();
    $campaign = Db::one('SELECT closed_at FROM campaigns WHERE id=? AND tenant_id=?', [$campaignId, $tenantId]);
    if ($campaign !== null && $campaign['closed_at'] !== null && $periodClause !== '') {
        json_error('クローズ済みレポートは期間を変更できません', 409);
    }
    if ($periodClause !== '') {
        return report_compute_detail($campaignId, $tenantId, $periodClause, $periodParams);
    }

    $snap = report_snapshot_of($campaignId, $tenantId);
    if ($snap !== null) {
        $data = json_decode((string) $snap['payload'], true);
        if (is_array($data)) {
            report_detail_postprocess($data, $campaignId);
            return $data;
        }
    }
    return report_compute_detail($campaignId, $tenantId, '', []);
}

/** 期間入力の先頭の日付をファイル名用 YYYYMMDD にする。 */
function report_export_period_date(string $value): string
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($value), $matches) !== 1) {
        return '';
    }
    return $matches[1] . $matches[2] . $matches[3];
}

function report_handle_detail(): never
{
    $user = require_role('viewer');
    $campaignId = report_query_int('campaign_id');
    if ($campaignId === null) {
        json_error('campaign_id は必須です', 400);
    }
    $tenantId = effective_tenant_id($user, isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : null);
    $campaign = assert_campaign_owned($campaignId, $tenantId);

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
    if ($campaign['closed_at'] !== null && ($hasPeriod || $testFilter !== 'prod')) {
        json_error('クローズ済みレポートは確定値のみ表示できます', 409);
    }
    if (!$hasPeriod && $testFilter === 'prod') {
        $snap = report_snapshot_of($campaignId, $tenantId);
        if ($snap !== null) {
            $data = json_decode((string) $snap['payload'], true);
            if (is_array($data)) {
                report_detail_postprocess($data, $campaignId);
                $data['success'] = true;
                $data['is_committed'] = true;
                $data['is_closed'] = $campaign['closed_at'] !== null;
                $data['closed_at'] = $campaign['closed_at'];
                $data['committed_at'] = $snap['committed_at'];
                json_out($data);
            }
        }
    }

    [$periodClause, $periodParams] = report_detail_period();
    $data = report_compute_detail($campaignId, $tenantId, $periodClause, $periodParams, $testFilter);
    $data['success'] = true;
    $data['is_committed'] = false;
    $data['is_closed'] = false;
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
               AND e.verdict = 'user'
         WHERE t.tenant_id = ?" . $testWhere . "
         GROUP BY t.id
         HAVING campaigns > 0
         ORDER BY clicks DESC, auths DESC, opens DESC
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
               AND e.verdict = 'user'
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

/** キャンペーンの ?campaign_id と、利用者のテナントを確かめて返す(viewer 以上の読み取り)。 */
function report_campaign_param(array $user): array
{
    $campaignId = report_query_int('campaign_id');
    if ($campaignId === null) {
        json_error('campaign_id は必須です', 400);
    }
    $tenantId = effective_tenant_id($user, isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : null);
    assert_campaign_owned($campaignId, $tenantId);
    return [$campaignId, $tenantId];
}

/**
 * 行動履歴(1行動1行)。集計に入らない装置の行も判定つきで出す。IP と端末の概要だけを返し、元の行(raw)は返さない。
 */
function report_handle_actions(): never
{
    [$campaignId, $tenantId] = report_campaign_param(require_role('viewer'));
    $result = training_actions_rows($campaignId, $tenantId);
    json_out(['success' => true, 'actions' => $result['rows'], 'counts' => $result['counts'], 'truncated' => $result['truncated'],
        'limit' => TRAINING_ACTIONS_LIMIT]);
}

/** 利用者ごとの1行(報告、返信、初回クリック、配信エラー)。 */
function report_handle_people(): never
{
    [$campaignId, $tenantId] = report_campaign_param(require_role('viewer'));
    json_out(['success' => true, 'people' => training_people_rows($campaignId, $tenantId)]);
}

/**
 * 行動1件の判定を担当者が直す(オペレータ以上、CSRF、監査ログ)。直した判定は次の集計から効く。
 * 確定済みのレポートの確定値は変わらない(確定を解除すると反映される)。
 */
function report_handle_set_verdict(): never
{
    $user = require_role('operator');
    tet2_require_csrf();
    $body = json_body();
    $eventId = isset($body['event_id']) ? (int) $body['event_id'] : 0;
    $verdict = (string) ($body['verdict'] ?? '');
    if ($eventId < 1) {
        json_error('event_id が不正です', 400);
    }
    if (!in_array($verdict, ['user', 'scanner'], true)) {
        json_error('判定は user か scanner です', 400);
    }
    $tenantId = effective_tenant_id($user, isset($body['tenant_id']) ? (int) $body['tenant_id'] : null);
    try {
        $result = training_set_verdict($eventId, $tenantId, $verdict, (int) $user['id']);
    } catch (DomainException $error) {
        json_error($error->getMessage(), in_array($error->getCode(), [404, 409], true) ? $error->getCode() : 409);
    }
    if ($result['changed']) {
        audit('report.event_verdict', json_encode(['tenant_id' => $tenantId] + $result, JSON_UNESCAPED_UNICODE) ?: '');
    }
    json_out(['success' => true, 'changed' => $result['changed'], 'verdict' => $verdict,
        'is_committed' => report_snapshot_of($result['campaign_id'], $tenantId) !== null]);
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
    $campaign = assert_campaign_owned($campaignId, $tenantId);
    $force = !empty($body['force']);
    if ($campaign['closed_at'] !== null) {
        json_error('クローズ済みのキャンペーンは再確定できません', 409);
    }
    if (report_snapshot_of($campaignId, $tenantId) !== null && !$force) {
        json_error('このレポートは既に確定済みです（値は固定されています）', 409);
    }
    // 全期間(期間フィルタなし)の集計を確定値とする。
    $payload = report_compute_detail($campaignId, $tenantId, '', []);
    $payload['campaign_summary'] = report_summary_from_counts(report_summary_row($campaignId, $tenantId));
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        json_error('スナップショットの生成に失敗しました', 500);
    }
    try {
        Db::txImmediate(static function () use ($campaignId, $tenantId, $force, $json, $user): void {
            $campaign = Db::one('SELECT closed_at FROM campaigns WHERE id=? AND tenant_id=? AND deleted_at IS NULL', [$campaignId, $tenantId]);
            if ($campaign === null || $campaign['closed_at'] !== null) {
                throw new DomainException('クローズ済みのキャンペーンは再確定できません');
            }
            $existing = report_snapshot_of($campaignId, $tenantId);
            if ($existing !== null && !$force) {
                throw new DomainException('このレポートは既に確定済みです（値は固定されています）');
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
        });
    } catch (DomainException $error) {
        json_error($error->getMessage(), 409);
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
    try {
        Db::txImmediate(static function () use ($campaignId, $tenantId): void {
            $campaign = Db::one('SELECT closed_at FROM campaigns WHERE id=? AND tenant_id=? AND deleted_at IS NULL', [$campaignId, $tenantId]);
            if ($campaign === null || $campaign['closed_at'] !== null) {
                throw new DomainException('クローズ済みのキャンペーンは確定解除できません');
            }
            Db::run('DELETE FROM campaign_report_snapshots WHERE campaign_id = ? AND tenant_id = ?', [$campaignId, $tenantId]);
        });
    } catch (DomainException $error) {
        json_error($error->getMessage(), 409);
    }
    audit('report.uncommit', 'campaign_id=' . $campaignId);
    json_out(['success' => true, 'is_committed' => false]);
}

/** 確定済みキャンペーンを閉じ、入力本文だけを原子的に消去する。統計は保持する。 */
function report_handle_close(): never
{
    $user = require_role('superadmin');
    tet2_require_csrf();
    $body = report_json_body();
    $campaignId = isset($body['campaign_id']) ? (int) $body['campaign_id'] : report_query_int('campaign_id');
    if ($campaignId === null || $campaignId < 1) {
        json_error('campaign_id は必須です', 400);
    }
    $tenantId = effective_tenant_id($user, isset($body['tenant_id']) ? (int) $body['tenant_id'] : null);
    assert_campaign_owned($campaignId, $tenantId);
    try {
        $result = Db::txImmediate(static function () use ($campaignId, $tenantId, $user): array {
            $campaign = Db::one('SELECT status, closed_at FROM campaigns WHERE id=? AND tenant_id=? AND deleted_at IS NULL', [$campaignId, $tenantId]);
            if ($campaign === null || $campaign['closed_at'] !== null) {
                throw new DomainException('キャンペーンは既にクローズ済みです');
            }
            if (!in_array((string) $campaign['status'], ['done', 'cancelled'], true)) {
                throw new DomainException('配信終了または中止済みのキャンペーンのみクローズできます');
            }
            $pending = Db::one("SELECT 1 FROM send_schedule WHERE campaign_id=? AND status NOT IN ('done','failed','cancelled') LIMIT 1", [$campaignId]);
            if ($pending !== null) {
                throw new DomainException('未処理の送信予定が残っています');
            }
            $snapshot = report_snapshot_of($campaignId, $tenantId);
            $payload = $snapshot === null ? null : json_decode((string) $snapshot['payload'], true);
            if (!is_array($payload) || !isset($payload['summary']) || !is_array($payload['summary'])) {
                throw new DomainException('レポート確定後にクローズしてください');
            }
            if (!isset($payload['campaign_summary'])) {
                // 旧版の確定snapshotには一覧用サマリーがない。本文消去前に一度だけ補完する。
                $payload['campaign_summary'] = report_closed_summary($snapshot, report_summary_row($campaignId, $tenantId));
                Db::run('UPDATE campaign_report_snapshots SET payload=? WHERE campaign_id=? AND tenant_id=?',
                    [json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $campaignId, $tenantId]);
            }
            Db::run("UPDATE campaigns SET closed_at=datetime('now','localtime'), closed_by=? WHERE id=? AND tenant_id=? AND closed_at IS NULL", [$user['id'], $campaignId, $tenantId]);
            $purged = Db::run('DELETE FROM credential_captures WHERE campaign_id=? AND tenant_id=?', [$campaignId, $tenantId]);
            $closed = Db::one('SELECT closed_at FROM campaigns WHERE id=? AND tenant_id=?', [$campaignId, $tenantId]);
            return ['closed_at' => $closed['closed_at'], 'purged_captures' => $purged];
        });
    } catch (DomainException $error) {
        json_error($error->getMessage(), 409);
    }
    audit('campaign.close', 'campaign_id=' . $campaignId . ',purged_captures=' . $result['purged_captures']);
    json_out(['success' => true, 'is_closed' => true, 'closed_at' => $result['closed_at'], 'purged_captures' => $result['purged_captures']]);
}

/**
 * リスク帯別の対象者一覧。「次に誰へ何をすべきか」を出すための入口。
 * 直近のスコアだけを見る(computed_date の最大値)。
 */
function report_handle_risk_individuals(): never
{
    $user = require_role('viewer');
    $tenantId = effective_tenant_id($user, isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : null);
    $limit = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 100;

    $band = $_GET['band'] ?? '';
    if (!in_array($band, ['', 'low', 'medium', 'high'], true)) {
        $band = '';
    }
    $bandWhere = '';

    $latest = Db::one(
        'SELECT MAX(computed_date) AS d FROM human_risk_scores WHERE tenant_id = ?',
        [$tenantId]
    );
    $computedDate = $latest !== null ? $latest['d'] : null;
    if ($computedDate === null) {
        json_out([
            'success' => true,
            'computed_date' => null,
            'individuals' => [],
            'bands' => ['high' => 0, 'medium' => 0, 'low' => 0],
            'total' => 0,
            'limit' => $limit,
        ]);
    }
    $params = [$tenantId, $computedDate];
    if ($band !== '') {
        $bandWhere = ' AND h.band = ?';
        $params[] = $band;
    }

    $rows = Db::all(
        "SELECT t.id, t.email, t.name, t.company, t.position_category,
                h.score, h.band, h.phish_component, h.edu_component, h.report_credit, h.detail
         FROM human_risk_scores h
         INNER JOIN targets t ON t.id = h.target_id AND t.tenant_id = h.tenant_id
         WHERE h.tenant_id = ? AND h.computed_date = ?
           AND t.status = 'active' AND t.is_test = 0" . $bandWhere . "
         ORDER BY h.score DESC, t.id
         LIMIT " . $limit,
        $params
    );
    foreach ($rows as &$r) {
        $r['detail'] = $r['detail'] !== null ? json_decode((string) $r['detail'], true) : null;
    }
    unset($r);

    $bandRows = Db::all(
        "SELECT h.band, COUNT(*) AS cnt
         FROM human_risk_scores h
         INNER JOIN targets t ON t.id = h.target_id AND t.tenant_id = h.tenant_id
         WHERE h.tenant_id = ? AND h.computed_date = ?
           AND t.status = 'active' AND t.is_test = 0
         GROUP BY h.band",
        [$tenantId, $computedDate]
    );
    $bands = ['high' => 0, 'medium' => 0, 'low' => 0];
    foreach ($bandRows as $b) {
        $bands[(string) $b['band']] = (int) $b['cnt'];
    }
    $total = $band === '' ? array_sum($bands) : ($bands[$band] ?? 0);

    json_out([
        'success' => true,
        'computed_date' => $computedDate,
        'bands' => $bands,
        'individuals' => $rows,
        'total' => $total,
        'limit' => $limit,
    ]);
}

/** 最新スナップショットの対象集合で失敗を順位付けしてからlimitを適用する。 */
function report_retrain_rows(int $tenantId, string $date, array $filter): array
{
    $bandWhere = $filter['band'] === '' ? '' : ' AND h.band = ?';
    $params = [$tenantId, $date];
    if ($bandWhere !== '') {
        $params[] = $filter['band'];
    }
    return Db::all(
        "WITH candidates AS (
            SELECT t.id, t.email, t.name, t.company, t.position_category,
                   h.score, h.band, h.phish_component, h.edu_component, h.report_credit, h.detail
            FROM human_risk_scores h
            INNER JOIN targets t ON t.id = h.target_id AND t.tenant_id = h.tenant_id
            WHERE h.tenant_id = ? AND h.computed_date = ?
              AND t.status = 'active' AND t.is_test = 0" . $bandWhere . "
        ), failures AS (
            SELECT ct.target_id, e.event_type, e.occurred_at, c.id AS campaign_id, c.name AS campaign_name,
                   (c.attachment_ext IS NOT NULL AND c.attachment_ext <> '') AS is_attachment,
                   ROW_NUMBER() OVER (PARTITION BY ct.target_id ORDER BY e.occurred_at DESC, e.id DESC) AS rn
            FROM events e
            INNER JOIN campaign_targets ct ON ct.tracking_id = e.tracking_id AND ct.campaign_id = e.campaign_id
            INNER JOIN campaigns c ON c.id = ct.campaign_id AND c.tenant_id = e.tenant_id
            INNER JOIN candidates p ON p.id = ct.target_id
            WHERE e.tenant_id = ? AND c.is_test = 0 AND c.deleted_at IS NULL AND e.verdict = 'user'
              AND e.event_type IN ('click', 'auth') AND e.occurred_at <= ?
        )
        SELECT p.*, f.event_type, f.occurred_at, f.campaign_id, f.campaign_name, f.is_attachment,
               COUNT(*) OVER () AS total_count
        FROM candidates p LEFT JOIN failures f ON f.target_id = p.id AND f.rn = 1
        ORDER BY p.score DESC, f.occurred_at DESC, p.id
        LIMIT " . (int) $filter['limit'],
        [...$params, $tenantId, $date . ' 23:59:59']
    );
}

/** 教育は表示対象者全員を一度に集計する。配信の所属も照合する。 */
function report_retrain_education(int $tenantId, string $date, array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = Db::all(
        "SELECT a.target_id,
                SUM(CASE WHEN a.status NOT IN ('completed', 'expired') THEN 1 ELSE 0 END) AS assigned_count,
                SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                MAX(CASE WHEN a.status = 'completed' THEN a.completed_at END) AS last_completed_at,
                SUM(CASE WHEN a.status <> 'completed' AND d.deadline < ? THEN 1 ELSE 0 END) AS overdue_count
         FROM edu_assignments a
         INNER JOIN edu_deliveries d ON d.id = a.delivery_id AND d.tenant_id = a.tenant_id
         WHERE a.tenant_id = ? AND a.target_id IN ($placeholders)
         GROUP BY a.target_id",
        [$date, $tenantId, ...$ids]
    );
    $result = [];
    foreach ($rows as $row) {
        $result[(int) $row['target_id']] = [
            'assigned_count' => (int) $row['assigned_count'],
            'completed_count' => (int) $row['completed_count'],
            'last_completed_at' => $row['last_completed_at'],
            'overdue_count' => (int) $row['overdue_count'],
        ];
    }
    return $result;
}

function report_retrain_categories(int $tenantId): array
{
    $rows = Db::all(
        'SELECT id, slug, name FROM edu_categories WHERE tenant_id = ? OR tenant_id IS NULL
         ORDER BY tenant_id IS NULL, id', [$tenantId]
    );
    $bySlug = [];
    foreach ($rows as $row) {
        $bySlug[$row['slug']] ??= ['id' => (int) $row['id'], 'slug' => $row['slug'], 'name' => $row['name']];
    }
    return $bySlug;
}

function report_retrain_failure_label(?string $kind): string
{
    return match ($kind) {
        'auth' => '認証情報を入力',
        'click_attachment' => 'クリック（添付型）',
        'click' => 'クリック',
        default => '失敗記録なし',
    };
}

function report_retrain_reason(?array $failure, ?string $kind, array $edu): string
{
    $reason = report_retrain_failure_label($kind);
    if ($failure !== null) {
        $reason .= '（' . date('n/j', strtotime($failure['occurred_at'])) . '）';
    }
    if ($edu['overdue_count'] > 0) {
        return $reason . '。教育 ' . $edu['overdue_count'] . ' 件が期限超過';
    }
    if ($failure !== null) {
        return $reason . ($edu['completed_after_failure'] ? '。失敗後の教育受講済み' : '。失敗後の教育未受講');
    }
    return $reason . '。教育完了 ' . $edu['completed_count'] . ' 件';
}

function report_retrain_enrich(array $row, array $education, array $categories): array
{
    $failure = $row['event_type'] === null ? null : [
        'type' => $row['event_type'], 'occurred_at' => $row['occurred_at'],
        'campaign_id' => (int) $row['campaign_id'], 'campaign_name' => $row['campaign_name'],
        'is_attachment' => (bool) $row['is_attachment'],
    ];
    $kind = $failure === null ? null : ($failure['type'] === 'auth' ? 'auth'
        : ($failure['is_attachment'] ? 'click_attachment' : 'click'));
    $recommended = [];
    foreach (TET2_RETRAIN_CATEGORY_MAP[$kind ?? ''] ?? [] as $slug) {
        if (isset($categories[$slug])) {
            $recommended[] = $categories[$slug];
        }
    }
    $edu = $education[$row['id']] ?? [
        'assigned_count' => 0, 'completed_count' => 0, 'last_completed_at' => null, 'overdue_count' => 0,
    ];
    $edu['completed_after_failure'] = $failure !== null && $edu['last_completed_at'] !== null
        && $edu['last_completed_at'] > $failure['occurred_at'];
    unset($row['event_type'], $row['occurred_at'], $row['campaign_id'], $row['campaign_name'], $row['is_attachment'], $row['total_count']);
    $detail = json_decode((string) ($row['detail'] ?? ''), true);
    $row['detail'] = is_array($detail) ? $detail : [];
    return [...$row, 'last_failure' => $failure, 'failure_kind' => $kind, 'recommended_categories' => $recommended,
        'edu' => $edu, 'reason' => report_retrain_reason($failure, $kind, $edu)];
}

function report_retrain_csv(int $tenantId, array $rows): never
{
    $out = fopen('php://temp', 'r+');
    $header = ['氏名', 'メール', '会社', '役職カテゴリ', 'スコア', '帯', '直近の失敗', '失敗日時',
        'キャンペーン', '推奨カテゴリ', '教育割当', '教育完了', '期限超過', '理由'];
    fputcsv($out, array_map('tet2_csv_sanitize', $header), ',', '"', '');
    foreach ($rows as $row) {
        $cells = [$row['name'], $row['email'], $row['company'], $row['position_category'], $row['score'],
            ['high' => '高', 'medium' => '中', 'low' => '低'][$row['band']] ?? $row['band'],
            report_retrain_failure_label($row['failure_kind']), $row['last_failure']['occurred_at'] ?? '',
            $row['last_failure']['campaign_name'] ?? '', implode('・', array_column($row['recommended_categories'], 'name')),
            $row['edu']['assigned_count'], $row['edu']['completed_count'], $row['edu']['overdue_count'], $row['reason']];
        fputcsv($out, array_map('tet2_csv_sanitize', $cells), ',', '"', '');
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    audit('report.risk_recommendations_csv', 'tenant_id=' . $tenantId . ',rows=' . count($rows));
    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="retrain_' . $tenantId . '_' . date('Ymd') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF" . $csv;
    exit;
}

function report_handle_risk_recommendations(): never
{
    $user = require_role('viewer');
    $tenantId = effective_tenant_id($user, isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : null);
    $limit = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 20;
    $band = $_GET['band'] ?? '';
    if (!in_array($band, ['', 'high', 'medium', 'low'], true)) {
        $band = '';
    }
    $format = $_GET['format'] ?? '';
    if (!in_array($format, ['', 'csv'], true)) {
        json_error('format が不正です', 400);
    }
    $latest = Db::one('SELECT MAX(computed_date) AS d FROM human_risk_scores WHERE tenant_id = ?', [$tenantId]);
    $date = $latest['d'] ?? null;
    $rows = $date === null ? [] : report_retrain_rows($tenantId, $date, ['band' => $band, 'limit' => $limit]);
    $total = (int) ($rows[0]['total_count'] ?? 0);
    if ($rows !== []) {
        $education = report_retrain_education($tenantId, $date, array_column($rows, 'id'));
        $categories = report_retrain_categories($tenantId);
        $rows = array_map(static fn (array $row) => report_retrain_enrich($row, $education, $categories), $rows);
    }
    if ($format === 'csv') {
        report_retrain_csv($tenantId, $rows);
    }
    json_out(['success' => true, 'computed_date' => $date, 'rows' => $rows, 'total' => $total, 'limit' => $limit]);
}

/**
 * 会社別のリスク帯分布。
 * 経産省ガイドライン Ver3.0 のチェック項目 5-10 の実践例
 * 「部門や個人がどのような傾向で間違えるか他部門との違いを示す」に対応する。
 * department は本番でほぼ未設定のため、集計軸は company を使う。
 */
function report_handle_risk_by_company(): never
{
    $user = require_role('viewer');
    $tenantId = effective_tenant_id($user, isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : null);

    $latest = Db::one(
        'SELECT MAX(computed_date) AS d FROM human_risk_scores WHERE tenant_id = ?',
        [$tenantId]
    );
    $computedDate = $latest !== null ? $latest['d'] : null;
    if ($computedDate === null) {
        json_out(['success' => true, 'computed_date' => null, 'companies' => []]);
    }

    $rows = Db::all(
        "SELECT COALESCE(NULLIF(t.company, ''), '(未設定)') AS company,
                COUNT(*) AS count,
                ROUND(AVG(h.score), 1) AS avg_score,
                SUM(CASE WHEN h.band = 'high'   THEN 1 ELSE 0 END) AS high_count,
                SUM(CASE WHEN h.band = 'medium' THEN 1 ELSE 0 END) AS medium_count,
                SUM(CASE WHEN h.band = 'low'    THEN 1 ELSE 0 END) AS low_count
         FROM human_risk_scores h
         INNER JOIN targets t ON t.id = h.target_id AND t.tenant_id = h.tenant_id
         WHERE h.tenant_id = ? AND h.computed_date = ?
           AND t.status = 'active' AND t.is_test = 0
         GROUP BY company
         ORDER BY avg_score DESC",
        [$tenantId, $computedDate]
    );

    json_out(['success' => true, 'computed_date' => $computedDate, 'companies' => $rows]);
}

/**
 * レポートを Excel (.xlsx) でダウンロードする。
 * 8シート構成: サマリー / 会社別 / 役職別 / コンテンツ別 / 日別タイムライン /
 *              訓練結果 / 訓練結果ログ(明細) / 返信者。
 * 後半3シートはログ管理の同名タブと同じ内容で、lib/TrainingLogRows.php と
 * lib/ReplyMaildir.php を共有する(2026-08-30/31 追加)。
 * 返信者だけは全テナントのメールが混在する superadmin 限定機能のため、
 * それ未満の権限ではシートを作るが中身を出さない(理由を1行入れる)。
 * ビーコン別明細はユーザー指示で除外。
 */
function report_handle_export_xlsx(): never
{
    $user = require_role('viewer');
    $campaignId = report_query_int('campaign_id');
    if ($campaignId === null) {
        json_error('campaign_id は必須です', 400);
    }
    $tenantId = effective_tenant_id($user, isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : null);
    assert_campaign_owned($campaignId, $tenantId);

    // キャンペーン名(ファイル名に使う)
    $campaign = Db::one('SELECT name FROM campaigns WHERE id = ? AND tenant_id = ?', [$campaignId, $tenantId]);
    $campaignName = $campaign ? (string) $campaign['name'] : 'campaign_' . $campaignId;

    $start = trim((string) ($_GET['start_date'] ?? ''));
    $end = trim((string) ($_GET['end_date'] ?? ''));
    $hasPeriod = $start !== '' || $end !== '';
    $d = report_export_resolve_detail($campaignId, $tenantId);

    $xlsx = new SimpleXlsx();

    // ---- シート1: サマリー ----
    $s = $d['summary'] ?? [];
    $lc = (int) ($s['link_clicked'] ?? 0);
    $ac = (int) ($s['auth_count'] ?? 0);
    $cnt = (int) ($s['count'] ?? 0);
    $summaryRows = [
        ['項目', '値'],
        ['キャンペーン名', $campaignName],
        ['対象者数', $cnt],
        ['サイト表示数', $lc],
        ['サイト表示率 (%)', $cnt > 0 ? round($lc / $cnt * 100, 1) : 0],
        ['認証数', $ac],
        ['認証率 (認証/表示 %)', $lc > 0 ? round($ac / $lc * 100, 1) : 0],
        ['認証率 (認証/対象数 %)', $cnt > 0 ? round($ac / $cnt * 100, 1) : 0],
        ['報告数', (int) ($s['report_count'] ?? 0)],
        ['報告率 (%)', (float) ($s['report_rate'] ?? 0)],
        ['生成日時', $d['generated_at'] ?? date('Y-m-d H:i:s')],
    ];
    if ($hasPeriod) {
        $periodLabel = $start !== '' && $end !== ''
            ? $start . ' 〜 ' . $end
            : ($start !== '' ? $start . ' 〜' : '〜 ' . $end);
        array_splice($summaryRows, 2, 0, [[
            '集計期間', $periodLabel,
        ]]);
    }
    $xlsx->addSheet('サマリー', $summaryRows, [0 => 28, 1 => 20]);

    // ---- シート2: 会社別 ----
    $companyRows = [['会社', '対象数', 'サイト表示数', 'サイト表示率 (%)', '認証数', '認証率 (認証/表示 %)', '認証率 (認証/対象数 %)', '報告数', '報告率 (%)']];
    foreach (($d['by_company'] ?? []) as $r) {
        $lc = (int) ($r['link_clicked'] ?? 0);
        $ac = (int) ($r['auth_count'] ?? 0);
        $cnt = (int) ($r['count'] ?? 0);
        $companyRows[] = [
            (string) ($r['company'] ?? ''),
            $cnt,
            $lc,
            $cnt > 0 ? round($lc / $cnt * 100, 1) : 0,
            $ac,
            $lc > 0 ? round($ac / $lc * 100, 1) : 0,
            $cnt > 0 ? round($ac / $cnt * 100, 1) : 0,
            (int) ($r['report_count'] ?? 0),
            (float) ($r['report_rate'] ?? 0),
        ];
    }
    $xlsx->addSheet('会社別', $companyRows, [0 => 24, 1 => 10, 2 => 14, 3 => 16, 4 => 10, 5 => 20, 6 => 20, 7 => 12, 8 => 14]);

    // ---- シート3: 役職別 ----
    $posRows = [['役職', '対象数', 'サイト表示数', 'サイト表示率 (%)', '認証数', '認証率 (認証/表示 %)', '認証率 (認証/対象数 %)', '報告数', '報告率 (%)']];
    foreach (($d['by_position'] ?? []) as $r) {
        $lc = (int) ($r['link_clicked'] ?? 0);
        $ac = (int) ($r['auth_count'] ?? 0);
        $cnt = (int) ($r['count'] ?? 0);
        $posRows[] = [
            (string) ($r['position'] ?? ''),
            $cnt,
            $lc,
            $cnt > 0 ? round($lc / $cnt * 100, 1) : 0,
            $ac,
            $lc > 0 ? round($ac / $lc * 100, 1) : 0,
            $cnt > 0 ? round($ac / $cnt * 100, 1) : 0,
            (int) ($r['report_count'] ?? 0),
            (float) ($r['report_rate'] ?? 0),
        ];
    }
    $xlsx->addSheet('役職別', $posRows, [0 => 16, 1 => 10, 2 => 14, 3 => 16, 4 => 10, 5 => 20, 6 => 20, 7 => 12, 8 => 14]);

    // ---- シート4: コンテンツ別 ----
    $contentRows = [['コンテンツNo', '件名', '対象数', 'サイト表示数', 'サイト表示率 (%)', '認証数', '認証率 (認証/表示 %)', '認証率 (認証/対象数 %)', '報告数', '報告率 (%)']];
    foreach (($d['by_content'] ?? []) as $r) {
        $lc = (int) ($r['link_clicked'] ?? 0);
        $ac = (int) ($r['auth_count'] ?? 0);
        $cnt = (int) ($r['count'] ?? 0);
        $contentRows[] = [
            (string) ($r['content_no'] ?? ''),
            (string) ($r['subject'] ?? ''),
            $cnt,
            $lc,
            $cnt > 0 ? round($lc / $cnt * 100, 1) : 0,
            $ac,
            $lc > 0 ? round($ac / $lc * 100, 1) : 0,
            $cnt > 0 ? round($ac / $cnt * 100, 1) : 0,
            (int) ($r['report_count'] ?? 0),
            (float) ($r['report_rate'] ?? 0),
        ];
    }
    $xlsx->addSheet('コンテンツ別', $contentRows, [0 => 14, 1 => 40, 2 => 10, 3 => 14, 4 => 16, 5 => 10, 6 => 20, 7 => 20, 8 => 12, 9 => 14]);

    // ---- シート5: 日別タイムライン ----
    $tlRows = [['日付', 'サイト表示', '認証', '累積表示', '累積認証']];
    foreach (($d['timeline'] ?? []) as $t) {
        $tlRows[] = [
            (string) ($t['date'] ?? ''),
            (int) ($t['beacon'] ?? 0),
            (int) ($t['auth'] ?? 0),
            (int) ($t['cum_beacon'] ?? 0),
            (int) ($t['cum_auth'] ?? 0),
        ];
    }
    $xlsx->addSheet('日別タイムライン', $tlRows, [0 => 14, 1 => 14, 2 => 10, 3 => 14, 4 => 14]);

    // ---- シート6: 訓練結果 / シート7: 訓練結果ログ(明細) ----
    // ログ管理の同名タブと同じ内容。行生成は lib/TrainingLogRows.php で共有しており、
    // フィルタは $_GET から読む。ここは campaign_id が必須なので、そのキャンペーンに
    // 限定された行だけが入る(logs_campaign_filter が所有権も検証する)。
    // 明細はシステム(サンドボックス/SWG等)の反応を既定で除外する。ログ管理画面と同じく
    // ?exclude_system=0 を付ければ装置の行も含める。
    $trRows = array_merge([training_results_headers()], training_results_rows($tenantId));
    $xlsx->addSheet('訓練結果', $trRows, [0 => 24, 1 => 8, 2 => 28, 3 => 16, 4 => 20, 5 => 16, 6 => 14]);

    $tldSrc = training_log_detail_rows($tenantId);
    $tldRows = array_merge([training_log_detail_headers()], training_log_detail_table_rows($tldSrc));
    $xlsx->addSheet('訓練結果ログ(明細)', $tldRows,
        [0 => 20, 1 => 12, 2 => 8, 3 => 14, 4 => 28, 5 => 16, 6 => 28, 7 => 20, 8 => 10,
         9 => 18, 10 => 14, 11 => 24, 12 => 18, 13 => 18, 14 => 16, 15 => 18, 16 => 22,
         17 => 22, 18 => 18, 19 => 24, 20 => 40]);

    // ---- シート8: 返信者 ----
    // ログ管理の「返信者」タブと同じ内容(訓練メール送信元の Maildir に届いた受信メール)。
    // このレポートExcelは viewer でも出力できるが、返信者は DB ではなくメールサーバの
    // Maildir を直接読むため全テナントのメールが混在し、ログ管理では superadmin 限定に
    // している。そのまま載せると権限の低い利用者に他テナントの差出人・件名が渡るため、
    // superadmin 以外にはシートだけ作って中身を出さない(シート構成は権限で変えない)。
    if (reply_maildir_export_allowed($user)) {
        $replyRows = array_merge([reply_maildir_headers()],
            reply_maildir_table_rows(reply_maildir_compute()['mails']));
    } else {
        $replyRows = [reply_maildir_headers(),
            ['返信者の一覧は superadmin のみ出力できます（全テナントのメールが混在するため）。'
             . 'ログ管理 > 返信者 から superadmin で出力してください。']];
    }
    $xlsx->addSheet('返信者', $replyRows, [0 => 20, 1 => 18, 2 => 28, 3 => 20, 4 => 28, 5 => 28, 6 => 44, 7 => 8]);

    // ファイル名: キャンペーン名を安全にする
    $safeName = preg_replace('/[^\p{L}\p{N}_\-]/u', '_', $campaignName);
    $safeName = mb_substr($safeName, 0, 60);
    $periodDates = array_values(array_filter([
        report_export_period_date($start),
        report_export_period_date($end),
    ], static fn ($date) => $date !== ''));
    $periodSuffix = $hasPeriod && $periodDates !== [] ? '_' . implode('-', $periodDates) : '';
    $filename = 'report_' . $safeName . '_' . date('Ymd') . $periodSuffix . '.xlsx';

    audit('report.export_xlsx', 'campaign_id=' . $campaignId);
    $xlsx->download($filename);
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
    if ($action === 'actions' && $method === 'GET') {
        report_handle_actions();
    }
    if ($action === 'people' && $method === 'GET') {
        report_handle_people();
    }
    if ($action === 'set_verdict' && $method === 'POST') {
        report_handle_set_verdict();
    }
    if ($action === 'risk_individuals' && $method === 'GET') {
        report_handle_risk_individuals();
    }
    if ($action === 'risk_recommendations' && $method === 'GET') {
        report_handle_risk_recommendations();
    }
    if ($action === 'risk_by_company' && $method === 'GET') {
        report_handle_risk_by_company();
    }
    if ($action === 'commit' && $method === 'POST') {
        report_handle_commit();
    }
    if ($action === 'uncommit' && $method === 'POST') {
        report_handle_uncommit();
    }
    if ($action === 'close' && $method === 'POST') {
        report_handle_close();
    }
    if ($action === 'export_xlsx' && $method === 'GET') {
        report_handle_export_xlsx();
    }
    json_error('不正なアクション', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}

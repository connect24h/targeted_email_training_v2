<?php
declare(strict_types=1);

/**
 * 段B1(G01): 装置と判断したクリックを捨てずに verdict='scanner' で残しても、今の集計の数字が1つも変わらないこと。
 *
 * 同じ合成の access.log を2通りに取り込んで、集計を全部比べる。
 *  - 前: 装置の User-Agent の行を先に抜いたログ(= 装置の行を捨てていた今までの取込と同じ結果)
 *  - 後: 装置の行を含むログをそのまま(装置の行は scanner として残る)
 * 同じ秒に装置と利用者の両方がいる行、装置だけの人、装置の User-Agent の開封(開封は数える)、
 * テストの対象者、ほかのテナントを混ぜる。比べるのは訓練の一覧・概要・詳細・個人別・ビーコン明細・
 * 防衛失敗者・訓練結果・ログの訓練イベント・個人のリスクのスコア・教育の自動配信の対象。
 * 最後に、判定を直した行(manual)を取込が上書きしないこと、直すと集計に入ることも確かめる。
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/EventIngest.php';
require_once __DIR__ . '/../lib/TrainingLogRows.php';
require_once __DIR__ . '/../lib/ReplyMaildir.php';
require_once __DIR__ . '/../lib/HumanRiskScore.php';
require_once __DIR__ . '/../lib/EduDeliveryLauncher.php';
load_api('report');
load_api('followup');

$logDir = sys_get_temp_dir() . '/tet2-verdict-' . bin2hex(random_bytes(4));
mkdir($logDir);
putenv('TET2_APACHE_LOG_DIR=' . $logDir);
putenv('TET2_REPORT_MAILDIR=' . $logDir . '/missing');
register_shutdown_function(static function () use ($logDir): void {
    foreach (glob($logDir . '/*') ?: [] as $f) {
        unlink($f);
    }
    @rmdir($logDir);
});

// 対象者 21..25(25 はテスト)、訓練 40(テナント1)。ほかのテナントの訓練 41
foreach ([21, 22, 23, 24, 25] as $id) {
    Db::run("INSERT INTO targets (id, tenant_id, tenant_no, email, name, company, status, is_test) VALUES (?, 1, ?, ?, ?, 'Example Co', 'active', ?)",
        [$id, $id, "v{$id}@example.test", "対象{$id}", $id === 25 ? 1 : 0]);
}
Db::run("INSERT INTO campaigns (id, tenant_id, name, status, created_by, end_at) VALUES (40, 1, '判定の確認', 'done', 1, '2026-09-10 00:00:00')");
Db::run("INSERT INTO campaigns (id, tenant_id, name, status, created_by) VALUES (41, 2, '他社', 'done', 2)");
foreach ([21, 22, 23, 24, 25] as $id) {
    Db::run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, content_no, send_status, sent_at) VALUES (40, ?, ?, 1, 'sent', '2026-09-01 09:00:00')",
        [$id, '40000000' . $id]);
}
Db::run("INSERT INTO campaign_targets (campaign_id, target_id, tracking_id, content_no, send_status, sent_at) VALUES (41, 3, '4100000003', 1, 'sent', '2026-09-01 09:00:00')");

const HUMAN = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36';
const SLACK = 'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)';
const PROOF = 'Mozilla/5.0 Proofpoint-URLDefense';

function vline(string $path, string $ua, string $time, string $ip = '198.51.100.7'): string
{
    // 長い referer でも User-Agent まで raw に残ること(行動履歴で端末を出す)を兼ねて、referer を長めにする
    return $ip . ' - - [01/Sep/2026:' . $time . ' +0900] "GET ' . $path . ' HTTP/1.1" 200 100 "https://mail.example.test/'
        . str_repeat('r', 400) . '" "' . $ua . '"';
}

$lines = [
    vline('/link-4000000021.html', HUMAN, '10:00:00'),               // 利用者のクリック
    vline('/link-4000000021.html', SLACK, '10:00:05'),               // 同じ人の装置のクリック
    vline('/link-4000000022.html', PROOF, '10:01:00', '203.0.113.9'), // 装置だけ(数えない)
    vline('/link-4000000023.html', SLACK, '10:02:00'),               // 同じ秒に装置 → 利用者(装置が先に入る)
    vline('/link-4000000023.html', HUMAN, '10:02:00'),
    vline('/link-4000000024.html', HUMAN, '10:03:00'),               // 同じ秒に利用者 → 装置
    vline('/link-4000000024.html', PROOF, '10:03:00'),
    vline('/kunren-beacon-4000000022.png', SLACK, '10:04:00'),       // 開封は装置の UA でも数える(今までどおり)
    vline('/link-4000000025.html', SLACK, '10:05:00'),               // テストの対象者
    vline('/link-4000000025.html', HUMAN, '10:05:30'),
    vline('/link-4100000003.html', HUMAN, '10:06:00'),               // ほかのテナント
    vline('/link-4100000003.html', PROOF, '10:06:10'),
];
// 報告と認証(ログ以外から入る行)は両方の取込に同じく足す
$fixedEvents = static function (): void {
    Db::run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source) VALUES (1, 40, '4000000021', 'auth', '2026-09-01 10:00:30', 'text_log')");
    Db::run("INSERT INTO events (tenant_id, campaign_id, tracking_id, event_type, occurred_at, source) VALUES (1, 40, '4000000022', 'report', '2026-09-01 11:00:00', 'report_mail')");
};

function verdict_ingest(array $lines): array
{
    file_put_contents(getenv('TET2_APACHE_LOG_DIR') . '/access.log', implode("\n", $lines) . "\n");
    return EventIngest::ingestAll();
}

/** 比べる集計を全部集める。どれも本物の関数・API の入口を通す。 */
function verdict_aggregates(): array
{
    $out = [];
    $get = static function (string $fn, array $query, string $role = 'viewer'): array {
        $_GET = $query;
        $r = call_handler($fn, [], $role, []);
        unset($r['payload']['generated_at']);
        return $r;
    };
    $out['campaigns'] = $get('report_handle_campaigns', ['test_filter' => 'all']);
    $out['summary'] = $get('report_handle_summary', ['campaign_id' => '40']);
    $out['detail'] = $get('report_handle_detail', ['campaign_id' => '40']);
    $out['detail_all'] = $get('report_handle_detail', ['campaign_id' => '40', 'test_filter' => 'all']);
    $out['individuals'] = $get('report_handle_individuals', ['include_test' => '1']);
    $out['beacons'] = $get('report_handle_beacons', ['campaign_id' => '40']);
    $out['failures'] = followup_failures(40, 1);
    $_GET = ['campaign_id' => '40'];
    $out['training_results'] = training_results_rows(1);
    $out['risk'] = array_map(static fn (int $id): array => HumanRiskScore::computeForTarget($id, 1, '2026-09-30'), [21, 22, 23, 24]);
    $out['edu_targets'] = EduDeliveryLauncher::resolveTargets(['target_type' => 'risk', 'phish_campaign_id' => 40, 'risk_results' => null], 1);
    $out['edu_targets_results'] = EduDeliveryLauncher::resolveTargets(['target_type' => 'risk', 'phish_campaign_id' => 40, 'risk_results' => '["opened","submitted","reported","none"]'], 1);
    $out['other_tenant'] = Db::all("SELECT tracking_id, event_type FROM events WHERE tenant_id = 2 AND verdict = 'user' ORDER BY id");
    return $out;
}

// 前: 装置の行を抜いたログ(= 今までの取込)
$humanOnly = array_values(array_filter($lines, static fn (string $l): bool => !str_contains($l, SLACK) && !str_contains($l, PROOF)
    || str_contains($l, 'kunren-beacon')));
verdict_ingest($humanOnly);
$fixedEvents();
$before = verdict_aggregates();
check(Db::one("SELECT COUNT(*) AS n FROM events WHERE verdict = 'scanner'")['n'] === 0, '前: 装置の行は1つもない(今までの取込と同じ)');

// 後: 装置の行を含むログ
Db::run('DELETE FROM events WHERE campaign_id IN (40, 41)');
$counts = verdict_ingest($lines);
$fixedEvents();
$after = verdict_aggregates();
$scanner = (int) Db::one("SELECT COUNT(*) AS n FROM events WHERE verdict = 'scanner'")['n'];
check($scanner === 4, '後: 装置の行は scanner として4件残る(同じ秒に利用者がいた2件は利用者の行動1件として数える)');
check($counts['scanner'] === 5, '取込の結果に装置と判断した件数が出る');
check(Db::one("SELECT verdict_reason FROM events WHERE tracking_id = '4000000022' AND event_type = 'click'")['verdict_reason'] === 'User-Agent に「Proofpoint」',
    '判定の理由に当たった User-Agent の語を残す');
check(str_contains((string) Db::one("SELECT raw FROM events WHERE tracking_id = '4000000022' AND event_type = 'click'")['raw'], 'Proofpoint-URLDefense'),
    '長い referer の行でも raw に User-Agent まで残る');
check(Db::one("SELECT verdict FROM events WHERE tracking_id = '4000000023' AND event_type = 'click'")['verdict'] === 'user',
    '同じ秒に装置が先に入っても、利用者の行があれば利用者の行動として数える');
check(Db::one("SELECT verdict FROM events WHERE tracking_id = '4000000022' AND event_type = 'open'")['verdict'] === 'user',
    '開封(ビーコン)は装置の User-Agent でも今までどおり数える');

foreach ($before as $key => $value) {
    check($value === $after[$key], "集計が変わらない: {$key}");
}
check($after['summary']['payload']['summary']['click_count'] === 3 && $after['summary']['payload']['summary']['failure_count'] === 3,
    '確認: 概要のクリックは利用者の3人(21、23、24)、防衛失敗も3人。装置だけの 22 は入らない');

// 再取込しても増えない(5分の timer が同じログを読み直す)
$again = verdict_ingest($lines);
check($again['click'] === 0 && $again['scanner'] === 0, '同じログを読み直しても行は増えない');

// 判定を直した行(manual)は、取込で上書きしない。直すと集計に入る
Db::run("UPDATE events SET verdict = 'user', verdict_source = 'manual', verdict_by = 1, verdict_at = '2026-09-02 09:00:00'
         WHERE tracking_id = '4000000022' AND event_type = 'click'");
verdict_ingest($lines);
check(Db::one("SELECT verdict || '/' || verdict_source AS v FROM events WHERE tracking_id = '4000000022' AND event_type = 'click'")['v'] === 'user/manual',
    '担当者が直した判定は、取込で装置に戻さない');
$_GET = ['campaign_id' => '40'];
$fixed = call_handler('report_handle_summary', [], 'viewer', []);
check($fixed['payload']['summary']['click_count'] === 4, '利用者に直したクリックは集計に入る(3 → 4)');

// 静的な確かめ: events を読む集計のクエリ(別名 e)は、どれも verdict の条件を持つ(足し忘れの再発防止)
$missing = [];
$files = array_merge(glob(__DIR__ . '/../api/*.php') ?: [], glob(__DIR__ . '/../lib/*.php') ?: [], glob(__DIR__ . '/../db/*.php') ?: []);
foreach ($files as $file) {
    $src = (string) file_get_contents($file);
    if (!preg_match_all('/(FROM|JOIN)\s+events\s+e\b/', $src, $m, PREG_OFFSET_CAPTURE)) {
        continue;
    }
    foreach ($m[0] as [$text, $offset]) {
        // そのクエリを含む関数の中(前の function から次の function まで)に verdict の条件があるか
        $start = (int) strrpos(substr($src, 0, $offset), 'function ');
        $end = strpos($src, 'function ', $offset);
        $body = substr($src, $start, ($end === false ? strlen($src) : $end) - $start);
        if (!str_contains($body, "verdict = 'user'") && !str_contains($body, "verdict='user'")) {
            $missing[] = basename($file) . ':' . (substr_count(substr($src, 0, $offset), "\n") + 1);
        }
    }
}
check($missing === [], 'events を読むクエリはどれも verdict で絞る' . ($missing === [] ? '' : '(抜け: ' . implode(', ', $missing) . ')'));

echo "ALL TESTS PASSED\n";

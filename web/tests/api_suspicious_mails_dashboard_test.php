<?php
declare(strict_types=1);

/**
 * 不審メールの報告ダッシュボード(G11/G39)の回帰テスト。
 * - 月ごとの件数(直近12ヶ月、訓練メール/実メール別)
 * - 状況の内訳
 * - 受付から初動/対応済までの時間(中央値・平均・分布のバケツ)
 * - テナント分離(他テナントの記録を数えない)
 * - update() が状況の変更で first_action_at / resolved_at を残すこと
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/SuspiciousMailStore.php';
require_once __DIR__ . '/../lib/VirusTotalClient.php';
require_once __DIR__ . '/../lib/Secrets.php';
load_api('suspicious_mails');
putenv('TET2_SECRETS_FILE=/nonexistent/secrets.ini');
Secrets::reset();

$thisMonth = date('Y-m');
$prevMonth = date('Y-m', strtotime('first day of -1 month'));

/** 合成の不審メールを1件入れる。created_at と first_action_at / resolved_at を直に指定して時間の計算を決め打ちできる。 */
function smSeed(int $tenant, string $receivedAt, int $isTraining, string $status = 'open',
               ?string $createdAt = null, ?string $firstAction = null, ?string $resolvedAt = null): int
{
    static $n = 0;
    $n++;
    return Db::insert(
        "INSERT INTO suspicious_mails (tenant_id, source, raw_path, sha256, raw_bytes, received_at, is_training,
                analysis_json, findings_json, status, first_action_at, resolved_at, created_at)
         VALUES (?, 'upload', '/x.eml', ?, 10, ?, ?, '{}', '[]', ?, ?, ?, ?)",
        [$tenant, hash('sha256', 'seed' . $n), $receivedAt, $isTraining, $status,
            $firstAction, $resolvedAt, $createdAt ?? $receivedAt]
    );
}

function smCallGet(string $handler, array $get = [], string $role = 'operator'): array
{
    $GLOBALS['__TET2_TEST_ROLE'] = $role;
    $_GET = $get;
    try {
        $handler(require_role('operator'));
    } catch (Tet2TestExit $e) {
        return ['code' => $e->httpCode, 'data' => $e->payload];
    }
    throw new RuntimeException('handler did not exit');
}

// --- 月ごとの件数(テナント1) ---
smSeed(1, $thisMonth . '-05 09:00:00', 1);           // 訓練
smSeed(1, $thisMonth . '-06 09:00:00', 1);           // 訓練
smSeed(1, $thisMonth . '-07 09:00:00', 0);           // 実メール
smSeed(1, $prevMonth . '-10 09:00:00', 0);           // 前月 実メール
// 他テナントの行(数に混ざってはいけない)
smSeed(2, $thisMonth . '-05 09:00:00', 1);
smSeed(2, $thisMonth . '-06 09:00:00', 0);

$r = smCallGet('sm_handle_dashboard');
check($r['code'] === 200 && $r['data']['success'] === true, 'dashboard: 200');
$d = $r['data']['dashboard'];
check(count($d['months']) === 12, 'dashboard: 直近12ヶ月ぶんの月がある');
check($d['months'][11]['month'] === $thisMonth, 'dashboard: 最後の月が当月');
check($d['months'][11]['training'] === 2 && $d['months'][11]['real'] === 1, 'dashboard: 当月は訓練2・実1(自テナントのみ)');
check($d['months'][10]['month'] === $prevMonth && $d['months'][10]['real'] === 1 && $d['months'][10]['training'] === 0,
    'dashboard: 前月は実1・訓練0');
check($d['total'] === 4 && $d['training_total'] === 2 && $d['real_total'] === 2, 'dashboard: 合計・訓練・実の総数(自テナントのみ)');

// テナント2の横断は superadmin のみ。tenant1 の operator では tenant2 の6件は見えない。
check(array_sum(array_map(static fn(array $m): int => $m['training'] + $m['real'], $d['months'])) === 4,
    'dashboard: テナント分離(他テナントの行を数えない)');

// --- 状況の内訳 ---
smSeed(1, $thisMonth . '-08 09:00:00', 0, 'in_progress');
smSeed(1, $thisMonth . '-09 09:00:00', 0, 'resolved');
$d = smCallGet('sm_handle_dashboard')['data']['dashboard'];
check($d['status_counts']['open'] === 4 && $d['status_counts']['in_progress'] === 1 && $d['status_counts']['resolved'] === 1,
    'dashboard: 状況の内訳');

// --- 確認までの時間(中央値・平均・バケツ) ---
// 初動までの経過を 0.5h / 2h / 30h に決め打ち(created_at を固定し first_action_at を足す)。
$base = '2026-03-01 00:00:00';
smSeed(1, $base, 0, 'in_progress', $base, '2026-03-01 00:30:00');   // 0.5h → 1時間未満
smSeed(1, $base, 0, 'in_progress', $base, '2026-03-01 02:00:00');   // 2h   → 1〜4時間
smSeed(1, $base, 0, 'resolved', $base, '2026-03-01 06:00:00', '2026-03-02 06:00:00'); // 初動6h(4〜24時間)、対応済30h(1〜3日)
$d = smCallGet('sm_handle_dashboard')['data']['dashboard'];
$fa = $d['first_action'];
check($fa['count'] === 3, 'dashboard: 初動の対象は3件');
check($fa['median_hours'] === 2.0, 'dashboard: 初動の中央値は2時間');
check(abs($fa['avg_hours'] - round((0.5 + 2 + 6) / 3, 1)) < 0.05, 'dashboard: 初動の平均');
$byLabel = array_column($fa['buckets'], 'count', 'label');
check($byLabel['1時間未満'] === 1 && $byLabel['1〜4時間'] === 1 && $byLabel['4〜24時間'] === 1, 'dashboard: 初動の分布のバケツ');
$rv = $d['resolved'];
check($rv['count'] === 1 && $rv['median_hours'] === 30.0, 'dashboard: 対応済までの中央値は30時間');
$rvByLabel = array_column($rv['buckets'], 'count', 'label');
check($rvByLabel['1〜3日'] === 1, 'dashboard: 対応済は1〜3日のバケツ');

// --- update() が状況の変更で first_action_at / resolved_at を残す ---
$open = smSeed(1, $thisMonth . '-11 09:00:00', 0, 'open');
SuspiciousMailStore::update($open, 1, ['status' => 'in_progress'], 'op@t');
$row = Db::one('SELECT first_action_at, resolved_at FROM suspicious_mails WHERE id=?', [$open]);
check($row['first_action_at'] !== null && $row['resolved_at'] === null, 'update: 初動で first_action_at が入り、resolved_at はまだ');
SuspiciousMailStore::update($open, 1, ['status' => 'resolved'], 'op@t');
$row = Db::one('SELECT first_action_at, resolved_at FROM suspicious_mails WHERE id=?', [$open]);
check($row['resolved_at'] !== null, 'update: 対応済で resolved_at が入る');
$firstActionKept = $row['first_action_at'];
SuspiciousMailStore::update($open, 1, ['status' => 'open'], 'op@t');
$row = Db::one('SELECT first_action_at FROM suspicious_mails WHERE id=?', [$open]);
check($row['first_action_at'] === $firstActionKept, 'update: 初動の時刻は最初の1回だけで、後で変えても上書きしない');

// --- 他テナントの id は自テナントの範囲で見えない(update のテナント分離) ---
$t2 = smSeed(2, $thisMonth . '-05 09:00:00', 0, 'open');
$threw = false;
try {
    SuspiciousMailStore::update($t2, 1, ['status' => 'in_progress'], 'op@t');
} catch (DomainException $e) {
    $threw = $e->getCode() === 404;
}
check($threw, 'update: 他テナントの不審メールは自テナントからは 404');

echo "ALL TESTS PASSED\n";

<?php
declare(strict_types=1);

/**
 * 予約した配信の自動の開始(F0)と、edu_scheduler.php の CLI の回帰テスト。
 *
 * 教育のメールは既定で送らない(send_invites=0)。送信は EduMailer の送信口を差し替えて数え、
 * TET2_EDU_MAIL_DISABLE に頼らずに「1回も呼ばれない」ことを固定する。
 */

require_once __DIR__ . '/helpers.php';

$dbPath = tet2_test_boot();
require_once __DIR__ . '/../lib/EduMailer.php';
require_once __DIR__ . '/../lib/EduScheduler.php';

$mails = [];
EduMailer::useTransport(static function (string $to, string $subject, string $body) use (&$mails): bool {
    $mails[] = $to;
    return true;
});

$now = new DateTimeImmutable('2026-10-01 09:00:00', new DateTimeZone('Asia/Tokyo'));

$categoryId = Db::insert(
    "INSERT INTO edu_categories (tenant_id, name, slug, is_active, is_shared) VALUES (NULL, '小問', 'aw-sched', 1, 1)"
);
for ($i = 1; $i <= 3; $i++) {
    Db::insert(
        "INSERT INTO edu_questions (tenant_id, category_id, title, options, correct_answer, difficulty, is_active, is_shared)
         VALUES (NULL, ?, ?, '[\"A\",\"B\"]', '[0]', 1, 1, 1)",
        [$categoryId, '設問' . $i]
    );
}

function schedDelivery(array $overrides = []): int
{
    $d = array_merge([
        'status' => 'scheduled', 'scheduled_at' => '2026-10-01 08:00:00', 'deadline' => null,
        'send_invites' => 0, 'target_type' => 'all', 'title' => '予約の配信',
    ], $overrides);
    return Db::insert(
        "INSERT INTO edu_deliveries
         (tenant_id, title, status, delivery_type, question_count, randomize, scheduled_at, deadline,
          target_type, triggered_by, send_invites, created_by)
         VALUES (1, ?, ?, 'awareness_quiz', 2, 0, ?, ?, ?, 'manual', ?, 1)",
        [$d['title'], $d['status'], $d['scheduled_at'], $d['deadline'], $d['target_type'], $d['send_invites']]
    );
}

function schedStatus(int $id): string
{
    return (string) Db::one('SELECT status FROM edu_deliveries WHERE id = ?', [$id])['status'];
}

function schedAssigned(int $id): int
{
    return (int) Db::one('SELECT COUNT(*) AS c FROM edu_assignments WHERE delivery_id = ?', [$id])['c'];
}

function schedAudit(string $action, int $deliveryId): array
{
    return Db::all(
        'SELECT tenant_id, user_id, detail FROM audit_log WHERE action = ? AND detail LIKE ?',
        [$action, 'delivery_id=' . $deliveryId . ',%']
    );
}

$due       = schedDelivery();
$dueT      = schedDelivery(['scheduled_at' => '2026-10-01T08:30']);
$future    = schedDelivery(['scheduled_at' => '2099-01-01 00:00:00']);
$expired   = schedDelivery(['deadline' => '2020-06-01']);
$draft     = schedDelivery(['status' => 'draft']);
$noTargets = schedDelivery(['target_type' => 'individual']);

$result = EduScheduler::run($now);

check(schedStatus($due) === 'running', 'F0-1: 予約の日時が来た配信を開始する');
check(schedAssigned($due) === 2, 'F0-1: 全対象者(tenant 1 の2名)へ割り当てる');
check((int) Db::one('SELECT COUNT(*) AS c FROM edu_delivery_questions WHERE delivery_id = ?', [$due])['c'] === 2,
    'F0-1: 設問を確定する');
check(schedStatus($dueT) === 'running', 'F0-2: 画面の日時の書式(T 区切り)でも開始する');
check(schedStatus($future) === 'scheduled', 'F0-3: 予約の日時の前の配信は開始しない');
check(schedStatus($draft) === 'draft', 'F0-4: 下書きの配信は開始しない');
check(schedStatus($expired) === 'scheduled' && schedAssigned($expired) === 0, 'F0-5: 締切を過ぎた配信は開始しない');
check(count(schedAudit('edu_scheduler.skip_expired', $expired)) === 1, 'F0-5: 締切を過ぎたことを監査ログに残す');
check(schedStatus($noTargets) === 'scheduled', 'F0-6: 対象者がいない配信は開始しない');
check(count(schedAudit('edu_scheduler.launch_failed', $noTargets)) === 1, 'F0-6: 開始できなかった理由を監査ログに残す');
check($mails === [], 'F0-7: send_invites=0 の配信では EduMailer の送信が1回も呼ばれない');

$launchAudit = schedAudit('edu_scheduler.launch', $due);
check(count($launchAudit) === 1, 'F0-8: 自動の開始を監査ログに残す');
check($launchAudit[0]['user_id'] === null && (int) $launchAudit[0]['tenant_id'] === 1,
    'F0-8: 自動の処理の監査ログは user_id が NULL で、配信のテナントを持つ');
check($result['launched'] === 2 && $result['skipped_expired'] === 1 && $result['failed'] === 1,
    'F0-9: 結果に開始、締切切れ、失敗の件数を返す');

// 冪等: もう一度動かしても、割当も記録も増えない
$again = EduScheduler::run($now);
check($again['launched'] === 0, 'F0-10: 2回目は開始する配信がない');
check(schedAssigned($due) === 2, 'F0-10: 2回目で割当が増えない');
check(count(schedAudit('edu_scheduler.skip_expired', $expired)) === 1, 'F0-10: 締切切れの記録は1回だけ');
check(count(schedAudit('edu_scheduler.launch_failed', $noTargets)) === 1, 'F0-10: 失敗の記録は1回だけ');

// send_invites=1 の配信だけ、開始時に案内メールを送る
$withMail = schedDelivery(['send_invites' => 1]);
$mailResult = EduScheduler::run($now);
check(schedStatus($withMail) === 'running', 'F0-11: 案内メールありの配信も開始する');
check(count($mails) === 2 && $mailResult['mail_sent'] === 2, 'F0-11: send_invites=1 の配信は割り当てた2名へ案内メールを送る');

// CLI: 1行で結果を出し、TET2_DB_PATH の隔離DBだけを見る
$cliDelivery = schedDelivery(['scheduled_at' => '2020-01-01 00:00:00']);
$cmd = 'TET2_DB_PATH=' . escapeshellarg($dbPath) . ' TET2_EDU_MAIL_DISABLE=1 php '
    . escapeshellarg(__DIR__ . '/../db/edu_scheduler.php') . ' 2>&1';
exec($cmd, $out, $code);
check($code === 0, 'F0-12: CLI は成功で終わる');
check(count($out) === 1 && str_starts_with($out[0], 'edu_scheduler: '), 'F0-12: CLI は結果を1行で出す');
check(str_contains($out[0], 'launched=1'), 'F0-12: CLI が予約の配信を開始した件数を出す');
check(schedStatus($cliDelivery) === 'running', 'F0-12: CLI で予約の配信が開始される');

EduMailer::useTransport(null);
echo "ALL TESTS PASSED\n";

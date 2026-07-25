<?php
/**
 * 未完了受講者への自動リマインド CLI バッチ。
 * cron/systemd timer から日次で呼ぶ想定(無人)。
 *
 *   php db/edu_reminder.php
 *   TET2_DB_PATH=/tmp/xxx.sqlite php db/edu_reminder.php               # 隔離DB検証用
 *   TET2_REMIND_INTERVAL_DAYS=7 php db/edu_reminder.php                # 再送間隔を上書き(既定3日)
 *
 * スパム防止: edu_assignments.last_reminded_at を送信後に更新し、間隔未満は送らない。
 * 締切(deadline)を過ぎた配信は対象外。
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/EduReminder.php';

try {
    $result = EduReminder::run();
    fwrite(STDOUT, sprintf(
        "自動リマインド: 対象 %d 名 / 送信 %d / 失敗 %d\n",
        $result['targets'],
        $result['sent'],
        $result['failed']
    ));
    exit($result['failed'] > 0 ? 1 : 0);
} catch (\Throwable $e) {
    fwrite(STDERR, '自動リマインドに失敗: ' . $e->getMessage() . "\n");
    exit(1);
}

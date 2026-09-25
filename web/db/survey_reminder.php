<?php
/**
 * アンケートの締切前の催促 CLI。案内済みの未回答者に、締切の2日前から締切までの間に1回だけ送る。
 *
 *   php db/survey_reminder.php
 *   TET2_DB_PATH=/tmp/xxx.sqlite php db/survey_reminder.php   # 隔離DB検証用
 *
 * 実在の従業員へメールが届くため、TET2_SURVEY_MAIL_ENABLED=1 のときだけ送る。
 * 既定(未設定)では何も送らずに終了する。systemd timer は用意していない(有効化は利用者の承認後)。
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/SurveyMailer.php';

if (!SurveyMailer::enabled()) {
    fwrite(STDOUT, "アンケート催促: 送信は無効です(TET2_SURVEY_MAIL_ENABLED 未設定)。何も送りません。\n");
    exit(0);
}
try {
    $result = SurveyMailer::remind();
    fwrite(STDOUT, sprintf("アンケート催促: 対象 %d 名 / 送信 %d / 失敗 %d\n", $result['targets'], $result['sent'], $result['failed']));
    exit($result['failed'] > 0 ? 1 : 0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'アンケート催促に失敗: ' . $e->getMessage() . "\n");
    exit(1);
}

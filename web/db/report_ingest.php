<?php
declare(strict_types=1);
/**
 * フィッシング訓練の反応ログ(Apache access.log)を定期取り込みする CLI スクリプト。
 * systemd timer (tet2-report-ingest.timer) から実行される。
 *
 * 開封(kunren-beacon-*.png) / クリック(link-*.html) / 認証(訓練ログ) を events に反映する。
 * これまで手動「ログ取込」ボタンでしか実行されず、反応が反映されない事故があったため自動化した。
 * access.log は root:adm 640。実行ユーザ(training)は adm グループ所属なので読める。
 */
require __DIR__ . '/../lib/Db.php';
require __DIR__ . '/../lib/EventIngest.php';

$counts = EventIngest::ingestAll();
$ts = date('Y-m-d H:i:s');
echo "[{$ts}] ingest " . json_encode($counts, JSON_UNESCAPED_UNICODE) . "\n";

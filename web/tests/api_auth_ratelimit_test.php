<?php
declare(strict_types=1);

/**
 * auth.php の IP 単位レート制限テスト(2026-07-20 OWASP A04 対応)。
 * 同一 IP から短時間に多数のログイン失敗があった場合、
 * (email/アカウントをまたいでも) 次のログインを 429 で拒否することを固定する。
 * パスワードスプレー(多数 email × 各数回)を IP 単位で止める。
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('auth');

$IP = '203.0.113.77'; // テスト用の攻撃元 IP

// audit_log に同一 IP の login.failed を閾値未満(9件)入れる → まだ許可。
for ($i = 0; $i < 9; $i++) {
    Db::run("INSERT INTO audit_log (action, detail, ip, occurred_at) VALUES ('login.failed', ?, ?, datetime('now','localtime'))",
        ['email=spray' . $i . '@x', $IP]);
}
check(auth_ip_rate_limited($IP) === false, '直近失敗9件(閾値10未満)はまだ許可');

// 10件目を追加 → 閾値到達で拒否。
Db::run("INSERT INTO audit_log (action, detail, ip, occurred_at) VALUES ('login.failed', 'email=spray9@x', ?, datetime('now','localtime'))", [$IP]);
check(auth_ip_rate_limited($IP) === true, '直近失敗10件で IP レート制限が発動');

// 別 IP は影響を受けない(道連れBANしない)。
check(auth_ip_rate_limited('198.51.100.1') === false, '無関係な IP は制限されない');

// 古い失敗(window 外 = 30分超前)はカウントしない。
$OLDIP = '203.0.113.88';
for ($i = 0; $i < 20; $i++) {
    Db::run("INSERT INTO audit_log (action, detail, ip, occurred_at) VALUES ('login.failed', 'old', ?, datetime('now','localtime','-40 minutes'))", [$OLDIP]);
}
check(auth_ip_rate_limited($OLDIP) === false, '30分より前の失敗は window 外でカウントしない');

// 成功ログイン(action='login')はカウント対象外(失敗のみ数える)。
$OKIP = '203.0.113.99';
for ($i = 0; $i < 15; $i++) {
    Db::run("INSERT INTO audit_log (action, detail, ip, occurred_at) VALUES ('login', 'ok', ?, datetime('now','localtime'))", [$OKIP]);
}
check(auth_ip_rate_limited($OKIP) === false, '成功ログインはレート制限にカウントしない');

// -----------------------------------------------------------------------
// 列挙型スプレー対策(2026-07-20 ブラウザ実働確認で発見・修正した穴):
// 存在しない email への失敗も login.failed としてカウント対象になること。
// auth.php は $user===null でも audit('login.failed', reason=unknown_user) を
// 記録するよう修正済み。この検証は auth_ip_rate_limited が login.failed を
// email の実在に関わらず ip だけで集計する(=存在しない email 由来でも数える)
// ことで担保される。存在しない email 由来の login.failed を直接入れて確認する。
// (ハーネスの audit() は no-op のため auth_handle_login 経由の記録は実 HTTP 実証
//  側でカバー: 認証済み実HTTPで11回目に429を確認済み。ここは集計側の回帰を固定)
// -----------------------------------------------------------------------
$SPRAYIP = '203.0.113.150';
for ($i = 0; $i < 10; $i++) {
    Db::run("INSERT INTO audit_log (action, detail, ip, occurred_at) VALUES ('login.failed', ?, ?, datetime('now','localtime'))",
        ["email=nouser{$i}@example.com,reason=unknown_user", $SPRAYIP]);
}
check(auth_ip_rate_limited($SPRAYIP) === true, '存在しない email 由来の login.failed も集計され、レート制限が発動');

echo "ALL TESTS PASSED\n";

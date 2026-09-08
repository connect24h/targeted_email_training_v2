<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$dbPath = tet2_test_boot();
$other = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$other->exec('PRAGMA journal_mode = WAL');
$other->exec('PRAGMA busy_timeout = 0');
$other->exec('PRAGMA foreign_keys = ON');

// 本番で起きた順序を再現する。DEFERRED transactionが先に読み、別接続が書いた後では
// 最初のtransactionをwriterへ昇格できず、busy_timeoutを待たずSQLITE_BUSYになる。
$deferredUpgradeFailed = false;
try {
    Db::tx(static function () use ($other): void {
        Db::one('SELECT COUNT(*) AS n FROM targets');
        $other->exec("INSERT INTO audit_log (tenant_id, action) VALUES (1, 'concurrent.writer')");
        Db::run("INSERT INTO audit_log (tenant_id, action) VALUES (1, 'deferred.writer')");
    });
} catch (PDOException $error) {
    $deferredUpgradeFailed = str_contains(strtolower($error->getMessage()), 'database is locked');
}
check($deferredUpgradeFailed, '読取後のDEFERRED書込み昇格で本番のdatabase is lockedを再現');

$concurrentWriterBlocked = false;
$result = Db::txImmediate(static function () use ($other, &$concurrentWriterBlocked): string {
    Db::one('SELECT COUNT(*) AS n FROM targets');
    try {
        $other->exec("INSERT INTO audit_log (tenant_id, action) VALUES (1, 'late.writer')");
    } catch (PDOException $error) {
        $concurrentWriterBlocked = str_contains(strtolower($error->getMessage()), 'database is locked');
    }
    Db::run("INSERT INTO audit_log (tenant_id, action) VALUES (1, 'immediate.writer')");
    return 'committed';
});

check($result === 'committed', 'IMMEDIATE transactionが戻り値を維持してcommit');
check($concurrentWriterBlocked, 'IMMEDIATE transactionが読取前に書込み予約を取得');
check(Db::one("SELECT COUNT(*) AS n FROM audit_log WHERE action='immediate.writer'")['n'] === 1,
    '予約取得後の集計側書込みが成功');
check(Db::one("SELECT COUNT(*) AS n FROM audit_log WHERE action='late.writer'")['n'] === 0,
    '競合writerの部分書込みを残さない');

$callbackErrorPropagated = false;
try {
    Db::txImmediate(static function (): void {
        Db::run("INSERT INTO audit_log (tenant_id, action) VALUES (1, 'rollback.writer')");
        throw new RuntimeException('rollback test');
    });
} catch (RuntimeException $error) {
    $callbackErrorPropagated = $error->getMessage() === 'rollback test';
}
check($callbackErrorPropagated, 'callbackの例外を呼出元へ伝播');
check(Db::one("SELECT COUNT(*) AS n FROM audit_log WHERE action='rollback.writer'")['n'] === 0,
    'callback失敗時にIMMEDIATE transactionをrollback');

Db::run("INSERT INTO audit_log (tenant_id, action) VALUES (1, 'after.rollback')");
check(Db::one("SELECT COUNT(*) AS n FROM audit_log WHERE action='after.rollback'")['n'] === 1,
    'rollback後も同じ接続で書込み可能');

echo "ALL TESTS PASSED\n";

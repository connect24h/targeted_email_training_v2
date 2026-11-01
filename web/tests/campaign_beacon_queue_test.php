<?php
declare(strict_types=1);

// ビーコン生成のキュー往復を検証する。training ランナーは www-data が書いたパスを信用せず、
// campaign_id で DB を引いて正規の data_dir を決める。systemd の path 起動は「0.1 秒間隔で
// ランナーを回す bash ループ」で代用し、生成本体は TET2_BEACON_SCRIPT スタブに差し替える。
require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/CampaignLauncher.php';

$queue = sys_get_temp_dir() . '/tet2-beacon-q-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($queue, 0775);
$dataRoot = $queue . '/data';           // DATA_ROOT 相当(テナント配下の親)
mkdir($dataRoot, 0775);
$runner = realpath(__DIR__ . '/../../bin/tet2-beacon-runner.py');
check($runner !== false, 'ランナースクリプトが存在する');

// ランナーが引く最小 DB(campaigns + tenants だけ)。slug=acme, campaign 777。
$db = $queue . '/mini.sqlite';
$slug = 'acme';
$dataDir = $dataRoot . '/' . $slug . '/campaign_777';
mkdir($dataDir, 0775, true);
$pdo = new PDO('sqlite:' . $db);
$pdo->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, slug TEXT)');
$pdo->exec('CREATE TABLE campaigns (id INTEGER PRIMARY KEY, tenant_id INTEGER, data_dir TEXT)');
$pdo->exec("INSERT INTO tenants (id, slug) VALUES (3, '$slug')");
$pdo->exec("INSERT INTO campaigns (id, tenant_id, data_dir) VALUES (777, 3, '$dataDir')");
$pdo = null;

// 成功スタブ: data_dir/beacon_done を作り rc=0 / 失敗スタブ: stderr+rc=1
$okStub = $queue . '/ok_stub.py';
file_put_contents($okStub, "import sys\n"
    . "i=sys.argv.index('--data-dir'); open(sys.argv[i+1]+'/beacon_done','w').write('x')\n"
    . "print('generated'); sys.exit(0)\n");
$ngStub = $queue . '/ng_stub.py';
file_put_contents($ngStub, "import sys\nsys.stderr.write('No module named qrcode\\n'); sys.exit(1)\n");

$watchFlag = $queue . '/.watch';
$startWatcher = static function (string $stub) use ($runner, $queue, $dataRoot, $db, $watchFlag): void {
    touch($watchFlag);
    $loop = 'for i in $(seq 1 300); do [ -f ' . escapeshellarg($watchFlag) . ' ] || break; '
        . 'TET2_BEACON_QUEUE_DIR=' . escapeshellarg($queue) . ' '
        . 'TET2_BEACON_SCRIPT=' . escapeshellarg($stub) . ' '
        . 'TET2_BEACON_DATA_ROOT=' . escapeshellarg($dataRoot) . ' '
        . 'TET2_BEACON_DB=' . escapeshellarg($db) . ' '
        . '/usr/bin/python3 ' . escapeshellarg($runner) . '; sleep 0.1; done';
    exec('( ' . $loop . ' ) >/dev/null 2>&1 </dev/null &');
};
$stopWatchers = static function () use ($watchFlag): void { @unlink($watchFlag); usleep(300000); };
register_shutdown_function(function () use ($queue, $watchFlag): void {
    @unlink($watchFlag); usleep(300000); exec('rm -rf ' . escapeshellarg($queue));
});

$invoke = static function (int $campaignId, string $beaconBase, float $timeout = 170.0) use ($queue): array {
    putenv('TET2_BEACON_QUEUE_DIR=' . $queue);
    putenv('TET2_BEACON_TIMEOUT=' . $timeout);
    $ref = new ReflectionMethod(CampaignLauncher::class, 'generateBeaconFiles');
    $ref->setAccessible(true);
    $r = $ref->invoke(null, $campaignId, $beaconBase);
    putenv('TET2_BEACON_TIMEOUT'); putenv('TET2_BEACON_QUEUE_DIR');
    return $r;
};

// 1. 成功往復(campaign_id で DB を引いて data_dir を決める)
$startWatcher($okStub);
[$ok, $err] = $invoke(777, 'https://beacon.example.test/');
check($ok === true && $err === '', '生成成功を受け取る: ' . $err);
check(is_file($dataDir . '/beacon_done'), 'DB 由来の data_dir に生成物を作った');
check(glob($queue . '/*.req') === [] && glob($queue . '/*.res') === [], '往復後にキューが空になる');
$stopWatchers();

// 2. 失敗往復(rc!=0 の output を伝える)
$startWatcher($ngStub);
[$ok2, $err2] = $invoke(777, '');
check($ok2 === false && str_contains($err2, 'rc=1') && str_contains($err2, 'qrcode'),
    '生成失敗の rc と理由を伝える: ' . $err2);
$stopWatchers();

// 3. 知らない campaign_id は拒否する(DB に無い)
$startWatcher($okStub);
[$ok3, $err3] = $invoke(99999, '');
check($ok3 === false && str_contains($err3, '見つかりません'), '未知の campaign_id を拒否する: ' . $err3);
$stopWatchers();

// 4. 経路に symlink があれば拒否する(www-data が campaign_<id> をすり替えた場合)
$slug2 = 'goldwin';
$realElsewhere = $queue . '/elsewhere';
mkdir($realElsewhere, 0775);
mkdir($dataRoot . '/' . $slug2, 0775, true);
symlink($realElsewhere, $dataRoot . '/' . $slug2 . '/campaign_888');
$pdo = new PDO('sqlite:' . $db);
$pdo->exec("INSERT INTO tenants (id, slug) VALUES (4, '$slug2')");
$pdo->exec("INSERT INTO campaigns (id, tenant_id, data_dir) VALUES (888, 4, '" . $dataRoot . '/' . $slug2 . "/campaign_888')");
$pdo = null;
$startWatcher($okStub);
[$ok4, $err4] = $invoke(888, '');
check($ok4 === false && str_contains($err4, 'symlink'), '経路の symlink を拒否する: ' . $err4);
check(!is_file($realElsewhere . '/beacon_done'), 'symlink 先に生成物を作らない');
$stopWatchers();

// 5. ランナー不在ならタイムアウトする(短縮して確認)
$t0 = microtime(true);
[$ok5, $err5] = $invoke(777, '', 1.0);
check($ok5 === false && str_contains($err5, 'タイムアウト'), 'ランナー不在でタイムアウトする: ' . $err5);
check(microtime(true) - $t0 < 10, 'タイムアウトが上限で打ち切られる');
check(glob($queue . '/*.req') === [], 'タイムアウト時に依頼ファイルを残さない');

echo "ALL TESTS PASSED\n";

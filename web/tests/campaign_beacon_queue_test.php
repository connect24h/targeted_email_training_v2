<?php
declare(strict_types=1);

// ビーコン生成のキュー往復(Web が .req を置き、training ランナーが .res を返す)を検証する。
// systemd の path 起動は、テストでは「ランナーを 0.1 秒間隔で回す bash ループ」で代用する。
// 生成本体 create_beacon_files.py は TET2_BEACON_SCRIPT のスタブに差し替え、rc の往復だけを見る。
require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/CampaignLauncher.php';

$queue = sys_get_temp_dir() . '/tet2-beacon-q-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($queue, 0775);
$runner = realpath(__DIR__ . '/../../bin/tet2-beacon-runner.py');
check($runner !== false, 'ランナースクリプトが存在する');

// 成功スタブ: data_dir/beacon_done を作り rc=0
$okStub = $queue . '/ok_stub.py';
file_put_contents($okStub, "import sys\n"
    . "i=sys.argv.index('--data-dir'); open(sys.argv[i+1]+'/beacon_done','w').write('x')\n"
    . "print('generated'); sys.exit(0)\n");
// 失敗スタブ: stderr を出して rc=1
$ngStub = $queue . '/ng_stub.py';
file_put_contents($ngStub, "import sys\nsys.stderr.write('No module named qrcode\\n'); sys.exit(1)\n");

$watchFlag = $queue . '/.watch';
$startWatcher = static function (string $queue, string $stub) use ($runner, $watchFlag): void {
    // systemd path 起動の代用。.watch がある間だけ、*.req をランナーで処理する。
    // 背景ジョブは fd を閉じて起動し(shell_exec が待たないように)、最長 30 秒で自然終了する。
    touch($watchFlag);
    $loop = 'for i in $(seq 1 300); do [ -f ' . escapeshellarg($watchFlag) . ' ] || break; '
        . 'TET2_BEACON_QUEUE_DIR=' . escapeshellarg($queue) . ' '
        . 'TET2_BEACON_SCRIPT=' . escapeshellarg($stub) . ' '
        . 'TET2_BEACON_DATA_ROOT=' . escapeshellarg($queue) . ' '
        . '/usr/bin/python3 ' . escapeshellarg($runner) . '; '
        . 'sleep 0.1; done';
    exec('( ' . $loop . ' ) >/dev/null 2>&1 </dev/null &');
};
$stopWatchers = static function () use ($watchFlag): void {
    @unlink($watchFlag); // ループは .watch 消失で次の反復に抜ける
    usleep(300000);
};
register_shutdown_function(function () use ($queue, $watchFlag): void {
    @unlink($watchFlag);
    usleep(300000);
    exec('rm -rf ' . escapeshellarg($queue));
});

$invoke = static function (string $dataDir, string $beaconBase) use ($queue): array {
    putenv('TET2_BEACON_QUEUE_DIR=' . $queue);
    $ref = new ReflectionMethod(CampaignLauncher::class, 'generateBeaconFiles');
    $ref->setAccessible(true);
    $r = $ref->invoke(null, $dataDir, $beaconBase);
    putenv('TET2_BEACON_QUEUE_DIR');
    return $r;
};

// 1. 成功往復
$dataDir = $queue . '/campaign_1';
mkdir($dataDir, 0775);
$startWatcher($queue, $okStub);
[$ok, $err] = $invoke($dataDir, 'https://beacon.example.test/');
check($ok === true && $err === '', '生成成功を受け取る: ' . $err);
check(is_file($dataDir . '/beacon_done'), 'ランナーが data_dir に生成物を作った');
check(glob($queue . '/*.req') === [] && glob($queue . '/*.res') === [], '往復後にキューが空になる');
$stopWatchers();

// 2. 失敗往復(rc!=0 の output を伝える)
$startWatcher($queue, $ngStub);
[$ok2, $err2] = $invoke($dataDir, '');
check($ok2 === false && str_contains($err2, 'rc=1') && str_contains($err2, 'qrcode'),
    '生成失敗の rc と理由を伝える: ' . $err2);
$stopWatchers();

// 2b. 範囲外の data_dir は拒否する(www-data 作成の依頼に細工された場合)
$startWatcher($queue, $okStub);
[$okX, $errX] = $invoke('/etc/cron.d', '');
check($okX === false && str_contains($errX, '許可範囲外'), '範囲外の data_dir を拒否する: ' . $errX);
check(!is_file('/etc/cron.d/beacon_done'), '範囲外には生成物を作らない');
$stopWatchers();

// 2c. .req が symlink なら辿らず拒否する(training 権限での symlink 追従を防ぐ)
$startWatcher($queue, $okStub);
$secret = $queue . '/secret_target';
$reqId = 'sym-' . bin2hex(random_bytes(4));
$payloadFile = $queue . '/payload.json';
file_put_contents($payloadFile, json_encode(['data_dir' => $dataDir, 'beacon_base' => '']));
symlink($payloadFile, $queue . '/' . $reqId . '.req');
$deadline = microtime(true) + 3.0;
while (microtime(true) < $deadline && !is_file($queue . '/' . $reqId . '.res')) {
    usleep(100000);
}
$resRaw = is_file($queue . '/' . $reqId . '.res') ? (string) file_get_contents($queue . '/' . $reqId . '.res') : '';
$res = json_decode($resRaw, true);
check(is_array($res) && (int) ($res['rc'] ?? 0) !== 0, 'symlink の .req は正常処理せずエラーにする: ' . $resRaw);
$stopWatchers();

// 3. ランナー不在ならタイムアウトする(短縮のため deadline を小さくして検証)
$t0 = microtime(true);
putenv('TET2_BEACON_QUEUE_DIR=' . $queue);
$ref = new ReflectionMethod(CampaignLauncher::class, 'generateBeaconFiles');
$ref->setAccessible(true);
// タイムアウト確認は本番の 170 秒だと長いので、環境変数で上限を縮める seam を使う
putenv('TET2_BEACON_DATA_ROOT=' . $queue);
putenv('TET2_BEACON_TIMEOUT=1');
[$ok3, $err3] = $ref->invoke(null, $dataDir, '');
putenv('TET2_BEACON_TIMEOUT');
putenv('TET2_BEACON_DATA_ROOT');
putenv('TET2_BEACON_QUEUE_DIR');
check($ok3 === false && str_contains($err3, 'タイムアウト'), 'ランナー不在でタイムアウトする: ' . $err3);
check(microtime(true) - $t0 < 10, 'タイムアウトが上限で打ち切られる');
check(glob($queue . '/*.req') === [], 'タイムアウト時に依頼ファイルを残さない');

echo "ALL TESTS PASSED\n";

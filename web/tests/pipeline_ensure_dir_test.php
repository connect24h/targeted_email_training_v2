<?php
declare(strict_types=1);

// 送信データの保存先は setgid(2775) で、Web(www-data)と送信worker(training)の双方が書き込む。
// apache2.service の RestrictSUIDSGID=yes 下では setgid を指定する mkdir/chmod が EPERM になるため、
// Apache と同じ制限をかけた子プロセスでも作成できることを確かめる。
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../lib/PipelineRunner.php';

$base = sys_get_temp_dir() . '/tet2-ensure-dir-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($base, 0775);
chmod($base, 02775);
register_shutdown_function(static function () use ($base): void {
    exec('rm -rf ' . escapeshellarg($base));
});
$perms = static function (string $dir): int {
    clearstatcache(true, $dir);
    return fileperms($dir) & 07777;
};

$oldUmask = umask(0022);
PipelineRunner::ensureDir($base . '/campaign_1/logs');
umask($oldUmask);
check($perms($base . '/campaign_1') === 02775 && $perms($base . '/campaign_1/logs') === 02775,
    'umask 022 でも途中の階層を含め 2775 で作る');
check(umask() === $oldUmask, '作成後に umask を元に戻す');

mkdir($base . '/no_group_write', 0755);
chmod($base . '/no_group_write', 02755);
PipelineRunner::ensureDir($base . '/no_group_write');
check($perms($base . '/no_group_write') === 02775, '制限のない環境では既存の権限不足を 2775 に直す');

$runner = trim((string) shell_exec('command -v systemd-run'));
if ($runner === '' || posix_geteuid() !== 0) {
    echo "SKIP: systemd-run が使えないため Apache 同条件の確認を省略\n";
    echo "ALL TESTS PASSED\n";
    exit(0);
}
$child = $base . '/child.php';
file_put_contents($child, '<?php
require ' . var_export(realpath(__DIR__ . '/../lib/PipelineRunner.php'), true) . ';
try { PipelineRunner::ensureDir($argv[1]); echo "OK"; } catch (Throwable $e) { echo "ERR:" . $e->getMessage(); }
');
$sandboxed = static function (string $dir) use ($child): string {
    return (string) shell_exec('systemd-run --quiet --pipe --wait -p RestrictSUIDSGID=yes -p UMask=0022 '
        . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($child) . ' ' . escapeshellarg($dir) . ' 2>&1');
};

$out = $sandboxed($base . '/campaign_2/Attachment');
check($out === 'OK', 'RestrictSUIDSGID 下でも保存先を作れる: ' . $out);
check($perms($base . '/campaign_2') === 02775 && $perms($base . '/campaign_2/Attachment') === 02775,
    'RestrictSUIDSGID 下でも 2775 になる');

$out = $sandboxed($base . '/campaign_2');
check($out === 'OK', 'RestrictSUIDSGID 下で既存の正しい保存先を再利用できる: ' . $out);

mkdir($base . '/broken', 0755);
chmod($base . '/broken', 02755);
$out = $sandboxed($base . '/broken');
check(str_starts_with($out, 'ERR:') && str_contains($out, '2755') && str_contains($out, $base . '/broken'),
    'RestrictSUIDSGID 下で直せない権限不足はパスと権限を示して失敗する: ' . $out);

echo "ALL TESTS PASSED\n";

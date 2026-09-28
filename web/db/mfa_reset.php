<?php
/**
 * 管理画面ユーザの多要素認証を解除する CLI(システム管理者の回復手順)。
 * 端末をなくし、回復コードも使えず、画面で解除できる管理者もいない時に、サーバの root で使う。
 * 解除すると秘密鍵、時刻窓、回復コードを消す。パスワードとロックは変えない(--unlock でロックも外す)。
 *
 *   php web/db/mfa_reset.php --email=admin@example.test               # 確認だけ(変更しない)
 *   php web/db/mfa_reset.php --email=admin@example.test --apply       # 解除する
 *   TET2_DB_PATH=/abs/path/copy.sqlite php web/db/mfa_reset.php ...   # 隔離DBで確かめる
 *
 * 監査ログ(audit_log)に user.mfa_reset_cli を残す。Web からは実行できない。
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/AdminMfa.php';

$email = null;
$apply = false;
$unlock = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--email=')) {
        $email = trim(substr($argument, 8));
    } elseif ($argument === '--apply') {
        $apply = true;
    } elseif ($argument === '--unlock') {
        $unlock = true;
    } elseif ($argument === '--help') {
        fwrite(STDOUT, "Usage: php mfa_reset.php --email=<管理画面ユーザのメール> [--apply] [--unlock]\n");
        fwrite(STDOUT, "--apply なしは確認だけで、DB を変更しません。\n");
        exit(0);
    } else {
        fwrite(STDERR, "不明な引数です: {$argument}\n");
        exit(1);
    }
}
if ($email === null || $email === '') {
    fwrite(STDERR, "--email=<管理画面ユーザのメール> を指定してください\n");
    exit(1);
}

try {
    $user = Db::one(
        "SELECT id, tenant_id, email, role, status, mfa_enabled_at, mfa_secret, locked_until FROM users
         WHERE lower(email) = lower(?) AND role != 'learner'",
        [$email]
    );
    if ($user === null) {
        fwrite(STDERR, "管理画面ユーザが見つかりません: {$email}\n");
        exit(1);
    }
    $state = $user['mfa_enabled_at'] !== null ? '有効（' . $user['mfa_enabled_at'] . ' から）'
        : ($user['mfa_secret'] !== null ? '登録の途中' : '未登録');
    fwrite(STDOUT, sprintf("ユーザ: id=%d role=%s tenant_id=%s 状態=%s 多要素認証=%s ロック=%s\n",
        (int) $user['id'], $user['role'], $user['tenant_id'] ?? '-', $user['status'], $state, $user['locked_until'] ?? 'なし'));
    if (!$apply) {
        fwrite(STDOUT, "[DRY-RUN] 変更していません。解除するには --apply を付けてください\n");
        exit(0);
    }
    $userId = (int) $user['id'];
    $changed = AdminMfa::disable($userId);
    if ($unlock) {
        Db::run('UPDATE users SET failed_count = 0, locked_until = NULL WHERE id = ?', [$userId]);
    }
    $operator = getenv('SUDO_USER') ?: (getenv('USER') ?: 'unknown');
    Db::run(
        'INSERT INTO audit_log (tenant_id, user_id, action, detail, ip) VALUES (?, NULL, ?, ?, ?)',
        [$user['tenant_id'], 'user.mfa_reset_cli',
            'user_id=' . $userId . ',changed=' . ($changed ? 1 : 0) . ',unlock=' . ($unlock ? 1 : 0) . ',os_user=' . $operator, 'cli']
    );
    fwrite(STDOUT, ($changed ? '多要素認証を解除しました' : '多要素認証は登録されていませんでした（変更なし）')
        . ($unlock ? '。ロックも外しました' : '') . "\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'mfa_reset: 失敗 ' . $e->getMessage() . "\n");
    exit(1);
}

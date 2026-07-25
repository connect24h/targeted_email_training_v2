<?php
/**
 * 共有プリセットテンプレートを投入する（tenant_id NULL, is_preset=1）。
 * 既存 /opt/training/bin/master*.html を phish_login preset として取り込む。
 * 冪等: 同名 preset が既にあればスキップ。CLI 実行専用。
 */
declare(strict_types=1);
require __DIR__ . '/../lib/Db.php';

$phishMasters = [
    ['name' => '訓練告知（通常）',     'file' => '/opt/training/bin/master.html',  'auth_flag' => 0],
    ['name' => '偽ログイン（Box）',    'file' => '/opt/training/bin/master2.html', 'auth_flag' => 1],
    ['name' => '偽ログイン（Microsoft 365）', 'file' => '/opt/training/bin/master3.html', 'auth_flag' => 2],
    ['name' => '偽ログイン（Digital Arts）',   'file' => '/opt/training/bin/master4.html', 'auth_flag' => 3],
];

$subjects = [
    ['name' => '【重要】パスワード有効期限のお知らせ', 'content' => '【要対応】アカウントのパスワード有効期限が近づいています'],
    ['name' => '【緊急】セキュリティ通知',             'content' => '【緊急】不審なログインを検知しました。確認をお願いします'],
    ['name' => 'ファイル共有のご案内',                'content' => '共有ファイルが届いています。ご確認ください'],
];

$bodies = [
    ['name' => 'パスワード確認（標準）', 'content' => "いつもお世話になっております。\n\nお使いのアカウントのパスワード有効期限が近づいております。\n下記のリンクより速やかに更新手続きをお願いいたします。\n\n#\$1\$#\n\n※本メールに心当たりがない場合は破棄してください。"],
    ['name' => 'ファイル受領（標準）',   'content' => "お疲れ様です。\n\n共有ファイルが届いています。以下よりご確認ください。\n\n#\$1\$#\n\nよろしくお願いいたします。"],
];

$inserted = 0;
$skipped = 0;

function seed(string $kind, string $name, string $content, ?int $authFlag): array
{
    $exists = Db::one(
        'SELECT id FROM templates WHERE tenant_id IS NULL AND kind = ? AND name = ?',
        [$kind, $name]
    );
    if ($exists !== null) {
        return ['skipped'];
    }
    Db::run(
        'INSERT INTO templates (tenant_id, kind, name, lang, format, content, auth_flag, is_preset)
         VALUES (NULL, ?, ?, ?, ?, ?, ?, 1)',
        [$kind, $name, 'ja', 'html', $content, $authFlag]
    );
    return ['inserted'];
}

foreach ($phishMasters as $m) {
    $content = is_readable($m['file']) ? (string) file_get_contents($m['file']) : '';
    if ($content === '') {
        fwrite(STDERR, "警告: {$m['file']} が読めません。空でスキップ\n");
        $skipped++;
        continue;
    }
    $r = seed('phish_login', $m['name'], $content, $m['auth_flag']);
    $r[0] === 'inserted' ? $inserted++ : $skipped++;
}
foreach ($subjects as $s) {
    $r = seed('subject', $s['name'], $s['content'], null);
    $r[0] === 'inserted' ? $inserted++ : $skipped++;
}
foreach ($bodies as $b) {
    $r = seed('body', $b['name'], $b['content'], null);
    $r[0] === 'inserted' ? $inserted++ : $skipped++;
}

echo "プリセット投入: inserted={$inserted} skipped={$skipped}\n";

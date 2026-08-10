<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
check(file_exists(__DIR__ . '/../api/edu_materials.php'), '教材APIがある');
load_api('edu_materials');

$slides = [
    ['title' => '不審メールに気づく', 'body' => '送信者とURLを確認します。'],
    ['title' => '失敗後の行動', 'body' => '速やかに報告します。'],
];

$r = call_handler('edu_m_handle_create', [
    'title' => '標的型メール訓練フォローアップ',
    'description' => '訓練失敗者向け',
    'slides' => $slides,
], 'operator');
check($r['code'] === 201, 'EM-1: スライド教材を作成できる');
$materialId = (int) $r['payload']['material']['id'];

$r = call_handler('edu_m_handle_update', [
    'id' => $materialId,
    'slides' => [['title' => '差し替え後', 'body' => '新しい教材本文']],
], 'operator');
check($r['code'] === 200, 'EM-2: スライド教材を差し替えられる');
check(count($r['payload']['material']['slides'] ?? []) === 1, 'EM-2: 差し替え後のスライドを返す');

$r = call_handler('edu_m_handle_create', [
    'title' => '不正教材',
    'slides' => [['title' => '', 'body' => '本文']],
], 'operator');
check($r['code'] === 400, 'EM-3: 空タイトルのスライドを拒否する');

Db::run("INSERT INTO edu_materials (tenant_id, title, slides, is_active) VALUES (2, '他テナント教材', '[]', 1)");
$otherId = (int) Db::one('SELECT id FROM edu_materials WHERE tenant_id = 2 ORDER BY id DESC LIMIT 1')['id'];
$r = call_handler('edu_m_handle_update', [
    'id' => $otherId,
    'title' => '不正更新',
], 'operator');
check($r['code'] === 404, 'EM-4: 他テナント教材を更新できない');

echo "ALL TESTS PASSED\n";

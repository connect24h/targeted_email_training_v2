<?php
declare(strict_types=1);

/**
 * キャンペーン編集: シナリオ(コンテンツ)毎に対象を手動割当する manual モード(2026-11-06)。
 *  - content_delivery='manual' + assignments で、各対象者の content_no が指定どおりになる
 *  - グループ指定は保存時にメンバーへ展開される
 *  - 1人1シナリオ排他: 同じ対象が複数 content に出たらエラー
 *  - IDOR: 全体プール外/他テナントの target・group を assignments に混ぜたらエラー
 *  - 未割当のプール対象者は送信対象外(content_no=NULL のまま、エラーにしない)
 *  - distribute/all は従来どおり(後方互換)
 *
 * 既存の api_campaigns_create_test.php と同じシードを使うため、そのセットアップを読み込む。
 * (tet2_test_boot/シード/ヘルパー create・update・actor99 はそちらで定義済み)
 */
require_once __DIR__ . '/api_campaigns_create_test.php';

// api_campaigns_create_test.php は末尾で "ALL TESTS PASSED" を出すが、
// ここでは続けて manual の検証を足す。helpers の check() を使う。

// manual 用の2コンテンツ。parse_contents は配列 index+1 を content_no にする。
$manualContents = [
    ['subject_template_id' => 9001, 'body_template_id' => 9002, 'phish_template_id' => 9003, 'link_mode' => 'link'],
    ['subject_template_id' => 9011, 'body_template_id' => 9012, 'phish_template_id' => 9013, 'link_mode' => 'link'],
];

$contentNoOf = static function (int $campaignId, int $targetId): ?int {
    $row = Db::one('SELECT content_no FROM campaign_targets WHERE campaign_id = ? AND target_id = ?', [$campaignId, $targetId]);
    if ($row === null) {
        return null;
    }
    return $row['content_no'] !== null ? (int) $row['content_no'] : null;
};

// ===== M-1: target_ids を content 毎に手動割当 =====
$r = create(base_body([
    'name' => 'M-1 手動割当',
    'contents' => $manualContents,
    'content_delivery' => 'manual',
    'target_ids' => [9901, 9902],
    'assignments' => [
        '1' => ['target_ids' => [9901]],
        '2' => ['target_ids' => [9902]],
    ],
]));
check($r['code'] === 201, 'M-1: manual 作成が 201');
$cid = (int) $r['payload']['campaign']['id'];
check($contentNoOf($cid, 9901) === 1, 'M-1: 9901 は content_no=1');
check($contentNoOf($cid, 9902) === 2, 'M-1: 9902 は content_no=2');
$deliv = Db::one('SELECT content_delivery FROM campaigns WHERE id = ?', [$cid]);
check(($deliv['content_delivery'] ?? '') === 'manual', 'M-1: content_delivery=manual が保存される');

// ===== M-2: グループ指定はメンバーに展開される =====
// group 9901 = {9901, 9902}。content1 に group、content2 は空。
$r = create(base_body([
    'name' => 'M-2 グループ割当',
    'contents' => $manualContents,
    'content_delivery' => 'manual',
    'group_ids' => [9901],
    'assignments' => [
        '1' => ['group_ids' => [9901]],
        '2' => [],
    ],
]));
check($r['code'] === 201, 'M-2: グループ割当の作成が 201');
$cid2 = (int) $r['payload']['campaign']['id'];
check($contentNoOf($cid2, 9901) === 1 && $contentNoOf($cid2, 9902) === 1,
    'M-2: group のメンバー 9901/9902 がどちらも content_no=1 に展開される');

// ===== M-3: 1人1シナリオ排他。重複割当はエラー =====
$r = create(base_body([
    'name' => 'M-3 重複割当',
    'contents' => $manualContents,
    'content_delivery' => 'manual',
    'target_ids' => [9901, 9902],
    'assignments' => [
        '1' => ['target_ids' => [9901, 9902]],
        '2' => ['target_ids' => [9902]], // 9902 が content1,2 に重複
    ],
]));
check($r['code'] === 400 || $r['code'] === 409, 'M-3: 同一対象が複数シナリオ → エラー(400/409)');

// ===== M-4: IDOR。プール外/他テナントの target を assignments に混ぜたらエラー =====
$r = create(base_body([
    'name' => 'M-4 プール外',
    'contents' => $manualContents,
    'content_delivery' => 'manual',
    'target_ids' => [9901],
    'assignments' => [
        '1' => ['target_ids' => [9901, 9902]], // 9902 はプールに無い
        '2' => [],
    ],
]));
check($r['code'] === 400 || $r['code'] === 404, 'M-4: プール外 target を割当 → エラー');

$r = create(base_body([
    'name' => 'M-4b 他テナント',
    'contents' => $manualContents,
    'content_delivery' => 'manual',
    'target_ids' => [9901],
    'assignments' => [
        '1' => ['target_ids' => [9801]], // 他テナント(98)の target
        '2' => [],
    ],
]));
check($r['code'] === 400 || $r['code'] === 404, 'M-4b: 他テナントの target を割当 → エラー(IDOR防止)');

// ===== M-5: 未割当のプール対象者は content_no=NULL(送信対象外)。エラーにしない =====
$r = create(base_body([
    'name' => 'M-5 未割当あり',
    'contents' => $manualContents,
    'content_delivery' => 'manual',
    'target_ids' => [9901, 9902],
    'assignments' => [
        '1' => ['target_ids' => [9901]],
        '2' => [], // 9902 はどこにも割り当てない
    ],
]));
check($r['code'] === 201, 'M-5: 未割当があっても作成は成功(送信対象の絞り込みに使える)');
$cid5 = (int) $r['payload']['campaign']['id'];
check($contentNoOf($cid5, 9901) === 1, 'M-5: 9901 は content_no=1');
// 重要(fail-open 対策): 未割当者は campaign_targets 行そのものを作らない。
// NULL content_no で行を残すと、PipelineRunner が content_no=1 にフォールバックして
// 「送らないはずの人」に実際にメールが届く(実在従業員への誤送信事故)。
$row9902 = Db::one('SELECT COUNT(*) AS c FROM campaign_targets WHERE campaign_id = ? AND target_id = ?', [$cid5, 9902]);
check((int) ($row9902['c'] ?? -1) === 0, 'M-5: 未割当の 9902 は campaign_targets 行が存在しない(送信対象に出さない)');
$row9901 = Db::one('SELECT COUNT(*) AS c FROM campaign_targets WHERE campaign_id = ? AND target_id = ?', [$cid5, 9901]);
check((int) ($row9901['c'] ?? -1) === 1, 'M-5: 割当済みの 9901 は行が1つある');
// target_count(送信対象の人数)も割当済みの1名だけ。
check((int) ($r['payload']['target_count'] ?? -1) === 1, 'M-5: target_count は割当済みの1名のみ');

// ===== M-6: update でも manual 割当が反映される =====
// まず distribute で作り、update で manual に切り替える。
$r = create(base_body([
    'name' => 'M-6 update',
    'contents' => $manualContents,
    'content_delivery' => 'distribute',
    'target_ids' => [9901, 9902],
]));
check($r['code'] === 201, 'M-6: 下地の distribute 作成が 201');
$cid6 = (int) $r['payload']['campaign']['id'];
$r = update([
    'id' => $cid6,
    'contents' => $manualContents,
    'content_delivery' => 'manual',
    'target_ids' => [9901, 9902],
    'assignments' => [
        '1' => ['target_ids' => [9902]],
        '2' => ['target_ids' => [9901]],
    ],
]);
check($r['code'] === 200, 'M-6: manual への update が 200');
check($contentNoOf($cid6, 9902) === 1 && $contentNoOf($cid6, 9901) === 2,
    'M-6: update 後の割当が反映される(9902→1, 9901→2)');

// ===== M-7: 後方互換。distribute はラウンドロビンのまま =====
$r = create(base_body([
    'name' => 'M-7 distribute 互換',
    'contents' => $manualContents,
    'content_delivery' => 'distribute',
    'target_ids' => [9901, 9902],
]));
check($r['code'] === 201, 'M-7: distribute 作成が 201');
$cid7 = (int) $r['payload']['campaign']['id'];
// koban 順(9901,9902)に content[0],[1] = 1,2
check($contentNoOf($cid7, 9901) === 1 && $contentNoOf($cid7, 9902) === 2,
    'M-7: distribute は従来どおりラウンドロビン(9901→1, 9902→2)');

echo "\n✅ api_campaigns_manual_assign_test 完了\n";

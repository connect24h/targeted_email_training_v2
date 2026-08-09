<?php
declare(strict_types=1);

/**
 * campaigns API の総合デシジョンテーブルテスト。
 * create / update / delete / cancel アクションを網羅的に検証する。
 *
 * Factor A: link_mode × send_mode
 * Factor B: テンプレート指定方式
 * Factor C: ターゲット指定方式
 * Factor D: beacon_base 検証
 * Factor E: split パラメータ
 * Update / Delete / Cancel 操作
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../lib/CampaignDraftFactory.php';

// tet2_test_boot は Db::run(seedSql) を使うが PDO::prepare は単一文しか実行しない。
// そのため boot 後に個別に Db::run() する。
tet2_test_boot();
load_api('campaigns');

// ----- シードデータ挿入 -----
// テスト専用テナント(id=99/98)、テンプレート、ターゲット、グループを用意する。

// テナント
Db::run("INSERT OR IGNORE INTO tenants (id, name, slug, data_dir) VALUES
  (99, 'TestTenant', 'test-tenant-99', '/opt/training/tet2-data/test-tenant-99')");
Db::run("INSERT OR IGNORE INTO tenants (id, name, slug, data_dir) VALUES
  (98, 'OtherTenant', 'other-tenant-98', '/opt/training/tet2-data/other-tenant-98')");

// ユーザー(テナント 99 の operator) ※campaigns.created_by FK のために必要
Db::run("INSERT OR IGNORE INTO users (id, tenant_id, email, password_hash, role) VALUES
  (999, 99, 'test-op@test-tenant-99.local', 'x', 'operator')");

// 共有テンプレート(tenant_id NULL)
Db::run("INSERT OR IGNORE INTO templates (id, tenant_id, kind, name, content, auth_flag) VALUES
  (9001, NULL, 'subject', 'テスト件名テンプレ(shared)', 'subject content', NULL)");
Db::run("INSERT OR IGNORE INTO templates (id, tenant_id, kind, name, content, auth_flag) VALUES
  (9002, NULL, 'body', 'テスト本文テンプレ(shared)', 'body content', NULL)");
Db::run("INSERT OR IGNORE INTO templates (id, tenant_id, kind, name, content, auth_flag) VALUES
  (9003, NULL, 'phish_login', 'テストフィッシュ(shared)', 'phish content', 0)");

// テナント 99 専用テンプレート
Db::run("INSERT OR IGNORE INTO templates (id, tenant_id, kind, name, content, auth_flag) VALUES
  (9011, 99, 'subject', 'テスト件名テンプレ(t99)', 'subject t99', NULL)");
Db::run("INSERT OR IGNORE INTO templates (id, tenant_id, kind, name, content, auth_flag) VALUES
  (9012, 99, 'body', 'テスト本文テンプレ(t99)', 'body t99', NULL)");
Db::run("INSERT OR IGNORE INTO templates (id, tenant_id, kind, name, content, auth_flag) VALUES
  (9013, 99, 'phish_login', 'テストフィッシュ(t99)', 'phish t99', 0)");

// テナント 98 のテンプレート(他テナント → IDOR テスト用)
Db::run("INSERT OR IGNORE INTO templates (id, tenant_id, kind, name, content, auth_flag) VALUES
  (9021, 98, 'subject', 'テスト件名テンプレ(t98)', 'subject t98', NULL)");
Db::run("INSERT OR IGNORE INTO templates (id, tenant_id, kind, name, content, auth_flag) VALUES
  (9022, 98, 'body', 'テスト本文テンプレ(t98)', 'body t98', NULL)");
Db::run("INSERT OR IGNORE INTO templates (id, tenant_id, kind, name, content, auth_flag) VALUES
  (9023, 98, 'phish_login', 'テストフィッシュ(t98)', 'phish t98', 0)");

// テナント 99 のターゲット
Db::run("INSERT OR IGNORE INTO targets (id, tenant_id, email, name) VALUES
  (9901, 99, 'target1@test-tenant-99.local', 'Target One')");
Db::run("INSERT OR IGNORE INTO targets (id, tenant_id, email, name) VALUES
  (9902, 99, 'target2@test-tenant-99.local', 'Target Two')");

// テナント 98 のターゲット(他テナント → IDOR テスト用)
Db::run("INSERT OR IGNORE INTO targets (id, tenant_id, email, name) VALUES
  (9801, 98, 'target1@other-tenant-98.local', 'Other Target')");

// テナント 99 のグループ
Db::run("INSERT OR IGNORE INTO groups (id, tenant_id, name) VALUES
  (9901, 99, 'TestGroup99')");

// グループにターゲットを追加
Db::run("INSERT OR IGNORE INTO target_group (target_id, group_id) VALUES (9901, 9901)");
Db::run("INSERT OR IGNORE INTO target_group (target_id, group_id) VALUES (9902, 9901)");

// ----- ヘルパー -----

/** テナント 99 の actor 配列を返す。 */
function actor99(): array
{
    return ['id' => 999, 'tenant_id' => 99, 'role' => 'operator', 'email' => 'test-op@test-tenant-99.local'];
}

/** キャンペーン作成の最小必須ボディ(テナント 99 向け・legacy 形式)。 */
function base_body(array $overrides = []): array
{
    return array_merge([
        'name'                => 'テストキャンペーン',
        'subject_template_id' => 9001,
        'body_template_id'    => 9002,
        'phish_template_id'   => 9003,
        'from_address'        => 'test@example.com',
        'link_mode'           => 'link',
        'send_mode'           => 'normal',
        'start_at'            => '2099-01-01 09:00:00',
        'end_at'              => '2099-01-31 18:00:00',
    ], $overrides);
}

/** campaigns_handle_create を actor99() で呼ぶショートカット。 */
function create(array $body): array
{
    return call_handler('campaigns_handle_create', $body, 'operator', [actor99()]);
}

/** campaigns_handle_update を actor99() で呼ぶショートカット。 */
function update(array $body): array
{
    return call_handler('campaigns_handle_update', $body, 'operator', [actor99()]);
}

/** campaigns_handle_delete を actor99() で呼ぶショートカット。 */
function delete_campaign(array $body): array
{
    return call_handler('campaigns_handle_delete', $body, 'operator', [actor99()]);
}

/** campaigns_handle_cancel を actor99() で呼ぶショートカット。 */
function cancel_campaign(array $body): array
{
    return call_handler('campaigns_handle_cancel', $body, 'operator', [actor99()]);
}

/** campaigns_handle_duplicate を actor99() で呼ぶショートカット。 */
function duplicate_campaign(array $body): array
{
    return call_handler('campaigns_handle_duplicate', $body, 'operator', [actor99()]);
}

/** create() を実行して作成済みキャンペーン ID を返す。失敗なら例外。 */
function create_draft(array $overrides = []): int
{
    $res = create(base_body($overrides));
    if ($res['code'] !== 201) {
        throw new RuntimeException(
            '下準備用 create 失敗: code=' . $res['code']
            . ' ' . json_encode($res['payload'], JSON_UNESCAPED_UNICODE)
        );
    }
    return (int) $res['payload']['campaign']['id'];
}

// ============================================================
// Factor A: link_mode × send_mode (4×3 = 12 ケース)
// ============================================================
echo "\n=== Factor A: link_mode × send_mode ===\n";

$linkModes = ['link', 'attachment', 'form', 'qr'];
$sendModes = ['normal', 'split', 'slow'];

foreach ($linkModes as $lm) {
    foreach ($sendModes as $sm) {
        $extra = ['link_mode' => $lm, 'send_mode' => $sm, 'name' => "CA {$lm}×{$sm}"];
        if ($sm === 'split') {
            $extra['split_count']        = 2;
            $extra['split_interval_min'] = 15;
        }
        $res = create(base_body($extra));
        check($res['code'] === 201, "CA link_mode={$lm} send_mode={$sm} → 201");
    }
}

// 不正 link_mode → 400
$res = create(base_body(['link_mode' => 'invalid']));
check($res['code'] === 400, 'CA 不正 link_mode → 400');

// 不正 send_mode → 400
$res = create(base_body(['send_mode' => 'turbo']));
check($res['code'] === 400, 'CA 不正 send_mode → 400');

// ============================================================
// Factor B: テンプレート指定方式
// ============================================================
echo "\n=== Factor B: テンプレート指定方式 ===\n";

// CB-1: Legacy 単一コンテンツ (subject/body/phish template IDs) → 201
$res = create(base_body([
    'name'                => 'CB-1',
    'subject_template_id' => 9001,
    'body_template_id'    => 9002,
    'phish_template_id'   => 9003,
]));
check($res['code'] === 201, 'CB-1 legacy 単一コンテンツ → 201');

// CB-2: Multi-content (contents[] 2件) → 201
$res = create(base_body([
    'name'     => 'CB-2',
    'contents' => [
        [
            'subject_template_id' => 9001,
            'body_template_id'    => 9002,
            'phish_template_id'   => 9003,
            'link_mode'           => 'link',
        ],
        [
            'subject_template_id' => 9011,
            'body_template_id'    => 9012,
            'phish_template_id'   => 9013,
            'link_mode'           => 'form',
        ],
    ],
]));
check($res['code'] === 201, 'CB-2 multi-content 2件 → 201');

$baseContent = [
    'subject_template_id' => 9001,
    'body_template_id' => 9002,
    'phish_template_id' => 9003,
    'link_mode' => 'link',
];
$twentyContents = array_fill(0, 20, $baseContent);
$res = create(base_body(['name' => 'CB-20', 'contents' => $twentyContents]));
check($res['code'] === 201, 'CB-20 multi-content 20件 → 201');
$twentyCampaignId = (int) ($res['payload']['campaign']['id'] ?? 0);
check((int) Db::one(
    'SELECT COUNT(*) AS n FROM campaign_contents WHERE campaign_id=?',
    [$twentyCampaignId]
)['n'] === 20, 'CB-20 contentを20件保存する');

$res = create(base_body([
    'name' => 'CB-101',
    'contents' => array_fill(0, 101, $baseContent),
]));
check($res['code'] === 400, 'CB-101 multi-content 101件を拒否する');

// CB-4: テンプレートも contents も指定なし → 400
$res = create([
    'name'         => 'CB-4',
    'from_address' => 'test@example.com',
    'link_mode'    => 'link',
    'send_mode'    => 'normal',
    'start_at'     => '2099-01-01 09:00:00',
    'end_at'       => '2099-01-31 18:00:00',
]);
check($res['code'] === 400, 'CB-4 テンプレート未指定 → 400');

// CB-5: 他テナントの subject テンプレート → 404
$res = create(base_body(['name' => 'CB-5a', 'subject_template_id' => 9021]));
check($res['code'] === 404, 'CB-5 他テナントの subject テンプレート → 404');

// CB-5: 他テナントの body テンプレート → 404
$res = create(base_body(['name' => 'CB-5b', 'body_template_id' => 9022]));
check($res['code'] === 404, 'CB-5 他テナントの body テンプレート → 404');

// CB-5: 他テナントの phish テンプレート → 404
$res = create(base_body(['name' => 'CB-5c', 'phish_template_id' => 9023]));
check($res['code'] === 404, 'CB-5 他テナントの phish テンプレート → 404');

// CB-6: 共有テンプレート(tenant_id NULL) → 201
$res = create(base_body([
    'name'                => 'CB-6',
    'subject_template_id' => 9001,
    'body_template_id'    => 9002,
    'phish_template_id'   => 9003,
]));
check($res['code'] === 201, 'CB-6 共有テンプレート(tenant_id NULL) → 201');

// ============================================================
// Factor C: ターゲット指定方式
// ============================================================
echo "\n=== Factor C: ターゲット指定方式 ===\n";

// CC-1: target_ids のみ → 201
$res = create(base_body(['name' => 'CC-1', 'target_ids' => [9901]]));
check($res['code'] === 201, 'CC-1 target_ids のみ → 201');

// CC-2: group_ids のみ → 201
$res = create(base_body(['name' => 'CC-2', 'group_ids' => [9901]]));
check($res['code'] === 201, 'CC-2 group_ids のみ → 201');

// CC-3: target_ids + group_ids 両方 → 201
$res = create(base_body(['name' => 'CC-3', 'target_ids' => [9901], 'group_ids' => [9901]]));
check($res['code'] === 201, 'CC-3 target_ids + group_ids 両方 → 201');

// CC-4: どちらも指定なし → 201 (0 targets)
$res = create(base_body(['name' => 'CC-4']));
check($res['code'] === 201, 'CC-4 ターゲット未指定(0件) → 201');

// CC-5: 他テナントのターゲット → 404
$res = create(base_body(['name' => 'CC-5', 'target_ids' => [9801]]));
check($res['code'] === 404, 'CC-5 他テナントのターゲット → 404');

// ============================================================
// Factor D: beacon_base 検証
// ============================================================
echo "\n=== Factor D: beacon_base 検証 ===\n";

// CD-1: 有効な https URL → 201
$res = create(base_body(['name' => 'CD-1', 'beacon_base' => 'https://beacon.example.com']));
check($res['code'] === 201, 'CD-1 有効 https URL → 201');

// CD-2: 有効な http URL → 201
$res = create(base_body(['name' => 'CD-2', 'beacon_base' => 'http://1.2.3.4/']));
check($res['code'] === 201, 'CD-2 有効 http URL → 201');

// CD-3: 空文字 → 201 (nullable)
$res = create(base_body(['name' => 'CD-3', 'beacon_base' => '']));
check($res['code'] === 201, 'CD-3 空文字 beacon_base → 201');

// CD-4: null → 201 (nullable)
$res = create(base_body(['name' => 'CD-4', 'beacon_base' => null]));
check($res['code'] === 201, 'CD-4 null beacon_base → 201');

// CD-5: ftp:// → 400
$res = create(base_body(['name' => 'CD-5', 'beacon_base' => 'ftp://evil.com']));
check($res['code'] === 400, 'CD-5 ftp:// beacon_base → 400');

// CD-6: javascript: → 400
$res = create(base_body(['name' => 'CD-6', 'beacon_base' => 'javascript:alert(1)']));
check($res['code'] === 400, 'CD-6 javascript: beacon_base → 400');

// ============================================================
// Factor E: split パラメータ
// ============================================================
echo "\n=== Factor E: split パラメータ ===\n";

// CF-1 to CF-4: split モードで全 4 種の有効 interval → 201
foreach ([5, 15, 30, 60] as $interval) {
    $res = create(base_body([
        'name'               => "CF split={$interval}",
        'send_mode'          => 'split',
        'split_count'        => 3,
        'split_interval_min' => $interval,
    ]));
    check($res['code'] === 201, "CF split_interval_min={$interval} → 201");
}

// CF-6: split モードで無効な interval → 400
$res = create(base_body([
    'name'               => 'CF-6',
    'send_mode'          => 'split',
    'split_count'        => 3,
    'split_interval_min' => 7,
]));
check($res['code'] === 400, 'CF-6 無効 split_interval_min=7 → 400');

// ============================================================
// Update アクション
// ============================================================
echo "\n=== Update アクション ===\n";

// CU-1: draft + name 変更 → 200
$cid_u1 = create_draft(['name' => 'CU-1用']);
$res = update(['id' => $cid_u1, 'name' => '更新後キャンペーン名']);
check($res['code'] === 200, 'CU-1 draft + name 変更 → 200');

// CU-5: scheduled ステータス → 409
$cid_u5 = create_draft(['name' => 'CU-5用']);
Db::run('UPDATE campaigns SET status = ? WHERE id = ?', ['scheduled', $cid_u5]);
$res = update(['id' => $cid_u5, 'name' => '変更しようとする']);
check($res['code'] === 409, 'CU-5 scheduled ステータス → 409');

// CU-9: 更新フィールドなし → 400
$cid_u9 = create_draft(['name' => 'CU-9用']);
$res = update(['id' => $cid_u9]);
check($res['code'] === 400, 'CU-9 更新フィールドなし → 400');

// CU-10: 不正 link_mode → 400
$cid_u10 = create_draft(['name' => 'CU-10用']);
$res = update(['id' => $cid_u10, 'link_mode' => 'invalid_mode']);
check($res['code'] === 400, 'CU-10 不正 link_mode → 400');

// ============================================================
// Delete / Cancel アクション
// ============================================================
echo "\n=== Delete / Cancel アクション ===\n";

// DEL-1: draft → delete → 200
$cid_del1 = create_draft(['name' => '削除用-draft']);
$res = delete_campaign(['id' => $cid_del1]);
check($res['code'] === 200, 'DEL-1 draft → delete → 200');

// DEL-2: cancelled → delete → 200
$cid_del2 = create_draft(['name' => '削除用-cancelled']);
Db::run('UPDATE campaigns SET status = ? WHERE id = ?', ['cancelled', $cid_del2]);
$res = delete_campaign(['id' => $cid_del2]);
check($res['code'] === 200, 'DEL-2 cancelled → delete → 200');

// DEL-3: scheduled → delete → 200(論理削除。全状態から削除可能に変更)
$cid_del3 = create_draft(['name' => '削除用-scheduled']);
Db::run('UPDATE campaigns SET status = ? WHERE id = ?', ['scheduled', $cid_del3]);
$res = delete_campaign(['id' => $cid_del3]);
check($res['code'] === 200, 'DEL-3 scheduled → delete → 200(論理削除)');

// DEL-4: 論理削除後は deleted_at がセットされ、get(assert_campaign_owned)から引けない
$deletedRow = Db::one('SELECT deleted_at FROM campaigns WHERE id = ?', [$cid_del3]);
check($deletedRow !== null && $deletedRow['deleted_at'] !== null, 'DEL-4 論理削除で deleted_at がセットされる');
$stillExists = Db::one('SELECT id FROM campaigns WHERE id = ?', [$cid_del3]);
check($stillExists !== null, 'DEL-5 実データは残る(パージまで保持)');

// DEL-6: 複製 → 新draftが作られ、tracking_id は新規・status=draft
$cid_dup_src = create_draft(['name' => '複製元', 'target_ids' => [9901]]);
$res = duplicate_campaign(['id' => $cid_dup_src]);
check($res['code'] === 200, 'DEL-6 duplicate → 200');
$newCid = $res['payload']['campaign']['id'] ?? 0;
check($newCid > 0 && $newCid !== $cid_dup_src, 'DEL-7 複製で新しいキャンペーンIDが返る');
$dupRow = Db::one('SELECT status, deleted_at, name, data_dir FROM campaigns WHERE id = ?', [$newCid]);
check($dupRow !== null && $dupRow['status'] === 'draft', 'DEL-8 複製先は draft');
check($dupRow['deleted_at'] === null, 'DEL-9 複製先は deleted_at=NULL(生存)');
// DEL-10: 複製先の data_dir が新IDで設定される(空だと送信時に「data_dir 未設定」で失敗する回帰)
check(
    !empty($dupRow['data_dir']) && strpos((string) $dupRow['data_dir'], 'campaign_' . $newCid) !== false,
    'DEL-10 複製先の data_dir が新IDで設定される'
);

// CANCEL-1: draft → cancel → 200
$cid_can1 = create_draft(['name' => 'キャンセル用-draft']);
$res = cancel_campaign(['id' => $cid_can1]);
check($res['code'] === 200, 'CANCEL-1 draft → cancel → 200');

// CANCEL-2: scheduled → cancel → 200
$cid_can2 = create_draft(['name' => 'キャンセル用-scheduled']);
Db::run('UPDATE campaigns SET status = ? WHERE id = ?', ['scheduled', $cid_can2]);
$res = cancel_campaign(['id' => $cid_can2]);
check($res['code'] === 200, 'CANCEL-2 scheduled → cancel → 200');

echo "\nALL TESTS PASSED\n";

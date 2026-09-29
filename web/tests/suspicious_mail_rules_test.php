<?php
declare(strict_types=1);

/**
 * 不審メールの解析で、テナントが登録した条件と照合する(A-10、G42)のテスト。
 *
 *   AN-*   解析(純関数): 送信者のアドレスとドメイン、件名の語、URL のドメインで当たる。点数と推奨分類は変えない
 *   VAL-*  入力の検証(種類、名前、値の長さと形、上限)
 *   API-*  一覧、作成、変更、削除、権限、テナントの分離
 *   INT-*  アップロードと再解析で、自テナントの条件だけが当たる
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/SuspiciousMailStore.php';
require_once __DIR__ . '/../lib/VirusTotalClient.php';
require_once __DIR__ . '/../lib/Secrets.php';
load_api('suspicious_mails');

$root = sys_get_temp_dir() . '/tet2-sm-rules-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($root);
putenv("TET2_SUSPICIOUS_BASE=$root");
putenv('TET2_SECRETS_FILE=/nonexistent/secrets.ini');
Secrets::reset();
register_shutdown_function(static function () use ($root): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($root);
});

function ruleCall(string $handler, array $body = [], array $get = [], string $role = 'operator'): array
{
    $GLOBALS['__TET2_TEST_BODY'] = $body;
    $GLOBALS['__TET2_TEST_ROLE'] = $role;
    $_GET = $get;
    try {
        // 本番の入口と同じく、不審メールの API は読み書きとも operator 以上
        $handler(require_role('operator'));
    } catch (Tet2TestExit $e) {
        return ['code' => $e->httpCode, 'data' => $e->payload];
    }
    throw new RuntimeException('handler did not exit');
}
function tenantRuleFindings(array $findings): array
{
    return array_values(array_filter($findings, static fn(array $f): bool => $f['code'] === 'tenant_rule'));
}

// ============ 解析(純関数) ============
$analysis = ['messages' => [[
    'depth' => 0, 'from_email' => 'Notice@Bank-Secure.test', 'from_name' => 'Bank', 'subject' => 'Your ACCOUNT is locked',
    'return_path' => null, 'reply_to' => null, 'auth_results' => ['spf=pass dkim=pass dmarc=pass'], 'received' => [],
    'urls' => [['raw' => 'https://login.evil-host.test/x', 'unwrapped' => 'https://login.evil-host.test/x', 'display' => null]],
    'attachments' => [], 'text' => 'hello', 'html_text' => '',
]]];
$base = SuspiciousMailAnalyzer::analyze($analysis);
$rules = [
    ['id' => 1, 'name' => '取引先を装う送信者', 'kind' => 'sender', 'value' => 'bank-secure.test'],
    ['id' => 2, 'name' => '特定のアドレス', 'kind' => 'sender', 'value' => 'notice@bank-secure.test'],
    ['id' => 3, 'name' => 'アカウント停止の件名', 'kind' => 'subject_keyword', 'value' => 'account is'],
    ['id' => 4, 'name' => '既知の偽サイト', 'kind' => 'url_domain', 'value' => 'evil-host.test'],
    ['id' => 5, 'name' => '当たらない送信者', 'kind' => 'sender', 'value' => 'secure.test.example'],
    ['id' => 6, 'name' => '当たらない件名', 'kind' => 'subject_keyword', 'value' => '請求書'],
    ['id' => 7, 'name' => '部分一致ではないドメイン', 'kind' => 'url_domain', 'value' => 'host.test'],
];
$result = SuspiciousMailAnalyzer::analyze($analysis, ['tenant_rules' => $rules]);
$hits = tenantRuleFindings($result['findings']);
check(array_column(array_column($hits, 'evidence'), 'rule_id') === [1, 2, 3, 4], 'AN-1: 送信者のドメインとアドレス、件名の語、URL のドメインの条件が当たる');
check($hits[0]['message'] === '登録した条件に一致: 取引先を装う送信者' && $hits[0]['severity'] === 'medium', 'AN-2: 所見は「登録した条件に一致」と条件の名前');
check($hits[3]['evidence']['matched'] === 'login.evil-host.test', 'AN-3: URL のドメインはサブドメインも含めて当たり、当たったホストを残す');
check($result['score'] === $base['score'] && $result['suggested_category'] === $base['suggested_category'], 'AN-4: 点数と推奨分類は変えない');
check(tenantRuleFindings(SuspiciousMailAnalyzer::analyze($analysis)['findings']) === [], 'AN-5: 条件がなければ所見を足さない');
$training = SuspiciousMailAnalyzer::analyze($analysis, ['tenant_rules' => $rules, 'training_tracking_ids' => ['0000000001']]);
check(tenantRuleFindings($training['findings']) === [], 'AN-6: 訓練メールには条件を当てない(訓練メールの判定を優先)');

// ============ 入力の検証と API ============
$r = ruleCall('sm_handle_rule_save', ['name' => '偽の銀行', 'kind' => 'sender', 'value' => '  *.Bank-Secure.TEST ']);
check($r['code'] === 201 && $r['data']['rule']['value'] === 'bank-secure.test' && $GLOBALS['__TET2_TEST_CSRF_CALLS'] >= 1, 'API-1: 作成できる(値は小文字、*. を外す、CSRF を確かめる)');
$senderRuleId = (int) $r['data']['rule']['id'];
check($GLOBALS['__TET2_TEST_AUDIT'][count($GLOBALS['__TET2_TEST_AUDIT']) - 1]['action'] === 'suspicious_mail.rule_create', 'API-2: 監査に残す');
$r = ruleCall('sm_handle_rule_save', ['name' => 'URL', 'kind' => 'url_domain', 'value' => 'https://www.bank-secure.test/login?x=1']);
check($r['code'] === 201 && $r['data']['rule']['value'] === 'www.bank-secure.test', 'VAL-1: URL のドメインは URL を貼ってもホストだけにする');
foreach ([
    [['name' => 'x', 'kind' => 'bogus', 'value' => 'a.test'], '種類が不正'],
    [['name' => '', 'kind' => 'sender', 'value' => 'a.test'], '名前が空'],
    [['name' => str_repeat('名', SuspiciousMailRules::MAX_NAME + 1), 'kind' => 'sender', 'value' => 'a.test'], '名前が長すぎる'],
    [['name' => 'x', 'kind' => 'sender', 'value' => str_repeat('a', SuspiciousMailRules::MAX_VALUE + 1)], '値が長すぎる'],
    [['name' => 'x', 'kind' => 'sender', 'value' => 'not a domain'], 'ドメインの形でない'],
    [['name' => 'x', 'kind' => 'sender', 'value' => 'bad@@x.test'], 'アドレスの形でない'],
    [['name' => 'x', 'kind' => 'url_domain', 'value' => 'javascript:alert(1)'], 'URL でもドメインでもない'],
    [['name' => 'x', 'kind' => 'subject_keyword', 'value' => 'a'], '件名の語が短すぎる'],
    [['name' => "改\x07行", 'kind' => 'subject_keyword', 'value' => '請求'], '制御文字'],
    [['name' => 'x', 'kind' => 'subject_keyword', 'value' => ['配列']], '文字列でない値'],
] as [$body, $label]) {
    check(ruleCall('sm_handle_rule_save', $body)['code'] === 400, "VAL-2: {$label}は 400");
}
check((int) Db::one('SELECT COUNT(*) n FROM suspicious_mail_rules')['n'] === 2, 'VAL-3: 拒んだ入力は保存しない');

// 閲覧者は一覧も作成もできない(一覧と同じく operator 以上)
check(ruleCall('sm_handle_rules', [], [], 'viewer')['code'] === 403 && ruleCall('sm_handle_rule_save', ['name' => 'x', 'kind' => 'sender', 'value' => 'a.test'], [], 'viewer')['code'] === 403,
    'API-3: 閲覧者は 403');

// テナントの分離: テナント2の条件はテナント1から見えず、変えられず、消せない
Db::run("INSERT INTO suspicious_mail_rules (tenant_id, name, kind, value) VALUES (2, '他社の条件', 'subject_keyword', 'locked')");
$otherRuleId = (int) Db::one("SELECT id FROM suspicious_mail_rules WHERE tenant_id = 2")['id'];
$r = ruleCall('sm_handle_rules');
check($r['code'] === 200 && count($r['data']['rules']) === 2 && !in_array($otherRuleId, array_map('intval', array_column($r['data']['rules'], 'id')), true),
    'API-4: 一覧は自テナントの条件だけ');
check(ruleCall('sm_handle_rule_save', ['id' => $otherRuleId, 'name' => '乗っ取り'])['code'] === 404, 'API-5: ほかのテナントの条件は変えられない(404)');
check(ruleCall('sm_handle_rule_delete', ['id' => $otherRuleId])['code'] === 404, 'API-6: ほかのテナントの条件は消せない(404)');
check(ruleCall('sm_handle_rules', [], ['tenant_id' => '2'], 'tenant_admin')['code'] === 403, 'API-7: ほかのテナントを指定すると 403');
check(Db::one('SELECT name FROM suspicious_mail_rules WHERE id = ?', [$otherRuleId])['name'] === '他社の条件', 'API-8: ほかのテナントの条件は変わらない');
check(ruleCall('sm_handle_rules', [], [], 'superadmin')['code'] === 400, 'API-9: システム管理者がテナントを選んでいない時は 400');
$r = ruleCall('sm_handle_rules', [], ['tenant_id' => '2'], 'superadmin');
check($r['code'] === 200 && count($r['data']['rules']) === 1, 'API-10: システム管理者はテナントを選ぶとそのテナントの条件を扱える');

// ============ アップロードと再解析 ============
$upload = static fn(string $file): array => ruleCall('sm_handle_upload',
    ['filename' => 'x.eml', 'file_base64' => base64_encode((string) file_get_contents(__DIR__ . '/fixtures/eml/' . $file))]);
$r = $upload('forwarded_rfc822.eml');
$mailId = (int) $r['data']['id'];
$findings = SuspiciousMailStore::detail($mailId, 1)['findings'];
$hits = tenantRuleFindings($findings);
check($r['code'] === 201 && array_column(array_column($hits, 'evidence'), 'rule_name') === ['偽の銀行'],
    'INT-1: 自テナントの送信者の条件が当たる(URL の条件は、実際のリンク先が IP で表示の文字列だけが一致するので当たらない)');
check(!in_array('他社の条件', array_column(array_column($hits, 'evidence'), 'rule_name'), true), 'INT-2: ほかのテナントの条件(件名 locked)は当たらない');
check((string) Db::one('SELECT category FROM suspicious_mails WHERE id = ?', [$mailId])['category'] === 'undetermined', 'INT-3: 分類は自動で変えない(送ったり消したりもしない)');

// 条件を止めて再解析すると、その所見は消える。件名の条件を足して再解析すると当たる
$r = ruleCall('sm_handle_rule_save', ['id' => $senderRuleId, 'is_active' => false]);
check($r['code'] === 200 && (int) $r['data']['rule']['is_active'] === 0, 'API-11: 条件を止められる');
ruleCall('sm_handle_rule_save', ['name' => 'ロックの件名', 'kind' => 'subject_keyword', 'value' => 'LOCKED']);
ruleCall('sm_handle_reanalyze', ['id' => $mailId]);
$names = array_column(array_column(tenantRuleFindings(SuspiciousMailStore::detail($mailId, 1)['findings']), 'evidence'), 'rule_name');
check($names === ['ロックの件名'], 'INT-4: 再解析は今の有効な条件で照合し直す(止めた条件は当たらない)');

// テナント2の同じメールには、テナント1の条件は当たらない
$r = ruleCall('sm_handle_upload', ['filename' => 'x.eml', 'tenant_id' => 2,
    'file_base64' => base64_encode((string) file_get_contents(__DIR__ . '/fixtures/eml/forwarded_rfc822.eml'))], [], 'superadmin');
$otherNames = array_column(array_column(tenantRuleFindings(SuspiciousMailStore::detail((int) $r['data']['id'], 2)['findings']), 'evidence'), 'rule_name');
check($r['code'] === 201 && $otherNames === ['他社の条件'], 'INT-5: テナント2のメールにはテナント2の条件だけが当たる');

// 削除
$r = ruleCall('sm_handle_rule_delete', ['id' => $senderRuleId]);
check($r['code'] === 200 && Db::one('SELECT 1 FROM suspicious_mail_rules WHERE id = ?', [$senderRuleId]) === null, 'API-12: 条件を削除できる');

// 上限
Db::run('DELETE FROM suspicious_mail_rules WHERE tenant_id = 1');
Db::tx(static function (): void {
    for ($i = 0; $i < SuspiciousMailRules::MAX_RULES; $i++) {
        Db::run("INSERT INTO suspicious_mail_rules (tenant_id, name, kind, value) VALUES (1, ?, 'subject_keyword', ?)", ["r{$i}", "kw{$i}"]);
    }
});
check(ruleCall('sm_handle_rule_save', ['name' => 'x', 'kind' => 'sender', 'value' => 'a.test'])['code'] === 409, 'VAL-4: 上限の件数を超えると 409');

echo "ALL TESTS PASSED\n";

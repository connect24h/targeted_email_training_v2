<?php
declare(strict_types=1);

/**
 * campaigns API の beacon_base 検証(P6)回帰テスト。
 * URL スキーム検証と、空文字/未指定の NULL 化を固定する。
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
load_api('campaigns');

// --- campaigns_optional_beacon_base の検証 ---
check(campaigns_optional_beacon_base(['beacon_base' => 'https://example.com']) === 'https://example.com', 'https URL は通る');
check(campaigns_optional_beacon_base(['beacon_base' => 'http://1.2.3.4']) === 'http://1.2.3.4', 'http IP は通る');
check(campaigns_optional_beacon_base(['beacon_base' => '']) === null, '空文字 → null');
check(campaigns_optional_beacon_base(['beacon_base' => null]) === null, 'null → null');
check(campaigns_optional_beacon_base([]) === null, '未指定 → null');

// 不正スキームは 400(Tet2TestExit)
$rejected = false;
try {
    campaigns_optional_beacon_base(['beacon_base' => 'ftp://evil']);
} catch (Tet2TestExit $e) {
    $rejected = ($e->httpCode === 400);
}
check($rejected, 'ftp スキーム → 400');

$rejected = false;
try {
    campaigns_optional_beacon_base(['beacon_base' => 'javascript:alert(1)']);
} catch (Tet2TestExit $e) {
    $rejected = ($e->httpCode === 400);
}
check($rejected, 'javascript スキーム → 400');

echo "ALL TESTS PASSED\n";

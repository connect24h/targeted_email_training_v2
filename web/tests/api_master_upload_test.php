<?php
declare(strict_types=1);

/**
 * master_upload API の回帰テスト。
 *
 * master_upload.php はルーティングを try ブロックに直書きしているため、
 * load_api で取り込まれるのは mu_validate_html / mu_write_with_backup のみ。
 * GET/invalid-kind の検証はローカルラッパー関数で行う。
 * アップロード検証は mu_validate_html を直接呼ぶ。
 *
 * MG-1: get kind=auth, file=master.html → 200
 * MG-5: get kind=auth, file=evil.html → 400
 * MG-8: get kind=invalid → 400
 * MU-2: upload HTML with <script> → 400
 * MU-3: upload HTML with onclick → 400
 * MU-4: upload empty HTML → 400
 */

require_once __DIR__ . '/helpers.php';

tet2_test_boot();
load_api('master_upload');

// -----------------------------------------------------------------------
// ローカルラッパー: master_upload の GET ルーティングを再現する。
// load_api で try ブロックが除去されるため、テスト内でロジックを再現する。
// -----------------------------------------------------------------------

/**
 * action=get のルーティングを実行し、Tet2TestExit を捕捉して結果配列を返す。
 * $_GET を呼び出し元がセットしてから呼ぶ。
 */
function mu_dispatch_get(string $role = 'operator'): array
{
    $GLOBALS['__TET2_TEST_ROLE'] = $role;
    try {
        $actor = require_role('operator');
        $kind  = $_GET['kind'] ?? '';

        if ($kind === 'auth') {
            $file = (string) ($_GET['file'] ?? '');
            if (!in_array($file, AUTH_MASTER_FILES, true)) {
                json_error('ファイル指定が不正です', 400);
            }
            // ファイルが存在しない場合でも空文字で返す(本番の動作に倣う)
            $path = AUTH_MASTER_DIR . '/' . $file;
            $content = is_file($path) ? file_get_contents($path) : '';
            json_out(['success' => true, 'exists' => is_file($path), 'content' => $content === false ? '' : $content]);
        } elseif ($kind === 'reveal') {
            $tenantId = effective_tenant_id($actor, isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : null);
            $t = Db::one('SELECT data_dir FROM tenants WHERE id = ?', [$tenantId]);
            if ($t === null || (string) $t['data_dir'] === '') {
                json_error('テナントのデータ領域がありません', 404);
            }
            $path = rtrim((string) $t['data_dir'], '/') . '/reveal.html';
            $tenantContent = is_file($path) ? (string) @file_get_contents($path) : '';
            if (trim($tenantContent) === '') {
                $common = AUTH_MASTER_DIR . '/master.html';
                $commonContent = is_file($common) ? (string) @file_get_contents($common) : '';
                json_out(['success' => true, 'exists' => false, 'is_fallback' => true, 'content' => $commonContent]);
            }
            json_out(['success' => true, 'exists' => true, 'is_fallback' => false, 'content' => $tenantContent]);
        } else {
            json_error('kind が不正です', 400);
        }
    } catch (Tet2TestExit $e) {
        return ['code' => $e->httpCode, 'payload' => $e->payload];
    }
    return ['code' => 0, 'payload' => null];
}

/**
 * mu_validate_html を呼び出し、エラー有無を配列で返す。
 * 正常なら code=200 のダミー、json_error なら code=400 を返す。
 */
function mu_call_validate(string $html): array
{
    try {
        mu_validate_html($html);
        return ['code' => 200, 'payload' => ['success' => true]];
    } catch (Tet2TestExit $e) {
        return ['code' => $e->httpCode, 'payload' => $e->payload];
    }
}

// -----------------------------------------------------------------------
// MG-1: get kind=auth, file=master.html → 200
// ファイルが /opt/training/bin/master.html に存在しなくても 200 で空を返す。
// -----------------------------------------------------------------------
$_GET = ['action' => 'get', 'kind' => 'auth', 'file' => 'master.html'];
$r = mu_dispatch_get('operator');
check($r['code'] === 200, 'MG-1: get kind=auth, file=master.html → 200');
check(($r['payload']['success'] ?? false) === true, 'MG-1: success=true');
check(array_key_exists('exists', $r['payload']), 'MG-1: exists フィールドが存在する');
check(array_key_exists('content', $r['payload']), 'MG-1: content フィールドが存在する');

// -----------------------------------------------------------------------
// MG-5: get kind=auth, file=evil.html → 400 (AUTH_MASTER_FILES に含まれない)
// -----------------------------------------------------------------------
$_GET = ['action' => 'get', 'kind' => 'auth', 'file' => 'evil.html'];
$r = mu_dispatch_get('operator');
check($r['code'] === 400, 'MG-5: get kind=auth, file=evil.html → 400');

// -----------------------------------------------------------------------
// MG-8: get kind=invalid → 400
// -----------------------------------------------------------------------
$_GET = ['action' => 'get', 'kind' => 'invalid_kind'];
$r = mu_dispatch_get('operator');
check($r['code'] === 400, 'MG-8: get kind=invalid → 400');

// -----------------------------------------------------------------------
// MU-2: upload HTML with <script> → 400
// -----------------------------------------------------------------------
$html = '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>';
$r = mu_call_validate($html);
check($r['code'] === 400, 'MU-2: upload HTML with <script> → 400');

// -----------------------------------------------------------------------
// MU-3: upload HTML with onclick → 400
// -----------------------------------------------------------------------
$html = '<!DOCTYPE html><html><body><button onclick="alert(1)">click</button></body></html>';
$r = mu_call_validate($html);
check($r['code'] === 400, 'MU-3: upload HTML with onclick → 400');

// -----------------------------------------------------------------------
// MU-4: upload empty HTML → 400
// -----------------------------------------------------------------------
$r = mu_call_validate('');
check($r['code'] === 400, 'MU-4: upload empty HTML → 400');

echo "ALL TESTS PASSED\n";

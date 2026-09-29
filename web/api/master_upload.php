<?php
/**
 * マスタHTML管理: 認証マスタ(共通) と 種明かし画面(テナント別) のアップロード/取得。
 *
 * 認証マスタ(master.html〜master4.html): 全テナント共通。/opt/training/bin/ にある偽ログインページ。
 *   → 変更は superadmin のみ(全テナントに影響するため)。
 * 種明かし画面(reveal.html): テナント別。tenants.data_dir 直下。訓練の種明かし/啓発ページ。
 *   → QRコード型(P5)や認証後の遷移先に使う。テナント operator が自組織分を差し替え可。
 *
 *   GET  action=get&kind=auth&file=master.html          認証マスタ取得(共通)
 *   GET  action=get&kind=reveal                          種明かし取得(自テナント)
 *   POST action=upload  {kind:'auth', file:'master.html', html:'...'}   認証マスタ差替(superadmin)
 *   POST action=upload  {kind:'reveal', html:'...'}                      種明かし差替(operator, 自テナント)
 */
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/RevealPages.php';

const AUTH_MASTER_FILES = ['master.html', 'master2.html', 'master3.html', 'master4.html', 'master5.html'];
const AUTH_MASTER_DIR   = '/opt/training/bin';
const REVEAL_FILE       = 'reveal.html';

/**
 * アップロードされた HTML の妥当性を検証(v1 upload_master.php 踏襲)。
 * 規則は RevealPages::validate に一本化し、複数の種明かしページ(G29)と同じ検証を使う。
 */
function mu_validate_html(string $html): void
{
    try {
        RevealPages::validate($html);
    } catch (DomainException $e) {
        json_error($e->getMessage(), 400);
    }
}

/**
 * バックアップを取ってから書き込む。
 * 既存ファイルが別ユーザー所有(例: master.html は root 所有)でも、ディレクトリに書き込み権限が
 * あれば unlink→新規作成で上書きできる。file_put_contents 直上書きは所有者不一致で失敗するため、
 * 先に unlink する。
 */
function mu_write_with_backup(string $path, string $html): void
{
    if (is_file($path)) {
        $bakDir = dirname($path) . '/backup';
        @mkdir($bakDir, 0775, true);
        @copy($path, $bakDir . '/' . basename($path) . '.bak-' . date('Ymd-His'));
        @unlink($path); // 別所有ファイルでもディレクトリ書込権限があれば置き換え可能に
    }
    if (@file_put_contents($path, $html) === false) {
        json_error('書き込みに失敗しました（権限を確認してください）', 500);
    }
}

try {
    $actor  = require_role('operator');
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // 複数の種明かしページ(G29)。テナント別なので operator が自組織分を扱える(reveal と同じ権限)。
    if ($action === 'reveal_list' && $method === 'GET') {
        $tenantId = effective_tenant_id($actor, isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : null);
        json_out(['success' => true, 'pages' => RevealPages::all($tenantId), 'limit' => RevealPages::MAX_PAGES]);
    }

    if ($action === 'reveal_page_get' && $method === 'GET') {
        $tenantId = effective_tenant_id($actor, isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : null);
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $row = $id > 0 ? RevealPages::find($id, $tenantId) : null;
        if ($row === null) {
            json_error('種明かしページが見つかりません', 404);
        }
        $content = RevealPages::content($id, $tenantId);
        json_out(['success' => true, 'id' => $id, 'name' => $row['name'], 'content' => $content ?? '']);
    }

    if ($action === 'reveal_page_save' && $method === 'POST') {
        tet2_require_csrf();
        $body = json_body();
        $tenantId = effective_tenant_id($actor, isset($body['tenant_id']) && is_int($body['tenant_id']) ? $body['tenant_id'] : null);
        $id = isset($body['id']) && is_int($body['id']) && $body['id'] > 0 ? $body['id'] : null;
        $name = is_string($body['name'] ?? null) ? $body['name'] : '';
        $html = (string) ($body['html'] ?? '');
        try {
            $savedId = RevealPages::save($tenantId, $id, $name, $html, (string) $actor['email']);
        } catch (DomainException $e) {
            json_error($e->getMessage(), $e->getCode() ?: 400);
        }
        audit('master.upload', 'reveal_page,tenant=' . $tenantId . ',id=' . $savedId);
        json_out(['success' => true, 'id' => $savedId], $id === null ? 201 : 200);
    }

    if ($action === 'reveal_page_delete' && $method === 'POST') {
        tet2_require_csrf();
        $body = json_body();
        $tenantId = effective_tenant_id($actor, isset($body['tenant_id']) && is_int($body['tenant_id']) ? $body['tenant_id'] : null);
        $id = isset($body['id']) && is_int($body['id']) ? $body['id'] : 0;
        try {
            RevealPages::delete($id, $tenantId);
        } catch (DomainException $e) {
            json_error($e->getMessage(), $e->getCode() ?: 400);
        }
        audit('master.upload', 'reveal_page_delete,tenant=' . $tenantId . ',id=' . $id);
        json_out(['success' => true]);
    }

    if ($action === 'get' && $method === 'GET') {
        $kind = $_GET['kind'] ?? '';
        if ($kind === 'auth') {
            $file = (string) ($_GET['file'] ?? '');
            if (!in_array($file, AUTH_MASTER_FILES, true)) {
                json_error('ファイル指定が不正です', 400);
            }
            $path = AUTH_MASTER_DIR . '/' . $file;
        } elseif ($kind === 'reveal') {
            $tenantId = effective_tenant_id($actor, isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : null);
            $t = Db::one('SELECT data_dir FROM tenants WHERE id = ?', [$tenantId]);
            if ($t === null || (string) $t['data_dir'] === '') {
                json_error('テナントのデータ領域がありません', 404);
            }
            $path = rtrim((string) $t['data_dir'], '/') . '/' . REVEAL_FILE;
            // テナント別 reveal.html が未設定/空なら、共通の種明かし(master.html)をフォールバック表示。
            $tenantContent = is_file($path) ? (string) @file_get_contents($path) : '';
            if (trim($tenantContent) === '') {
                $common = AUTH_MASTER_DIR . '/master.html';
                $commonContent = is_file($common) ? (string) @file_get_contents($common) : '';
                json_out([
                    'success'     => true,
                    'exists'      => false,        // テナント別は未設定
                    'is_fallback' => true,         // 共通の種明かしを表示中
                    'content'     => $commonContent,
                ]);
            }
            json_out(['success' => true, 'exists' => true, 'is_fallback' => false, 'content' => $tenantContent]);
        } else {
            json_error('kind が不正です', 400);
        }
        // auth: そのまま該当ファイルを返す。
        $content = is_file($path) ? file_get_contents($path) : '';
        json_out(['success' => true, 'exists' => is_file($path), 'content' => $content === false ? '' : $content]);
    }

    if ($action === 'upload' && $method === 'POST') {
        tet2_require_csrf();
        $body = json_body();
        $kind = (string) ($body['kind'] ?? '');
        $html = (string) ($body['html'] ?? '');
        mu_validate_html($html);

        if ($kind === 'auth') {
            // 認証マスタは全テナント共通 → superadmin だけ(変更はほかのテナントの訓練にも効く)。
            if (($actor['role'] ?? '') !== 'superadmin') {
                json_error('認証マスタの変更はシステム管理者だけが可能です', 403);
            }
            $file = (string) ($body['file'] ?? '');
            if (!in_array($file, AUTH_MASTER_FILES, true)) {
                json_error('ファイル指定が不正です', 400);
            }
            $path = AUTH_MASTER_DIR . '/' . $file;
            mu_write_with_backup($path, $html);
            audit('master.upload', 'auth=' . $file);
            json_out(['success' => true, 'file' => $file]);
        }

        if ($kind === 'reveal') {
            try {
                RevealPages::validateReveal($html);
            } catch (DomainException $e) {
                json_error($e->getMessage(), 400);
            }
            // 種明かしはテナント別 → 自テナント(superadmin は tenant_id 指定可)
            $tenantId = effective_tenant_id($actor, isset($body['tenant_id']) && is_int($body['tenant_id']) ? $body['tenant_id'] : null);
            $t = Db::one('SELECT data_dir FROM tenants WHERE id = ?', [$tenantId]);
            if ($t === null || (string) $t['data_dir'] === '') {
                json_error('テナントのデータ領域がありません', 404);
            }
            $dir = rtrim((string) $t['data_dir'], '/');
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $path = $dir . '/' . REVEAL_FILE;
            mu_write_with_backup($path, $html);
            audit('master.upload', 'reveal,tenant=' . $tenantId);
            json_out(['success' => true, 'file' => REVEAL_FILE, 'tenant_id' => $tenantId]);
        }

        json_error('kind が不正です', 400);
    }

    json_error('不正なアクションです', 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_error('サーバエラー', 500);
}

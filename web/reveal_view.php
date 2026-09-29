<?php declare(strict_types=1);
/**
 * 種明かしメール(段D の D2)のリンクから開く、訓練の種明かしのページ(認証不要・トークン方式)。
 * /tet2/reveal_view.php?token=<48桁hex>
 *
 * - トークンは種明かしメールを送った記録(notification_sends)の1行に結びつく。訓練で選んだ種明かしのページ、
 *   なければテナントの既定(reveal.html)、それもなければ既定の説明のページを出す。
 * - 訓練の測定に入らない: events には何も書かない(クリックの記録の link-*.html とは別の入口)。
 * - 種明かしのページはアップロードの時に script・form などを拒んでいるが、念のため CSP の sandbox で
 *   スクリプトとフォームを止め、外への送信とほかのページへの埋め込みもさせない。
 */
require_once __DIR__ . '/lib/RevealMail.php';

$token = isset($_GET['token']) && is_string($_GET['token']) ? trim($_GET['token']) : '';
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header("Content-Security-Policy: sandbox allow-popups allow-popups-to-escape-sandbox; default-src 'none'; img-src https: data:; "
    . "style-src 'unsafe-inline' https:; font-src https: data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");

try {
    $html = RevealMail::pageForToken($token);
} catch (Throwable $e) {
    error_log('reveal_view: ' . $e->getMessage());
    $html = null;
}
if ($html === null) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>ページが見つかりません</title></head>'
        . '<body><p>リンクが正しくないか、有効期限が切れています。</p></body></html>';
    exit;
}
header('Content-Type: text/html; charset=UTF-8');
echo $html;

<?php declare(strict_types=1);
/**
 * パスワード設定のページ(招待と再設定。ログイン不要・トークン方式)。
 * /tet2/set_password.php?token=<64桁hex>
 * リンクが使えるかを api/password_set.php?action=check で確かめ、使えなければ理由を出す。
 * 設定の後はログイン画面へ案内する。受講者のマイページ(sat.cojp.online)でも使うため、セッションに頼らない。
 * &site=my(受講者のマイページの招待・再設定のメールのリンク)の時は、設定の後にマイページ(my.php)へ案内する。
 */
$token = isset($_GET['token']) && is_string($_GET['token']) ? trim($_GET['token']) : '';
$learnerSite = ($_GET['site'] ?? '') === 'my';
$tokenValid = (bool) preg_match('/^[0-9a-f]{64}$/D', $token);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
// トークンが URL にあるので、ほかのサイトへ送られる Referer に載せない
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>パスワードの設定 | TET v2</title>
  <link href="assets/vendor/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root { --tet-bg: #f6f7f9; --tet-surface: #ffffff; --tet-border: #e4e7ec; --tet-text: #101828; --tet-text-2: #475467;
            --tet-accent: #2563eb; --tet-danger: #d92d20; --tet-success: #067647; --tet-radius: 10px; }
    body { background: var(--tet-bg); color: var(--tet-text); font-family: -apple-system, "Segoe UI", "Hiragino Sans", "Noto Sans JP", "Yu Gothic UI", Meiryo, sans-serif; font-size: 14px; }
    .sp-wrap { max-width: 480px; margin: 0 auto; padding: 16px; }
    .sp-card { background: var(--tet-surface); border: 1px solid var(--tet-border); border-radius: var(--tet-radius); box-shadow: 0 1px 2px rgba(16,24,40,.05); padding: 24px; margin-top: 32px; }
    .sp-card h1 { font-size: 20px; font-weight: 600; margin-bottom: 8px; }
    .sp-note { color: var(--tet-text-2); font-size: 13px; }
    .sp-rule { font-size: 13px; color: var(--tet-text-2); }
    .sp-rule.ok { color: var(--tet-success); }
    .sp-icon-err { color: var(--tet-danger); font-size: 2rem; }
    .sp-icon-ok { color: var(--tet-success); font-size: 2rem; }
    :focus-visible { outline: 2px solid var(--tet-accent); outline-offset: 2px; }
  </style>
</head>
<body>
<main class="sp-wrap">

  <?php if (!$tokenValid): ?>
    <div class="sp-card text-center" role="alert" id="errorView">
      <i class="bi bi-exclamation-circle sp-icon-err" aria-hidden="true"></i>
      <h1 class="mt-2">リンクが正しくありません</h1>
      <p class="sp-note mb-0" id="errorMsg">メールで案内されたリンクを、もう一度そのまま開いてください。うまくいかない場合は、管理者に再送を依頼してください。</p>
    </div>
  <?php else: ?>

  <div id="loadingView" class="sp-card text-center" role="status">
    <span class="spinner-border spinner-border-sm" aria-hidden="true"></span> 確認しています
  </div>

  <form id="formView" class="sp-card d-none" novalidate>
    <h1 id="spTitle">パスワードの設定</h1>
    <p class="sp-note">アカウント: <span id="spEmail"></span><br>リンクの有効期限: <span id="spExpires"></span></p>
    <div class="mb-3">
      <label class="form-label" for="spPassword">新しいパスワード</label>
      <input class="form-control" id="spPassword" type="password" autocomplete="new-password" required aria-describedby="spRules">
    </div>
    <ul class="list-unstyled mb-3" id="spRules" aria-live="polite">
      <li class="sp-rule" data-rule="length"><i class="bi bi-circle" aria-hidden="true"></i> 12文字以上</li>
      <li class="sp-rule" data-rule="classes"><i class="bi bi-circle" aria-hidden="true"></i> 英大文字・英小文字・数字・記号のうち3種類以上</li>
    </ul>
    <div class="mb-3">
      <label class="form-label" for="spConfirm">確認のため、もう一度</label>
      <input class="form-control" id="spConfirm" type="password" autocomplete="new-password" required>
    </div>
    <div id="spError" class="alert alert-danger py-2 d-none" role="alert"></div>
    <button type="submit" class="btn btn-primary w-100" id="spSubmit">
      <span class="spinner-border spinner-border-sm d-none" id="spSpin" aria-hidden="true"></span>
      パスワードを設定する
    </button>
  </form>

  <div id="doneView" class="sp-card text-center d-none" role="status">
    <i class="bi bi-check-circle sp-icon-ok" aria-hidden="true"></i>
    <h1 class="mt-2">パスワードを設定しました</h1>
    <p class="sp-note">設定したパスワードでログインしてください。</p>
    <a class="btn btn-primary" href="<?= $learnerSite ? 'my.php' : './' ?>" id="spLoginLink"><?= $learnerSite ? 'マイページのログインへ' : 'ログイン画面へ' ?></a>
  </div>

  <div id="errorView" class="sp-card text-center d-none" role="alert">
    <i class="bi bi-exclamation-circle sp-icon-err" aria-hidden="true"></i>
    <h1 class="mt-2">このリンクは使えません</h1>
    <p class="sp-note mb-0" id="errorMsg"></p>
  </div>

  <?php endif; ?>
</main>

<?php if ($tokenValid): ?>
<script>
(function () {
  'use strict';
  const TOKEN = <?= json_encode($token) ?>;
  const API = 'api/password_set.php';
  const $ = (id) => document.getElementById(id);

  async function call(action, body) {
    const res = await fetch(API + '?action=' + action, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body), credentials: 'omit', cache: 'no-store',
    });
    let data;
    try { data = await res.json(); } catch (_) { data = { success: false, error: 'サーバーの応答を読めませんでした。時間をおいてもう一度お試しください。' }; }
    return data;
  }

  function show(id) {
    ['loadingView', 'formView', 'doneView', 'errorView'].forEach((v) => $(v).classList.toggle('d-none', v !== id));
  }

  function fail(message) {
    $('errorMsg').textContent = message || 'エラーが発生しました。';
    show('errorView');
  }

  function classCount(pw) {
    return [/[A-Z]/, /[a-z]/, /[0-9]/, /[^A-Za-z0-9]/].filter((r) => r.test(pw)).length;
  }

  function refreshRules() {
    const pw = $('spPassword').value;
    const rules = { length: [...pw].length >= 12, classes: classCount(pw) >= 3 };
    document.querySelectorAll('#spRules [data-rule]').forEach((li) => {
      const ok = rules[li.dataset.rule];
      li.classList.toggle('ok', ok);
      li.querySelector('i').className = ok ? 'bi bi-check-circle' : 'bi bi-circle';
    });
  }

  async function start() {
    const data = await call('check', { token: TOKEN }).catch(() => ({ success: false, error: '通信に失敗しました。' }));
    if (!data.success) { fail(data.error); return; }
    $('spTitle').textContent = data.purpose === 'invite' ? 'パスワードの設定' : 'パスワードの再設定';
    $('spEmail').textContent = data.email;
    $('spExpires').textContent = data.expires_at;
    show('formView');
    $('spPassword').focus();
  }

  $('spPassword').addEventListener('input', refreshRules);
  $('formView').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const err = $('spError');
    err.classList.add('d-none');
    const password = $('spPassword').value;
    if (password !== $('spConfirm').value) {
      err.textContent = '確認のパスワードが一致しません。';
      err.classList.remove('d-none');
      $('spConfirm').focus();
      return;
    }
    $('spSubmit').disabled = true;
    $('spSpin').classList.remove('d-none');
    try {
      const data = await call('set', { token: TOKEN, password }).catch(() => ({ success: false, error: '通信に失敗しました。' }));
      if (data.success) { show('doneView'); $('spLoginLink').focus(); return; }
      if (data.reason === 'policy' || !data.reason) {
        err.textContent = data.error;
        err.classList.remove('d-none');
        $('spPassword').focus();
        return;
      }
      fail(data.error);
    } finally {
      $('spSubmit').disabled = false;
      $('spSpin').classList.add('d-none');
    }
  });

  start();
})();
</script>
<?php endif; ?>
</body>
</html>

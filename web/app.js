'use strict';
/* TET v2 フロントエンド SPA。バックエンド API (api/*.php) と連携する。 */

const State = { user: null, csrf: null, tenants: [], activeTenantId: null, view: null, campaignWorkspaceId: null, workspaceReview: null };
/* 一覧取得結果を id→行 でキャッシュ。編集モーダルは属性埋め込みでなくここから引く（XSS/クオート破損回避） */
const Cache = { targets: {}, groups: {}, users: {}, tenants: {}, reports: {}, failuresCampaign: null };
function cacheRows(kind, rows) { Cache[kind] = {}; for (const r of rows) Cache[kind][r.id] = r; }
function clearTenantCache() {
  credentialRequest++;
  closeCredentialDialog();
  for (const key of Object.keys(Cache)) delete Cache[key];
  Object.assign(Cache, { targets: {}, groups: {}, users: {}, tenants: {}, reports: {}, failuresCampaign: null });
}

/* ========== API ラッパ ========== */
async function api(path, { method = 'GET', body = null, query = {}, timeout = 30000 } = {}) {
  const qs = new URLSearchParams();
  for (const [k, v] of Object.entries(query)) {
    if (v !== null && v !== undefined && v !== '') qs.set(k, v);
  }
  // superadmin がテナント切替中なら tenant_id を自動付与
  if (State.user && State.user.role === 'superadmin' && State.activeTenantId && !('tenant_id' in query)) {
    if (!path.startsWith('api/tenants') && !path.startsWith('api/auth')) qs.set('tenant_id', State.activeTenantId);
  }
  const url = qs.toString() ? `${path}?${qs}` : path;
  const headers = { 'Content-Type': 'application/json' };
  if (method !== 'GET' && State.csrf) headers['X-CSRF-Token'] = State.csrf;
  let payload = body;
  if (body && State.user && State.user.role === 'superadmin' && State.activeTenantId
      && !path.startsWith('api/tenants') && !('tenant_id' in body)) {
    payload = { ...body, tenant_id: State.activeTenantId };
  }
  const ctrl = new AbortController();
  // 既定30秒。launch など重い生成処理は呼び出し側で timeout を延ばす
  const timer = setTimeout(() => ctrl.abort(), timeout);
  let res;
  try {
    res = await fetch(url, { method, headers, body: payload ? JSON.stringify(payload) : null, credentials: 'same-origin', signal: ctrl.signal });
  } catch (e) {
    if (e.name === 'AbortError') throw new Error('通信がタイムアウトしました');
    throw new Error('通信に失敗しました');
  } finally {
    clearTimeout(timer);
  }
  let data;
  try { data = await res.json(); } catch { data = { success: false, error: 'レスポンス解析失敗' }; }
  // セッションが切れた時は、サーバの理由(テナントの停止など)をログイン画面に出す
  if (res.status === 401 && State.user) {
    logout(true);
    showLoginNotice(data.error || 'セッションが切れました。もう一度ログインしてください');
    throw new Error(data.error || 'セッション切れ');
  }
  // 多要素認証が必須の組織で未登録なら、登録の画面へ移す(サーバは登録の API 以外を 403 で止める)
  if (res.status === 403 && State.user && String(data.error || '').startsWith(MFA_ENROLLMENT_REQUIRED_PREFIX)) {
    State.user.mfa_enrollment_required = true;
    showForcedEnrollment();
  }
  if (!res.ok || data.success === false) {
    const err = new Error(data.error || `HTTP ${res.status}`);
    err.status = res.status;
    throw err;
  }
  return data;
}

/* ========== ユーティリティ ========== */
const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => Array.from(document.querySelectorAll(sel));
function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}
function toast(msg, kind = 'info', ms = 3500) {
  const el = document.createElement('div');
  el.className = `app-toast ${kind}`;
  el.textContent = msg;
  $('#toast').appendChild(el);
  setTimeout(() => el.remove(), ms);
}
function fmtDate(s) { return s ? String(s).replace('T', ' ').slice(0, 16) : '—'; }

function fileToBase64(file, maxBytes = 5 * 1024 * 1024) {
  if (!file) return Promise.reject(new Error('ファイルを選択してください'));
  if (file.size > maxBytes) return Promise.reject(new Error('ファイルは5MB以内にしてください'));
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || '').split(',', 2)[1] || '');
    reader.onerror = () => reject(new Error('ファイルを読み取れません'));
    reader.readAsDataURL(file);
  });
}

function eduDownloadUrl(action) {
  const query = new URLSearchParams({ action });
  if (State.user?.role === 'superadmin' && State.activeTenantId) query.set('tenant_id', State.activeTenantId);
  return `api/edu_questions.php?${query.toString()}`;
}

function eduMediaUrl(path, query) {
  const params = new URLSearchParams(query);
  if (State.user?.role === 'superadmin' && State.activeTenantId) params.set('tenant_id', State.activeTenantId);
  return `${path}?${params}`;
}

async function uploadEduChunks(file, progress) {
  if (!file?.size || file.size > 60_000_000) throw new Error('ファイルは60MB以内にしてください');
  const { upload_id: uploadId, chunk_bytes: chunkBytes } = await api('api/edu_materials.php', {
    method: 'POST', query: { action: 'upload_begin' }, body: {},
  });
  const total = Math.ceil(file.size / chunkBytes);
  try {
    for (let index = 0; index < total; index++) {
      const chunk = file.slice(index * chunkBytes, (index + 1) * chunkBytes);
      const dataBase64 = await fileToBase64(chunk, chunkBytes);
      await api('api/edu_materials.php', { method: 'POST', query: { action: 'upload_chunk' },
        body: { upload_id: uploadId, index, data_base64: dataBase64 }, timeout: 60000 });
      progress.textContent = `アップロード ${index + 1}/${total}（${Math.round((index + 1) * 100 / total)}%）`;
    }
  } catch (error) {
    await discardEduUpload(uploadId);   // 途中で失敗したアップロードをサーバーに残さない
    throw error;
  }
  return uploadId;
}

/** 分割アップロードを破棄する(失敗しても本来の処理のエラーを優先するので、ここでは握りつぶす)。 */
async function discardEduUpload(uploadId) {
  if (!uploadId) return;
  try { await api('api/edu_materials.php', { method: 'POST', query: { action: 'upload_discard' }, body: { upload_id: uploadId } }); }
  catch (_) { /* サーバー側も24時間で掃除する */ }
}

/**
 * 完了まで消えない進行表示。toast は 3.5 秒で消えるため、
 * 生成のように「押してから結果が返るまで」を覆いたい処理はこちらを使う。
 * 返り値の close() を finally で必ず呼ぶ。
 */
function showProgress(msg) {
  const overlay = document.createElement('div');
  overlay.className = 'app-progress-overlay';
  const box = document.createElement('div');
  box.className = 'app-progress-box';
  const spinner = document.createElement('div');
  spinner.className = 'app-progress-spinner';
  const text = document.createElement('div');
  text.className = 'app-progress-text';
  text.textContent = msg;
  box.appendChild(spinner);
  box.appendChild(text);
  overlay.appendChild(box);
  document.body.appendChild(overlay);
  return { close() { overlay.remove(); } };
}

/* ========== 認証 ========== */
// ログイン画面のエラー欄に、ログアウトした理由を出す(次のログインの送信で消える)
function showLoginNotice(message) {
  const err = $('#loginError');
  if (!err) return;
  err.textContent = message;
  err.classList.remove('d-none');
}
async function login(email, password) {
  const data = await api('api/auth.php', { method: 'POST', query: { action: 'login' }, body: { email, password } });
  // 多要素認証のユーザは、パスワードの後に確認コードを入れる(この時点ではまだログインしていない)
  if (data.mfa_required) { showMfaStep(); return; }
  State.user = data.user;
  State.csrf = data.csrf;
  await afterLogin();
}
async function logout(silent = false) {
  try { if (!silent) await api('api/auth.php', { method: 'POST', query: { action: 'logout' } }); } catch {}
  riskDashboard?.invalidate();
  State.user = null; State.csrf = null; State.activeTenantId = null;
  credentialRequest++;
  closeCredentialDialog();
  contextHelp?.close();
  $('#helpToggle').setAttribute('aria-expanded', 'false');
  $('#appView').classList.add('d-none');
  $('#mfaEnrollView').classList.add('d-none');
  hideMfaStep();
  $('#loginView').classList.remove('d-none');
  $('#loginPassword').value = '';
  // 前の利用者の画面の位置を URL に残さず、入力欄から始める
  history.replaceState(null, '', window.location.pathname + window.location.search);
  $('#loginEmail').focus();
}
async function checkSession() {
  try {
    const data = await api('api/auth.php', { query: { action: 'me' } });
    if (data.user) { State.user = data.user; State.csrf = data.csrf; await afterLogin(); return; }
    if (data.notice) showLoginNotice(data.notice);
    // 確認コードの入力の途中で読み込み直した時は、コードの入力から続ける
    if (data.mfa_pending) { $('#loginView').classList.remove('d-none'); showMfaStep(); return; }
  } catch {}
  $('#loginView').classList.remove('d-none');
}
async function afterLogin() {
  // 組織の方針で多要素認証が必須なのに未登録なら、登録の画面だけを出す
  if (State.user.mfa_enrollment_required) { showForcedEnrollment(); return; }
  $('#mfaEnrollView').classList.add('d-none');
  $('#loginView').classList.add('d-none');
  $('#appView').classList.remove('d-none');
  $('#userLabel').textContent = `${State.user.email}（${roleLabel(State.user.role)}）`;
  // ロール別メニュー表示
  $$('[data-role]').forEach((el) => {
    el.classList.toggle('d-none', !roleAtLeast(State.user.role, el.dataset.role));
  });
  $$('[data-perm]').forEach((el) => {
    el.classList.toggle('d-none', !roleAtLeast(State.user.role, el.dataset.perm));
  });
  await setupTenantSwitcher();
  State.campaignWorkspaceId = campaignIdFromHash(window.location.hash);
  navigate(routeFromHash(window.location.hash));
}
/* ========== 多要素認証(段階1) ========== */
// サーバの TET2_MFA_ENROLLMENT_REQUIRED_MESSAGE の書き出し(403 の理由がこれなら登録の画面へ移す)
const MFA_ENROLLMENT_REQUIRED_PREFIX = '多要素認証の登録が必要です';
let mfaRecoveryMode = false;
function showMfaStep() {
  $('#loginForm').classList.add('d-none');
  $('#loginMfaForm').classList.remove('d-none');
  $('#loginMfaError').classList.add('d-none');
  $('#loginMfaCode').value = '';
  $('#loginRecoveryCode').value = '';
  setMfaRecoveryMode(false);
}
function hideMfaStep() {
  $('#loginMfaForm').classList.add('d-none');
  $('#loginForm').classList.remove('d-none');
}
function setMfaRecoveryMode(on) {
  mfaRecoveryMode = on;
  $('#loginMfaCodeField').classList.toggle('d-none', on);
  $('#loginRecoveryField').classList.toggle('d-none', !on);
  $('#loginRecoveryToggle').textContent = on ? '認証アプリのコードを使う' : '回復コードを使う';
  $('#loginMfaLead').textContent = on
    ? '控えておいた回復コードを1つ入力してください。'
    : '認証アプリに表示されている6桁のコードを入力してください。';
  (on ? $('#loginRecoveryCode') : $('#loginMfaCode')).focus();
}
async function submitMfa() {
  const body = mfaRecoveryMode
    ? { recovery_code: $('#loginRecoveryCode').value.trim() }
    : { code: $('#loginMfaCode').value.trim() };
  if (!(body.code || body.recovery_code)) throw new Error('確認コードを入力してください');
  const data = await api('api/auth.php', { method: 'POST', query: { action: 'mfa_verify' }, body });
  hideMfaStep();
  State.user = data.user;
  State.csrf = data.csrf;
  await afterLogin();
}
function showForcedEnrollment() {
  if (!$('#mfaEnrollView').classList.contains('d-none')) return;
  $('#loginView').classList.add('d-none');
  $('#appView').classList.add('d-none');
  $('#mfaEnrollView').classList.remove('d-none');
  renderMfaEnrollment($('#mfaEnrollBody'), async () => {
    const data = await api('api/auth.php', { query: { action: 'me' } });
    State.user = data.user;
    State.csrf = data.csrf;
    $('#mfaEnrollView').classList.add('d-none');
    await afterLogin();
  });
}
// 秘密鍵は4文字ずつ区切って見せる(認証アプリには区切りなしでも区切りありでも入る)
const formatMfaSecret = (secret) => String(secret).replace(/(.{4})/g, '$1 ').trim();
/**
 * 登録の3つの手順(始める → 秘密鍵を認証アプリに入れてコードを確かめる → 回復コードを控える)を container に描く。
 * QR コードの部品は同梱していないので、秘密鍵と otpauth の URI を出す。
 */
function renderMfaEnrollment(container, onDone) {
  container.innerHTML = `<p class="mb-2">スマートフォンの認証アプリ（Google Authenticator、Microsoft Authenticator など）に登録し、ログインの時にパスワードと6桁のコードを入力します。</p>
    <div id="mfaEnrollError" class="alert alert-danger py-2 d-none" role="alert"></div>
    <button type="button" class="btn btn-primary" id="mfaStartBtn">登録を始める</button>`;
  const showError = (message) => { const el = container.querySelector('#mfaEnrollError'); el.textContent = message; el.classList.remove('d-none'); };
  container.querySelector('#mfaStartBtn').addEventListener('click', async (ev) => {
    ev.currentTarget.disabled = true;
    let setup;
    try { setup = await api('api/users.php', { method: 'POST', query: { action: 'mfa_setup' }, body: {} }); }
    catch (e) { showError(e.message); ev.currentTarget.disabled = false; return; }
    renderMfaSetupStep(container, setup, onDone);
  });
}
function renderMfaSetupStep(container, setup, onDone) {
  container.innerHTML = `<ol class="small ps-3 mb-2">
      <li>認証アプリで「セットアップキーを入力」（キーを手動で入力）を選びます。</li>
      <li>アカウント名に「${esc(setup.issuer)}」、キーに次の文字列を入れ、種類は「時間ベース」を選びます。</li>
    </ol>
    <div class="mfa-secret mb-2" id="mfaSecret">${esc(formatMfaSecret(setup.secret))}</div>
    <details class="mb-3"><summary class="small">URI で登録する（パスワード管理ソフトなど）</summary>
      <label class="form-label small mt-2" for="mfaUri">otpauth の URI</label>
      <textarea class="form-control mfa-uri" id="mfaUri" rows="3" readonly>${esc(setup.otpauth_uri)}</textarea></details>
    <div class="mb-2"><label class="form-label" for="mfaEnableCode">認証アプリに表示された6桁のコード</label>
      <input type="text" class="form-control mfa-code-input" id="mfaEnableCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6"></div>
    <div id="mfaEnrollError" class="alert alert-danger py-2 d-none" role="alert"></div>
    <button type="button" class="btn btn-primary" id="mfaEnableBtn">確認して有効にする</button>`;
  const input = container.querySelector('#mfaEnableCode');
  const btn = container.querySelector('#mfaEnableBtn');
  const submit = async () => {
    const err = container.querySelector('#mfaEnrollError');
    err.classList.add('d-none');
    btn.disabled = true;
    try {
      const r = await api('api/users.php', { method: 'POST', query: { action: 'mfa_enable' }, body: { code: input.value.trim() } });
      if (State.user) State.user.mfa_enabled = true;
      renderRecoveryCodes(container, r.recovery_codes, '多要素認証を有効にしました。', onDone);
    } catch (e) { err.textContent = e.message; err.classList.remove('d-none'); input.focus(); }
    finally { btn.disabled = false; }
  };
  btn.addEventListener('click', submit);
  input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
  input.focus();
}
// 回復コードは、この画面を閉じると二度と出せない(サーバはハッシュだけを持つ)
function renderRecoveryCodes(container, codes, lead, onDone) {
  container.innerHTML = `<div class="alert alert-success py-2" role="status">${esc(lead)}</div>
    <p class="small mb-2">端末をなくした時にコードの代わりに使う<strong>回復コード</strong>です。この画面を閉じると二度と表示されません。安全な場所に控えてください。1つのコードは1回だけ使えます。</p>
    <ul class="mfa-recovery-list mb-2" id="mfaRecoveryCodes">${codes.map((c) => `<li>${esc(c)}</li>`).join('')}</ul>
    <button type="button" class="btn btn-outline-secondary btn-sm mb-3" id="mfaCopyCodes"><i class="bi bi-clipboard" aria-hidden="true"></i> コピー</button>
    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="mfaSavedCheck">
      <label class="form-check-label" for="mfaSavedCheck">回復コードを控えました</label></div>
    <button type="button" class="btn btn-primary" id="mfaDoneBtn" disabled>続ける</button>`;
  container.querySelector('#mfaCopyCodes').addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(codes.join('\n')); toast('回復コードをコピーしました', 'ok'); }
    catch { toast('コピーできませんでした。画面から書き写してください', 'err'); }
  });
  const done = container.querySelector('#mfaDoneBtn');
  container.querySelector('#mfaSavedCheck').addEventListener('change', (e) => { done.disabled = !e.target.checked; });
  done.addEventListener('click', async () => {
    done.disabled = true;
    try { await onDone(); } catch (e) { toast(e.message, 'err'); done.disabled = false; }
  });
}
// 上のバーの盾のボタン: 本人の多要素認証の状態、登録、回復コードの作り直し、解除
async function openAccountSecurity() {
  let st;
  try { st = await api('api/users.php', { query: { action: 'mfa_status' } }); }
  catch (e) { toast(e.message, 'err'); return; }
  showInfoModal('多要素認証', '<div id="accountMfaBody"></div>');
  renderAccountMfa(st);
}
function renderAccountMfa(st) {
  const box = $('#accountMfaBody');
  const finish = async () => { modalInstance.hide(); toast('多要素認証の設定を保存しました', 'ok'); };
  if (!st.enabled) {
    box.innerHTML = `<p class="mb-2"><span class="text-muted">未登録</span>${st.required ? '（組織の方針で必須です）' : ''}</p>
      ${st.available ? '<div id="accountMfaEnroll"></div>'
        : '<div class="alert alert-warning py-2">多要素認証の準備ができていません（暗号鍵が未設定です）。システム管理者に連絡してください。</div>'}`;
    if (st.available) renderMfaEnrollment($('#accountMfaEnroll'), finish);
    return;
  }
  box.innerHTML = `<p class="mb-1"><span class="badge user-badge-mfa">有効</span> <span class="small text-muted">${esc(fmtDate(st.enabled_at))} から</span></p>
    <p class="small mb-3">使える回復コード: <strong id="accountMfaRemaining">${Number(st.recovery_remaining)}</strong> 個${Number(st.recovery_remaining) <= 2 ? '（残りが少ないので作り直してください）' : ''}</p>
    <div class="mb-2"><label class="form-label" for="accountMfaCode">確認コード（認証アプリの6桁）</label>
      <input type="text" class="form-control mfa-code-input" id="accountMfaCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6"></div>
    <div class="mb-2"><label class="form-label" for="accountMfaPassword">またはパスワード（解除の時だけ使えます）</label>
      <input type="password" class="form-control" id="accountMfaPassword" autocomplete="current-password"></div>
    <div id="accountMfaError" class="alert alert-danger py-2 d-none" role="alert"></div>
    <div class="d-flex gap-2 flex-wrap">
      <button type="button" class="btn btn-outline-secondary btn-sm" id="accountMfaRegen">回復コードを作り直す</button>
      <button type="button" class="btn btn-outline-danger btn-sm" id="accountMfaDisable">多要素認証を解除</button>
    </div>
    ${st.required ? '<div class="form-text mt-2">組織の方針で必須のため、解除すると次の操作から登録の画面になります。</div>' : ''}`;
  const fail = (e) => { const el = $('#accountMfaError'); el.textContent = e.message; el.classList.remove('d-none'); };
  $('#accountMfaRegen').addEventListener('click', async () => {
    $('#accountMfaError').classList.add('d-none');
    try {
      const r = await api('api/users.php', { method: 'POST', query: { action: 'mfa_recovery_regenerate' }, body: { code: $('#accountMfaCode').value.trim() } });
      renderRecoveryCodes(box, r.recovery_codes, '回復コードを作り直しました。前の回復コードはもう使えません。', finish);
    } catch (e) { fail(e); }
  });
  $('#accountMfaDisable').addEventListener('click', async () => {
    $('#accountMfaError').classList.add('d-none');
    if (!confirm('多要素認証を解除しますか？\nログインはパスワードだけになります。')) return;
    const code = $('#accountMfaCode').value.trim();
    const body = code ? { code } : { password: $('#accountMfaPassword').value };
    try {
      await api('api/users.php', { method: 'POST', query: { action: 'mfa_disable' }, body });
      if (State.user) State.user.mfa_enabled = false;
      modalInstance.hide();
      toast('多要素認証を解除しました', 'ok');
      if (st.required) {
        const me = await api('api/auth.php', { query: { action: 'me' } });
        if (me.user?.mfa_enrollment_required) { State.user = me.user; showForcedEnrollment(); }
      }
    } catch (e) { fail(e); }
  });
}
// 管理画面ユーザの一覧: 端末をなくした人の多要素認証を解除する
async function resetUserMfa(id) {
  const u = Cache.users[id];
  if (!u) return;
  if (!confirm(`${u.email} の多要素認証を解除しますか？\nご本人は次のログインでパスワードだけで入り、改めて登録します。`)) return;
  try {
    await api('api/users.php', { method: 'POST', query: { action: 'mfa_reset' }, body: { id } });
    toast('多要素認証を解除しました', 'ok');
    renderAdminUsers();
  } catch (e) { toast(e.message, 'err'); }
}
// パスワードと多要素認証の方針(組織管理者は自組織、システム管理者は全テナント共通も)
let securityPolicy = null;
function securityPolicyText(p) {
  return `パスワードは${p.min_length}文字以上で、${Number(p.min_classes) >= 4 ? '英大文字・英小文字・数字・記号をすべて含む' : '4種類の文字のうち3種類以上を含む'}。多要素認証は${p.require_mfa ? '必須' : '任意'}。`;
}
async function renderSecurityPolicy() {
  const el = $('#securityPolicySummary');
  try { securityPolicy = await api('api/users.php', { query: { action: 'security_policy' } }); }
  catch (e) { el.textContent = `方針を読み込めませんでした（${e.message}）`; return; }
  const g = securityPolicy.global || {};
  const words = (securityPolicy.tenant?.banned_words?.length || 0) + (g.banned_words_count ?? g.banned_words?.length ?? 0);
  el.textContent = securityPolicyText(securityPolicy.effective)
    + (securityPolicy.global.configured ? '（全テナント共通の方針と合わせた結果）' : '')
    + ` よく使われる語句と組織名・ドメイン名を含むパスワードは使えません${words ? `（組織で足した禁止語 ${words}語）` : ''}。`;
}
function editSecurityPolicy() {
  const p = securityPolicy;
  if (!p) return;
  const scopes = [...(p.tenant ? [['tenant', 'このテナント']] : []), ...(p.can_edit_global ? [['global', '全テナント共通（システム管理者を含む）']] : [])];
  const body = `<form id="policyForm">
    ${scopes.length > 1 ? `<div class="mb-2"><label class="form-label" for="pfScope">対象</label>
      <select class="form-select" id="pfScope" name="scope">${scopes.map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}</select></div>` : ''}
    <div class="mb-2"><label class="form-label" for="pfMinLength">パスワードの最小の文字数</label>
      <input class="form-control" type="number" id="pfMinLength" name="min_length" min="${p.limits.min_length}" max="${p.limits.max_length}" required>
      <div class="form-text">${p.limits.min_length}〜${p.limits.max_length}。${p.limits.min_length}文字より短くはできません。</div></div>
    <div class="mb-2"><label class="form-label" for="pfMinClasses">文字の種類</label>
      <select class="form-select" id="pfMinClasses" name="min_classes">
        <option value="3">英大文字・英小文字・数字・記号のうち3種類以上</option><option value="4">4種類すべて</option></select></div>
    <div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="pfRequireMfa" name="require_mfa"${p.mfa_available ? '' : ' disabled'}>
      <label class="form-check-label" for="pfRequireMfa">多要素認証を必須にする</label></div>
    <div class="form-text">${p.mfa_available ? '必須にすると、未登録のユーザは次の操作から登録の画面だけになります。'
      : '多要素認証の暗号鍵が未設定のため、今は必須にできません。'}</div>
    <div class="mb-2 mt-2"><label class="form-label" for="pfBannedWords">パスワードの禁止語（1行に1語）</label>
      <textarea class="form-control" id="pfBannedWords" name="banned_words" rows="4" placeholder="例: 社名の略称、製品名、所在地"></textarea>
      <div class="form-text">${p.limits.banned_word_min}〜${p.limits.banned_word_max}文字で${p.limits.banned_words}語まで。大文字と小文字は区別しません。
        よく使われる語句（password など）、組織名、テナントの識別子、本人のメールのドメインは、ここに書かなくても使えません。受講者のマイページには組織で足した語は当てません。</div></div>
    ${!p.can_edit_global && p.global.configured ? `<div class="small text-muted mt-2">全テナント共通の方針（${esc(securityPolicyText(p.global))}）の方が厳しい項目は、そちらが効きます。</div>` : ''}
  </form>`;
  showModal('パスワードと多要素認証の方針', body, async () => {
    const f = $('#policyForm');
    const scope = f.scope ? f.scope.value : scopes[0][0];
    await api('api/users.php', { method: 'POST', query: { action: 'security_policy' }, body: {
      scope, min_length: Number(f.min_length.value), min_classes: Number(f.min_classes.value), require_mfa: f.require_mfa.checked,
      banned_words: f.banned_words.value,
    } });
    toast('方針を保存しました', 'ok');
    // 自分が未登録のまま必須にした時は、すぐに登録の画面へ移す
    const me = await api('api/auth.php', { query: { action: 'me' } });
    if (me.user?.mfa_enrollment_required) { State.user = me.user; setTimeout(showForcedEnrollment, 300); return; }
    renderAdminUsers();
  });
  const f = $('#policyForm');
  const fill = () => {
    const cur = (f.scope ? f.scope.value : scopes[0][0]) === 'global' ? p.global : p.tenant;
    f.min_length.value = cur.min_length;
    f.min_classes.value = String(cur.min_classes);
    f.require_mfa.checked = !!cur.require_mfa;
    f.banned_words.value = (cur.banned_words || []).join('\n');
  };
  fill();
  f.scope?.addEventListener('change', fill);
}
function roleLabel(r) {
  return { superadmin:'システム管理者', tenant_admin:'組織管理者', operator:'オペレータ', viewer:'閲覧者' }[r] || r;
}
const ROLE_RANK = { viewer:1, operator:2, tenant_admin:3, superadmin:4 };
function roleAtLeast(have, need) { return (ROLE_RANK[have] || 0) >= (ROLE_RANK[need] || 99); }
// 役職カテゴリの正規値(サーバの TET2_POSITION_CATEGORIES と一致させる)。
// 旧称「社員」は取込・API 側でエイリアス変換されるため、選択肢には出さない。
const POSITION_CATEGORIES = ['役員', '管理職', '一般従業員'];
// カテゴリごとのバッジ色。役職マスタ画面・集計画面と共通。
const POSITION_CATEGORY_BADGE = { '役員': 'danger', '管理職': 'warning', '一般従業員': 'secondary' };

async function setupTenantSwitcher() {
  const sw = $('#tenantSwitcher');
  if (State.user.role !== 'superadmin') {
    State.activeTenantId = State.user.tenant_id;
    sw.classList.add('d-none');
    return;
  }
  const data = await api('api/tenants.php', { query: { action: 'list' } });
  State.tenants = data.tenants || [];
  const choices = tenantSwitcherChoices();
  sw.innerHTML = tenantSwitcherOptions(choices);
  if (choices.length) {
    // 作り直した時は、見ていたテナントが候補に残っていればそのままにする。
    // 初めての時は「そのsuperadminの所属テナント(users.tenant_id)」を優先し、なければ候補の先頭にする。
    const current = choices.find((t) => Number(t.id) === Number(State.activeTenantId));
    const preferred = choices.find((t) => Number(t.id) === Number(State.user.tenant_id));
    State.activeTenantId = Number((current || preferred || choices[0]).id);
    sw.value = State.activeTenantId;
    sw.classList.remove('d-none');
  } else {
    sw.classList.add('d-none');
  }
  sw.onchange = () => {
    if ($('#appModal').classList.contains('show') && !window.confirm('編集中の内容が失われる可能性があります。顧客を切り替えますか？')) {
      sw.value = State.activeTenantId;
      return;
    }
    modalInstance?.hide();
    applyActiveTenant(Number(sw.value));
    if (State.view === 'campaignWorkspace') {
      State.campaignWorkspaceId = null;
      navigate('dashboard');
      return;
    }
    renderCurrentView();
  };
}

// 見ているテナントを切り替える(上のバーの切り替えと、テナントの詳細の「切り替えて開く」で共通)
function applyActiveTenant(id) {
  riskDashboard?.invalidate();
  State.activeTenantId = id;
  clearTenantCache();
  reportSelectedId = null;
  resetEduRep();
  State.workspaceReview = null;
}

/* ========== ルーティング ========== */
let riskDashboard = null;
let contextHelp = null;
function renderRiskDashboard() {
  if (!riskDashboard) {
    riskDashboard = createRiskDashboard({
      api,
      root: $('#riskDashboardRoot'),
      getContext: () => ({
        tenantId: State.activeTenantId ?? State.user?.tenant_id ?? '',
        userKey: `${State.user?.id ?? ''}:${State.user?.email ?? ''}`,
      }),
      getView: () => State.view,
    });
  }
  return riskDashboard.render();
}
const VIEWS = {
  dashboard: renderDashboard,
  campaigns: renderCampaigns,
  campaignWorkspace: renderCampaignWorkspace,
  reports: renderReports,
  riskDashboard: renderRiskDashboard,
  groups: renderGroups,
  templates: renderTemplates,
  eduDeliveries: refreshEduDeliveries,
  eduQuestions: renderEduQuestions,
  eduReport: renderEduReport,
  masters: renderMasters,
  logs: renderLogs,
  users: renderUsers,
  tenants: renderTenants,
};
function campaignIdFromHash(hash) {
  const match = /^#campaignWorkspace\/([1-9]\d*)$/.exec(String(hash || ''));
  const id = match ? Number(match[1]) : null;
  return Number.isSafeInteger(id) && id > 0 ? id : null;
}
function routeFromHash(hash) {
  if (campaignIdFromHash(hash)) return 'campaignWorkspace';
  const route = String(hash || '').replace(/^#/, '');
  return Object.prototype.hasOwnProperty.call(VIEWS, route) ? route : 'dashboard';
}
function canNavigate(view) {
  if (!Object.prototype.hasOwnProperty.call(VIEWS, view)) return false;
  if (view === 'campaignWorkspace') return roleAtLeast(State.user?.role, 'operator') && Number.isSafeInteger(State.campaignWorkspaceId) && State.campaignWorkspaceId > 0;
  const link = $$('.app-sidebar .nav-link').find((item) => item.dataset.view === view);
  if (!link) return false;
  const guard = link.closest('[data-role], [data-perm]');
  return !guard || roleAtLeast(State.user?.role, guard.dataset.role || guard.dataset.perm);
}
function navigate(view) {
  if (!canNavigate(view)) view = 'dashboard';
  if (view !== 'logs') { credentialRequest++; closeCredentialDialog(); }
  // ビュー切替時に一覧自動更新タイマーを止める(campaigns に戻れば renderCampaigns が再設定)。
  if (campaignsRefreshTimer) { clearTimeout(campaignsRefreshTimer); campaignsRefreshTimer = null; }
  if (State.view === 'riskDashboard' && view !== 'riskDashboard') riskDashboard?.invalidate();
  // ほかの画面からテナント管理へ来た時は、詳細ではなく一覧から出す
  if (view === 'tenants' && State.view !== 'tenants') tenantDetailId = null;
  const previousView = State.view;
  State.view = view;
  const hash = view === 'campaignWorkspace' ? `#campaignWorkspace/${State.campaignWorkspaceId}` : `#${view}`;
  if (window.location.hash !== hash) window.location.hash = hash;
  $$('.view-panel').forEach((p) => p.classList.toggle('d-none', p.dataset.panel !== view));
  $$('.app-sidebar .nav-link').forEach((a) => {
    const current = a.dataset.view === view;
    a.classList.toggle('active', current);
    if (current) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
  });
  const viewLabel = $(`.app-sidebar .nav-link[data-view="${view}"]`)?.textContent.trim();
  document.title = viewLabel ? `${viewLabel} | TET v2` : 'TET v2 — 標的型メール訓練システム';
  setSidebarOpen(false);
  // 中央だけがスクロールするので、別の画面に移ったら中央を先頭に戻す
  if (State.view !== previousView) $('#appMain').scrollTop = 0;
  if (contextHelp && !$('#contextHelp').classList.contains('d-none')) contextHelp.render(view);
  renderCurrentView();
}
// モバイルの引き出し式の一覧。開閉の状態を aria-expanded にも反映する
function setSidebarOpen(open) {
  $('#sidebar').classList.toggle('open', open);
  $('#sidebarToggle').setAttribute('aria-expanded', String(open));
  $('#sidebarToggle').setAttribute('aria-label', open ? 'メニューを閉じる' : 'メニューを開く');
}
// 一覧の読み込みの状態: 描き始める前に表示中の表を「読み込み中」にし、失敗したら理由と再読み込みを出す。
// 各画面の描く関数を個別に直さずに済むよう、画面の切り替えの入口で扱う。
function panelTableBodies(view) {
  const panel = document.querySelector(`[data-panel="${view}"]`);
  return panel ? [...panel.querySelectorAll('table > tbody[id]')].filter((tb) => tb.offsetParent !== null) : [];
}
function tableColumns(tbody) { return tbody.closest('table')?.querySelectorAll('thead th').length || 1; }
function loadingRow(cols) {
  return `<tr class="table-state-row" data-state="loading"><td colspan="${cols}" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>読み込み中…</td></tr>`;
}
function errorRow(cols, message) {
  return `<tr class="table-state-row" data-state="error"><td colspan="${cols}" class="text-center py-4" role="alert">
    <div class="text-danger mb-2"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>読み込めませんでした。${esc(message)}</div>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="renderCurrentView()"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> 再読み込み</button></td></tr>`;
}
function renderCurrentView() {
  const view = State.view;
  const fn = VIEWS[view];
  if (!fn) return;
  const bodies = panelTableBodies(view);
  for (const tb of bodies) tb.innerHTML = loadingRow(tableColumns(tb));
  fn().then(() => {
    // 描く関数が触らなかった表は、読み込み中のまま残さない
    for (const tb of bodies) if (tb.querySelector('tr[data-state="loading"]')) tb.innerHTML = '';
  }).catch((e) => {
    for (const tb of bodies) if (tb.querySelector('tr[data-state="loading"]')) tb.innerHTML = errorRow(tableColumns(tb), e.message);
    toast(e.message, 'err');
  });
}

/* ========== ダッシュボード ========== */
let dashChart = null;
// 本番/テスト フィルタ: prod=本番のみ(既定・日常の確認は本番数値) / test=テストのみ / all=全部。
let dashTestFilter = 'prod';
function setDashTestFilter(v) { dashTestFilter = v; renderDashboard(); }
function dashboardWorkItems(campaigns) {
  return {
    drafts: campaigns.filter((c) => c.status === 'draft'),
    paused: campaigns.filter((c) => c.status === 'paused'),
    upcoming: campaigns.filter((c) => c.status === 'scheduled')
      .sort((a, b) => String(a.start_at || '').localeCompare(String(b.start_at || ''))),
  };
}
async function renderDashboard() {
  const tenantId = State.activeTenantId;
  const { campaigns: allCampaigns } = await api('api/campaigns.php', { query: { action: 'list' } });
  if (State.view !== 'dashboard' || State.activeTenantId !== tenantId) return;
  const filterBar = document.getElementById('dashFilterBar');
  if (filterBar) {
    const btn = (v, label) => `<button class="btn btn-sm btn-outline-secondary${dashTestFilter === v ? ' active' : ''}" aria-pressed="${dashTestFilter === v}" onclick="setDashTestFilter('${v}')">${label}</button>`;
    filterBar.innerHTML = `<div class="btn-group btn-group-sm">${btn('prod', '本番のみ')}${btn('test', 'テストのみ')}${btn('all', '全部')}</div>`;
  }
  const campaigns = allCampaigns.filter((c) => {
    if (dashTestFilter === 'prod') return !Number(c.is_test);
    if (dashTestFilter === 'test') return Number(c.is_test);
    return true; // all
  });
  const work = dashboardWorkItems(campaigns);
  const workItem = (c, action) => `<li class="ops-work-item"><span class="ops-work-name">${esc(c.name)}${Number(c.is_test) ? ' <span class="badge bg-info">TEST</span>' : ''}</span><span class="small text-muted">${action === 'scheduled' ? fmtDate(c.start_at) : action === 'paused' ? '送信停止中' : '内容確認待ち'}</span></li>`;
  $('#dashWorkItems').innerHTML = `
    <div class="ops-work-grid">
      <section class="ops-work-card" aria-label="配信前の確認待ち"><h6>配信前の確認待ち <span class="ops-count">${work.drafts.length}</span></h6>
        <ul>${work.drafts.slice(0, 5).map((c) => workItem(c, 'draft')).join('') || '<li class="text-muted small">該当なし</li>'}</ul>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="navigate('campaigns')">訓練一覧へ</button></section>
      <section class="ops-work-card" aria-label="送信停止中"><h6>送信停止中 <span class="ops-count">${work.paused.length}</span></h6>
        <ul>${work.paused.slice(0, 5).map((c) => workItem(c, 'paused')).join('') || '<li class="text-muted small">該当なし</li>'}</ul>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="navigate('campaigns')">状態を確認</button></section>
      <section class="ops-work-card" aria-label="次の配信予定"><h6>次の配信予定 <span class="ops-count">${work.upcoming.length}</span></h6>
        <ul>${work.upcoming.slice(0, 5).map((c) => workItem(c, 'scheduled')).join('') || '<li class="text-muted small">予定なし</li>'}</ul>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="navigate('campaigns')">予定を確認</button></section>
    </div>`;
  const total = campaigns.length;
  const running = campaigns.filter((c) => c.status === 'running' || c.status === 'scheduled').length;
  const done = campaigns.filter((c) => c.status === 'done').length;
  const targets = campaigns.reduce((s, c) => s + Number(c.target_count || 0), 0);
  const kpis = [
    { label: '総キャンペーン', value: total, icon: 'bi-envelope-paper' },
    { label: '実行中/予約', value: running, icon: 'bi-send', cls: running ? 'val-warning' : '' },
    { label: '完了', value: done, icon: 'bi-check-circle', cls: 'val-success' },
    { label: '対象者延べ', value: targets, icon: 'bi-people' },
  ];
  $('#kpiRow').innerHTML = kpis.map(kpiCard).join('');

  const labels = campaigns.map((c) => c.name);
  const counts = campaigns.map((c) => Number(c.target_count || 0));
  if (dashChart) dashChart.destroy();
  const ctx = $('#dashChart');
  dashChart = new Chart(ctx, {
    type: 'bar',
    data: { labels, datasets: [{ label: '対象者数', data: counts, backgroundColor: '#2563eb', borderRadius: 4 }] },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
  });
}

/* ========== キャンペーン ========== */
const STATUS_LABEL = { draft:'下書き', scheduled:'予約', running:'実行中', paused:'一時停止', done:'完了', cancelled:'中止' };
let campaignsRefreshTimer = null;
let campaignSortDesc = true;  // 並び順: true=降順(最新が上, 既定) / false=昇順(古い順が上)
function toggleCampaignSort() { campaignSortDesc = !campaignSortDesc; renderCampaigns(); }
// 本番/テスト フィルタ: all=全部(既定・運用一覧なのでまず全部見せる) / prod=本番のみ / test=テストのみ。
let campaignTestFilter = 'all';
function setCampaignTestFilter(v) { campaignTestFilter = v; renderCampaigns(); }
async function renderCampaigns() {
  renderSendControl();  // 送信制御パネル(ステータス+アラート)を描画・ポーリング開始
  const tenantId = State.activeTenantId;
  // 報告率と防衛失敗率はレポートの集計(report.php の campaigns)をそのまま使う。1回の問い合わせで全訓練分を返すので、行ごとに問い合わせない。
  // 集計を読めなくても一覧と操作ボタンは出す(率は「-」)。
  const [{ campaigns }, rates] = await Promise.all([
    api('api/campaigns.php', { query: { action: 'list' } }),
    api('api/report.php', { query: { action: 'campaigns', test_filter: 'all' } }).catch(() => ({ campaigns: [] })),
  ]);
  if (State.view !== 'campaigns' || State.activeTenantId !== tenantId) return;
  const rateById = {};
  for (const r of (rates.campaigns || [])) rateById[r.id] = r;
  // #列は表示上の通し番号(作成順=古い順に固定で1,2,3…)。並び順を変えても番号は変わらない。
  // 古い順(id昇順)でordinalを確定 → id→番号 のマップを作る。
  const campaignsAsc = campaigns.slice().sort((a, b) => a.id - b.id);
  const numById = {};
  campaignsAsc.forEach((c, i) => { numById[c.id] = i + 1; });
  // 本番/テストフィルタを適用(番号は全体基準で確定済みなので絞っても番号は不変)。
  const filtered = campaignsAsc.filter((c) => {
    if (campaignTestFilter === 'prod') return !Number(c.is_test);
    if (campaignTestFilter === 'test') return Number(c.is_test);
    return true; // all
  });
  // 表示順は選択された方向。降順(既定)は最新が上。番号は上記マップで固定。
  const shown = campaignSortDesc ? filtered.slice().reverse() : filtered;
  // フィルタUI(全部/本番のみ/テストのみ)をツールバーに描画(レポートと同じ btn-group)。
  const filterBar = document.getElementById('campaignFilterBar');
  if (filterBar) {
    const fbtn = (v, label) => `<button class="btn btn-sm btn-outline-secondary${campaignTestFilter === v ? ' active' : ''}" aria-pressed="${campaignTestFilter === v}" onclick="setCampaignTestFilter('${v}')">${label}</button>`;
    filterBar.innerHTML = `<div class="btn-group btn-group-sm">${fbtn('all', '全部')}${fbtn('prod', '本番のみ')}${fbtn('test', 'テストのみ')}</div>`;
  }
  // 並び順トグルUI(ヘッダの#列に矢印ボタン)。
  const sortArrow = campaignSortDesc ? 'bi-sort-down' : 'bi-sort-up';
  const sortLabel = campaignSortDesc ? '最新が上(降順)' : '古い順が上(昇順)';
  const head = document.getElementById('campaignsHead');
  if (head) {
    head.innerHTML = `<tr><th><button class="btn btn-sm btn-link p-0 text-decoration-none" onclick="toggleCampaignSort()" title="${sortLabel}・クリックで切替">#<i class="bi ${sortArrow}"></i></button></th><th>名称</th><th>状態</th><th>対象</th><th title="${esc(REPORT_RATE_TITLE)}">報告率</th><th title="${esc(FAILURE_RATE_TITLE)}">防衛失敗率</th><th>開始</th><th>操作</th></tr>`;
  }
  $('#campaignsBody').innerHTML = shown.length ? shown.map((c) => `
    <tr>
      <td>${numById[c.id]}</td>
      <td>${esc(c.name)}${Number(c.is_test) ? ' <span class="badge bg-info">TEST</span>' : ''}</td>
      <td><span class="badge st-${c.status}">${STATUS_LABEL[c.status] || c.status}</span>${c.closed_at ? ' <span class="badge bg-secondary">クローズ</span>' : ''}</td>
      <td>${c.target_count}</td>
      ${campaignRateCells(rateById[c.id])}
      <td class="small text-muted">${fmtDate(c.start_at)}</td>
      <td><div class="d-flex flex-wrap gap-1">
        ${roleAtLeast(State.user.role, 'operator') && c.status === 'draft'
          ? `<button class="btn btn-sm btn-outline-secondary" onclick="editCampaign(${c.id})" title="修正（下書きを編集）"><i class="bi bi-pencil"></i></button>` : ''}
        ${roleAtLeast(State.user.role, 'operator') && c.status === 'draft'
          ? `<button class="btn btn-sm btn-outline-secondary" onclick="generateCampaign(${c.id})" title="生成確認"><i class="bi bi-file-earmark-check"></i></button>` : ''}
        ${roleAtLeast(State.user.role, 'operator') && (c.status === 'scheduled' || c.status === 'paused')
          ? `<button class="btn btn-sm btn-outline-secondary" onclick="toDraftCampaign(${c.id})" title="下書きに戻す（送信予約を取り消して編集可能に）"><i class="bi bi-arrow-counterclockwise"></i></button>` : ''}
        ${['scheduled','running','paused','done'].includes(c.status)
          ? `<button class="btn btn-sm btn-outline-secondary" onclick="showCampaignData(${c.id})" title="データ確認"><i class="bi bi-table"></i></button>` : ''}
        ${roleAtLeast(State.user.role, 'operator') && c.status === 'draft'
          ? `<button class="btn btn-sm btn-outline-primary" onclick="launchCampaign(${c.id})" title="配信前確認へ"><i class="bi bi-clipboard-check" aria-hidden="true"></i> 配信前確認</button>` : ''}
        ${['running','scheduled','paused'].includes(c.status)
          ? `<button class="btn btn-sm btn-outline-secondary" onclick="showCampaignProgress(${c.id})" title="送信進捗"><i class="bi bi-bar-chart-line"></i></button>` : ''}
        ${roleAtLeast(State.user.role, 'operator') && (c.status === 'running' || c.status === 'scheduled')
          ? `<button class="btn btn-sm btn-danger" onclick="stopCampaign(${c.id})" title="緊急停止"><i class="bi bi-stop-circle"></i></button>` : ''}
        ${roleAtLeast(State.user.role, 'operator') && c.status === 'paused'
          ? `<button class="btn btn-sm btn-outline-primary" onclick="resumeCampaign(${c.id})" title="停止点から再開"><i class="bi bi-play-circle"></i></button>` : ''}
        ${roleAtLeast(State.user.role, 'operator') && ['done','paused','cancelled'].includes(c.status)
          ? `<button class="btn btn-sm btn-outline-secondary" onclick="relaunchCampaign(${c.id})" title="複製して再送信（新しい下書きを作成）"><i class="bi bi-arrow-repeat"></i></button>` : ''}
        ${roleAtLeast(State.user.role, 'operator')
          ? `<button class="btn btn-sm btn-outline-secondary" onclick="renameCampaign(${c.id})" title="名称変更（送信データには影響しません）"><i class="bi bi-input-cursor-text"></i></button>` : ''}
        ${roleAtLeast(State.user.role, 'operator')
          ? `<button class="btn btn-sm btn-outline-secondary" onclick="openRevealMailSettings(${c.id})" title="種明かしメール（既定は送らない）" aria-label="種明かしメールの設定" data-reveal-mail="${c.id}"><i class="bi bi-envelope-open"></i></button>` : ''}
        ${roleAtLeast(State.user.role, 'operator') && !c.closed_at
          ? `<button class="btn btn-sm btn-outline-secondary" onclick="toggleTestCampaign(${c.id}, ${Number(c.is_test) ? 1 : 0})" title="${Number(c.is_test) ? '本番系へ切替（分類のみ・送信データには影響しません）' : 'テスト系へ切替（分類のみ・送信データには影響しません）'}"><i class="bi ${Number(c.is_test) ? 'bi-toggle-on' : 'bi-toggle-off'}"></i></button>` : ''}
        ${roleAtLeast(State.user.role, 'operator')
          ? `<button class="btn btn-sm btn-outline-secondary" onclick="openCampaignSurveyFollowup(${c.id})" title="訓練後のアンケート（クローズした時に配る設定）" aria-label="訓練後のアンケート"><i class="bi bi-ui-checks" aria-hidden="true"></i></button>` : ''}
        ${roleAtLeast(State.user.role, 'operator')
          ? `<button class="btn btn-sm btn-outline-secondary" onclick="duplicateCampaign(${c.id})" title="複製（設定・対象者を引き継いで下書き作成）"><i class="bi bi-files"></i></button>` : ''}
        ${roleAtLeast(State.user.role, 'operator')
          ? `<button class="btn btn-sm btn-outline-danger" onclick="deleteCampaign(${c.id})" title="削除（90日間はデータ保持、その後自動削除）"><i class="bi bi-trash"></i></button>` : ''}
      </div></td>
    </tr>`).join('') : emptyRow(8);
  // 進行中(実行中/予約)のキャンペーンがあれば、送信完了→done 遷移を画面に反映するため
  // 15秒ごとに一覧を自動更新する。緊急停止ボタンが完了後も残る問題への対処。
  if (campaignsRefreshTimer) { clearTimeout(campaignsRefreshTimer); campaignsRefreshTimer = null; }
  const hasActive = campaigns.some((c) => c.status === 'running' || c.status === 'scheduled');
  if (hasActive && State.view === 'campaigns') {
    campaignsRefreshTimer = setTimeout(() => { if (State.view === 'campaigns') renderCampaigns(); }, 15000);
  }
}

/* ========== メール送信制御パネル（送信ステータス＋アラート） ========== */
let sendControlTimer = null;
let sendControlAlertsOpen = false;
const SEND_STATUS_LABEL = {
  idle: '待機', running: '送信中', paused: '一時停止',
  stopped: '停止', completed: '完了', error: 'エラー',
};
// アラート種別ごとの深刻度（色分け）。
const ALERT_SEVERITY = {
  MULTIPLE_DEFERRED: 'danger', AUTO_STOP: 'danger', SEND_FAILED: 'danger',
  SEND_EXCEPTION: 'danger', BOUNCED: 'danger',
  AUTO_PAUSE: 'warning', RATE_LIMITED: 'warning', GREYLISTED: 'warning',
  CONNECTION_REFUSED: 'warning', CONNECTION_TIMEOUT: 'warning',
  TEMPORARILY_REJECTED: 'warning', DEFERRED: 'warning',
};

async function renderSendControl() {
  const el = $('#sendControlPanel');
  if (!el) return;
  const tenantId = State.activeTenantId;
  let statusData, alertsData;
  try {
    statusData = await api('api/send_control.php', { query: { action: 'status' } });
    alertsData = await api('api/send_control.php', { query: { action: 'alerts' } });
  } catch (e) {
    if (State.view === 'campaigns' && State.activeTenantId === tenantId) el.innerHTML = '';
    return;
  }
  if (State.view !== 'campaigns' || State.activeTenantId !== tenantId) return;
  const statuses = statusData.statuses || [];
  const alerts = alertsData.data || [];
  const activeCount = statusData.active_count || 0;

  // 実行中（running系）のキャンペーンを優先表示。無ければ直近の完了を1件見せる。
  const running = statuses.filter((s) => ['running', 'scheduled', 'paused'].includes(s.campaign_status));
  const shown = running.length ? running : statuses.slice(0, 1);

  const statusRows = shown.map((s) => {
    const rate = s.total > 0 ? Math.round((s.processed * 100) / s.total) : 0;
    const stLabel = SEND_STATUS_LABEL[s.status] || s.status;
    const stColor = s.status === 'running' ? 'primary'
      : s.status === 'completed' ? 'success'
      : (s.status === 'error' || s.status === 'stopped') ? 'danger'
      : s.status === 'paused' ? 'warning' : 'secondary';
    const stopBadge = s.is_stopped ? '<span class="badge bg-danger ms-1">停止フラグ</span>' : '';
    const stopBtn = (roleAtLeast(State.user.role, 'operator') && s.status === 'running')
      ? `<button class="btn btn-sm btn-danger ms-2" onclick="stopCampaign(${s.campaign_id})" title="緊急停止"><i class="bi bi-stop-circle"></i> 停止</button>` : '';
    return `
      <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
        <span class="badge bg-${stColor}">${stLabel}</span>
        <span class="fw-bold">${esc(s.campaign_name)}</span>
        <span class="small text-muted">${s.success}/${s.total} 送信（失敗 ${s.error}）</span>
        ${stopBadge}
        <div class="progress flex-grow-1" style="height:16px;min-width:120px;max-width:280px">
          <div class="progress-bar bg-${stColor}" style="width:${rate}%">${rate}%</div>
        </div>
        ${s.current_email ? `<span class="small text-muted">送信中: ${esc(s.current_email)}</span>` : ''}
        <span class="small text-muted">更新 ${esc(s.timestamp || '—')}</span>
        ${stopBtn}
      </div>`;
  }).join('');

  const dangerCount = alerts.filter((a) => ALERT_SEVERITY[a.type] === 'danger').length;
  const alertBadge = alerts.length
    ? `<button class="btn btn-sm btn-outline-${dangerCount ? 'danger' : 'warning'}" onclick="toggleSendAlerts()">
         <i class="bi bi-exclamation-triangle"></i> アラート ${alerts.length}
         <i class="bi bi-chevron-${sendControlAlertsOpen ? 'up' : 'down'}"></i>
       </button>`
    : '<span class="small text-success"><i class="bi bi-check-circle"></i> アラートなし</span>';

  const alertList = (sendControlAlertsOpen && alerts.length) ? `
    <div class="border rounded p-2 mt-2" style="max-height:240px;overflow-y:auto">
      ${roleAtLeast(State.user.role, 'operator')
        ? `<div class="text-end mb-1"><button class="btn btn-sm btn-outline-secondary" onclick="clearSendAlerts()"><i class="bi bi-trash"></i> アラートをクリア</button></div>` : ''}
      ${alerts.map((a) => {
        const sev = ALERT_SEVERITY[a.type] || 'secondary';
        return `<div class="small border-start border-3 border-${sev} ps-2 mb-1">
          <span class="badge bg-${sev}">${esc(a.type)}</span>
          <span>${esc(a.message)}</span>
          ${a.to_email ? `<span class="text-muted">- ${esc(a.to_email)}</span>` : ''}
          ${a.campaign_name ? `<span class="text-muted">[${esc(a.campaign_name)}]</span>` : ''}
          <span class="text-muted float-end">${esc(a.timestamp || '')}</span>
        </div>`;
      }).join('')}
    </div>` : '';

  el.innerHTML = `
    <div class="card">
      <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-bold"><i class="bi bi-broadcast me-1"></i>メール送信制御
          ${activeCount ? `<span class="badge bg-primary ms-1">実行中 ${activeCount}</span>` : ''}</span>
        ${alertBadge}
      </div>
      <div class="card-body py-2">
        ${statusRows || '<span class="small text-muted">送信中のキャンペーンはありません</span>'}
        ${alertList}
      </div>
    </div>`;

  // 送信中は3秒ポーリング。無ければ止める（idle/completed は手動更新）。
  if (sendControlTimer) { clearTimeout(sendControlTimer); sendControlTimer = null; }
  if (activeCount > 0 && State.view === 'campaigns') {
    sendControlTimer = setTimeout(() => { if (State.view === 'campaigns') renderSendControl(); }, 3000);
  }
}

function toggleSendAlerts() {
  sendControlAlertsOpen = !sendControlAlertsOpen;
  renderSendControl();
}

async function clearSendAlerts() {
  if (!confirm('アラート履歴をクリアします。よろしいですか？')) return;
  try {
    await api('api/send_control.php', { method: 'POST', query: { action: 'clear_alerts' }, body: {} });
    toast('アラートをクリアしました', 'ok');
    renderSendControl();
  } catch (e) { toast(e.message, 'err'); }
}

let campaignWorkspaceUi = null;
let campaignWorkspaceRequest = 0;
function launchCampaign(id) {
  State.campaignWorkspaceId = Number(id);
  State.workspaceReview = null;
  navigate('campaignWorkspace');
}
async function renderCampaignWorkspace() {
  const id = State.campaignWorkspaceId;
  const tenantId = State.activeTenantId;
  const requestId = ++campaignWorkspaceRequest;
  if (!campaignWorkspaceUi) {
    campaignWorkspaceUi = createCampaignWorkspace($('#campaignWorkspaceRoot'), {
      back: () => navigate('campaigns'),
      edit: () => editCampaign(State.campaignWorkspaceId),
      refresh: () => renderCampaignWorkspace(),
      launch: () => confirmCampaignWorkspaceLaunch(),
    });
  }
  campaignWorkspaceUi.loading();
  State.workspaceReview = null;
  try {
    const reviewData = await api('api/campaign_launch.php', { method: 'POST', query: { action: 'preflight' }, body: { id } });
    if (requestId !== campaignWorkspaceRequest || State.view !== 'campaignWorkspace' || State.campaignWorkspaceId !== id || State.activeTenantId !== tenantId) return;
    const review = reviewData.preflight;
    State.workspaceReview = { id, tenantId, review };
    campaignWorkspaceUi.render({ review });
  } catch (error) {
    if (requestId === campaignWorkspaceRequest && State.view === 'campaignWorkspace' && State.campaignWorkspaceId === id && State.activeTenantId === tenantId) {
      campaignWorkspaceUi.error(error.message);
    }
  }
}
async function confirmCampaignWorkspaceLaunch() {
  const current = State.workspaceReview;
  if (!current || current.id !== State.campaignWorkspaceId || current.tenantId !== State.activeTenantId || !current.review.can_launch) {
    toast('配信前確認をやり直してください', 'err');
    return;
  }
  const summary = current.review.summary;
  const distribution = summary.test_distribution.map((row) => `${row.email}: ${row.count}通`).join('\n');
  const mode = summary.is_test ? 'TEST（全件転送）' : '本番';
  const message = `${summary.campaign_name}\n${mode} / 対象 ${summary.target_count}名 / 総通数 ${summary.send_count}通\n${summary.start_at} ～ ${summary.end_at}${distribution ? `\n${distribution}` : ''}\n\n配信を予約しますか？`;
  if (!confirm(message)) return;
  campaignWorkspaceUi.setLaunching(true);
  // 大人数のキャンペーンは送信データ生成に時間がかかる(1,876人で約40秒)。
  // 生成中と分かる表示を出し、api の既定30秒では切れるので timeout を延ばす。
  const overlay = showProgress('送信データを生成しています…（対象人数が多いと1分ほどかかります）');
  try {
    const r = await api('api/campaign_launch.php', {
      method: 'POST', query: { action: 'launch' }, body: { id: current.id, revision: current.review.revision }, timeout: 180000,
    });
    State.workspaceReview = null;
    toast(`配信を予約しました（${r.batches} バッチ）`, 'ok');
    navigate('campaigns');
  } catch (e) {
    toast(e.message, 'err');
    await renderCampaignWorkspace();
  } finally {
    campaignWorkspaceUi.setLaunching(false);
    overlay.close();
  }
}
async function stopCampaign(id) {
  if (!confirm('⚠️ メール送信を緊急停止します。\n未実行分の送信を止め、送信済みは保持されます。よろしいですか？')) return;
  try { await api('api/campaign_launch.php', { method: 'POST', query: { action: 'stop' }, body: { id } });
    toast('緊急停止しました', 'ok'); renderCampaigns(); } catch (e) { toast(e.message, 'err'); }
}
async function resumeCampaign(id) {
  if (!confirm('停止した位置から送信を再開します。\n送信済みの宛先には再送しません。よろしいですか？')) return;
  try { const r = await api('api/campaign_launch.php', { method: 'POST', query: { action: 'resume' }, body: { id } });
    toast(r.batches > 0 ? `再開しました（残り ${r.batches} バッチ）` : '未送信の宛先はありませんでした', 'ok');
    renderCampaigns(); } catch (e) { toast(e.message, 'err'); }
}
let campaignProgressTimer = null;
async function showCampaignProgress(id) {
  const render = async () => {
    let p;
    try { p = await api('api/campaign_launch.php', { method: 'POST', query: { action: 'progress' }, body: { id } }); }
    catch (e) { return `<div class="text-danger">進捗の取得に失敗しました</div>`; }
    const stopBadge = p.is_stopped ? '<span class="badge bg-danger ms-2">停止フラグあり</span>' : '';
    const batchRows = Object.entries(p.batches || {}).map(([s, n]) => `<span class="badge bg-secondary me-1">${esc(s)}: ${n}</span>`).join('') || '<span class="text-muted">なし</span>';
    return `
      <div class="mb-2">状態: <span class="badge st-${p.campaign_status}">${STATUS_LABEL[p.campaign_status] || p.campaign_status}</span>${stopBadge}</div>
      <div class="progress mb-2" style="height:22px"><div class="progress-bar" style="width:${p.progress_rate}%">${p.progress_rate}%</div></div>
      <div class="mb-2 small">送信済 <b>${p.sent}</b> / 全 <b>${p.total}</b> 件（未送信 ${p.pending}）</div>
      <div class="mb-2 small">再開項番（次に送る koban）: <b>${p.resume_koban ?? '—（全件送信済み）'}</b></div>
      <div class="small text-muted">バッチ: ${batchRows}</div>`;
  };
  showInfoModal('送信進捗', await render());
  // モーダル表示中は3秒ごとに自動更新
  if (campaignProgressTimer) clearInterval(campaignProgressTimer);
  campaignProgressTimer = setInterval(async () => {
    const modal = document.getElementById('appModalBody');
    if (!modal || !document.querySelector('.modal.show')) { clearInterval(campaignProgressTimer); campaignProgressTimer = null; return; }
    modal.innerHTML = await render();
  }, 3000);
}
async function deleteCampaign(id) {
  if (!confirm('キャンペーンを削除します。\n\n削除後 90日間はデータを保持し、その後に自動で完全削除（ビーコン・追跡ファイル含む）されます。\n実行中の場合は送信も停止します。よろしいですか？')) return;
  try { await api('api/campaigns.php', { method: 'POST', query: { action: 'delete' }, body: { id } });
    toast('削除しました（90日間はデータ保持）', 'ok'); renderCampaigns(); } catch (e) { toast(e.message, 'err'); }
}
async function editCampaign(id, initialStep = 0) {
  // 下書きキャンペーンの編集モーダルを開く（既存値をプリフィル）。
  try {
    await openCampaignModal(id, initialStep);
  } catch (e) { toast(e.message || '編集フォームを開けませんでした', 'err'); }
}
async function toDraftCampaign(id) {
  if (!confirm('このキャンペーンを下書きに戻します。\n\n送信予約（未実行分）を取り消し、編集できる状態にします。\n送信済みの宛先はそのまま残ります。よろしいですか？')) return;
  try {
    await api('api/campaign_launch.php', { method: 'POST', query: { action: 'to_draft' }, body: { id } });
    toast('下書きに戻しました', 'ok'); renderCampaigns();
  } catch (e) { toast(e.message, 'err'); }
}
async function renameCampaign(id) {
  // 現在の名前を初期値にする(一覧APIから取得。onclick属性に名前を埋め込むと
  // クォート混入で壊れるため、id だけ受け取り名前はここで引く)。
  let current = '';
  try {
    const { campaigns } = await api('api/campaigns.php', { query: { action: 'list' } });
    const c = (campaigns || []).find((x) => x.id === id);
    current = c ? c.name : '';
  } catch (e) { /* 取得失敗時は空で続行 */ }
  const name = prompt('新しいキャンペーン名を入力してください（送信データには影響しません）', current);
  if (name === null) return;                 // キャンセル
  if (name.trim() === '') { toast('名称を入力してください', 'err'); return; }
  if (name.trim() === current) return;       // 変更なし
  try {
    await api('api/campaigns.php', { method: 'POST', query: { action: 'rename' }, body: { id, name: name.trim() } });
    toast('名称を変更しました', 'ok'); renderCampaigns();
  } catch (e) { toast(e.message, 'err'); }
}
// 本番系/テスト系を後から切り替える(分類のみ。status に関わらず可能。送信データには影響しない)。
async function toggleTestCampaign(id, currentIsTest) {
  const toTest = Number(currentIsTest) ? 0 : 1;
  const label = toTest ? 'テスト系' : '本番系';
  if (!confirm(`このキャンペーンを「${label}」に切り替えます。\n分類のみの変更で、送信済みメールや追跡には影響しません。よろしいですか？`)) return;
  try {
    await api('api/campaigns.php', { method: 'POST', query: { action: 'set_test' }, body: { id, is_test: toTest } });
    toast(`${label}に切り替えました`, 'ok'); renderCampaigns();
  } catch (e) { toast(e.message, 'err'); }
}
async function duplicateCampaign(id) {
  if (!confirm('このキャンペーンを複製して新しい下書きを作成します。\n設定・対象者を引き継ぎます（送信履歴は引き継ぎません）。よろしいですか？')) return;
  try { const r = await api('api/campaigns.php', { method: 'POST', query: { action: 'duplicate' }, body: { id } });
    toast(`複製しました（下書き #${r.campaign.id}）`, 'ok'); renderCampaigns(); } catch (e) { toast(e.message, 'err'); }
}
async function relaunchCampaign(id) {
  if (!confirm('このキャンペーンを複製して再送信の下書きを作成します。\n同じ対象者に新しい tracking_id で再送できます。\n複製後、送信日時を設定して開始してください。よろしいですか？')) return;
  try { const r = await api('api/campaigns.php', { method: 'POST', query: { action: 'duplicate' }, body: { id } });
    toast(`再送信用の下書き #${r.campaign.id} を作成しました。日時を設定して開始してください`, 'ok'); renderCampaigns(); } catch (e) { toast(e.message, 'err'); }
}
async function generateCampaign(id) {
  toast('生成中…', 'info');
  try {
    const r = await api('api/campaign_generate.php', { method: 'POST', query: { action: 'generate' }, body: { id } });
    const rows = (r.files || []).map((f) =>
      `<tr><td><code>${esc(f.filename)}</code></td><td class="text-end">${f.rows}</td><td class="text-end text-muted">${f.bytes} B</td></tr>`).join('');
    const body = `<p class="small text-muted">${esc(r.note || '')}</p>
      <p class="small">出力先: <code>${esc(r.data_dir || '')}</code></p>
      <table class="table table-sm"><thead><tr><th>ファイル</th><th class="text-end">行数</th><th class="text-end">サイズ</th></tr></thead>
        <tbody>${rows || '<tr><td colspan="3" class="text-muted">生成物なし</td></tr>'}</tbody></table>`;
    showInfoModal('生成確認（送信はしていません）', body);
  } catch (e) { toast(e.message, 'err'); }
}
/* ========== マスタ管理（認証マスタ=共通 / 種明かし=テナント別） ========== */
const AUTH_MASTERS = [
  ['master.html', '認証フラグ0: 通常'],
  ['master2.html', '認証フラグ1: Box認証'],
  ['master3.html', '認証フラグ2: Microsoft365認証'],
  ['master4.html', '認証フラグ3: デジタルアーツ認証'],
  ['master5.html', '認証フラグ4: Microsoft 365（メールのみ）'],
];
async function renderMasters() {
  const canEditAuth = State.user.role === 'superadmin'; // 認証マスタは全テナント共通で、ほかのテナントの訓練にも効くのでシステム管理者だけ
  const authCards = AUTH_MASTERS.map(([f, label]) => `
    <div class="col-md-6 mb-2"><div class="card"><div class="card-body py-2 d-flex justify-content-between align-items-center">
      <div><div class="fw-bold small">${esc(label)}</div><code class="small text-muted">${esc(f)}</code></div>
      <div class="text-nowrap">
        <button class="btn btn-sm btn-outline-secondary" onclick="viewMaster('auth','${f}')">表示</button>
        <button class="btn btn-sm btn-outline-secondary" onclick="downloadMaster('auth','${f}')" title="ダウンロード"><i class="bi bi-download"></i></button>
        ${canEditAuth ? `<button class="btn btn-sm btn-outline-primary" onclick="editMaster('auth','${f}')">修正</button>` : ''}
      </div>
    </div></div></div>`).join('');
  $('#mastersBody').innerHTML = `
    <div class="card mb-3"><div class="card-header py-2 small fw-bold">認証画面マスタ（全テナント共通${canEditAuth ? '' : '・閲覧のみ'}）</div>
      <div class="card-body"><div class="row">${authCards}</div>
        <p class="small text-muted mb-0">Box/Microsoft365/デジタルアーツ等の偽ログイン画面。全テナント共通で、変更はシステム管理者のみ。</p></div></div>
    <div class="card mb-3"><div class="card-header py-2 small fw-bold">種明かし画面（既定・テナント別）</div>
      <div class="card-body">
        <p class="small text-muted">訓練の種明かし・啓発ページの<strong>既定</strong>。認証後やQRコードの遷移先で、キャンペーンでページを選ばない時に使います。テナントごとに差し替えできます（自社の問い合わせ先・ロゴ入り等）。</p>
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-sm btn-outline-secondary" onclick="viewMaster('reveal','reveal.html')"><i class="bi bi-eye me-1"></i>現在の内容を表示</button>
          <button class="btn btn-sm btn-outline-info" onclick="viewMaster('auth','master.html')"><i class="bi bi-file-earmark-text me-1"></i>共通の種明かしをプレビュー</button>
          <button class="btn btn-sm btn-outline-secondary" onclick="downloadMaster('reveal','reveal.html')"><i class="bi bi-download me-1"></i>ダウンロード</button>
          <button class="btn btn-sm btn-primary" data-perm="operator" onclick="editMaster('reveal','reveal.html')"><i class="bi bi-pencil me-1"></i>修正</button>
        </div>
      </div></div>
    <div class="card"><div class="card-header py-2 small fw-bold d-flex justify-content-between align-items-center">
        <span>種明かしページ（複数・キャンペーンごとに選択）</span>
        <button class="btn btn-sm btn-outline-primary" data-perm="operator" onclick="addRevealPage()"><i class="bi bi-plus-lg me-1"></i>ページを追加</button>
      </div>
      <div class="card-body">
        <p class="small text-muted mb-2">名前を付けた種明かしページを複数持て、キャンペーンの「送信環境」で選べます。選ばないキャンペーンは上の既定を使います。検証は既定の種明かしと同じ（script・onclick・iframe 等は不可）。</p>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
          <thead><tr><th>名前</th><th>更新日時</th><th class="text-end">操作</th></tr></thead>
          <tbody id="revealPagesBody"><tr><td colspan="3" class="small text-muted">読み込み中…</td></tr></tbody>
        </table></div>
      </div></div>
    <div class="card mt-3"><div class="card-header py-2 small fw-bold d-flex justify-content-between align-items-center">
        <span>送信エンドポイント（ビーコンURL・送信元アドレス）</span>
        <span class="btn-group btn-group-sm" data-perm="tenant_admin">
          <button class="btn btn-outline-primary" onclick="addSendEndpoint('beacon')"><i class="bi bi-plus-lg me-1"></i>ビーコンを追加</button>
          <button class="btn btn-outline-primary" onclick="addSendEndpoint('from')"><i class="bi bi-plus-lg me-1"></i>送信元を追加</button>
        </span>
      </div>
      <div class="card-body">
        <p class="small text-muted mb-2">キャンペーンの「送信環境」や各コンテンツで選べる、ビーコンベースURLと送信元アドレスの登録元です。複数選ぶと対象者へ均等に振り分けられます。共有（全テナント共通）はシステム管理者だけが編集できます。</p>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
          <thead><tr><th>種別</th><th>値</th><th>ラベル</th><th>範囲</th><th class="text-end">操作</th></tr></thead>
          <tbody id="sendEndpointsBody"><tr><td colspan="5" class="small text-muted">読み込み中…</td></tr></tbody>
        </table></div>
      </div></div>`;
  loadRevealPagesList().catch(() => {});
  loadSendEndpointsList().catch(() => {});
}

/* ---- 送信エンドポイントのマスタ管理 ---- */
const SendEndpointsState = { rows: {} };

async function loadSendEndpointsList() {
  const body = $('#sendEndpointsBody');
  if (!body) return;
  let data;
  try {
    data = await api('api/send_endpoints.php', { query: { action: 'list' } });
  } catch (e) {
    body.innerHTML = `<tr><td colspan="5" class="small text-danger">${esc(e.message)}</td></tr>`;
    return;
  }
  SendEndpointsState.rows = {};
  for (const r of data.endpoints) SendEndpointsState.rows[r.id] = r;
  const role = State.user?.role;
  const kindLabel = (k) => k === 'beacon' ? 'ビーコン' : '送信元';
  body.innerHTML = data.endpoints.length ? data.endpoints.map((r) => {
    // 共有行は superadmin だけ編集可。テナント行は tenant_admin 以上。
    const canEdit = r.shared ? role === 'superadmin' : ['tenant_admin', 'superadmin'].includes(role);
    return `<tr>
      <td class="small text-nowrap">${esc(kindLabel(r.kind))}</td>
      <td class="text-break"><code class="small">${esc(r.value)}</code></td>
      <td class="small text-break">${esc(r.label || '')}</td>
      <td class="small text-nowrap">${r.shared ? '<span class="badge bg-secondary">共有</span>' : '<span class="badge bg-info-subtle text-dark border">自組織</span>'}</td>
      <td class="text-end text-nowrap">${canEdit
        ? `<button class="btn btn-sm btn-outline-primary" onclick="editSendEndpoint(${Number(r.id)})"><i class="bi bi-pencil"></i></button>
           <button class="btn btn-sm btn-outline-danger" onclick="deleteSendEndpoint(${Number(r.id)})" aria-label="削除"><i class="bi bi-trash"></i></button>`
        : '<span class="small text-muted">閲覧のみ</span>'}</td>
    </tr>`;
  }).join('') : '<tr><td colspan="5" class="small text-muted">まだ登録がありません。</td></tr>';
}

function sendEndpointForm(row = null, kind = 'beacon') {
  const k = row ? row.kind : kind;
  const isSuper = State.user?.role === 'superadmin';
  const kindLabel = k === 'beacon' ? 'ビーコンベースURL' : '送信元アドレス';
  const ph = k === 'beacon' ? 'https://track.example.com/' : 'noreply@example.com';
  return `<form id="sendEndpointForm" data-kind="${k}">
    <div class="mb-2"><label class="form-label">${esc(kindLabel)}</label>
      <input class="form-control" name="value" type="${k === 'from' ? 'email' : 'text'}" required
        placeholder="${esc(ph)}" value="${esc(row ? row.value : '')}"></div>
    <div class="mb-2"><label class="form-label">ラベル（任意）</label>
      <input class="form-control" name="label" maxlength="100" value="${esc(row && row.label ? row.label : '')}" placeholder="表示用の名前"></div>
    ${isSuper && (!row || row.shared) ? `<div class="form-check mb-2">
      <input class="form-check-input" type="checkbox" name="shared" id="epShared" ${row && row.shared ? 'checked disabled' : ''}>
      <label class="form-check-label" for="epShared">共有（全テナント共通・システム管理者のみ）</label></div>` : ''}
  </form>`;
}

function addSendEndpoint(kind) {
  showModal(kind === 'beacon' ? 'ビーコンを追加' : '送信元を追加', sendEndpointForm(null, kind), async () => {
    const f = document.getElementById('sendEndpointForm');
    const body = { kind: f.dataset.kind, value: f.value.value.trim() };
    const label = f.label.value.trim();
    if (label) body.label = label;
    if (f.shared && f.shared.checked) body.shared = true;
    if (State.user?.role === 'superadmin' && State.activeTenantId && !(f.shared && f.shared.checked)) body.tenant_id = Number(State.activeTenantId);
    await api('api/send_endpoints.php', { method: 'POST', query: { action: 'create' }, body });
    toast('追加しました', 'ok');
    await loadSendEndpointsList();
  });
}

async function editSendEndpoint(id) {
  const row = SendEndpointsState.rows[id];
  if (!row) return;
  showModal('エンドポイントを編集', sendEndpointForm(row), async () => {
    const f = document.getElementById('sendEndpointForm');
    const body = { id: Number(id), value: f.value.value.trim() };
    body.label = f.label.value.trim();
    if (State.user?.role === 'superadmin' && !row.shared && State.activeTenantId) body.tenant_id = Number(State.activeTenantId);
    await api('api/send_endpoints.php', { method: 'POST', query: { action: 'update' }, body });
    toast('更新しました', 'ok');
    await loadSendEndpointsList();
  });
}

async function deleteSendEndpoint(id) {
  const row = SendEndpointsState.rows[id];
  if (!row) return;
  if (!confirm(`「${row.value}」を削除しますか？\nこの値を選んでいた過去のキャンペーンの送信・集計には影響しません（値は各キャンペーンに控えられています）。`)) return;
  try {
    const body = { id: Number(id) };
    if (State.user?.role === 'superadmin' && !row.shared && State.activeTenantId) body.tenant_id = Number(State.activeTenantId);
    await api('api/send_endpoints.php', { method: 'POST', query: { action: 'delete' }, body });
    toast('削除しました', 'ok');
    await loadSendEndpointsList();
  } catch (e) { toast(e.message, 'err'); }
}

/* ---- 種明かしページ(複数・G29) ---- */
const RevealPagesState = { rows: {} };

async function loadRevealPagesList() {
  const body = $('#revealPagesBody');
  if (!body) return;
  let data;
  try {
    data = await api('api/master_upload.php', { query: { action: 'reveal_list' } });
  } catch (e) {
    body.innerHTML = `<tr><td colspan="3" class="small text-danger">${esc(e.message)}</td></tr>`;
    return;
  }
  RevealPagesState.rows = {};
  for (const p of data.pages) RevealPagesState.rows[p.id] = p;
  const canEdit = ['operator', 'tenant_admin', 'superadmin'].includes(State.user?.role);
  body.innerHTML = data.pages.length ? data.pages.map((p) => `
    <tr data-reveal-id="${Number(p.id)}"><td class="text-break">${esc(p.name)}</td>
      <td class="small text-nowrap">${esc(fmtDate(p.updated_at))}</td>
      <td class="text-end text-nowrap">
        <button class="btn btn-sm btn-outline-secondary" onclick="viewRevealPage(${Number(p.id)})"><i class="bi bi-eye"></i> 表示</button>
        ${canEdit ? `<button class="btn btn-sm btn-outline-primary" onclick="editRevealPage(${Number(p.id)})"><i class="bi bi-pencil"></i> 修正</button>
        <button class="btn btn-sm btn-outline-danger" onclick="deleteRevealPage(${Number(p.id)})" aria-label="ページを削除"><i class="bi bi-trash"></i></button>` : ''}
      </td></tr>`).join('') : '<tr><td colspan="3" class="small text-muted">まだページがありません。</td></tr>';
}

function revealPageForm(name = '', html = '') {
  return `<form id="revealPageForm">
    <div class="mb-2"><label class="form-label">ページ名</label>
      <input class="form-control" name="name" maxlength="100" required value="${esc(name)}" placeholder="例: 営業部向けの種明かし"></div>
    <div class="mb-2"><label class="form-label">HTML</label>
      <textarea class="form-control font-monospace" name="html" rows="14" required placeholder="<!DOCTYPE html> ...">${esc(html)}</textarea>
      <div class="form-text">script・onclick 等のイベントハンドラ・iframe・object・embed・meta refresh は使えません。</div></div>
  </form>`;
}

async function saveRevealPage(id, form) {
  const name = form.name.value.trim();
  const html = form.html.value;
  const body = { name, html };
  if (id) body.id = Number(id);
  if (State.user && State.user.role === 'superadmin' && State.activeTenantId) body.tenant_id = Number(State.activeTenantId);
  await api('api/master_upload.php', { method: 'POST', query: { action: 'reveal_page_save' }, body });
  toast(id ? 'ページを更新しました' : 'ページを追加しました', 'ok');
  await loadRevealPagesList();
}

function addRevealPage() {
  showModal('種明かしページを追加', revealPageForm(), async () => {
    await saveRevealPage(null, document.getElementById('revealPageForm'));
  });
}

async function editRevealPage(id) {
  let r;
  try {
    r = await api('api/master_upload.php', { query: { action: 'reveal_page_get', id } });
  } catch (e) { toast(e.message, 'err'); return; }
  showModal('種明かしページを修正', revealPageForm(r.name, r.content), async () => {
    await saveRevealPage(id, document.getElementById('revealPageForm'));
  });
}

async function viewRevealPage(id) {
  let r;
  try {
    r = await api('api/master_upload.php', { query: { action: 'reveal_page_get', id } });
  } catch (e) { toast(e.message, 'err'); return; }
  const body = `<div class="mb-2 small text-muted">${esc(r.name)}</div>
    <pre class="border rounded p-2 bg-light small" style="white-space:pre-wrap;max-height:60vh;overflow:auto">${esc(r.content || '(空)')}</pre>`;
  showModal('種明かしページ', body, async () => {}, { saveLabel: '閉じる' });
}

async function deleteRevealPage(id) {
  const p = RevealPagesState.rows[id];
  if (!p) return;
  if (!confirm(`種明かしページ「${p.name}」を削除しますか？\nこのページを選んでいるキャンペーンは既定に戻ります。`)) return;
  try {
    await api('api/master_upload.php', { method: 'POST', query: { action: 'reveal_page_delete' }, body: { id: Number(id) } });
    toast('ページを削除しました', 'ok');
    await loadRevealPagesList();
  } catch (e) { toast(e.message, 'err'); }
}

/* ========== ログ管理 (P8) ========== */
// 各ログ種別の列定義: ラベルと行→セル値(esc済み or 数値)の写像。
const LOG_COLUMNS = {
  delivery: {
    headers: ['#', 'キャンペーン', '宛先', '結果', 'SMTP', '日時'],
    cells: (r) => [r.id, esc(r.campaign_name), esc(r.to_email), logResultBadge(r.result), esc(r.smtp_message), esc(r.occurred_at)],
  },
  events: {
    headers: ['#', 'キャンペーン', '追跡ID', '種別', '認証種', 'source', '日時'],
    cells: (r) => [r.id, esc(r.campaign_name), esc(r.tracking_id), logEventBadge(r.event_type), esc(r.auth_variant), esc(r.source), esc(r.occurred_at)],
  },
  schedule: {
    headers: ['#', 'キャンペーン', 'バッチ', '予定時刻', '項番', '間隔', '状態', 'PID', '試行'],
    cells: (r) => [r.id, esc(r.campaign_name), r.batch_no, esc(r.scheduled_at), `${r.koban_from ?? ''}–${r.koban_to ?? ''}`, `${r.interval_sec}s`, esc(r.status), esc(r.worker_pid), r.attempts],
  },
  replies: {
    headers: ['#', 'キャンペーン', '氏名', 'メール', '会社', '本文', '日時'],
    cells: (r) => [r.id, esc(r.campaign_name), esc(r.target_name), esc(r.email), esc(r.company), esc(r.raw), esc(r.occurred_at)],
  },
  audit: {
    headers: ['#', 'ユーザ', 'アクション', '詳細', 'IP', '日時'],
    cells: (r) => [r.id, esc(r.user_email), esc(r.action), esc(r.detail), esc(r.ip), esc(r.occurred_at)],
  },
  training_results: {
    headers: ['キャンペーン', '項番', '氏名', 'メール', '会社', '送信', '開封', 'クリック', '認証'],
    cells: (r) => {
      const mark = (v) => Number(v) ? '<span class="text-danger fw-bold">○</span>' : '<span class="text-muted">—</span>';
      return [esc(r.campaign_name), r.koban, esc(r.target_name || ''), esc(r.email), esc(r.company || ''),
        r.send_status === 'sent' ? '<span class="badge bg-success">済</span>' : esc(r.send_status),
        mark(r.opened), mark(r.clicked), mark(r.authed)];
    },
  },
  training_log_detail: {
    headers: ['日時', '乱数', '区分', '開封回数', 'タイプ', '送信先メール', '氏名', '会社名', '本務役職', '役職カテゴリ',
      '入力Email', 'Password/ID', 'IP', '国', '場所', 'ISP', '組織', 'AS', 'ホスト名'],
    cells: (r) => [
      esc(r.timestamp), esc(r.random),
      // 区分: 人間 or システム(サンドボックス/SWG等。exclude_system=0 の時だけ混在)。
      r.is_system ? '<span class="badge bg-secondary">装置</span>' : '<span class="badge bg-success">人間</span>',
      // 開封回数: 人間アクセスの「n/m回目」。システム行は対象外なので空。
      (!r.is_system && r.human_total > 1)
        ? `<span class="badge bg-info text-dark">${r.human_seq}/${r.human_total}回目</span>`
        : (!r.is_system && r.human_total === 1 ? '1回' : ''),
      logTypeBadge(r.type), esc(r.recipient_email), esc(r.fullname), esc(r.company), esc(r.position),
      esc(r.position_category), esc(r.email), esc(r.password), esc(r.ip), esc(r.country),
      esc(r.location), esc(r.isp), esc(r.org), esc(r.as), esc(r.hostname),
    ],
  },
  campaign_files: {
    headers: ['項番', '乱数(tracking_id)', '送信先メール', '氏名', '会社名', '送信状況',
      'リンクHTMLファイル名', 'リンクHTML URL', 'ビーコン画像ファイル名', 'ビーコン画像 URL'],
    cells: (r) => [
      esc(r.koban), esc(r.tracking_id), esc(r.recipient_email), esc(r.fullname), esc(r.company),
      esc(r.send_status),
      `<code class="small">${esc(r.link_file)}</code>`,
      `<a href="${esc(r.link_url)}" target="_blank" rel="noopener" class="small text-break">${esc(r.link_url)}</a>`,
      `<code class="small">${esc(r.beacon_file)}</code>`,
      `<a href="${esc(r.beacon_url)}" target="_blank" rel="noopener" class="small text-break">${esc(r.beacon_url)}</a>`,
    ],
  },
  webaccess: {
    headers: ['IP', 'Timestamp', 'Method', 'Path', 'Protocol', 'Status', 'Size', 'Referer', 'User-Agent',
      '国', '場所', 'ISP', '組織', 'AS', 'ホスト名'],
    cells: (r) => [
      esc(r.ip), esc(r.timestamp), esc(r.method),
      `<span class="text-break" style="max-width:280px;display:inline-block">${esc(r.path)}</span>`,
      esc(r.protocol), logStatusBadge(r.status), esc(r.size),
      `<span class="text-break small" style="max-width:180px;display:inline-block">${esc(r.referer)}</span>`,
      `<span class="text-break small" style="max-width:220px;display:inline-block">${esc(r.useragent)}</span>`,
      esc(r.country), esc(r.location), esc(r.isp), esc(r.org), esc(r.as), esc(r.hostname),
    ],
  },
  reply_maildir: {
    headers: ['受信日時', '送信元アカウント', '差出人', '件名', '添付', ''],
    // r は Maildir 由来。本文表示ボタンで別モーダルを開く。
    cells: (r) => [
      esc(r.date), esc(r.sender_account), esc(r.from || r.from_email), esc(r.subject),
      Number(r.has_attachment) ? '<i class="bi bi-paperclip" title="添付あり"></i>' : '',
      `<button class="btn btn-sm btn-outline-primary py-0" data-mailview="1" data-account="${esc(r.sender_account)}" data-file="${esc(r.filename)}"><i class="bi bi-envelope-open"></i> 本文</button>`,
    ],
  },
};
function logResultBadge(v) {
  const cls = v === 'sent' ? 'bg-success' : v === 'failed' ? 'bg-danger' : 'bg-secondary';
  return `<span class="badge ${cls}">${esc(v)}</span>`;
}
function logEventBadge(v) {
  const map = { open: 'bg-info text-dark', click: 'bg-warning text-dark', auth: 'bg-danger', reply: 'bg-primary' };
  return `<span class="badge ${map[v] || 'bg-secondary'}">${esc(v)}</span>`;
}
function logTypeBadge(v) {
  // link_click=リンククリック / box・ms365・da 等=認証入力
  const cls = v === 'link_click' ? 'bg-warning text-dark' : 'bg-danger';
  return `<span class="badge ${cls}">${esc(v)}</span>`;
}
function logStatusBadge(v) {
  const n = parseInt(v, 10);
  const cls = n >= 500 ? 'bg-danger' : n >= 400 ? 'bg-warning text-dark' : n >= 300 ? 'bg-info text-dark' : 'bg-success';
  return `<span class="badge ${cls}">${esc(v)}</span>`;
}
const logsState = { type: 'delivery', offset: 0, limit: 100 };
let reportMailRequest = 0;
let credentialRequest = 0;

// ログ管理テーブルの列見出しクリックソート(共通)。表示済みの tbody 行を並べ替える
// DOM ベース方式で、各タブの cells 実装に依存しない。列のセル値から数値/日時/文字を
// 自動判定して比較する。バッジ等のHTMLはテキスト内容(例「2/7回目」→数値2)で比較する。
function makeLogTableSortable(headSel, bodySel) {
  const head = $(headSel);
  const body = $(bodySel);
  if (!head || !body) return;
  const ths = [...head.querySelectorAll('th')];
  if (!ths.length) return;
  // ソート用の値を取り出す: 日時 > 数値 > 文字 の順で判定。
  const sortKey = (text) => {
    const t = (text || '').trim();
    // 日時 YYYY-MM-DD[ HH:MM[:SS]]
    if (/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/.test(t)) {
      const ms = Date.parse(t.replace(' ', 'T'));
      return { num: isNaN(ms) ? null : ms, str: t };
    }
    // 先頭に数値を含む(「2/7回目」「5059」「12回」等)→ 先頭数値で比較。
    const m = t.match(/-?\d[\d,]*\.?\d*/);
    if (m) return { num: parseFloat(m[0].replace(/,/g, '')), str: t };
    return { num: null, str: t };
  };
  ths.forEach((th, idx) => {
    th.style.cursor = 'pointer';
    th.title = 'クリックで並び替え';
    th.addEventListener('click', () => {
      const cur = th.dataset.sortDir === 'asc' ? 'desc' : 'asc';
      ths.forEach((o) => { delete o.dataset.sortDir; o.querySelector('.sort-caret')?.remove(); });
      th.dataset.sortDir = cur;
      const caret = document.createElement('span');
      caret.className = 'sort-caret text-muted small ms-1';
      caret.textContent = cur === 'asc' ? '▲' : '▼';
      th.appendChild(caret);
      const rows = [...body.querySelectorAll('tr')].filter((tr) => tr.querySelectorAll('td').length === ths.length);
      rows.sort((ra, rb) => {
        const a = sortKey(ra.children[idx]?.textContent);
        const b = sortKey(rb.children[idx]?.textContent);
        let cmp;
        if (a.num !== null && b.num !== null) cmp = a.num - b.num;
        else if (a.num !== null) cmp = -1; // 数値を空文字より前に
        else if (b.num !== null) cmp = 1;
        else cmp = a.str.localeCompare(b.str, 'ja');
        return cur === 'asc' ? cmp : -cmp;
      });
      rows.forEach((tr) => body.appendChild(tr));
    });
  });
}
async function renderLogs() {
  // キャンペーン絞込セレクトは画面を開くたびに毎回作り直す。
  // キャンペーンは随時追加されるためキャッシュしない(新規作成分がすぐ出るように)。
  // 選択中のキャンペーンは維持するが、テナントを切り替えた時だけは破棄する。
  const sel = $('#logsCampaignFilter');
  const tenantKey = String(State.activeTenantId ?? State.user?.tenant_id ?? '');
  if (sel) {
    const tenantChanged = sel.dataset.tenantKey !== undefined && sel.dataset.tenantKey !== tenantKey;
    const prev = tenantChanged ? '' : sel.value;
    try {
      const { campaigns } = await api('api/report.php', { query: { action: 'campaigns' } });
      // 先頭の「すべて」を残して以降を破棄してから詰め直す。
      while (sel.options.length > 1) sel.remove(1);
      for (const c of campaigns) {
        const o = document.createElement('option');
        o.value = c.id; o.textContent = `#${c.id} ${c.name}`;
        sel.appendChild(o);
      }
      // 選択中の値が新しい一覧にも存在すれば復元する。無ければ「すべて」に戻す。
      sel.value = prev && [...sel.options].some((o) => o.value === prev) ? prev : '';
      sel.dataset.tenantKey = tenantKey;
    } catch { /* 一覧取得失敗は絞込なしで続行 */ }
  }
  await loadLogs();
}
async function loadLogs() {
  const type = logsState.type;
  if (type === 'credential_captures') return loadCredentialCaptures();
  if (type === 'raw_mail' || type === 'raw_web') return loadRawLog(type);
  if (type === 'training_results') return loadTrainingResults();
  if (type === 'training_log_detail') return loadTrainingLogDetail();
  if (type === 'campaign_files') return loadCampaignFiles();
  if (type === 'webaccess') return loadWebAccessLog();
  if (type === 'reply_maildir') return loadReplyMaildir();
  if (type === 'report_mail') return loadReportMail();
  // 既存の DB ログ(ページングつきテーブル)
  const def = LOG_COLUMNS[type];
  $('#logsHead').innerHTML = `<tr>${def.headers.map((h) => `<th>${h}</th>`).join('')}</tr>`;
  $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">読込中…</td></tr>`;
  const query = { action: type, limit: logsState.limit, offset: logsState.offset };
  const cid = $('#logsCampaignFilter')?.value;
  if (cid) query.campaign_id = cid;
  let data;
  try { data = await api('api/logs.php', { query }); }
  catch (e) { $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-danger py-3">${esc(e.message)}</td></tr>`; return; }
  const rows = data.rows || [];
  $('#logsBody').innerHTML = rows.length
    ? rows.map((r) => `<tr>${def.cells(r).map((c) => `<td>${c ?? ''}</td>`).join('')}</tr>`).join('')
    : `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">ログはありません</td></tr>`;
  makeLogTableSortable('#logsHead', '#logsBody');
  const from = rows.length ? logsState.offset + 1 : 0;
  $('#logsInfo').textContent = `${from}–${logsState.offset + rows.length} 件目（${logsState.limit} 件/ページ）`;
  $('#logsPrevBtn').disabled = logsState.offset === 0;
  $('#logsNextBtn').disabled = rows.length < logsState.limit;
}

function closeCredentialDialog() {
  const dialog = $('#credentialRevealDialog');
  if (dialog?.open) dialog.close();
  const body = $('#credentialRevealText');
  if (body) body.textContent = '';
}

async function loadCredentialCaptures() {
  if (State.user?.role !== 'superadmin') return;
  const tenantId = String(State.activeTenantId || '');
  const campaignId = String($('#logsCampaignFilter')?.value || '');
  const request = ++credentialRequest;
  const headers = ['ID', '追跡 ID', '入力画面', '日時', '操作'];
  $('#logsHead').innerHTML = `<tr>${headers.map((label) => `<th>${label}</th>`).join('')}</tr>`;
  if (!tenantId || !campaignId) {
    $('#logsBody').innerHTML = '<tr><td colspan="5" class="text-muted py-3">上部でキャンペーンを選択してください。</td></tr>';
    $('#logsInfo').textContent = '';
    $('#logsPrevBtn').disabled = true;
    $('#logsNextBtn').disabled = true;
    return;
  }
  $('#logsBody').innerHTML = '<tr><td colspan="5" class="text-muted py-3">読込中…</td></tr>';
  let rows;
  try {
    const data = await api('api/credential_captures.php', {
      query: { action: 'list', tenant_id: tenantId, campaign_id: campaignId, offset: logsState.offset },
    });
    rows = data.captures || [];
  } catch (error) {
    if (request === credentialRequest) $('#logsBody').innerHTML = `<tr><td colspan="5" class="text-danger py-3">${esc(error.message)}</td></tr>`;
    return;
  }
  if (request !== credentialRequest || State.view !== 'logs' || logsState.type !== 'credential_captures'
      || String(State.activeTenantId || '') !== tenantId || String($('#logsCampaignFilter')?.value || '') !== campaignId) return;
  $('#logsBody').innerHTML = rows.length ? rows.map((row) => `<tr>
    <td>${esc(row.id)}</td><td><code>${esc(row.tracking_id)}</code></td>
    <td>${esc(row.auth_type)}</td><td>${esc(row.created_at)}</td>
    <td><button type="button" class="btn btn-sm btn-outline-primary" data-capture-id="${esc(row.id)}">本文を表示</button></td>
  </tr>`).join('') : '<tr><td colspan="5" class="text-muted py-3">このキャンペーンの入力本文はありません。</td></tr>';
  $('#logsInfo').textContent = `${logsState.offset + (rows.length ? 1 : 0)}–${logsState.offset + rows.length} 件目`;
  $('#logsPrevBtn').disabled = logsState.offset === 0;
  $('#logsNextBtn').disabled = rows.length < logsState.limit;
}

async function revealCredentialCapture(button) {
  if (State.user?.role !== 'superadmin') return;
  const tenantId = String(State.activeTenantId || '');
  const campaignId = String($('#logsCampaignFilter')?.value || '');
  const request = credentialRequest;
  if (!tenantId || !campaignId) return;
  button.disabled = true;
  try {
    const data = await api('api/credential_captures.php', {
      method: 'POST', query: { action: 'reveal' },
      body: { id: Number(button.dataset.captureId), tenant_id: Number(tenantId), campaign_id: Number(campaignId) },
    });
    if (request !== credentialRequest || State.view !== 'logs' || logsState.type !== 'credential_captures'
        || String(State.activeTenantId || '') !== tenantId || String($('#logsCampaignFilter')?.value || '') !== campaignId) return;
    const dialog = $('#credentialRevealDialog');
    $('#credentialRevealText').textContent = JSON.stringify(data.fields, null, 2);
    dialog.showModal();
  } catch (error) {
    toast(error.message || '本文を表示できません', 'err');
  } finally {
    button.disabled = false;
  }
}
function reportMailCells(r) {
  const labels = { pending: '保留', confirmed: '確定', rejected: '却下' };
  const colors = { pending: 'warning text-dark', confirmed: 'success', rejected: 'secondary' };
  const canDecide = ['operator', 'tenant_admin', 'superadmin'].includes(State.user?.role);
  const actions = r.status === 'pending' && canDecide
    ? `<button class="btn btn-sm btn-outline-success" data-report-decision="confirm" data-id="${esc(r.id)}">確定</button>
       <button class="btn btn-sm btn-outline-secondary" data-report-decision="reject" data-id="${esc(r.id)}">却下</button>` : '';
  return [esc(r.received_at), `<span class="badge bg-${esc(colors[r.status] || 'secondary')}">${esc(labels[r.status] || r.status)}</span>`,
    esc(r.method), esc(r.reason), esc(r.tracking_id), esc(r.campaign_name),
    `${esc(r.target_name)}<br>${esc(r.target_email)}`, esc(r.from_email), esc(r.subject_head), actions];
}
async function loadReportMail() {
  const request = ++reportMailRequest;
  const query = { action: 'report_mail', status: $('#reportMailStatus').value, limit: logsState.limit, offset: logsState.offset };
  if (State.user?.role === 'superadmin' && $('#reportMailUnmatched').checked) query.include_unmatched = '1';
  const headers = ['受信日時', '状態', '方法', '理由', '追跡 ID', 'キャンペーン', '対象者', '差出人', '件名', '操作'];
  $('#logsHead').innerHTML = `<tr>${headers.map((h) => `<th>${esc(h)}</th>`).join('')}</tr>`;
  $('#logsBody').innerHTML = '<tr><td colspan="10">読込中…</td></tr>';
  const unmatched = $('#reportMailUnmatchedTable');
  unmatched.style.display = 'none'; unmatched.innerHTML = '';
  $('#logsPrevBtn').disabled = true; $('#logsNextBtn').disabled = true;
  $('#logsInfo').textContent = '';
  let data;
  try { data = await api('api/logs.php', { query }); }
  catch (e) {
    if (logsState.type === 'report_mail' && request === reportMailRequest) $('#logsBody').innerHTML = `<tr><td colspan="10" class="text-danger">${esc(e.message)}</td></tr>`;
    return;
  }
  if (logsState.type !== 'report_mail' || request !== reportMailRequest) return;
  $('#logsBody').innerHTML = data.rows.length
    ? data.rows.map((r) => `<tr>${reportMailCells(r).map((c) => `<td>${c}</td>`).join('')}</tr>`).join('')
    : '<tr><td colspan="10">報告メールはありません</td></tr>';
  $('#logsInfo').textContent = `${data.rows.length ? data.offset + 1 : 0}–${data.offset + data.rows.length} 件目 / ${data.total} 件`;
  $('#logsPrevBtn').disabled = data.offset === 0;
  $('#logsNextBtn').disabled = data.offset + data.rows.length >= data.total;
  if (State.user?.role === 'superadmin' && data.unmatched) {
    unmatched.style.display = '';
    unmatched.innerHTML = `<h6>未照合（${esc(data.unmatched.length)}件）</h6><table class="table table-sm"><thead><tr>${['受信日時', '差出人', '件名', 'parse_status', 'parse_error'].map((h) => `<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${data.unmatched.map((r) => `<tr>${['received_at', 'from_email', 'subject_head', 'parse_status', 'parse_error'].map((k) => `<td>${esc(r[k])}</td>`).join('')}</tr>`).join('') || '<tr><td colspan="5">未照合メールはありません</td></tr>'}</tbody></table>`;
  }
}
async function decideReportMail(button) {
  const decision = button.dataset.reportDecision;
  if (!['confirm', 'reject'].includes(decision) || !['operator', 'tenant_admin', 'superadmin'].includes(State.user?.role)) return;
  if (!confirm(decision === 'confirm' ? 'この報告メールを確定しますか？' : 'この報告メールを却下しますか？')) return;
  button.disabled = true;
  try {
    await api('api/logs.php', { method: 'POST', query: { action: `report_mail_${decision}` }, body: { id: Number(button.dataset.id) } });
    if (logsState.type === 'report_mail') await loadReportMail();
  } catch (e) { alert(e.message); }
  finally { button.disabled = false; }
}
// 生ログ(mail/access)を pre 表示。キーワードで絞込。最新5000行、ファイルサイズ表示。
async function loadRawLog(type) {
  const pre = $('#logsRaw');
  pre.textContent = '読込中…';
  const query = { action: type, limit: 5000 };
  const kw = $('#logsKeyword')?.value.trim();
  if (kw) query.q = kw;
  try {
    const d = await api('api/logs.php', { query });
    if (d.error) { pre.textContent = d.error; }
    const lines = d.lines || [];
    if (!d.error) pre.textContent = lines.length ? lines.join('\n') : '（該当する行がありません）';
    $('#logsInfo').textContent = `${lines.length} 行（末尾から最新最大5000行、${esc(d.source)}）`;
    // ファイルサイズ・更新日時をバッジ表示。
    const fi = d.file_info;
    if (fi) {
      $('#logsFileInfo').style.display = '';
      $('#logsFileInfo').textContent = `サイズ ${fi.size_human} / 更新 ${fi.modified}`;
    }
  } catch (e) { pre.textContent = e.message; }
}
// 生ログ全件ダウンロード(mail/access)。
function downloadRawLog(type) {
  const qs = new URLSearchParams({ action: type, download: '1' });
  window.open(`api/logs.php?${qs}`, '_blank');
}
// 訓練結果ログ(メール一覧・明細)。events(click/auth)をパースした21カラム。キャンペーン別フィルタ。
async function loadTrainingLogDetail() {
  const def = LOG_COLUMNS.training_log_detail;
  $('#logsHead').innerHTML = `<tr>${def.headers.map((h) => `<th class="text-nowrap">${h}</th>`).join('')}</tr>`;
  $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">読込中…</td></tr>`;
  const query = { action: 'training_log_detail' };
  const cid = $('#logsCampaignFilter')?.value; if (cid) query.campaign_id = cid;
  const tp = $('#logsTypeFilter')?.value; if (tp) query.type = tp;
  const sd = logDateVal('#logsStartDate'); if (sd) query.start_date = sd;
  const ed = logDateVal('#logsEndDate'); if (ed) query.end_date = ed;
  // 「システム開封を除外」チェック(既定ON)。外すとシステム(サンドボックス/SWG等)も表示。
  const inclSys = $('#logsInclSystem')?.checked;
  if (inclSys) query.exclude_system = '0';
  let data;
  try { data = await api('api/logs.php', { query }); }
  catch (e) { $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-danger py-3">${esc(e.message)}</td></tr>`; return; }
  // タイプフィルタの選択肢は毎回作り直す(新しい種別がすぐ出るように)。選択中の値は維持。
  const sel = $('#logsTypeFilter');
  if (sel && data.by_type) {
    const prev = sel.value;
    while (sel.options.length > 1) sel.remove(1);
    for (const t of Object.keys(data.by_type)) {
      const o = document.createElement('option'); o.value = t; o.textContent = t; sel.appendChild(o);
    }
    sel.value = prev && [...sel.options].some((o) => o.value === prev) ? prev : '';
  }
  const rows = data.rows || [];
  $('#logsBody').innerHTML = rows.length
    ? rows.map((r) => `<tr>${def.cells(r).map((c) => `<td class="text-nowrap">${c ?? ''}</td>`).join('')}</tr>`).join('')
    : `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">対象データがありません</td></tr>`;
  makeLogTableSortable('#logsHead', '#logsBody');
  const byType = data.by_type ? Object.entries(data.by_type).map(([k, v]) => `${k}:${v}`).join(' / ') : '';
  $('#logsInfo').textContent = `${data.count ?? rows.length} 件${byType ? '（' + byType + '）' : ''}`;
}
// 訓練結果ログ(明細)の GeoIP を後追い補完する。click 行の国/場所/ISP は ip_cache.json 頼みで、
// 未登録IPだと空欄になる。このボタンで表示中の条件に含まれる未解決IPを解決してから再表示する。
// サーバは1回200件まで解決し未解決の残数(geoip_remaining)を返すので、残数0まで繰り返す。
async function refreshTrainingLogGeoip() {
  const btn = document.getElementById('tldGeoipBtn');
  if (btn) { btn.disabled = true; btn.dataset.orig = btn.innerHTML; }
  const query = { action: 'training_log_geoip' };
  const cid = $('#logsCampaignFilter')?.value; if (cid) query.campaign_id = cid;
  const tp = $('#logsTypeFilter')?.value; if (tp) query.type = tp;
  const sd = logDateVal('#logsStartDate'); if (sd) query.start_date = sd;
  const ed = logDateVal('#logsEndDate'); if (ed) query.end_date = ed;
  // 補完対象は表示中の行に合わせる(システム除外チェックの状態も引き継ぐ)。
  const inclSys = $('#logsInclSystem')?.checked;
  if (inclSys) query.exclude_system = '0';
  const setLabel = (t) => { if (btn) btn.innerHTML = `<span class="spinner-border spinner-border-sm"></span> ${t}`; };
  try {
    // 残数が尽きるまで繰り返す。上限は暴走防止(200件×100回=2万件)。
    // 残数が2回続けて減らなければ(API失敗でUnknown固定等)打ち切る。
    let prevRemaining = Infinity, stall = 0;
    for (let i = 0; i < 100; i++) {
      const res = await api('api/logs.php', { query });
      const remaining = Number(res?.geoip_remaining ?? 0);
      if (remaining <= 0) { break; }
      setLabel(`GeoIP取得中… 残り${remaining}件`);
      if (remaining >= prevRemaining) { if (++stall >= 2) break; } else { stall = 0; }
      prevRemaining = remaining;
    }
    toast('GeoIP情報を更新しました（未知IPをすべて解決）', 'ok');
    await loadTrainingLogDetail();  // キャッシュが埋まった状態で再表示
  } catch (e) {
    toast(e.message || 'GeoIP更新に失敗しました', 'err');
  } finally {
    if (btn) { btn.disabled = false; if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig; }
  }
}
// 訓練結果ログ(明細)を CSV ダウンロード。
function downloadTrainingLogDetailCsv() {
  const qs = new URLSearchParams({ action: 'training_log_detail_csv' });
  const cid = $('#logsCampaignFilter')?.value; if (cid) qs.set('campaign_id', cid);
  const tp = $('#logsTypeFilter')?.value; if (tp) qs.set('type', tp);
  const sd = logDateVal('#logsStartDate'); if (sd) qs.set('start_date', sd);
  const ed = logDateVal('#logsEndDate'); if (ed) qs.set('end_date', ed);
  if (State.user && State.user.role === 'superadmin' && State.activeTenantId) qs.set('tenant_id', State.activeTenantId);
  window.open(`api/logs.php?${qs}`, '_blank');
}
// キャンペーン別 リンク/ビーコン ファイル一覧。対象者×(link HTML / beacon PNG)の完全URL。
// curl で外部から存在チェックする用途。キャンペーン未選択時は促す。
async function loadCampaignFiles() {
  const def = LOG_COLUMNS.campaign_files;
  $('#logsHead').innerHTML = `<tr>${def.headers.map((h) => `<th class="text-nowrap">${h}</th>`).join('')}</tr>`;
  const cid = $('#logsCampaignFilter')?.value;
  if (!cid) {
    $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">上部の「キャンペーン」を選択してください。</td></tr>`;
    $('#logsInfo').textContent = '';
    return;
  }
  $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">読込中…</td></tr>`;
  const query = { action: 'campaign_files', campaign_id: cid };
  let data;
  try { data = await api('api/logs.php', { query }); }
  catch (e) { $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-danger py-3">${esc(e.message)}</td></tr>`; return; }
  const rows = data.rows || [];
  $('#logsBody').innerHTML = rows.length
    ? rows.map((r) => `<tr>${def.cells(r).map((c) => `<td class="text-nowrap">${c ?? ''}</td>`).join('')}</tr>`).join('')
    : `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">対象者がいません（キャンペーン未生成の可能性）。</td></tr>`;
  makeLogTableSortable('#logsHead', '#logsBody');
  $('#logsInfo').textContent = `${data.count ?? rows.length} 名（base: ${esc(data.beacon_base || '')}）`;
}
// キャンペーン別 リンク/ビーコン ファイル一覧を CSV ダウンロード。
function downloadCampaignFilesCsv() {
  const cid = $('#logsCampaignFilter')?.value;
  if (!cid) { toast('キャンペーンを選択してください', 'err'); return; }
  const qs = new URLSearchParams({ action: 'campaign_files_csv', campaign_id: cid });
  if (State.user && State.user.role === 'superadmin' && State.activeTenantId) qs.set('tenant_id', State.activeTenantId);
  window.open(`api/logs.php?${qs}`, '_blank');
}
// WebアクセスLog(Apache access.log + GeoIP)。期間/Path/検索/ページング5000件。superadmin。
async function loadWebAccessLog() {
  const def = LOG_COLUMNS.webaccess;
  $('#logsHead').innerHTML = `<tr>${def.headers.map((h) => `<th class="text-nowrap">${h}</th>`).join('')}</tr>`;
  $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">読込中…（大量ログの解析に時間がかかることがあります）</td></tr>`;
  const query = { action: 'webaccess', page: logsState.webPage || 1, per_page: 5000 };
  const sd = logDateVal('#logsStartDate'); if (sd) query.start_date = sd;
  const ed = logDateVal('#logsEndDate'); if (ed) query.end_date = ed;
  const pf = $('#logsPathFilter')?.value.trim(); if (pf) query.path = pf;
  const kw = $('#logsKeyword')?.value.trim(); if (kw) query.search = kw;
  let data;
  try { data = await api('api/logs.php', { query }); }
  catch (e) { $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-danger py-3">${esc(e.message)}</td></tr>`; return; }
  const rows = data.logs || [];
  $('#logsBody').innerHTML = rows.length
    ? rows.map((r) => `<tr>${def.cells(r).map((c) => `<td class="text-nowrap">${c ?? ''}</td>`).join('')}</tr>`).join('')
    : `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">該当するアクセスログがありません</td></tr>`;
  makeLogTableSortable('#logsHead', '#logsBody');
  const pg = data.pagination || {};
  $('#logsInfo').textContent = `全 ${pg.total_count ?? rows.length} 件中 ${rows.length} 件表示（ページ ${pg.page ?? 1}/${pg.total_pages ?? 1}、最大5000件/ページ）`;
  // ページャ(webaccess専用)。
  logsState.webTotalPages = pg.total_pages || 1;
  $('#logsPrevBtn').disabled = (pg.page ?? 1) <= 1;
  $('#logsNextBtn').disabled = (pg.page ?? 1) >= (pg.total_pages || 1);
  // ログファイルサイズ表示。
  if (data.log_files && data.log_files.length) {
    const total = data.log_files.reduce((s, f) => s + (f.size || 0), 0);
    $('#logsFileInfo').style.display = '';
    $('#logsFileInfo').textContent = `ログ ${data.log_files.length} ファイル / 合計 ${formatBytesJs(total)}`;
  }
}
// GeoIP を後追い補完する（未知IPだけ外部APIで解決。一覧は即座に返すのでタイムアウトしない）。
// サーバは1回200件まで解決し未解決の残数(geoip_remaining)を返す。残数が0になるまで
// 自動で繰り返し叩き、その時点で表示中の全IPの国/ISPを埋め切る。
async function refreshWebAccessGeoip() {
  const btn = document.getElementById('webGeoipBtn');
  if (btn) { btn.disabled = true; btn.dataset.orig = btn.innerHTML; }
  const query = { action: 'webaccess_geoip', page: logsState.webPage || 1, per_page: 5000 };
  const sd = logDateVal('#logsStartDate'); if (sd) query.start_date = sd;
  const ed = logDateVal('#logsEndDate'); if (ed) query.end_date = ed;
  const pf = $('#logsPathFilter')?.value.trim(); if (pf) query.path = pf;
  const kw = $('#logsKeyword')?.value.trim(); if (kw) query.search = kw;
  const setLabel = (t) => { if (btn) btn.innerHTML = `<span class="spinner-border spinner-border-sm"></span> ${t}`; };
  try {
    // 残数が尽きるまで繰り返す。上限は暴走防止(200件×100回=2万件)。
    // 残数が2回続けて減らなければ(API失敗でUnknown固定等)打ち切る。
    let prevRemaining = Infinity, stall = 0;
    for (let i = 0; i < 100; i++) {
      const res = await api('api/logs.php', { query });
      const remaining = Number(res?.geoip_remaining ?? 0);
      if (remaining <= 0) { break; }
      setLabel(`GeoIP取得中… 残り${remaining}件`);
      if (remaining >= prevRemaining) { if (++stall >= 2) break; } else { stall = 0; }
      prevRemaining = remaining;
    }
    toast('GeoIP情報を更新しました（未知IPをすべて解決）', 'ok');
    await loadWebAccessLog();  // キャッシュが埋まった状態で再表示
  } catch (e) {
    toast(e.message || 'GeoIP更新に失敗しました', 'err');
  } finally {
    if (btn) { btn.disabled = false; if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig; }
  }
}
// WebアクセスLog CSV(全件, GeoIP付き)ダウンロード。
function downloadWebAccessCsv() {
  const qs = new URLSearchParams({ action: 'webaccess_csv' });
  const sd = logDateVal('#logsStartDate'); if (sd) qs.set('start_date', sd);
  const ed = logDateVal('#logsEndDate'); if (ed) qs.set('end_date', ed);
  const pf = $('#logsPathFilter')?.value.trim(); if (pf) qs.set('path', pf);
  window.open(`api/logs.php?${qs}`, '_blank');
}
// datetime-local の値(YYYY-MM-DDTHH:MM)を 'YYYY-MM-DD HH:MM:SS' に変換。空なら ''。
function logDateVal(sel) {
  const v = $(sel)?.value;
  if (!v) return '';
  // datetime-local は秒がない場合があるので補完。
  return v.replace('T', ' ') + (v.length === 16 ? ':00' : '');
}
// バイト数を人間可読に(JS側)。
function formatBytesJs(size) {
  if (!size || size <= 0) return '0 B';
  const u = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.min(Math.floor(Math.log(size) / Math.log(1024)), u.length - 1);
  return `${(size / 1024 ** i).toFixed(2)} ${u[i]}`;
}
// 訓練結果ログ(対象者別 開封状況)。status フィルタ、全件表示。
async function loadTrainingResults() {
  const def = LOG_COLUMNS.training_results;
  $('#logsHead').innerHTML = `<tr>${def.headers.map((h) => `<th>${h}</th>`).join('')}</tr>`;
  $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">読込中…</td></tr>`;
  const query = { action: 'training_results' };
  const cid = $('#logsCampaignFilter')?.value; if (cid) query.campaign_id = cid;
  const st = $('#logsStatusFilter')?.value; if (st) query.status = st;
  let data;
  try { data = await api('api/logs.php', { query }); }
  catch (e) { $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-danger py-3">${esc(e.message)}</td></tr>`; return; }
  const rows = data.rows || [];
  $('#logsBody').innerHTML = rows.length
    ? rows.map((r) => `<tr>${def.cells(r).map((c) => `<td>${c ?? ''}</td>`).join('')}</tr>`).join('')
    : `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">対象データがありません</td></tr>`;
  makeLogTableSortable('#logsHead', '#logsBody');
  $('#logsInfo').textContent = `${rows.length} 件`;
}
// 訓練結果ログを CSV ダウンロード。
async function downloadTrainingResultsCsv() {
  const qs = new URLSearchParams({ action: 'training_results_csv' });
  const cid = $('#logsCampaignFilter')?.value; if (cid) qs.set('campaign_id', cid);
  if (State.user && State.user.role === 'superadmin' && State.activeTenantId) qs.set('tenant_id', State.activeTenantId);
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), 30000);
  let res;
  try { res = await fetch(`api/logs.php?${qs}`, { credentials: 'same-origin', signal: ctrl.signal }); }
  finally { clearTimeout(timer); }
  if (!res.ok) { toast(`出力に失敗しました（HTTP ${res.status}）`, 'err'); return; }
  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = `training_results_${new Date().toISOString().slice(0, 10)}.csv`;
  document.body.appendChild(a); a.click(); a.remove();
  URL.revokeObjectURL(url);
}
// 返信者(Maildir 実ファイル): 送信元アカウントの受信メール一覧。superadmin 限定。
async function loadReplyMaildir() {
  const def = LOG_COLUMNS.reply_maildir;
  $('#logsHead').innerHTML = `<tr>${def.headers.map((h) => `<th>${h}</th>`).join('')}</tr>`;
  $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">読込中…</td></tr>`;
  const query = { action: 'reply_maildir' };
  // キャンペーン選択時は、その送信元アドレス宛に届いた受信メールだけに絞る。
  const cid = $('#logsCampaignFilter')?.value; if (cid) query.campaign_id = cid;
  const sender = $('#logsSenderFilter')?.value; if (sender) query.sender = sender;
  const kw = $('#logsKeyword')?.value.trim(); if (kw) query.q = kw;
  const sd = logDateVal('#logsStartDate'); if (sd) query.start_date = sd;
  const ed = logDateVal('#logsEndDate'); if (ed) query.end_date = ed;
  let data;
  try { data = await api('api/logs.php', { query }); }
  catch (e) { $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-danger py-3">${esc(e.message)}</td></tr>`; return; }
  // 送信元アカウントのセレクトは毎回作り直す(件数も最新化される)。選択中の値は維持。
  const sel = $('#logsSenderFilter');
  if (sel && data.accounts) {
    const prevSender = sel.value;
    while (sel.options.length > 1) sel.remove(1);
    for (const [key, email] of Object.entries(data.accounts)) {
      const n = (data.counts && data.counts[key]) || 0;
      const o = document.createElement('option');
      o.value = key; o.textContent = `${key} <${email}> (${n})`;
      sel.appendChild(o);
    }
    sel.value = prevSender && [...sel.options].some((o) => o.value === prevSender) ? prevSender : '';
  }
  const rows = data.rows || [];
  // 権限不足で読めないメールは黄色で目立たせる。以前は一覧から静かに消えており、
  // 届いている返信を見落とす原因になっていた。
  $('#logsBody').innerHTML = rows.length
    ? rows.map((r) => {
        const cls = r.unreadable ? ' class="table-warning"' : '';
        return `<tr${cls}>${def.cells(r).map((c) => `<td>${c ?? ''}</td>`).join('')}</tr>`;
      }).join('')
    : `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">受信メールはありません</td></tr>`;
  makeLogTableSortable('#logsHead', '#logsBody');
  const unreadable = rows.filter((r) => r.unreadable).length;
  const info = $('#logsInfo');
  info.textContent = `${data.total ?? rows.length} 件（送信元アカウントの受信トレイを直接参照）`;
  if (unreadable) {
    const span = document.createElement('span');
    span.className = 'text-danger fw-bold ms-2';
    span.textContent = `／読み取り不可 ${unreadable} 件（サーバ側のファイル権限を要確認）`;
    info.appendChild(span);
  }
}
// 受信メール本文をモーダル表示(Maildir)。
async function viewMailBody(account, filename, meta) {
  $('#mailBodyTitle').textContent = '受信メール';
  $('#mailBodyMeta').innerHTML = meta || '';
  $('#mailBodyText').textContent = '読込中…';
  const modal = bootstrap.Modal.getOrCreateInstance($('#mailBodyModal'));
  modal.show();
  try {
    const d = await api('api/logs.php', { query: { action: 'reply_maildir_view', account, filename } });
    $('#mailBodyText').textContent = d.body || '（本文が空です）';
  } catch (e) {
    $('#mailBodyText').textContent = e.message;
  }
}
// 返信者(受信メール)一覧を CSV ダウンロード。
function downloadReplyMaildirCsv() {
  const qs = new URLSearchParams({ action: 'reply_maildir_csv' });
  const cid = $('#logsCampaignFilter')?.value; if (cid) qs.set('campaign_id', cid);
  const sender = $('#logsSenderFilter')?.value; if (sender) qs.set('sender', sender);
  const kw = $('#logsKeyword')?.value.trim(); if (kw) qs.set('q', kw);
  const sd = logDateVal('#logsStartDate'); if (sd) qs.set('start_date', sd);
  const ed = logDateVal('#logsEndDate'); if (ed) qs.set('end_date', ed);
  window.open(`api/logs.php?${qs}`, '_blank');
}
function switchLogTab(type) {
  reportMailRequest++;
  credentialRequest++;
  closeCredentialDialog();
  logsState.type = type;
  logsState.offset = 0;
  logsState.webPage = 1;
  $$('#logsTabs .nav-link').forEach((a) => a.classList.toggle('active', a.dataset.log === type));
  const isRaw = (type === 'raw_mail' || type === 'raw_web');
  const isTr = (type === 'training_results');
  const isTld = (type === 'training_log_detail');
  const isWeb = (type === 'webaccess');
  const isMd = (type === 'reply_maildir');
  const isCf = (type === 'campaign_files');
  const isReport = (type === 'report_mail');
  const isCapture = (type === 'credential_captures');
  // 追加フィルタ行を出すタブ(campaign_files は CSV ボタンを出すため含める)。
  const hasFilter = (isRaw || isTr || isTld || isWeb || isMd || isCf || isReport);
  // 期間(開始/終了)を使うタブ。
  const hasPeriod = (isTld || isWeb || isMd);
  // DB ページャ(offset)を使う既存タブ。
  const isDbPaged = (type === 'delivery' || type === 'events' || type === 'schedule' || type === 'replies' || type === 'audit' || isReport || isCapture);

  const show = (sel, on) => { const el = $(sel); if (el) el.style.display = on ? '' : 'none'; };

  $('#logsRaw').classList.toggle('d-none', !isRaw);
  $('#logsTableWrap').classList.toggle('d-none', isRaw);
  // ページャは DB ページ系 と WebアクセスLog(独自ページ)で表示。
  $('#logsPager').style.display = (isDbPaged || isWeb) ? '' : 'none';
  $('#logsExtraFilter').style.display = hasFilter ? '' : 'none';

  show('#logsCampaignFilter', !isReport);
  show('#reportMailStatus', isReport);
  show('#reportMailUnmatchedWrap', isReport && State.user?.role === 'superadmin');
  show('#reportMailUnmatchedTable', false);
  show('#logsStatusFilter', isTr);
  show('#logsTypeFilter', isTld);
  show('#logsInclSystemWrap', isTld);
  show('#logsSenderFilter', isMd);
  show('#logsStartLabel', hasPeriod);
  show('#logsEndLabel', hasPeriod);
  show('#logsPathFilter', isWeb);
  show('#logsKeyword', isRaw || isMd || isWeb);
  show('#logsApplyBtn', hasFilter && !isReport);
  // CSV 出力: 訓練結果/訓練結果ログ明細/WebアクセスLog/返信者Maildir/リンク・ビーコンファイル一覧。
  show('#logsCsvBtn', isTr || isTld || isWeb || isMd || isCf);
  // Excel(XLSX)出力: 生ログ以外の全タブ。
  show('#logsXlsxBtn', !isRaw && !isReport && !isCapture);
  // GeoIP取得: WebアクセスLog のみ(一覧はキャッシュのみ表示、未知IPはこのボタンで後追い解決)。
  show('#webGeoipBtn', isWeb);
  show('#tldGeoipBtn', isTld);
  // 「更新」ボタンは campaign_files では上部キャンペーン選択で再読込するため隠す(専用フィルタなし)。
  if (isCf) show('#logsApplyBtn', false);
  // ログ全件DL: 生ログ(mail/web)のみ。
  show('#logsRawDlBtn', isRaw);
  // ファイルサイズバッジは raw/web のみ。切替時は一旦隠す。
  show('#logsFileInfo', false);

  loadLogs();
}

async function viewMaster(kind, file) {
  let r;
  try { r = await api('api/master_upload.php', { query: kind === 'auth' ? { action: 'get', kind, file } : { action: 'get', kind } }); }
  catch (e) { toast(e.message, 'err'); return; }
  const html = r.content || '';
  if (!html) { showInfoModal('マスタ表示', '<p class="text-muted">まだ内容がありません。</p>'); return; }
  // 種明かしでテナント別が未設定→共通(master.html)を表示している場合のバナー。
  const fallbackBanner = r.is_fallback
    ? '<div class="alert alert-info py-2 small mb-2"><i class="bi bi-info-circle me-1"></i>このテナント専用の種明かしは未設定です。<strong>共通の種明かし画面</strong>を表示しています（実際の訓練でもこれが使われます）。「修正」で自社用に差し替えられます。</div>'
    : '';
  // プレビューは iframe sandbox(全制限=JS無効)で安全にレンダリング。srcdoc は属性値なので esc()。
  const body = `
    ${fallbackBanner}
    <ul class="nav nav-pills mb-2" id="masterViewTabs">
      <li class="nav-item"><a class="nav-link active" href="#" data-mv="preview">プレビュー</a></li>
      <li class="nav-item"><a class="nav-link" href="#" data-mv="source">HTMLソース</a></li>
    </ul>
    <div id="mvPreview">
      <div class="small text-muted mb-1">実際の表示イメージ（スクリプトは無効化して安全に表示）。</div>
      <iframe sandbox srcdoc="${esc(html)}" style="width:100%;height:55vh;border:1px solid var(--tet-border);border-radius:var(--tet-radius-sm);background:#fff"></iframe>
    </div>
    <div id="mvSource" class="d-none">
      <pre class="border rounded p-2 bg-light" style="max-height:55vh;overflow:auto"><code>${esc(html)}</code></pre>
    </div>`;
  showInfoModal(`マスタ表示: ${esc(file)}`, body);
  // タブ切替
  const tabs = document.getElementById('masterViewTabs');
  if (tabs) tabs.addEventListener('click', (e) => {
    const a = e.target.closest('.nav-link'); if (!a) return;
    e.preventDefault();
    tabs.querySelectorAll('.nav-link').forEach((x) => x.classList.toggle('active', x === a));
    document.getElementById('mvPreview').classList.toggle('d-none', a.dataset.mv !== 'preview');
    document.getElementById('mvSource').classList.toggle('d-none', a.dataset.mv !== 'source');
  });
}
// マスタHTMLを「現在の内容を読み込んで編集→保存」する。プレビュー(iframe sandbox)付き。
async function editMaster(kind, file) {
  const label = kind === 'auth' ? `認証マスタ ${file}` : '種明かし画面';
  let cur = '', isFallback = false;
  try {
    const r = await api('api/master_upload.php', { query: kind === 'auth' ? { action: 'get', kind, file } : { action: 'get', kind } });
    cur = r.content || '';
    isFallback = !!r.is_fallback;
  } catch (e) { toast(e.message, 'err'); return; }
  // 注意バナー: 認証マスタは全テナント共通 / 種明かしは共通からコピー編集。
  const banner = kind === 'auth'
    ? '<div class="alert alert-warning py-2 small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>この認証画面は<strong>全テナント共通</strong>です。変更はすべてのテナントの訓練に反映されます。</div>'
    : (isFallback ? '<div class="alert alert-info py-2 small mb-2"><i class="bi bi-info-circle me-1"></i>共通の種明かしを読み込んでいます。保存すると<strong>このテナント専用</strong>の種明かしになります。</div>' : '');
  const body = `${banner}<p class="small text-muted">${esc(label)} を編集します（&lt;script&gt; やイベント属性は不可・1MB以内）。保存時に既存はバックアップされます。</p>
    <ul class="nav nav-pills mb-2" id="editMasterTabs">
      <li class="nav-item"><a class="nav-link active" href="#" data-em="edit">HTML編集</a></li>
      <li class="nav-item"><a class="nav-link" href="#" data-em="preview">プレビュー</a></li>
    </ul>
    <div id="emEdit"><textarea class="form-control" id="masterHtml" rows="14">${esc(cur)}</textarea></div>
    <div id="emPreview" class="d-none">
      <div class="small text-muted mb-1">現在の編集内容の表示イメージ（スクリプト無効）。プレビュータブに切り替えるたび最新化されます。</div>
      <iframe sandbox id="emPreviewFrame" style="width:100%;height:50vh;border:1px solid var(--tet-border);border-radius:var(--tet-radius-sm);background:#fff"></iframe>
    </div>`;
  showModal(`編集: ${label}`, body, async () => {
    const html = $('#masterHtml').value;
    if (!html.trim()) throw new Error('内容を入力してください');
    if (kind === 'auth' && !confirm('この認証画面は全テナント共通です。すべてのテナントの訓練に反映されます。保存しますか？')) return;
    const payload = kind === 'auth' ? { kind, file, html } : { kind, html };
    await api('api/master_upload.php', { method: 'POST', query: { action: 'upload' }, body: payload });
    toast('保存しました', 'ok');
  });
  // タブ切替(プレビューは編集中の最新内容を毎回反映)
  const tabs = document.getElementById('editMasterTabs');
  if (tabs) tabs.addEventListener('click', (e) => {
    const a = e.target.closest('.nav-link'); if (!a) return;
    e.preventDefault();
    tabs.querySelectorAll('.nav-link').forEach((x) => x.classList.toggle('active', x === a));
    const isPreview = a.dataset.em === 'preview';
    document.getElementById('emEdit').classList.toggle('d-none', isPreview);
    document.getElementById('emPreview').classList.toggle('d-none', !isPreview);
    if (isPreview) document.getElementById('emPreviewFrame').srcdoc = $('#masterHtml').value;
  });
}
// マスタHTMLをファイルとしてダウンロード。
async function downloadMaster(kind, file) {
  try {
    const r = await api('api/master_upload.php', { query: kind === 'auth' ? { action: 'get', kind, file } : { action: 'get', kind } });
    if (!r.exists) { toast('まだ内容がありません', 'err'); return; }
    const blob = new Blob([r.content || ''], { type: 'text/html;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = kind === 'auth' ? file : 'reveal.html';
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
  } catch (e) { toast(e.message, 'err'); }
}

let campaignDataCtx = null;
async function showCampaignData(id) {
  let r;
  try { r = await api('api/campaign_files.php', { query: { action: 'list', id } }); }
  catch (e) { toast(e.message, 'err'); return; }
  campaignDataCtx = { id, files: r.files || [] };
  const tabs = campaignDataCtx.files.map((f, i) =>
    `<button class="btn btn-sm ${i===0?'btn-primary':'btn-outline-secondary'} me-1 mb-1" ${f.exists?'':'disabled'}
       onclick="loadCampaignFile('${esc(f.file)}', this)">${esc(f.label)}${f.exists?'':'（無）'}</button>`).join('');
  const body = `<div class="mb-2">${tabs}</div><div id="campaignFileView" class="small text-muted">ファイルを選択してください</div>`;
  showInfoModal('データビューア', body);
  const first = campaignDataCtx.files.find((f) => f.exists);
  if (first) loadCampaignFile(first.file, null);
}
async function loadCampaignFile(file, btn) {
  if (btn) { document.querySelectorAll('#appModalBody .btn').forEach((b) => { b.classList.remove('btn-primary'); b.classList.add('btn-outline-secondary'); });
    btn.classList.remove('btn-outline-secondary'); btn.classList.add('btn-primary'); }
  const view = document.getElementById('campaignFileView');
  if (!view) return;
  view.innerHTML = '読み込み中…';
  let r;
  try { r = await api('api/campaign_files.php', { query: { action: 'content', id: campaignDataCtx.id, file } }); }
  catch (e) { view.innerHTML = `<span class="text-danger">${esc(e.message)}</span>`; return; }
  if (r.type === 'csv') {
    const headers = r.headers || [];
    const thead = `<thead><tr>${headers.map((h) => `<th>${esc(h)}</th>`).join('')}</tr></thead>`;
    const tbody = (r.data || []).map((row) => `<tr>${headers.map((h) => `<td>${esc(String(row[h] ?? ''))}</td>`).join('')}</tr>`).join('');
    view.innerHTML = `<div class="table-responsive" style="max-height:50vh;overflow:auto"><table class="table table-sm table-bordered">${thead}<tbody>${tbody || `<tr><td colspan="${headers.length||1}" class="text-muted">データなし</td></tr>`}</tbody></table></div>`;
  } else {
    view.innerHTML = `<pre class="border rounded p-2 bg-light" style="max-height:50vh;overflow:auto"><code>${esc(r.content || '')}</code></pre>`;
  }
}

async function openCampaignModal(campaignId = null, initialStep = 0) {
  const isEdit = campaignId !== null;
  const [tpls, tgts, grps, beacons, fromCand, campaigns, revealPages] = await Promise.all([
    api('api/templates.php', { query: { action: 'list' } }),
    api('api/targets.php', { query: { action: 'list' } }),
    api('api/groups.php', { query: { action: 'list' } }),
    api('api/campaigns.php', { query: { action: 'beacon_bases' } }),
    api('api/campaigns.php', { query: { action: 'from_addresses' } }).catch(() => ({ from_addresses: [] })),
    api('api/campaigns.php', { query: { action: 'list' } }),
    api('api/master_upload.php', { query: { action: 'reveal_list' } }).catch(() => ({ pages: [] })),
  ]);
  // 編集時は既存キャンペーンの値を取得してプリフィルする。
  let editData = null;
  if (isEdit) {
    editData = await api('api/campaigns.php', { query: { action: 'get', id: campaignId } });
  }
  const beaconList = beacons.beacon_bases || ['http://85.131.251.224/'];
  const fromList = fromCand.from_addresses || [];
  // 複数選択＋直接入力ウィジェット。候補(マスタ＋過去値)をチェックで複数選び、直接入力も足せる。
  // data-endpoints-widget に kind を持たせ、選択値の収集(collectEndpointWidget)と復元(fillEndpointWidget)で使う。
  const endpointWidget = (name, kind, candidates, opts = {}) => {
    const cls = kind === 'beacon' ? 'ep-beacon' : 'ep-from';
    const listId = 'epList-' + name;
    const checks = (candidates || []).map((v, i) => `
      <div class="form-check form-check-inline">
        <input class="form-check-input ep-check" type="checkbox" id="${listId}-${i}" value="${esc(v)}">
        <label class="form-check-label small" for="${listId}-${i}">${esc(v)}</label>
      </div>`).join('') || '<span class="text-muted small">登録済みの候補がありません。下の欄で直接追加できます。</span>';
    const checkBtn = kind === 'beacon'
      ? `<button type="button" class="btn btn-outline-secondary ep-check-btn"><i class="bi bi-broadcast"></i> 疎通確認</button>` : '';
    return `<div class="endpoint-widget border rounded p-2 ${cls}" data-endpoints-widget="${kind}" data-ep-name="${name}">
      <div class="ep-candidates mb-1">${checks}</div>
      <div class="input-group input-group-sm">
        <input class="form-control ep-add-input" type="${kind === 'from' ? 'email' : 'text'}" placeholder="${kind === 'from' ? '直接入力：メールアドレス' : '直接入力：http(s):// のベースURL'}">
        <button type="button" class="btn btn-outline-primary ep-add-btn"><i class="bi bi-plus-lg"></i> 追加</button>
        ${checkBtn}
      </div>
      <div class="form-check mt-1">
        <input class="form-check-input ep-save-master" type="checkbox" id="${listId}-savemaster">
        <label class="form-check-label small text-muted" for="${listId}-savemaster">直接入力した値をマスタにも登録する</label>
      </div>
      <div class="ep-added small mt-1"></div>
      <div class="ep-check-result small"></div>
      ${opts.hint ? `<div class="form-text">${opts.hint}</div>` : ''}
    </div>`;
  };
  const byKind = (k) => (tpls.templates || []).filter((t) => t.kind === k);
  const opt = (arr) => arr.map((t) => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
  // 偽ログイン専用: 認証種別(auth_flag)をラベルに出す。
  // QR型/リンク型で「認証画面を出すか(=auth_flag 1/2/3)、種明かし直行か(=0)」を選択者が区別できるように。
  const AUTH_LABEL = { 0: '認証なし・種明かし直行', 1: 'Box認証', 2: 'Microsoft365認証', 3: 'デジタルアーツ認証' };
  const optPhish = (arr) => arr.map((t) => {
    const af = Number(t.auth_flag) || 0;
    return `<option value="${t.id}">${esc(t.name)}（${AUTH_LABEL[af] || '認証なし'}）</option>`;
  }).join('');
  // プレビュー用: テンプレート id → {name, content, format}
  const tplById = {};
  for (const t of (tpls.templates || [])) tplById[t.id] = t;
  // シナリオ構築: scenario_key を持つ件名/本文をペアにまとめる(件名と本文の連動用)。
  const scenarioMap = {}; // key → {subject_id, body_id, label}
  for (const t of (tpls.templates || [])) {
    if (!t.scenario_key) continue;
    if (!scenarioMap[t.scenario_key]) scenarioMap[t.scenario_key] = { key: t.scenario_key };
    if (t.kind === 'subject') { scenarioMap[t.scenario_key].subject_id = t.id; scenarioMap[t.scenario_key].label = t.name; }
    if (t.kind === 'body') scenarioMap[t.scenario_key].body_id = t.id;
  }
  // 件名と本文が両方揃ったシナリオのみ選択肢にする。
  const scenarios = Object.values(scenarioMap).filter((s) => s.subject_id && s.body_id);
  const scenarioOptions = scenarios.map((s) => `<option value="${s.key}">${esc(s.label)}</option>`).join('');
  const importableCampaigns = (campaigns.campaigns || []).filter((campaign) =>
    Number(campaign.id) !== Number(campaignId) && Number(campaign.content_count || 0) > 0
  );
  const campaignImportOptions = importableCampaigns.map((campaign) =>
    `<option value="${Number(campaign.id)}">${esc(campaign.name)}（${Number(campaign.content_count)}件）</option>`
  ).join('');
  // 種明かしページの選択(G29)。既定は従来どおりテナントの reveal.html。
  const revealPageOptions = ['<option value="">既定（テナントの種明かしページ）</option>']
    .concat((revealPages.pages || []).map((p) => `<option value="${Number(p.id)}">${esc(p.name)}</option>`)).join('');
  const body = `
    <form id="campaignForm">
      <section class="campaign-editor-section" data-campaign-step="basic"><h3 tabindex="-1">基本情報</h3>
      <div class="mb-2"><label class="form-label">キャンペーン名</label><input class="form-control" name="name" required></div>
      </section>
      <section class="campaign-editor-section" data-campaign-step="scenario"><h3 tabindex="-1">シナリオ</h3>
      <div class="campaign-content-toolbar border rounded p-2 mb-2 bg-light">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <label class="form-label mb-0">コンテンツ <span class="badge bg-primary" id="contentCountLabel">0 / 100件</span></label>
          <div class="btn-group btn-group-sm">
            <button type="button" class="btn btn-outline-secondary" id="collapseAllContents">すべて折りたたむ</button>
            <button type="button" class="btn btn-outline-secondary" id="expandAllContents">すべて展開</button>
            <button type="button" class="btn btn-outline-primary" id="addContentBtn"><i class="bi bi-plus-lg"></i> 追加</button>
          </div>
        </div>
        <div class="row g-2 align-items-end">
          <div class="col-md-9"><label class="form-label small mb-1">過去キャンペーンから取り込む（複数選択可）</label>
            <select class="form-select form-select-sm" id="contentImportCampaigns" multiple size="3">${campaignImportOptions}</select></div>
          <div class="col-md-3 d-grid"><button type="button" class="btn btn-sm btn-outline-primary" id="importSelectedCampaigns">選択内容を追加</button></div>
        </div>
      </div>
      <div id="contentsList" class="mb-2"></div>
      </section>
      <section class="campaign-editor-section" data-campaign-step="delivery"><h3 tabindex="-1">送信環境</h3>
      <div class="row g-2">
        <div class="col-md-12 mb-2"><label class="form-label">送信元アドレス（キャンペーン既定・各コンテンツで上書き可）</label>
          ${endpointWidget('from', 'from', fromList, { hint: '複数選ぶと対象者へ均等に振り分けます。1件だけなら全員がそのアドレスになります。到達性(SPF/DKIM)は運用側で用意してください。' })}
        </div>
      </div>
      <div class="row g-2">
        <div class="col-md-12 mb-2"><label class="form-label">ビーコンベースURL（キャンペーン既定・各コンテンツで上書き可。登録済みから選択、または直接入力）</label>
          ${endpointWidget('beacon', 'beacon', beaconList, { hint: '複数選ぶと対象者へ均等に振り分けます。追跡サーバへ解決・到達できるホストだけを選んでください。' })}
        </div>
      </div>
      <div class="row g-2">
        <div class="col-md-12 mb-2"><label class="form-label">種明かしページ</label>
          <select class="form-select" name="reveal_page_id">${revealPageOptions}</select>
          <div class="form-text">認証なし（種明かし直行）のリンク／QR と、認証後の遷移先に表示するページです。選ばない場合はテナントの既定（reveal.html）を使います。ページは「不審メール」画面などの種明かし管理から追加できます。</div>
        </div>
      </div>
      </section>
      <section class="campaign-editor-section" data-campaign-step="schedule"><h3 tabindex="-1">日時・送信量</h3>
      <div class="row g-2">
        <div class="col-md-4 mb-2"><label class="form-label">送信方式</label>
          <select class="form-select" name="send_mode"><option value="normal">通常</option><option value="split">分割</option><option value="slow">なだらか</option></select></div>
        <div class="col-md-4 mb-2"><label class="form-label">分割数</label><input class="form-control" name="split_count" type="number" min="1"></div>
        <div class="col-md-4 mb-2"><label class="form-label">分割間隔(分)</label>
          <select class="form-select" name="split_interval_min"><option value="">—</option><option>5</option><option>15</option><option>30</option><option>60</option></select></div>
      </div>
      <div class="row g-2">
        <div class="col-md-6 mb-2"><label class="form-label">開始日時</label><input class="form-control" name="start_at" type="datetime-local" required></div>
        <div class="col-md-6 mb-2"><label class="form-label">終了日時</label><input class="form-control" name="end_at" type="datetime-local" required></div>
      </div>
      <div class="row g-2">
        <div class="col-md-4 mb-2"><label class="form-label">営業開始</label><input class="form-control" name="business_start" placeholder="09:00"></div>
        <div class="col-md-4 mb-2"><label class="form-label">営業終了</label><input class="form-control" name="business_end" placeholder="18:00"></div>
        <div class="col-md-4 mb-2 d-flex align-items-end justify-content-between"><div class="form-check">
          <input class="form-check-input" type="checkbox" name="weekdays_only" checked id="cbWeek"><label class="form-check-label" for="cbWeek">平日限定</label></div>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="weekdayPreviewBtn"><i class="bi bi-calendar-week"></i> 対象日を確認</button></div>
      </div>
      <div class="mb-2 d-none" id="weekdayPreview"></div>
      <div class="mb-2"><label class="form-label">配信方式</label>
        <select class="form-select" name="content_delivery">
          <option value="distribute">均等割り（各対象者に1コンテンツを均等配分）</option>
          <option value="all">全員に全コンテンツ（テスト対象者に全パターン送付）</option>
        </select>
        <div class="form-text">「全員に全コンテンツ」は各対象者へ登録した全コンテンツを送ります。コンテンツごとに個別追跡されます。</div></div>
      <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_test" id="cbTest"><label class="form-check-label" for="cbTest">テスト送信（is_test）</label></div>
      <div class="mb-2" id="testRedirectWrap" style="display:none">
        <label class="form-label">テスト宛先（リダイレクト先メール・カンマ区切り）</label>
        <textarea class="form-control" name="test_redirect_emails" rows="2" placeholder="test1@example.com, test2@example.com"></textarea>
        <div class="form-text">全件転送テストです。対象者・コンテンツの全送信行を、指定したアドレスへ<strong>均等分配</strong>します（例: 1000通を4アドレスに各250通）。差し込みデータ・追跡は元の対象者のままです。テスト宛先は必須で、空の場合は配信できません。</div>
      </div>
      </section>
      <section class="campaign-editor-section" data-campaign-step="targets"><h3 tabindex="-1">対象者</h3>
      <div class="mb-2"><label class="form-label">対象グループ</label>
        <select class="form-select" name="group_ids" multiple size="3">${(grps.groups||[]).map((g)=>`<option value="${g.id}">${esc(g.name)}（${Number(g.target_count || 0)}名）</option>`).join('')}</select>
        <div class="form-text">グループを変更すると、前回の個別対象者選択は解除されます。</div></div>
      <div class="mb-2"><label class="form-label">個別対象者（グループへ追加する場合）</label>
        <select class="form-select" name="target_ids" multiple size="4">${(tgts.targets||[]).map((t)=>`<option value="${t.id}">${esc(t.email)}（${esc(t.name||'')}）</option>`).join('')}</select></div>
      <div class="alert alert-info py-2 mb-0" id="campaignTargetSummary">送付予定人数を計算しています...</div>
      </section>
      <section class="campaign-editor-section" data-campaign-step="review"><h3 tabindex="-1">最終確認</h3>
        <p>保存後、配信前確認画面で対象人数・総通数・TEST宛先別の通数を確認してください。</p>
        <p class="small text-muted mb-0">ここで保存してもメールは送信されません。教育の割当と報告方法は別画面で設定します。</p>
      </section>
    </form>`;
  // コンテンツ行のHTML（件名/本文/偽ログイン/配信形式/添付拡張子・zip/削除）
  const contentRow = (idx) => `
    <div class="border rounded mb-2 content-row" data-idx="${idx}">
      <div class="content-row-header d-flex justify-content-between align-items-center gap-2 p-2 bg-light">
        <div class="min-w-0"><span class="badge bg-secondary">コンテンツ ${idx + 1}</span>
          <span class="content-row-summary small text-muted ms-2"></span></div>
        <div class="btn-group btn-group-sm flex-shrink-0">
          <button type="button" class="btn btn-outline-secondary move-content-up" title="上へ"><i class="bi bi-arrow-up"></i></button>
          <button type="button" class="btn btn-outline-secondary move-content-down" title="下へ"><i class="bi bi-arrow-down"></i></button>
          <button type="button" class="btn btn-outline-secondary toggle-content" aria-expanded="true" title="折りたたむ"><i class="bi bi-chevron-up"></i></button>
          <button type="button" class="btn btn-outline-danger del-content" ${idx === 0 ? 'style="visibility:hidden"' : ''}><i class="bi bi-trash"></i></button>
        </div>
      </div>
      <div class="content-row-body p-2">
      <div class="row g-2">
        <div class="col-md-12"><label class="form-label small fw-bold">シナリオ（選ぶと件名・本文が連動します）</label>
          <select class="form-select form-select-sm c-scenario">
            <option value="">— 個別に選択 —</option>
            ${scenarioOptions}
          </select>
          <div class="form-text c-scenario-desc" style="white-space:pre-wrap" aria-live="polite"></div></div>
      </div>
      <div class="row g-2 mt-1">
        <div class="col-md-4"><label class="form-label small">件名</label><select class="form-select form-select-sm c-subject">${opt(byKind('subject'))}</select></div>
        <div class="col-md-4"><label class="form-label small">本文</label><select class="form-select form-select-sm c-body">${opt(byKind('body'))}</select></div>
        <div class="col-md-4"><label class="form-label small">偽ログイン（認証画面）</label><select class="form-select form-select-sm c-phish">${optPhish(byKind('phish_login'))}</select>
          ${byKind('phish_login').length ? '' : '<div class="form-text text-danger">偽ログインのテンプレートがありません。「テンプレート」画面の「偽ログイン」で作成してください。</div>'}</div>
      </div>
      <div class="text-end mt-1">
        <button type="button" class="btn btn-sm btn-outline-info c-preview"><i class="bi bi-eye"></i> 内容プレビュー</button>
      </div>
      <div class="c-preview-area border rounded p-2 mt-1 bg-light d-none"></div>
      <div class="row g-2 mt-1">
        <div class="col-md-4"><label class="form-label small">配信形式</label>
          <select class="form-select form-select-sm c-linkmode"><option value="link">リンク</option><option value="attachment">添付</option><option value="form">フォーム</option><option value="qr">QRコード</option></select></div>
        <div class="col-md-4"><label class="form-label small c-ext-label text-muted">添付拡張子(添付/QR時)</label><input class="form-control form-control-sm c-ext" placeholder="html / docx / pdf / xlsx / pptx"></div>
        <div class="col-md-4 d-flex align-items-end"><div class="form-check">
          <input class="form-check-input c-zip" type="checkbox"><label class="form-check-label small c-zip-label text-muted">zip化</label></div></div>
      </div>
      <div class="row g-2 mt-1">
        <div class="col-md-8"><label class="form-label small c-prefix-label text-muted">添付ファイル名の接頭辞(添付/QR時・任意)</label>
          <input class="form-control form-control-sm c-prefix" maxlength="40" placeholder="例: 添付資料-  （末尾に管理番号と拡張子が自動で付きます）"></div>
      </div>
      <div class="form-text c-linkmode-hint"></div>
      <div class="form-check mt-1">
        <input class="form-check-input c-suppress-url" type="checkbox" id="sup-${idx}">
        <label class="form-check-label small c-suppress-label text-muted" for="sup-${idx}">本文にURLを差し込まない（添付/QR型向け：本文に半端なリンクを残さない）</label>
      </div>
      <div class="form-check mt-1">
        <input class="form-check-input c-suppress-email" type="checkbox" id="supmail-${idx}">
        <label class="form-check-label small text-muted" for="supmail-${idx}">認証画面にメールアドレスを事前入力しない（利用者に自分で入力させる）</label>
      </div>
      <details class="content-endpoint-override mt-1">
        <summary class="small text-muted" style="cursor:pointer">このコンテンツだけ送信元／ビーコンを変える（未設定ならキャンペーン既定）</summary>
        <div class="row g-2 mt-1">
          <div class="col-md-6"><label class="form-label small text-muted">送信元アドレス（任意・未指定ならキャンペーン既定）</label>
            ${endpointWidget('cfrom-' + idx, 'from', fromList, {})}</div>
          <div class="col-md-6"><label class="form-label small text-muted">ビーコンURL（任意・未指定ならキャンペーン既定）</label>
            ${endpointWidget('cbeacon-' + idx, 'beacon', beaconList, {})}</div>
        </div>
      </details>
      </div>
    </div>`;
  let campaignEditorSteps;
  showModal(isEdit ? 'キャンペーン編集' : '新規キャンペーン', body, async () => {
    if (!campaignEditorSteps.validateBeforeSave()) {
      // 該当の手順と欄はブラウザの案内で示すので、重ねてエラーを出さない
      const err = new Error('入力内容を確認してください');
      err.handled = true;
      throw err;
    }
    const f = $('#campaignForm');
    // コンテンツ収集
    const contents = Array.from(document.querySelectorAll('#contentsList .content-row')).map((row) => {
      const c = {
        subject_template_id: Number(row.querySelector('.c-subject').value),
        body_template_id: Number(row.querySelector('.c-body').value),
        phish_template_id: Number(row.querySelector('.c-phish').value),
        link_mode: row.querySelector('.c-linkmode').value,
      };
      const ext = row.querySelector('.c-ext').value.trim();
      if (ext) c.attachment_ext = ext;
      const prefix = row.querySelector('.c-prefix').value.trim();
      if (prefix) c.attachment_filename = prefix;
      if (row.querySelector('.c-zip').checked) c.attachment_zip = 1;
      if (row.querySelector('.c-suppress-url').checked) c.suppress_body_url = 1;
      if (row.querySelector('.c-suppress-email').checked) c.suppress_prefill_email = 1;
      // コンテンツ別の送信元/ビーコンURL(複数選択＋直接入力・任意)。空ならキャンペーン既定を使う。
      const cFromList = collectEndpointWidget(row.querySelector('[data-ep-name^="cfrom-"]'));
      const cBeaconList = collectEndpointWidget(row.querySelector('[data-ep-name^="cbeacon-"]'));
      if (cFromList.length) { c.from_addresses = cFromList; c.from_address = cFromList[0]; }
      if (cBeaconList.length) { c.beacon_bases = cBeaconList; c.beacon_base = cBeaconList[0]; }
      return c;
    });
    if (!contents.length) throw new Error('コンテンツを1件以上追加してください');
    // キャンペーン既定の複数選択(送信元・ビーコン)。単数列は先頭要素をサーバでも入れるが、UI からも送る。
    const fromDefaults = collectEndpointWidget(f.querySelector('[data-ep-name="from"]'));
    const beaconDefaults = collectEndpointWidget(f.querySelector('[data-ep-name="beacon"]'));
    if (!fromDefaults.length) {
      campaignEditorSteps.openStep('delivery');
      const err = new Error('送信元アドレスを1件以上選ぶか入力してください');
      err.handled = true;
      toast(err.message, 'err');
      throw err;
    }
    const payload = {
      name: f.name.value.trim(),
      contents,
      from_address: fromDefaults[0],
      from_addresses: fromDefaults,
      send_mode: f.send_mode.value,
      weekdays_only: f.weekdays_only.checked,
      is_test: f.is_test.checked,
      content_delivery: f.content_delivery ? f.content_delivery.value : 'distribute',
      reveal_page_id: (f.reveal_page_id && f.reveal_page_id.value) ? Number(f.reveal_page_id.value) : null,
      test_redirect_emails: (f.test_redirect_emails && f.test_redirect_emails.value.trim()) || null,
      start_at: f.start_at.value.replace('T', ' '),
      end_at: f.end_at.value.replace('T', ' '),
      target_ids: multiVals(f.target_ids),
      group_ids: multiVals(f.group_ids),
    };
    if (f.split_count.value) payload.split_count = Number(f.split_count.value);
    if (f.split_interval_min.value) payload.split_interval_min = Number(f.split_interval_min.value);
    if (f.business_start.value.trim()) payload.business_start = f.business_start.value.trim();
    if (f.business_end.value.trim()) payload.business_end = f.business_end.value.trim();
    if (beaconDefaults.length) { payload.beacon_bases = beaconDefaults; payload.beacon_base = beaconDefaults[0]; }
    if (!payload.target_ids.length && !payload.group_ids.length) {
      // 対象者の手順へ移り、欄の下に理由を出す(最終確認の手順に留まったままにしない)
      campaignEditorSteps.openStep('targets');
      markModalField(f.group_ids, '対象グループか個別対象者を1つ以上選んでください');
      f.group_ids.focus();
      const err = new Error('対象者かグループを選択してください');
      err.handled = true;
      throw err;
    }
    // 直接入力した値のうち「マスタにも登録する」を選んだものを先にマスタへ登録する(任意・失敗は無視)。
    await saveEndpointWidgetsToMaster(f);
    if (isEdit) {
      payload.id = campaignId;
      await api('api/campaigns.php', { method: 'POST', query: { action: 'update' }, body: payload });
      toast('更新しました', 'ok');
    } else {
      await api('api/campaigns.php', { method: 'POST', query: { action: 'create' }, body: payload });
      toast('作成しました', 'ok');
    }
    if (State.view === 'campaignWorkspace' && State.campaignWorkspaceId === campaignId) renderCampaignWorkspace();
    else renderCampaigns();
  }, { size: 'xl' });
  campaignEditorSteps = createCampaignEditorSteps($('#campaignForm'), { initialStep });
  // モーダル表示後: コンテンツリストを初期化（1行）+ 追加/削除ボタン配線
  let contentIdx = 0;
  const listEl = document.getElementById('contentsList');
  const syncContentSummary = (row) => {
    const scenario = row.querySelector('.c-scenario').selectedOptions[0]?.textContent || '個別指定';
    const mode = row.querySelector('.c-linkmode').selectedOptions[0]?.textContent || '';
    row.querySelector('.content-row-summary').textContent = `${scenario} / ${mode}`;
  };
  const setContentExpanded = (row, expanded) => {
    row.querySelector('.content-row-body').classList.toggle('d-none', !expanded);
    const button = row.querySelector('.toggle-content');
    button.setAttribute('aria-expanded', String(expanded));
    button.title = expanded ? '折りたたむ' : '展開する';
    button.querySelector('i').className = expanded ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
  };
  const renumber = () => {
    const rows = Array.from(listEl.querySelectorAll('.content-row'));
    rows.forEach((row, i) => {
      row.querySelector('.badge').textContent = `コンテンツ ${i + 1}`;
      const del = row.querySelector('.del-content');
      del.style.visibility = i === 0 && rows.length === 1 ? 'hidden' : 'visible';
      row.querySelector('.move-content-up').disabled = i === 0;
      row.querySelector('.move-content-down').disabled = i === rows.length - 1;
      syncContentSummary(row);
    });
    const countLabel = document.getElementById('contentCountLabel');
    if (countLabel) countLabel.textContent = `${rows.length} / 100件`;
  };
  // 配信形式に応じて添付拡張子/zipの有効・無効を切り替える(整合性ガード)。
  // link/form 型は本文中のリンクで追跡するため、添付拡張子・zipは意味を持たない→無効化。
  const syncAttachment = (row) => {
    const mode = row.querySelector('.c-linkmode').value;
    const needsAttachment = (mode === 'attachment' || mode === 'qr');
    const ext = row.querySelector('.c-ext');
    const zip = row.querySelector('.c-zip');
    const prefix = row.querySelector('.c-prefix');
    const extLabel = row.querySelector('.c-ext-label');
    const zipLabel = row.querySelector('.c-zip-label');
    const prefixLabel = row.querySelector('.c-prefix-label');
    const hint = row.querySelector('.c-linkmode-hint');
    const suppress = row.querySelector('.c-suppress-url');
    const suppressLabel = row.querySelector('.c-suppress-label');
    ext.disabled = !needsAttachment;
    zip.disabled = !needsAttachment;
    // 添付ファイル名の接頭辞も添付/QR型のみ有効(link/form型ではファイルを作らない)。
    prefix.disabled = !needsAttachment;
    // 本文URL抑制は添付/QR型のみ有効(リンク/フォーム型でURLを消すと追跡不能になるため)。
    suppress.disabled = !needsAttachment;
    extLabel.classList.toggle('text-muted', !needsAttachment);
    zipLabel.classList.toggle('text-muted', !needsAttachment);
    prefixLabel.classList.toggle('text-muted', !needsAttachment);
    suppressLabel.classList.toggle('text-muted', !needsAttachment);
    if (!needsAttachment) { ext.value = ''; zip.checked = false; suppress.checked = false; prefix.value = ''; }
    else { suppress.checked = true; } // 添付/QR型は既定で本文URLを抑制(半端なリンクを残さない)
    const hints = {
      link: 'リンク型：本文中のリンクをクリックすると追跡します（添付は使いません）。',
      form: 'フォーム型：偽ログインフォームへの入力を追跡します（添付は使いません）。',
      attachment: '添付型：指定拡張子のファイルを添付し、開封を追跡します。',
      qr: 'QRコード型：QR画像を添付し、スキャン（＝クリック）を追跡します。',
    };
    hint.textContent = hints[mode] || '';
  };
  // コンテンツの本文プレビュー(事故防止): 選択中の件名/本文/偽ログインの実内容を行内に展開表示。
  // 作成フォームを壊さないよう、モーダルではなく行内の c-preview-area にトグル表示する。
  const previewContent = (row) => {
    const area = row.querySelector('.c-preview-area');
    if (!area.classList.contains('d-none')) { area.classList.add('d-none'); area.innerHTML = ''; return; }
    const sid = row.querySelector('.c-subject').value;
    const bid = row.querySelector('.c-body').value;
    const pid = row.querySelector('.c-phish').value;
    const block = (label, t) => {
      if (!t) return `<div class="mb-2"><strong>${label}</strong> <span class="text-muted small">（未選択）</span></div>`;
      return `<div class="mb-2"><strong>${label}：</strong>${esc(t.name)}
        <pre class="border rounded p-2 mt-1 small mb-0" style="white-space:pre-wrap;max-height:180px;overflow:auto">${esc(t.content || '')}</pre></div>`;
    };
    area.innerHTML = `<div class="small text-muted mb-2">送信前の内容確認。プレースホルダ #$1$#〜 は対象者情報に置換されます。</div>
      ${block('件名', tplById[sid])}
      ${block('本文', tplById[bid])}
      ${block('偽ログイン画面', tplById[pid])}`;
    area.classList.remove('d-none');
  };
  const addRow = (prefill = null) => {
    if (listEl.querySelectorAll('.content-row').length >= 100) {
      toast('コンテンツは100件までです', 'err');
      return null;
    }
    const wrap = document.createElement('div');
    wrap.innerHTML = contentRow(contentIdx++);
    const row = wrap.firstElementChild;
    row.querySelector('.del-content').addEventListener('click', () => { row.remove(); renumber(); });
    row.querySelector('.toggle-content').addEventListener('click', () => {
      const expanded = row.querySelector('.toggle-content').getAttribute('aria-expanded') === 'true';
      setContentExpanded(row, !expanded);
    });
    row.querySelector('.move-content-up').addEventListener('click', () => {
      const previous = row.previousElementSibling;
      if (previous) listEl.insertBefore(row, previous);
      renumber();
    });
    row.querySelector('.move-content-down').addEventListener('click', () => {
      const next = row.nextElementSibling;
      if (next) listEl.insertBefore(next, row);
      renumber();
    });
    row.querySelector('.c-linkmode').addEventListener('change', () => {
      syncAttachment(row);
      syncContentSummary(row);
    });
    row.querySelector('.c-preview').addEventListener('click', () => previewContent(row));
    // シナリオ選択 → 件名・本文を連動セット。
    const scenSel = row.querySelector('.c-scenario');
    scenSel.addEventListener('change', () => {
      const s = scenarioMap[scenSel.value];
      if (s && s.subject_id && s.body_id) {
        row.querySelector('.c-subject').value = s.subject_id;
        row.querySelector('.c-body').value = s.body_id;
      }
      syncContentSummary(row);
    });
    // 件名/本文を手動変更したら、シナリオ選択を「個別」に戻す(連動が崩れたことを示す)。
    const clearScenario = () => { scenSel.value = ''; };
    row.querySelector('.c-subject').addEventListener('change', clearScenario);
    row.querySelector('.c-body').addEventListener('change', clearScenario);
    // 選んだ本文の概要(元の事例、手口、見分けるポイント)を選択欄の下に出す。利用者の入力なので textContent で入れる。
    const showScenarioDescription = () => {
      const t = tplById[row.querySelector('.c-body').value];
      const text = String(t?.description || '').trim();
      row.querySelector('.c-scenario-desc').textContent = text ? `概要: ${text}` : '';
    };
    scenSel.addEventListener('change', showScenarioDescription);
    row.querySelector('.c-body').addEventListener('change', showScenarioDescription);
    listEl.appendChild(row);
    if (prefill) {
      // 編集時: 既存コンテンツの値を復元。
      if (prefill.subject_template_id) row.querySelector('.c-subject').value = prefill.subject_template_id;
      if (prefill.body_template_id) row.querySelector('.c-body').value = prefill.body_template_id;
      if (prefill.phish_template_id) row.querySelector('.c-phish').value = prefill.phish_template_id;
      if (prefill.link_mode) row.querySelector('.c-linkmode').value = prefill.link_mode;
      row.querySelector('.c-scenario').value = '';  // 個別指定として復元
      syncAttachment(row);  // link_mode に応じて添付欄の有効/無効を先に整える
      if (prefill.attachment_ext) row.querySelector('.c-ext').value = prefill.attachment_ext;
      if (prefill.attachment_filename) row.querySelector('.c-prefix').value = prefill.attachment_filename;
      row.querySelector('.c-zip').checked = Number(prefill.attachment_zip) === 1;
      row.querySelector('.c-suppress-url').checked = Number(prefill.suppress_body_url) === 1;
      row.querySelector('.c-suppress-email').checked = Number(prefill.suppress_prefill_email) === 1;
      // コンテンツ別の複数指定を復元(JSON 配列。無ければ単数列を1件として復元)。
      const cFromVals = parseEndpointList(prefill.from_addresses, prefill.from_address);
      const cBeaconVals = parseEndpointList(prefill.beacon_bases, prefill.beacon_base);
      fillEndpointWidget(row.querySelector('[data-ep-name^="cfrom-"]'), cFromVals);
      fillEndpointWidget(row.querySelector('[data-ep-name^="cbeacon-"]'), cBeaconVals);
      // 既に上書きがあるコンテンツは、上書き欄を開いた状態で見せる(隠したまま見落とさないように)
      if (cFromVals.length || cBeaconVals.length) {
        const ov = row.querySelector('.content-endpoint-override');
        if (ov) ov.open = true;
      }
    } else {
      syncAttachment(row); // 初期状態(link)で添付を無効化
      // 初期状態で最初のシナリオを選択して件名・本文を連動させておく(ちぐはぐ防止の既定)。
      if (scenarios.length) { scenSel.value = scenarios[0].key; scenSel.dispatchEvent(new Event('change')); }
    }
    showScenarioDescription();
    row.dataset.pristine = prefill ? 'false' : 'true';
    row.addEventListener('input', () => { row.dataset.pristine = 'false'; });
    row.addEventListener('change', () => { row.dataset.pristine = 'false'; syncContentSummary(row); });
    setContentExpanded(row, true);
    renumber();
    return row;
  };
  const importSelectedCampaigns = async () => {
    const select = document.getElementById('contentImportCampaigns');
    const campaignIds = Array.from(select?.selectedOptions || []).map((option) => Number(option.value));
    if (!campaignIds.length) throw new Error('取り込むキャンペーンを選択してください');
    const importedCampaigns = await Promise.all(campaignIds.map((id) =>
      api('api/campaigns.php', { query: { action: 'get', id } })
    ));
    const importedContents = importedCampaigns.flatMap((campaign) => campaign.contents || []);
    const rows = Array.from(listEl.querySelectorAll('.content-row'));
    const replacePlaceholder = rows.length === 1 && rows[0].dataset.pristine === 'true';
    const retainedCount = replacePlaceholder ? 0 : rows.length;
    if (retainedCount + importedContents.length > 100) {
      throw new Error(`取り込み後のコンテンツ数が100件を超えます（${retainedCount + importedContents.length}件）`);
    }
    if (replacePlaceholder) rows[0].remove();
    listEl.querySelectorAll('.content-row').forEach((row) => setContentExpanded(row, false));
    let firstImported = null;
    importedContents.forEach((content) => {
      const row = addRow(content);
      if (row) setContentExpanded(row, false);
      if (firstImported === null) firstImported = row;
    });
    if (firstImported) setContentExpanded(firstImported, true);
    Array.from(select.options).forEach((option) => { option.selected = false; });
    renumber();
    toast(`${importedContents.length}件のコンテンツを取り込みました`, 'ok');
  };
  if (listEl) {
    const editContents = (isEdit && editData && editData.contents && editData.contents.length) ? editData.contents : null;
    if (editContents) {
      editContents.forEach((c, index) => {
        const row = addRow(c);
        if (row && index > 0) setContentExpanded(row, false);
      });  // 既存コンテンツを行として復元
    } else {
      addRow(); // 初期1行(新規)
    }
    const addBtn = document.getElementById('addContentBtn');
    if (addBtn) addBtn.addEventListener('click', () => {
      listEl.querySelectorAll('.content-row').forEach((row) => setContentExpanded(row, false));
      const row = addRow();
      if (row) setContentExpanded(row, true);
    });
    document.getElementById('collapseAllContents')?.addEventListener('click', () => {
      listEl.querySelectorAll('.content-row').forEach((row) => setContentExpanded(row, false));
    });
    document.getElementById('expandAllContents')?.addEventListener('click', () => {
      listEl.querySelectorAll('.content-row').forEach((row) => setContentExpanded(row, true));
    });
    document.getElementById('importSelectedCampaigns')?.addEventListener('click', async () => {
      try { await importSelectedCampaigns(); }
      catch (error) { toast(error.message, 'err'); }
    });
  }
  // 編集時: キャンペーン本体フィールドをプリフィル。
  if (isEdit && editData && editData.campaign) {
    const c = editData.campaign;
    const f = document.getElementById('campaignForm');
    const setVal = (name, val) => { if (f[name] !== undefined && val !== null && val !== undefined) f[name].value = val; };
    setVal('name', c.name);
    // 送信元・ビーコンの複数選択を復元(JSON 配列。無ければ単数列を1件として)。
    fillEndpointWidget(f.querySelector('[data-ep-name="from"]'), parseEndpointList(c.from_addresses, c.from_address));
    fillEndpointWidget(f.querySelector('[data-ep-name="beacon"]'), parseEndpointList(c.beacon_bases, c.beacon_base));
    setVal('send_mode', c.send_mode || 'normal');
    if (c.split_count) setVal('split_count', c.split_count);
    if (c.split_interval_min) setVal('split_interval_min', c.split_interval_min);
    // datetime-local は "YYYY-MM-DDTHH:MM" 形式。DBは "YYYY-MM-DD HH:MM:SS"。
    if (c.start_at) f.start_at.value = String(c.start_at).replace(' ', 'T').slice(0, 16);
    if (c.end_at) f.end_at.value = String(c.end_at).replace(' ', 'T').slice(0, 16);
    if (c.business_start) setVal('business_start', c.business_start);
    if (c.business_end) setVal('business_end', c.business_end);
    f.weekdays_only.checked = Number(c.weekdays_only) === 1;
    f.is_test.checked = Number(c.is_test) === 1;
    if (f.content_delivery) f.content_delivery.value = c.content_delivery || 'distribute';
    if (f.reveal_page_id) f.reveal_page_id.value = c.reveal_page_id != null ? String(c.reveal_page_id) : '';
    if (f.test_redirect_emails && c.test_redirect_emails) f.test_redirect_emails.value = c.test_redirect_emails;
    toggleTestRedirect();  // is_test の状態に応じてテスト宛先欄の表示を更新
    // 対象者を選択状態にする。
    const tids = (editData.target_ids || []).map(String);
    if (f.target_ids && tids.length) {
      Array.from(f.target_ids.options).forEach((o) => { o.selected = tids.includes(o.value); });
    }
  }
  const campaignForm = document.getElementById('campaignForm');
  const campaignTargetSummary = document.getElementById('campaignTargetSummary');
  const allMembersGroupIds = new Set((grps.groups || [])
    .filter((group) => group.kind === 'all' || group.name === '全職員')
    .map((group) => Number(group.id)));
  let cachedGroupMemberIds = new Set();
  let targetSummaryRequest = 0;
  const clearIndividualTargets = () => {
    Array.from(campaignForm.target_ids.options).forEach((option) => { option.selected = false; });
  };
  const renderCampaignTargetSummary = () => {
    const individualIds = new Set(multiVals(campaignForm.target_ids));
    const combinedIds = new Set([...cachedGroupMemberIds, ...individualIds]);
    const addedIndividuals = [...individualIds].filter((id) => !cachedGroupMemberIds.has(id)).length;
    campaignTargetSummary.textContent = `送付予定: ${combinedIds.size}名（グループ ${cachedGroupMemberIds.size}名 + 個別追加 ${addedIndividuals}名）`;
  };
  const syncCampaignTargetSummary = async () => {
    const requestId = ++targetSummaryRequest;
    const groupIds = multiVals(campaignForm.group_ids);
    try {
      const responses = await Promise.all(groupIds.map((groupId) =>
        api('api/groups.php', { query: { action: 'members', group_id: groupId } })
      ));
      if (requestId !== targetSummaryRequest) return;
      cachedGroupMemberIds = new Set(responses.flatMap((response) =>
        (response.members || []).map((member) => Number(member.id))
      ));
      renderCampaignTargetSummary();
    } catch (error) {
      if (requestId !== targetSummaryRequest) return;
      campaignTargetSummary.textContent = `送付予定人数を取得できません（${error.message}）`;
    }
  };
  campaignForm.group_ids.addEventListener('change', () => {
    const selectedGroupIds = multiVals(campaignForm.group_ids);
    if (selectedGroupIds.some((groupId) => allMembersGroupIds.has(groupId))) clearIndividualTargets();
    syncCampaignTargetSummary();
  });
  campaignForm.target_ids.addEventListener('change', renderCampaignTargetSummary);
  syncCampaignTargetSummary();
  // 送信元・ビーコンの複数選択ウィジェット(追加/削除/疎通確認)をフォーム全体へ委譲する。
  wireEndpointWidgets(campaignForm);
  // 対象日プレビュー(平日限定の可視化)
  const wpBtn = document.getElementById('weekdayPreviewBtn');
  if (wpBtn) wpBtn.addEventListener('click', showWeekdayPreview);
  // テスト送信チェックに応じてテスト宛先(リダイレクト先)欄の表示を切り替える。
  const cbTest = document.getElementById('cbTest');
  if (cbTest) cbTest.addEventListener('change', toggleTestRedirect);
  toggleTestRedirect();  // 初期表示
}
// is_test チェック時のみテスト宛先(リダイレクト先メール)入力欄を表示する。
function toggleTestRedirect() {
  const cb = document.getElementById('cbTest');
  const wrap = document.getElementById('testRedirectWrap');
  if (wrap) wrap.style.display = (cb && cb.checked) ? '' : 'none';
}
// 開始〜終了の期間の各日をカレンダー表示。平日限定ONなら土日を配信対象外として色分けする。
function showWeekdayPreview() {
  const area = document.getElementById('weekdayPreview');
  const f = document.getElementById('campaignForm');
  if (!area.classList.contains('d-none')) { area.classList.add('d-none'); area.innerHTML = ''; return; }
  const sv = f.start_at.value, ev = f.end_at.value;
  if (!sv || !ev) { area.classList.remove('d-none'); area.innerHTML = '<span class="text-warning small">開始日時・終了日時を先に入力してください。</span>'; return; }
  const start = new Date(sv), end = new Date(ev);
  if (isNaN(start) || isNaN(end) || end < start) { area.classList.remove('d-none'); area.innerHTML = '<span class="text-danger small">期間が不正です。</span>'; return; }
  const weekdaysOnly = f.weekdays_only.checked;
  const days = [];
  const cur = new Date(start.getFullYear(), start.getMonth(), start.getDate());
  const last = new Date(end.getFullYear(), end.getMonth(), end.getDate());
  let guard = 0;
  while (cur <= last && guard < 366) {
    days.push(new Date(cur));
    cur.setDate(cur.getDate() + 1);
    guard++;
  }
  const wd = ['日', '月', '火', '水', '木', '金', '土'];
  let sendable = 0;
  const chips = days.map((d) => {
    const dow = d.getDay();
    const isWeekend = (dow === 0 || dow === 6);
    const excluded = weekdaysOnly && isWeekend;
    if (!excluded) sendable++;
    const cls = excluded ? 'bg-light text-muted text-decoration-line-through' : (isWeekend ? 'bg-info-subtle' : 'bg-primary-subtle');
    return `<span class="badge ${cls} border me-1 mb-1" style="font-weight:normal">${d.getMonth() + 1}/${d.getDate()}(${wd[dow]})</span>`;
  }).join('');
  area.classList.remove('d-none');
  area.innerHTML = `<div class="small text-muted mb-1">配信対象日：${sendable} 日 / 全 ${days.length} 日${weekdaysOnly ? '（平日限定：土日は除外）' : '（全曜日）'}。取消線＝配信されない日。</div>
    <div class="border rounded p-2" style="max-height:120px;overflow:auto">${chips}</div>`;
}
// (旧 checkBeaconUrl は checkEndpointBeacons に統合。単一入力のビーコン疎通確認は廃止)
/* ===== 送信エンドポイントの複数選択ウィジェット(beacon/from) ===== */
// JSON 配列(文字列)を string[] にする。空/不正なら単数値 single を1件の配列にフォールバック。
function parseEndpointList(json, single) {
  if (json !== null && json !== undefined && json !== '') {
    try {
      const arr = typeof json === 'string' ? JSON.parse(json) : json;
      if (Array.isArray(arr)) {
        const out = arr.filter((v) => typeof v === 'string' && v.trim() !== '');
        if (out.length) return out;
      }
    } catch (e) { /* フォールバックへ */ }
  }
  return (single !== null && single !== undefined && String(single).trim() !== '') ? [String(single)] : [];
}
// 選択済み(チェック済み)＋直接追加した値を、重複なく順序を保って返す。
function collectEndpointWidget(el) {
  if (!el) return [];
  const out = [];
  const add = (v) => { v = (v || '').trim(); if (v && !out.includes(v)) out.push(v); };
  el.querySelectorAll('.ep-check:checked').forEach((c) => add(c.value));
  el.querySelectorAll('.ep-added [data-ep-val]').forEach((c) => add(c.getAttribute('data-ep-val')));
  return out;
}
// 既存値を復元する。候補にあればチェック、無ければ追加チップにする。
function fillEndpointWidget(el, values) {
  if (!el || !Array.isArray(values)) return;
  const known = new Set();
  el.querySelectorAll('.ep-check').forEach((c) => known.add(c.value));
  values.forEach((v) => {
    v = (v || '').trim();
    if (!v) return;
    if (known.has(v)) {
      el.querySelectorAll('.ep-check').forEach((c) => { if (c.value === v) c.checked = true; });
    } else {
      addEndpointChip(el, v);
    }
  });
}
// 直接入力の値をチップとして足す(×で外せる)。kind で簡易バリデーション。
function addEndpointChip(el, value) {
  value = (value || '').trim();
  if (!value) return false;
  const kind = el.getAttribute('data-endpoints-widget');
  if (kind === 'from' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) { toast('メールアドレスの形式で入力してください', 'err'); return false; }
  if (kind === 'beacon' && !/^https?:\/\/[^\s/][^\s]*$/.test(value)) { toast('http:// または https:// で始まるURLを入力してください', 'err'); return false; }
  // 既にチェック候補にあるならチェックを立てるだけ
  let inCandidates = false;
  el.querySelectorAll('.ep-check').forEach((c) => { if (c.value === value) { c.checked = true; inCandidates = true; } });
  if (inCandidates) return true;
  // 既に追加済みなら何もしない
  if (el.querySelector(`.ep-added [data-ep-val="${CSS.escape(value)}"]`)) return true;
  const added = el.querySelector('.ep-added');
  const chip = document.createElement('span');
  chip.className = 'badge bg-primary-subtle text-dark border me-1 mb-1';
  chip.setAttribute('data-ep-val', value);
  chip.innerHTML = `${esc(value)} <a href="#" class="ep-chip-remove text-danger text-decoration-none" aria-label="削除">×</a>`;
  added.appendChild(chip);
  return true;
}
// モーダル内のエンドポイントウィジェットへ、追加・削除・疎通確認のイベントを1回だけ委譲する。
function wireEndpointWidgets(root) {
  if (!root || root.__epWired) return;
  root.__epWired = true;
  root.addEventListener('click', async (e) => {
    const widget = e.target.closest('[data-endpoints-widget]');
    if (!widget) return;
    if (e.target.closest('.ep-add-btn')) {
      e.preventDefault();
      const input = widget.querySelector('.ep-add-input');
      if (addEndpointChip(widget, input.value)) input.value = '';
      return;
    }
    if (e.target.closest('.ep-chip-remove')) {
      e.preventDefault();
      e.target.closest('[data-ep-val]').remove();
      return;
    }
    if (e.target.closest('.ep-check-btn')) {
      e.preventDefault();
      await checkEndpointBeacons(widget);
      return;
    }
  });
  // Enter で追加(送信の暴発を防ぐ)。
  root.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    const input = e.target.closest('.ep-add-input');
    if (!input) return;
    e.preventDefault();
    const widget = input.closest('[data-endpoints-widget]');
    if (addEndpointChip(widget, input.value)) input.value = '';
  });
}
// 選択(＋直接追加)した各 beacon の疎通を確認する(P6)。
async function checkEndpointBeacons(widget) {
  const result = widget.querySelector('.ep-check-result');
  const urls = collectEndpointWidget(widget);
  const target = urls.length ? urls : [(widget.querySelector('.ep-add-input')?.value || '').trim()].filter(Boolean);
  if (!target.length) { result.innerHTML = '<span class="text-warning">確認するビーコンを選ぶか入力してください</span>'; return; }
  const btn = widget.querySelector('.ep-check-btn');
  if (btn) btn.disabled = true;
  result.innerHTML = '<span class="text-muted">確認中…</span>';
  const lines = [];
  for (const url of target) {
    try {
      const r = await api('api/url_check.php', { method: 'POST', body: { url } });
      lines.push(r.reachable
        ? `<div class="text-success"><i class="bi bi-check-circle"></i> ${esc(url)}（HTTP ${r.http_status}, ${r.response_ms}ms）</div>`
        : `<div class="text-danger"><i class="bi bi-x-circle"></i> ${esc(url)}（${esc(r.error || '応答なし')}）</div>`);
    } catch (e) {
      lines.push(`<div class="text-danger"><i class="bi bi-x-circle"></i> ${esc(url)}（${esc(e.message)}）</div>`);
    }
  }
  result.innerHTML = lines.join('');
  if (btn) btn.disabled = false;
}
// 直接入力してマスタにも登録するチェックが入ったウィジェットの、候補に無い値をマスタへ保存する。
async function saveEndpointWidgetsToMaster(root) {
  const widgets = Array.from(root.querySelectorAll('[data-endpoints-widget]'));
  for (const w of widgets) {
    const save = w.querySelector('.ep-save-master');
    if (!save || !save.checked) continue;
    const kind = w.getAttribute('data-endpoints-widget');
    const chips = Array.from(w.querySelectorAll('.ep-added [data-ep-val]')).map((c) => c.getAttribute('data-ep-val'));
    for (const value of chips) {
      try {
        await api('api/send_endpoints.php', { method: 'POST', query: { action: 'create' }, body: { kind, value } });
      } catch (e) { /* 重複(409)などは無視。保存は任意なので失敗しても本体保存は続ける */ }
    }
  }
}
function multiVals(sel) { return Array.from(sel.selectedOptions).map((o) => Number(o.value)); }

/* ========== レポート ========== */
let reportChart = null;
let reportTimelineChart = null;
let reportSelectedId = null;
let reportCommitCampaignId = null;
function pct(v) { return `${(Number(v) || 0).toFixed(1)}%`; }
// 数と率の併記セル。率が null(分母0で未定義)のときは数だけを出す。
function countRate(count, rate) { const c = Number(count) || 0; return rate === null ? `${c}` : `${c} <span class="small text-muted">(${pct(rate)})</span>`; }
// 認証率 = 認証数 ÷ クリック数(2026-08-24 に定義を統一)。クリック0のときは null(未定義)。
// 従来の「分母=サイト表示数」だと表ごとに定義が揺れて誤読するため、全テーブルこの定義で表示する。
function authRateOf(auth, clicks) { const c = Number(clicks) || 0; return c > 0 ? (Number(auth) || 0) / c * 100 : null; }
function authTargetRateOf(auth, targets) { const t = Number(targets) || 0; return t > 0 ? (Number(auth) || 0) / t * 100 : null; }
function rateClass(v, warn, danger) { const n = Number(v) || 0; return n >= danger ? 'val-danger' : n >= warn ? 'val-warning' : 'val-success'; }
// 報告率は「高いほど良い」ので rateClass とは色の向きが逆になる。
// 失敗率と同じ関数を使い回すと、よく報告している部署が赤く出て判断を誤る。
function goodRateClass(v, ok, great) { const n = Number(v) || 0; return n >= great ? 'val-success' : n >= ok ? 'val-warning' : 'val-danger'; }
// 報告率と防衛失敗率の定義(サーバの report_summary_from_counts と同じ)。見出しの title に出す。
const REPORT_RATE_TITLE = '報告率＝訓練メールを報告した人数÷対象数（テストの対象者を除く）';
const FAILURE_RATE_TITLE = '防衛失敗率＝リンクを踏んで偽サイトを表示したか、認証情報を入力した人数÷対象数（両方した人も1人。テストの対象者を除く）';
// 訓練の一覧の報告率と防衛失敗率のセル。集計がない(読めなかった)ときは「-」。
function campaignRateCells(r) {
  if (!r) return '<td>-</td><td>-</td>';
  return `<td class="${goodRateClass(r.report_rate,5,20)}">${countRate(r.report_count, r.report_rate)}</td>
      <td class="${rateClass(r.failure_rate,25,50)}">${countRate(r.failure_count, r.failure_rate)}</td>`;
}
let reportTestFilter = 'prod';  // prod=本番のみ / test=テストのみ / all=全部
function setReportFilter(v) { reportTestFilter = v; renderReports(); }
async function renderReports() {
  // 本番統計にテスト送信が混ざらないよう、既定は本番のみ(prod)。フィルタで切替。
  const { campaigns } = await api('api/report.php', { query: { action: 'campaigns', test_filter: reportTestFilter } });
  Cache.reports = {}; for (const c of campaigns) Cache.reports[c.id] = c;
  // フィルタUI(本番/テスト/全部)を一覧上部に描画。
  const filterBar = document.getElementById('reportFilterBar');
  if (filterBar) {
    const btn = (v, label) => `<button class="btn btn-sm btn-outline-secondary${reportTestFilter === v ? ' active' : ''}" aria-pressed="${reportTestFilter === v}" onclick="setReportFilter('${v}')">${label}</button>`;
    filterBar.innerHTML = `<div class="btn-group btn-group-sm">${btn('prod', '本番のみ')}${btn('test', 'テストのみ')}${btn('all', '全部')}</div>`;
  }
  // #列は作成順(古い順)で固定の通し番号。表示は降順(最新が上)。onclick は内部ID(c.id)を保持。
  const reportsAsc = campaigns.slice().sort((a, b) => a.id - b.id);
  const numById = {};
  reportsAsc.forEach((c, i) => { numById[c.id] = i + 1; });
  const shown = reportsAsc.slice().reverse();  // 最新が一番上
  $('#reportsBody').innerHTML = shown.length ? shown.map((c) => {
    const s = c.summary || c;
    const authTargetRate = authTargetRateOf(s.auth_count, s.target_count);
    return `<tr style="cursor:pointer" onclick="showReportDetail(${c.id})">
      <td>${numById[c.id]}</td><td>${esc(c.name)}${Number(c.is_test) ? ' <span class="badge bg-info">TEST</span>' : ''}${c.closed_at ? ' <span class="badge bg-secondary">クローズ</span>' : ''}</td><td>${s.target_count}</td>
      <td>${pct(s.sent_rate)}</td>
      <td class="${rateClass(s.click_rate,25,50)}">${countRate(s.click_count, s.click_rate)}</td>
      <td class="${rateClass(authRateOf(s.auth_count, s.click_count) ?? 0,5,20)}">${countRate(s.auth_count, authRateOf(s.auth_count, s.click_count))}</td>
      <td class="${authTargetRate === null ? '' : rateClass(authTargetRate,5,20)}">${authTargetRate === null ? '-' : pct(authTargetRate)}</td>
      ${campaignRateCells(s)}
      <td><i class="bi bi-chevron-right"></i></td>
    </tr>`;
  }).join('') : emptyRow(10);
  if (reportSelectedId && Cache.reports[reportSelectedId]) showReportDetail(reportSelectedId);
  else { $('#reportDetail').classList.add('d-none'); reportSelectedId = null; }
}
async function showReportDetail(id) {
  reportSelectedId = id;
  const c = Cache.reports[id];
  if (!c) return;
  const s = c.summary || c;
  $('#reportDetail').classList.remove('d-none');
  $('#reportDetailTitle').textContent = `${c.name} — 反応内訳`;
  renderReportOverviewKpis(s);
  if (reportChart) reportChart.destroy();
  reportChart = new Chart($('#reportChart'), {
    type: 'bar',
    data: {
      // 「開封」ではなく「サイト表示」。ビーコン(kunren-beacon-*.png)は偽サイトのHTMLに
      // 埋め込まれており、訓練メール本文はプレーンテキスト(send_email.py の MIMEText(...,'plain'))で
      // 画像を含まないため、メールを開いただけでは計測されない。実体はクリック先ページの表示。
      // メール開封を本当に測るには HTML メール化が必要(2026-08-19 時点では見送り)。
      labels: ['送信', 'サイト表示', '認証'],
      datasets: [{ label: '件数', data: [s.sent_count, s.click_count, s.auth_count],
        backgroundColor: ['#2563eb', '#b54708', '#d92d20'] }],
    },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
  });
  await renderFailures(id);
  await renderReportDetail(id);
}
// 概要の数字(報告率、防衛失敗率、配信エラーの人数)。値は数だけなので textContent で入れる。
function renderReportOverviewKpis(s) {
  const el = $('#reportOverviewKpis');
  if (!el) return;
  const item = (label, value, title) => {
    const box = document.createElement('div');
    box.className = 'report-kpi border rounded px-3 py-2';
    box.dataset.kpi = label;
    if (title) box.title = title;
    const l = document.createElement('div'); l.className = 'small text-muted'; l.textContent = label;
    const v = document.createElement('div'); v.className = 'fw-semibold'; v.textContent = value;
    box.append(l, v);
    return box;
  };
  el.replaceChildren(
    item('報告率', `${pct(s.report_rate)}（${Number(s.report_count) || 0}人）`, REPORT_RATE_TITLE),
    item('防衛失敗率', `${pct(s.failure_rate)}（${Number(s.failure_count) || 0}人）`, FAILURE_RATE_TITLE),
    item('配信エラー', `${Number(s.delivery_error_count) || 0}人`, '送信できなかった宛先と、送った後に届かないと分かった宛先の人数'),
    item('届かない宛先', `${Number(s.undeliverable_count) || 0}人`, '相手のサーバーから届かないと返された宛先の人数。率の分母（対象数）から外しています'),
  );
}
// P7: v1同等の詳細レポート(会社別/役職別/コンテンツ別/日別タイムライン)
async function renderReportDetail(campaignId) {
  const selected = Cache.reports[campaignId];
  const closed = Boolean(selected?.closed_at);
  for (const selector of ['#reportStartDate', '#reportEndDate', '#reportPeriodBtn', '#reportPeriodClearBtn']) {
    const control = $(selector);
    if (control) control.disabled = closed;
  }
  if (closed) { $('#reportStartDate').value = ''; $('#reportEndDate').value = ''; }
  const query = { action: 'detail', campaign_id: campaignId };
  const sd = $('#reportStartDate')?.value, ed = $('#reportEndDate')?.value;
  if (sd) query.start_date = sd;
  if (ed) query.end_date = ed;
  // テスト送信(is_test)の内訳を見るフィルタ。prod 以外のときだけ送る(prod は確定
  // スナップショットを使うため付けない)。all配信のテストパターン開封をコンテンツ別に見る用途。
  if (!closed && reportTestFilter && reportTestFilter !== 'prod') query.test_filter = reportTestFilter;
  let d;
  try {
    d = await api('api/report.php', { query });
  } catch (e) { toast(e.message, 'err'); return; }
  // コミット(確定)状態の反映
  reportCommitCampaignId = campaignId;
  const committed = d.is_committed === true;
  const badge = $('#reportCommitBadge');
  if (badge) badge.innerHTML = committed
    ? `<span class="badge bg-secondary"><i class="bi bi-lock-fill me-1"></i>確定済み ${d.committed_at ? esc(d.committed_at) : ''}</span>`
    : '';
  const commitBtn = $('#reportCommitBtn'), uncommitBtn = $('#reportUncommitBtn'), closeBtn = $('#reportCloseBtn');
  if (commitBtn) commitBtn.classList.toggle('d-none', closed || committed || !roleAtLeast(State.user?.role, 'operator'));
  if (uncommitBtn) uncommitBtn.classList.toggle('d-none', closed || !committed || !roleAtLeast(State.user?.role, 'tenant_admin'));
  if (closeBtn) closeBtn.classList.toggle('d-none', closed || !committed || !['done', 'cancelled'].includes(selected?.status) || State.user?.role !== 'superadmin');
  if (closed && badge) badge.innerHTML += ` <span class="badge bg-dark" title="入力本文は消去済み">クローズ済み ${esc(d.closed_at || '')}</span>`;
  // 会社別(サイト表示=click。beacon と click はほぼ同一事象のため click に統一・2026-08-24)
  $('#reportByCompany').innerHTML = (d.by_company || []).length
    ? d.by_company.map((r) => { const authTargetRate = authTargetRateOf(r.auth_count, r.count); return `<tr><td>${esc(r.company)}</td><td>${r.count}</td>
        <td class="${rateClass(r.link_rate,25,50)}">${countRate(r.link_clicked, r.link_rate)}</td>
        <td class="${rateClass(authRateOf(r.auth_count, r.link_clicked) ?? 0,5,20)}">${countRate(r.auth_count, authRateOf(r.auth_count, r.link_clicked))}</td>
        <td class="${authTargetRate === null ? '' : rateClass(authTargetRate,5,20)}">${authTargetRate === null ? '-' : pct(authTargetRate)}</td>
        <td class="${r.report_rate == null ? '' : goodRateClass(r.report_rate,5,20)}">${r.report_rate == null ? '-' : countRate(r.report_count, r.report_rate)}</td></tr>`; }).join('')
    : emptyRow(6);
  // 役職別
  $('#reportByPosition').innerHTML = (d.by_position || []).length
    ? d.by_position.map((r) => { const authTargetRate = authTargetRateOf(r.auth_count, r.count); return `<tr><td>${esc(r.position)}</td><td>${r.count}</td>
        <td class="${rateClass(r.link_rate,25,50)}">${countRate(r.link_clicked, r.link_rate)}</td>
        <td class="${rateClass(authRateOf(r.auth_count, r.link_clicked) ?? 0,5,20)}">${countRate(r.auth_count, authRateOf(r.auth_count, r.link_clicked))}</td>
        <td class="${authTargetRate === null ? '' : rateClass(authTargetRate,5,20)}">${authTargetRate === null ? '-' : pct(authTargetRate)}</td>
        <td class="${r.report_rate == null ? '' : goodRateClass(r.report_rate,5,20)}">${r.report_rate == null ? '-' : countRate(r.report_count, r.report_rate)}</td></tr>`; }).join('')
    : emptyRow(6);
  // コンテンツ別(No昇順・件名付き)
  $('#reportByContent').innerHTML = (d.by_content || []).length
    ? d.by_content.map((r) => { const authTargetRate = authTargetRateOf(r.auth_count, r.count); return `<tr><td>${esc(r.content_no)}</td>
        <td class="small" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(r.subject || '')}">${esc(r.subject || '')}</td>
        <td>${r.count}</td>
        <td class="${rateClass(r.link_rate,25,50)}">${countRate(r.link_clicked, r.link_rate)}</td>
        <td class="${rateClass(authRateOf(r.auth_count, r.link_clicked) ?? 0,5,20)}">${countRate(r.auth_count, authRateOf(r.auth_count, r.link_clicked))}</td>
        <td class="${authTargetRate === null ? '' : rateClass(authTargetRate,5,20)}">${authTargetRate === null ? '-' : pct(authTargetRate)}</td>
        <td class="${r.report_rate == null ? '' : goodRateClass(r.report_rate,5,20)}">${r.report_rate == null ? '-' : countRate(r.report_count, r.report_rate)}</td></tr>`; }).join('')
    : emptyRow(7);
  // 日別タイムライン(累積 beacon/auth)
  const tl = d.timeline || [];
  if (reportTimelineChart) reportTimelineChart.destroy();
  reportTimelineChart = new Chart($('#reportTimelineChart'), {
    type: 'line',
    data: {
      labels: tl.map((t) => t.date),
      datasets: [
        { label: '累積サイト表示', data: tl.map((t) => t.cum_beacon), borderColor: '#067647', backgroundColor: 'rgba(6,118,71,.1)', tension: .2, fill: true },
        { label: '累積認証', data: tl.map((t) => t.cum_auth), borderColor: '#d92d20', backgroundColor: 'rgba(217,45,32,.1)', tension: .2, fill: true },
      ],
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
  });
  // ビーコン(tracking_id)単位の開封明細。all配信で1人×Nコンテンツを個別に確認する。
  await renderReportBeacons(campaignId);
  // 利用者ごとの1行と行動履歴(段B1)。どちらも失敗しても他のタブは見られるよう、中で握って表に出す
  // 部署別(C3)は別の要求。部署を「/」で区切ったテナントでは1段目、2段目でまとめられる
  await Promise.all([renderReportPeople(campaignId), renderReportActions(campaignId), renderReportDepartments(campaignId)]);
}

// ビーコン別 開封明細の取得・描画(tracking_id 単位。人物単位に潰さず全パターンを出す)。
async function renderReportBeacons(campaignId) {
  const body = $('#reportBeaconsBody');
  const summaryEl = $('#reportBeaconSummary');
  if (!body) return;
  let d;
  try {
    d = await api('api/report.php', { query: { action: 'beacons', campaign_id: campaignId } });
  } catch (e) {
    body.innerHTML = `<tr><td colspan="8" class="text-muted small">明細の取得に失敗しました: ${esc(e.message)}</td></tr>`;
    return;
  }
  const mark = (on) => on ? '<span class="badge bg-danger">✓</span>' : '<span class="text-muted">–</span>';
  const beacons = d.beacons || [];
  body.innerHTML = beacons.length
    ? beacons.map((b) => `<tr>
        <td>${esc(b.name)}${b.is_test ? ' <span class="badge bg-secondary">TEST</span>' : ''}</td>
        <td>${esc(b.company)}</td>
        <td>${b.content_no === null ? '(単一)' : esc(b.content_no)}</td>
        <td class="small text-muted">${esc(b.tracking_id)}</td>
        <td>${mark(b.opened)}</td>
        <td>${mark(b.clicked)}</td>
        <td>${mark(b.authed)}</td>
        <td class="small">${b.opened_at ? esc(b.opened_at) : ''}</td>
      </tr>`).join('')
    : emptyRow(8);
  if (summaryEl && d.summary) {
    const s = d.summary;
    summaryEl.textContent = `全${s.total}件中 サイト表示${s.clicked} / 認証${s.authed}`;
  }
}
// 行動の種類の表示名。open は偽サイトの表示(ビーコン)で、メールの開封ではない
const ACTION_TYPE_LABELS = { open: 'ページの表示', click: 'リンクのクリック', auth: '認証情報の入力', report: '報告', reply: '返信' };
function verdictBadge(a) {
  const manual = a.verdict_source === 'manual' ? '（手で修正）' : '';
  const title = esc(a.verdict_reason || '');
  return a.verdict === 'scanner'
    ? `<span class="badge bg-warning text-dark" title="${title}">装置${manual}</span>`
    : `<span class="badge bg-light text-dark border" title="${title}">利用者${manual}</span>`;
}
function dateTimeCell(v) { return v ? `<span class="text-nowrap">${esc(String(v).slice(0, 16))}</span>` : '<span class="text-muted">–</span>'; }
// 利用者ごとの1行(報告、返信、初回クリック、配信エラー)。
async function renderReportPeople(campaignId) {
  const body = $('#reportPeopleBody');
  if (!body) return;
  let d;
  try {
    d = await api('api/report.php', { query: { action: 'people', campaign_id: campaignId } });
  } catch (e) {
    body.innerHTML = `<tr><td colspan="9" class="text-muted small">利用者ごとの取得に失敗しました: ${esc(e.message)}</td></tr>`;
    return;
  }
  const people = d.people || [];
  body.innerHTML = people.length ? people.map((p) => {
    const undeliverable = p.delivery_state === 'undeliverable';
    const deliveryCell = undeliverable
      ? `<span class="badge bg-danger" title="${esc(p.delivery_detail || '')}">届かない</span>`
      : p.delivery_error ? '<span class="badge bg-warning text-dark">送信エラー</span>' : '<span class="text-muted">–</span>';
    return `<tr data-tracking-id="${esc(p.tracking_id)}">
      <td>${esc(p.name || p.email)}${p.is_test ? ' <span class="badge bg-secondary">TEST</span>' : ''}<div class="small text-muted">${esc(p.email)}</div></td>
      <td>${esc(p.department)}</td>
      <td>${p.send_status === 'sent' ? '済' : esc(p.send_status)}</td>
      <td>${deliveryCell}</td>
      <td>${dateTimeCell(p.first_click_at)}</td><td>${dateTimeCell(p.auth_at)}</td>
      <td>${dateTimeCell(p.report_at)}</td><td>${dateTimeCell(p.reply_at)}</td>
      <td>${p.scanner_count ? `<span class="badge bg-warning text-dark">${Number(p.scanner_count)}</span>` : '<span class="text-muted">–</span>'}</td>
    </tr>`;
  }).join('') : emptyRow(9);
}
// 行動履歴(1行動1行)。装置の行も出し、オペレータ以上は判定を直せる。
async function renderReportActions(campaignId) {
  const body = $('#reportActionsBody');
  const summaryEl = $('#reportActionsSummary');
  if (!body) return;
  let d;
  try {
    d = await api('api/report.php', { query: { action: 'actions', campaign_id: campaignId } });
  } catch (e) {
    body.innerHTML = `<tr><td colspan="7" class="text-muted small">行動履歴の取得に失敗しました: ${esc(e.message)}</td></tr>`;
    return;
  }
  const canEdit = roleAtLeast(State.user?.role, 'operator') && !Cache.reports[campaignId]?.closed_at;
  const actions = d.actions || [];
  body.innerHTML = actions.length ? actions.map((a) => {
    const next = a.verdict === 'scanner' ? 'user' : 'scanner';
    const label = next === 'user' ? '利用者にする' : '装置にする';
    const button = canEdit && a.editable
      ? `<button class="btn btn-sm btn-outline-secondary text-nowrap" data-verdict-event="${Number(a.id)}" data-verdict-next="${next}" onclick="setEventVerdict(${Number(a.id)}, '${next}')">${label}</button>` : '';
    return `<tr data-event-id="${Number(a.id)}" data-verdict="${esc(a.verdict)}">
      <td>${dateTimeCell(a.occurred_at)}</td>
      <td>${esc(a.target_name || a.target_email || a.tracking_id)}${a.is_test ? ' <span class="badge bg-secondary">TEST</span>' : ''}</td>
      <td>${esc(ACTION_TYPE_LABELS[a.event_type] || a.event_type)}</td>
      <td>${verdictBadge(a)}</td>
      <td class="small">${esc(a.ip || '–')}</td>
      <td class="small">${esc(a.device)}</td>
      <td>${button}</td>
    </tr>`;
  }).join('') : emptyRow(7);
  if (summaryEl) {
    const c = d.counts || {};
    summaryEl.textContent = `利用者 ${Number(c.user) || 0}件 / 装置 ${Number(c.scanner) || 0}件${d.truncated ? `（新しい順に${d.limit}件まで表示）` : ''}`;
  }
}
// 行動1件の判定を直す。直した判定は集計にすぐ効くので、一覧と詳細を読み直す
async function setEventVerdict(eventId, verdict) {
  const text = verdict === 'user'
    ? 'この行動を利用者の行動に直しますか？\n集計（クリック数・防衛失敗率など）に入ります。'
    : 'この行動を装置の行動に直しますか？\n集計から外れます。';
  if (!confirm(text)) return;
  try {
    const r = await api('api/report.php', { method: 'POST', query: { action: 'set_verdict' }, body: { event_id: eventId, verdict } });
    toast(r.is_committed ? '判定を直しました（確定済みの値は変わりません。確定を解除すると反映されます）' : '判定を直しました', 'ok');
    await renderReports();
  } catch (e) { toast(e.message, 'err'); }
}
// レポート確定(コミット): 現時点の集計値を固定し、以後変更されないようにする
/** レポートを Excel (.xlsx) でダウンロードする。 */
function exportReportXlsx() {
  if (!reportSelectedId) { toast('先にキャンペーンを選択してください', 'warn'); return; }
  const tid = State.activeTenantId || State.user?.tenant_id;
  const query = new URLSearchParams({ action: 'export_xlsx', campaign_id: reportSelectedId });
  if (tid) query.set('tenant_id', tid);
  const sd = $('#reportStartDate')?.value, ed = $('#reportEndDate')?.value;
  if (sd) query.set('start_date', sd);
  if (ed) query.set('end_date', ed);
  // 認証クッキー付きで直接ダウンロードさせる(API は Content-Disposition: attachment で返す)
  window.location.href = `api/report.php?${query.toString()}`;
}

async function commitReport() {
  const id = reportCommitCampaignId;
  if (!id) return;
  if (!confirm('このレポートを確定しますか？\n確定後は集計値が固定され、以降のログ取込や対象者・ユーザの変更でも数値は変わりません。')) return;
  try {
    await api('api/report.php', { method: 'POST', query: { action: 'commit' }, body: { campaign_id: id } });
    toast('レポートを確定しました', 'ok');
    renderReportDetail(id);
  } catch (e) { toast(e.message, 'err'); }
}
// レポート確定解除: 再びリアルタイム集計に戻す(誤確定の救済, tenant_admin以上)
async function uncommitReport() {
  const id = reportCommitCampaignId;
  if (!id) return;
  if (!confirm('確定を解除しますか？\n再びリアルタイム集計に戻り、数値が現在のデータで再計算されます。')) return;
  try {
    await api('api/report.php', { method: 'POST', query: { action: 'uncommit' }, body: { campaign_id: id } });
    toast('確定を解除しました', 'ok');
    renderReportDetail(id);
  } catch (e) { toast(e.message, 'err'); }
}
async function closeReport() {
  const id = reportCommitCampaignId;
  if (!id || State.user?.role !== 'superadmin') return;
  if (!confirm('キャンペーンをクローズしますか？\n確定済み統計を保持し、保存済みの入力本文・パスワードを消去します。この操作は取り消せません。')) return;
  try {
    const res = await api('api/report.php', { method: 'POST', query: { action: 'close' }, body: { campaign_id: id } });
    toast('クローズしました。入力本文は消去されました', 'ok');
    // 訓練後のアンケートの自動配信(D6)を有効にしていた時だけ、結果を知らせる
    const f = res.survey_followup;
    if (f && f.status === 'delivered') toast(`訓練後のアンケートを${Number(f.assigned)}人に配りました（案内メール ${Number(f.mail_sent)}通）`, 'ok', 6000);
    else if (f && ['skipped', 'error'].includes(f.status)) toast(`訓練後のアンケートは配っていません: ${f.message || '内部のエラー'}`, 'err', 6000);
    await renderReports();
  } catch (e) { toast(e.message, 'err'); }
}
async function renderFailures(campaignId) {
  const tbody = $('#failuresBody');
  tbody.innerHTML = `<tr><td colspan="4" class="text-center text-muted py-3">読込中…</td></tr>`;
  try {
    const data = await api('api/followup.php', { query: { action: 'failures', campaign_id: campaignId } });
    const rows = data.failures || [];
    Cache.failuresCampaign = campaignId;
    tbody.innerHTML = rows.length ? rows.map((f) => {
      const sev = Number(f.did_auth) ? '<span class="badge bg-danger">認証入力</span>'
        : Number(f.did_click) ? '<span class="badge bg-warning text-dark">クリック</span>' : '<span class="badge bg-secondary">開封</span>';
      return `<tr><td>${esc(f.email)}</td><td>${esc(f.name)}</td><td>${esc(f.department)}</td><td>${sev}</td></tr>`;
    }).join('') : `<tr><td colspan="4" class="text-center text-muted py-3">防衛失敗者はいません</td></tr>`;
    $('#toGroupBtn').classList.toggle('d-none', !rows.length || !roleAtLeast(State.user.role, 'operator'));
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="4" class="text-center text-danger py-3">${esc(e.message)}</td></tr>`;
  }
}
// 個人別統計(全キャンペーン横断・アーカイブ済み含む)。よく開封する人ランキング。
async function renderIndividuals() {
  const tbody = $('#individualsBody');
  tbody.innerHTML = `<tr><td colspan="8" class="text-center text-muted py-3">読込中…</td></tr>`;
  try {
    const { individuals } = await api('api/report.php', { query: { action: 'individuals' } });
    tbody.innerHTML = (individuals || []).length ? individuals.map((p) => {
      const archived = p.status === 'archived';
      const statusBadge = archived ? '<span class="badge bg-secondary">退職</span>'
        : p.status === 'suspended' ? '<span class="badge bg-light text-dark">停止中</span>' : '';
      return `<tr${archived ? ' class="text-muted"' : ''}>
        <td>${esc(p.name || p.email)}${Number(p.is_test) ? ' <span class="badge bg-info">TEST</span>' : ''}</td>
        <td>${esc(p.company)}</td>
        <td>${p.position_category ? esc(p.position_category) : ''}</td>
        <td>${p.campaigns}</td>
        <td class="${rateClass(p.click_rate,25,50)}">${pct(p.click_rate)}</td>
        <td class="${rateClass(p.auth_rate,5,20)}">${pct(p.auth_rate)}</td>
        <td>${statusBadge}</td>
      </tr>`;
    }).join('') : `<tr><td colspan="7" class="text-center text-muted py-3">対象データがありません</td></tr>`;
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-3">${esc(e.message)}</td></tr>`;
  }
}
function toggleIndividuals() {
  const card = $('#individualsCard');
  const show = card.classList.contains('d-none');
  card.classList.toggle('d-none', !show);
  if (show) renderIndividuals();
}
async function ingestLogs() {
  const btn = $('#ingestBtn');
  btn.disabled = true;
  try {
    const r = await api('api/report.php', { method: 'POST', query: { action: 'ingest' } });
    const total = Object.values(r.ingested || {}).reduce((a, b) => a + Number(b), 0);
    toast(`ログ取込完了（${total} 件）`, 'ok');
    renderReports();
  } catch (e) { toast(e.message, 'err'); }
  finally { btn.disabled = false; }
}
async function openToGroupModal() {
  const campaignId = Cache.failuresCampaign;
  if (!campaignId) return;
  const { groups } = await api('api/groups.php', { query: { action: 'list' } });
  const body = `<p class="small text-muted">この失敗者を既存グループに追加し、後続の debrief / eラーニング配信の対象にできます。</p>
    <div class="mb-2"><label class="form-label">追加先グループ</label>
      <select class="form-select" id="toGroupSelect">${(groups || []).map((g) => `<option value="${g.id}">${esc(g.name)}</option>`).join('')}</select></div>`;
  showModal('失敗者をグループ化', body, async () => {
    const groupId = Number($('#toGroupSelect').value);
    if (!groupId) throw new Error('グループを選択してください');
    const r = await api('api/followup.php', { method: 'POST', query: { action: 'to_group' }, body: { campaign_id: campaignId, group_id: groupId } });
    toast(`${r.added} 名を追加しました`, 'ok');
  });
}

/* ========== 対象者 ========== */
// 日時(YYYY-MM-DD HH:MM:SS)を日付だけにする。空なら空文字。
function dateOnly(v) { return v ? String(v).slice(0, 10) : ''; }

async function renderTargets() {
  // トグル ON のときだけ削除済み(アーカイブ)も取得する。
  const showArchived = $('#showArchivedTargets')?.checked === true;
  const query = { action: 'list' };
  if (showArchived) query.include_archived = '1';
  // 検索はメール、氏名、従業員番号、メモの部分一致(サーバーで絞る)
  const q = ($('#targetSearch')?.value || '').trim();
  if (q) query.q = q;
  const { targets } = await api('api/targets.php', { query });
  cacheRows('targets', targets);
  // 組織管理者以上には、受講者のマイページの状態と招待の操作を出す
  const canInvite = roleAtLeast(State.user.role, 'tenant_admin');
  const myPage = {};
  if (canInvite) {
    try {
      const { learners } = await api('api/learners.php', { query: { action: 'status' } });
      (learners || []).forEach((l) => { myPage[l.target_id] = l; });
    } catch (e) { toast(e.message, 'err'); }
  }
  if ($('#targetsSelectAll')) $('#targetsSelectAll').checked = false;
  // #列は表示上の通し番号(古い順に1,2,3…)。他の一覧と統一。削除しても詰まる。
  // list は tenant_no 順(=作成順)で返るため、その並びのまま連番を振る。
  $('#targetsBody').innerHTML = targets.length ? targets.map((t, i) => {
    const archived = t.status === 'archived';
    const canEdit = roleAtLeast(State.user.role, 'operator');
    // 削除済みは編集/削除ではなく「復活」だけを出す(履歴は保持したまま在籍に戻す)。
    const actions = !canEdit ? ''
      : archived
        ? `<button class="btn btn-sm btn-outline-success" onclick="restoreTarget(${t.id})" title="削除を取り消して在籍に戻す"><i class="bi bi-arrow-counterclockwise"></i></button>`
        : `<button class="btn btn-sm btn-outline-secondary" onclick="editTarget(${t.id})"><i class="bi bi-pencil"></i></button>
           <button class="btn btn-sm btn-outline-danger" onclick="deleteTarget(${t.id})"><i class="bi bi-trash"></i></button>`;
    const selectCell = canInvite
      ? `<td>${archived ? '' : `<input class="form-check-input tgt-select" type="checkbox" value="${t.id}" aria-label="${esc(t.email)} を選ぶ">`}</td>` : '';
    const myPageCell = canInvite ? `<td class="small">${myPageStatus(myPage[t.id], t, archived)}</td>` : '';
    return `
    <tr${archived ? ' class="text-muted table-light"' : ''}>
      ${selectCell}
      <td>${i + 1}</td>
      <td class="text-nowrap">${esc(t.employee_no || '')}</td>
      <td>${esc(t.email)}${Number(t.is_test) ? ' <span class="badge bg-info">TEST</span>' : ''}${archived ? ' <span class="badge bg-secondary">削除済</span>' : ''}${undeliverableBadge(t)}${t.memo ? `<div class="small text-muted target-memo" title="${esc(t.memo)}"><i class="bi bi-sticky me-1" aria-hidden="true"></i>${esc(t.memo)}</div>` : ''}</td>
      <td>${esc(t.name)}</td>
      <td>${esc(t.company)}</td><td>${esc(t.department)}</td><td>${esc(t.title)}</td>
      <td>${t.position_category ? `<span class="badge bg-light text-dark">${esc(t.position_category)}</span>` : ''}</td>
      <td class="text-nowrap small">${esc(dateOnly(t.created_at))}</td>
      <td class="text-nowrap small">${t.archived_at ? esc(dateOnly(t.archived_at)) : ''}</td>
      ${myPageCell}
      <td class="text-nowrap">${actions}</td>
    </tr>`;
  }).join('') : (q ? eduNoteRow(canInvite ? 13 : 11, '条件に合う対象者がいません') : emptyRow(canInvite ? 13 : 11));
}
// 訓練メールが届かなかった記録(段B1)。続けて届かない宛先は警告の色にする。対象者は自動では消さない
function undeliverableBadge(t) {
  const n = Number(t.undeliverable_count) || 0;
  if (!n) return '';
  const last = t.last_undeliverable_at ? `（最後: ${String(t.last_undeliverable_at).slice(0, 10)}）` : '';
  return t.delivery_warning
    ? ` <span class="badge bg-warning text-dark" data-undeliverable="warn" title="訓練メールが続けて届いていません${esc(last)}。アドレスの誤りや退職を確かめてください（自動では消しません）">続けて届かない ${n}回</span>`
    : ` <span class="badge bg-light text-dark border" data-undeliverable="1" title="訓練メールが届かなかった回数${esc(last)}">届かない ${n}回</span>`;
}
// 受講者のマイページの状態(対象者の一覧の列)と、1件の招待・アカウントの削除のボタン
function myPageStatus(l, t, archived) {
  const invite = archived ? '' : `<button class="btn btn-sm btn-outline-primary ms-1" onclick="inviteMyPage([${t.id}])" title="マイページの招待を送る" aria-label="${esc(t.email)} にマイページの招待を送る"><i class="bi bi-envelope"></i></button>`;
  if (!l) return `<span class="text-muted">未招待</span>${invite}`;
  if (l.account === 'admin') return '<span class="badge bg-light text-dark border" title="管理画面と同じパスワードでマイページに入れます">管理画面のアカウント</span>';
  const remove = `<button class="btn btn-sm btn-outline-danger ms-1" onclick="deleteMyPageAccount(${t.id})" title="マイページのアカウントを削除" aria-label="${esc(t.email)} のマイページのアカウントを削除"><i class="bi bi-person-x"></i></button>`;
  let label;
  if (l.status !== 'active') label = '<span class="badge bg-secondary">停止</span>';
  else if (l.password_pending) label = `<span class="badge bg-warning text-dark">パスワード未設定</span>${l.link_expires_at ? `<div class="text-muted">リンクの期限 ${esc(String(l.link_expires_at).slice(0, 16))}</div>` : ''}`;
  else label = `<span class="badge bg-success">利用可</span>${l.last_login_at ? `<div class="text-muted">最終ログイン ${esc(dateOnly(l.last_login_at))}</div>` : ''}`;
  return `${label}${invite}${remove}`;
}
async function inviteMyPage(ids) {
  if (!ids.length) return toast('招待を送る対象者をチェックしてください', 'err');
  if (!confirm(`${ids.length}名に、受講者のマイページの招待メール（パスワード設定のリンク）を送りますか？\n既にアカウントがある人には、パスワード再設定のメールを送ります。`)) return;
  try {
    const r = await api('api/learners.php', { method: 'POST', query: { action: 'invite' }, body: { target_ids: ids } });
    const parts = [`送信 ${r.sent}件`];
    if (r.admin_account) parts.push(`管理画面のアカウントで入れる人 ${r.admin_account}件`);
    if (r.errors) parts.push(`送れなかった ${r.errors}件`);
    toast(parts.join('・'), r.errors ? 'warn' : 'ok', 8000);
    (r.results || []).filter((x) => x.result === 'error').slice(0, 3).forEach((x) => toast(`${x.email}: ${x.message}`, 'err', 10000));
    renderTargets();
  } catch (e) { toast(e.message, 'err'); }
}
async function deleteMyPageAccount(targetId) {
  if (!confirm('この対象者の受講者のマイページのアカウントを削除しますか？\n成績は消えません。もう一度招待すれば使えるようになります。')) return;
  try {
    await api('api/learners.php', { method: 'POST', query: { action: 'delete' }, body: { target_id: targetId } });
    toast('マイページのアカウントを削除しました', 'ok'); renderTargets();
  } catch (e) { toast(e.message, 'err'); }
}
// 削除済み対象者を在籍に戻す。訓練履歴はもともと消えていないのでそのまま復活する。
async function restoreTarget(id) {
  if (!confirm('この対象者を在籍に戻しますか？')) return;
  try {
    const r = await api('api/targets.php', { method: 'POST', query: { action: 'restore' }, body: { id } });
    toast('在籍に戻しました', 'ok'); renderTargets();
    if (r.limit_warning) toast(r.limit_warning, 'warn', 8000);
  } catch (e) { toast(e.message, 'err'); }
}
function targetForm(t = {}) {
  return `<form id="targetForm">
    <div class="mb-2"><label class="form-label">メール</label><input class="form-control" name="email" type="email" value="${esc(t.email)}" required></div>
    <div class="row g-2">
      <div class="col-md-8 mb-2"><label class="form-label">氏名</label><input class="form-control" name="name" value="${esc(t.name)}"></div>
      <div class="col-md-4 mb-2"><label class="form-label" for="targetEmployeeNo">従業員番号</label><input class="form-control" name="employee_no" id="targetEmployeeNo" maxlength="64" value="${esc(t.employee_no || '')}" aria-describedby="targetEmployeeNoHelp"><div class="form-text" id="targetEmployeeNoHelp">組織の中で重ならない番号。空でもかまいません。</div></div>
    </div>
    <div class="row g-2">
      <div class="col-md-4 mb-2"><label class="form-label">会社</label><input class="form-control" name="company" value="${esc(t.company)}"></div>
      <div class="col-md-4 mb-2"><label class="form-label">部署</label><input class="form-control" name="department" value="${esc(t.department)}"></div>
      <div class="col-md-4 mb-2"><label class="form-label">役職</label><input class="form-control" name="title" value="${esc(t.title)}"></div>
    </div>
    <div class="mb-2"><label class="form-label">役職カテゴリ</label>
      <select class="form-select" name="position_category">
        <option value="">—</option>
        ${POSITION_CATEGORIES.map((c)=>`<option value="${c}"${t.position_category===c?' selected':''}>${c}</option>`).join('')}
      </select></div>
    <div class="form-check mb-2">
      <input class="form-check-input" type="checkbox" name="is_test" id="cbTargetTest"${Number(t.is_test) === 1 ? ' checked' : ''}>
      <label class="form-check-label" for="cbTargetTest">テストユーザ（レポート集計から除外）</label>
      <div class="form-text">検証用の宛先。訓練配信には使えますが、開封率などの集計には数えません。</div>
    </div>
    <div class="mb-2"><label class="form-label" for="targetMemo">メモ</label><input class="form-control" name="memo" id="targetMemo" maxlength="1000" value="${esc(t.memo || '')}" aria-describedby="targetMemoHelp"><div class="form-text" id="targetMemoHelp">担当者向けのメモ（1行、1000文字まで）。受講者には見えません。</div></div>
    </form>`;
}
function collectTarget() {
  const f = $('#targetForm');
  return { email: f.email.value.trim(), name: f.name.value.trim(), company: f.company.value.trim(),
    department: f.department.value.trim(), title: f.title.value.trim(),
    position_category: f.position_category.value, is_test: f.is_test.checked,
    employee_no: f.employee_no.value.trim(), memo: f.memo.value.trim() };
}
function newTarget() {
  showModal('新規対象者', targetForm(), async () => {
    const r = await api('api/targets.php', { method: 'POST', query: { action: 'create' }, body: collectTarget() });
    toast('追加しました', 'ok'); renderTargets();
    if (r.limit_warning) toast(r.limit_warning, 'warn', 8000);
  });
}
function editTarget(id) {
  const t = Cache.targets[id];
  if (!t) return;
  showModal('対象者編集', targetForm(t), async () => {
    await api('api/targets.php', { method: 'POST', query: { action: 'update' }, body: { id: t.id, ...collectTarget() } });
    toast('更新しました', 'ok'); renderTargets();
  });
}
async function deleteTarget(id) {
  if (!confirm('この対象者を削除しますか？')) return;
  try {
    const r = await api('api/targets.php', { method: 'POST', query: { action: 'delete' }, body: { id } });
    // 履歴のない対象者は本当に消え、履歴のある対象者は統計に残すためアーカイブになる
    toast(r.deleted ? '削除しました' : '削除しました（訓練の履歴があるため、統計用にアーカイブとして残します）', 'ok');
    renderTargets();
  } catch (e) { toast(e.message, 'err'); }
}
function importCsv() {
  const body = `<p class="small text-muted">1行目にヘッダ（メールアドレス/氏名/会社名/部署/役職/役職カテゴリ/従業員番号/メモ または email/name/company/department/title/position_category/employee_no/memo）。メール列は必須。役職カテゴリは「役員/管理職/一般従業員」のみ有効（旧称「社員」は「一般従業員」として取り込みます）。列が無い場合は役職名から役職マスタを引いて自動補完します。</p>
    <p class="small text-muted">既存の対象者との照合: 従業員番号が入っている行は<strong>従業員番号で先に</strong>探し、見つかった人のメールアドレスを CSV の値に変えます。番号で見つからなければメールアドレスで探します。従業員番号とメモが空の行は、今の値を残します。</p>
    <textarea class="form-control" id="csvText" rows="8" placeholder="メールアドレス,氏名,部署&#10;taro@example.com,山田太郎,営業部"></textarea>`;
  showModal('CSV 取込', body, async () => {
    const csv = $('#csvText').value.trim();
    if (!csv) throw new Error('CSV を入力してください');
    const r = await api('api/targets.php', { method: 'POST', query: { action: 'import_csv' }, body: { csv } });
    toast(`取込 ${r.imported} / 更新 ${r.updated}${r.email_changed ? `（うちメールアドレスの変更 ${r.email_changed}）` : ''} / スキップ ${r.skipped}`, r.skipped ? 'warn' : 'ok'); renderTargets();
    (r.errors || []).slice(0, 3).forEach((x) => toast(`${x.line}行目: ${x.reason}`, 'err', 10000));
    if (r.limit_warning) toast(r.limit_warning, 'warn', 8000);
  });
}
// 現対象者を CSV でダウンロード。CSV(非JSON)なので api() は使わず直接 fetch → blob 保存。
// 既定は一覧と同じくアーカイブ(退職者)を除外。includeArchived=true で削除日付きの全件を出す。
async function exportTargetsCsv(includeArchived = false) {
  const btn = $('#exportCsvBtn');
  btn.disabled = true;
  try {
    const qs = new URLSearchParams({ action: 'export_csv' });
    if (includeArchived) qs.set('include_archived', '1');
    // superadmin がテナント切替中なら tenant_id を付与(api() と同じ挙動)
    if (State.user && State.user.role === 'superadmin' && State.activeTenantId) qs.set('tenant_id', State.activeTenantId);
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), 30000);
    let res;
    try {
      res = await fetch(`api/targets.php?${qs}`, { credentials: 'same-origin', signal: ctrl.signal });
    } finally { clearTimeout(timer); }
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `targets_${includeArchived ? 'all_' : ''}${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
    toast(includeArchived ? 'CSV を出力しました（削除済みを含む）' : 'CSV を出力しました', 'ok');
  } catch (e) { toast(`出力に失敗しました（${e.message}）`, 'err'); }
  finally { btn.disabled = false; }
}

/* ========== グループ ========== */
async function renderGroups() {
  const { groups } = await api('api/groups.php', { query: { action: 'list' } });
  cacheRows('groups', groups);
  // #列は表示上の通し番号(古い順に1,2,3…)。削除しても詰まる。
  const groupsAsc = groups.slice().sort((a, b) => a.id - b.id);
  $('#groupsBody').innerHTML = groupsAsc.length ? groupsAsc.map((g, i) => {
    const n = Number(g.target_count || 0);
    const memberCell = n === 0
      ? '<span class="badge bg-warning text-dark">空（0名）</span>'
      : `<span class="badge bg-light text-dark">${n} 名</span>`;
    return `
    <tr><td>${i + 1}</td><td>${esc(g.name)}</td><td>${g.kind === 'department' ? '部署' : 'カスタム'}</td>
      <td>${memberCell}</td>
      <td class="text-nowrap">
        <button class="btn btn-sm btn-outline-primary" onclick="manageGroupMembers(${g.id})" title="メンバー管理（対象者の追加・削除）"><i class="bi bi-people"></i> メンバー</button>
        ${roleAtLeast(State.user.role,'operator')?`
        <button class="btn btn-sm btn-outline-secondary" onclick="editGroup(${g.id})" title="グループを編集" aria-label="グループを編集"><i class="bi bi-pencil" aria-hidden="true"></i></button>
        <button class="btn btn-sm btn-outline-danger" onclick="deleteGroup(${g.id})" title="グループを削除" aria-label="グループを削除"><i class="bi bi-trash" aria-hidden="true"></i></button>`:''}
      </td></tr>`;
  }).join('') : emptyRow(5);
}
function groupForm(g = {}) {
  return `<form id="groupForm">
    <div class="mb-2"><label class="form-label">名称</label><input class="form-control" name="name" value="${esc(g.name)}" required></div>
    <div class="mb-2"><label class="form-label">種別</label>
      <select class="form-select" name="kind"><option value="custom"${g.kind==='custom'?' selected':''}>カスタム</option><option value="department"${g.kind==='department'?' selected':''}>部署</option><option value="all"${g.kind==='all'?' selected':''}>全職員（自動）</option></select></div>
  </form>`;
}
function newGroup() {
  showModal('新規グループ', groupForm(), async () => {
    const f = $('#groupForm');
    await api('api/groups.php', { method: 'POST', query: { action: 'create' }, body: { name: f.name.value.trim(), kind: f.kind.value } });
    toast('作成しました', 'ok'); renderGroups();
  });
}
function editGroup(id) {
  const g = Cache.groups[id];
  if (!g) return;
  showModal('グループ編集', groupForm(g), async () => {
    const f = $('#groupForm');
    await api('api/groups.php', { method: 'POST', query: { action: 'update' }, body: { id: g.id, name: f.name.value.trim(), kind: f.kind.value } });
    toast('更新しました', 'ok'); renderGroups();
  });
}
async function deleteGroup(id) {
  if (!confirm('このグループを削除しますか？')) return;
  try { await api('api/groups.php', { method: 'POST', query: { action: 'delete' }, body: { id } });
    toast('削除しました', 'ok'); renderGroups(); } catch (e) { toast(e.message, 'err'); }
}
// グループのメンバー管理: 現メンバーと全対象者を一覧し、チェックで追加/削除する。
async function manageGroupMembers(groupId) {
  const g = Cache.groups[groupId];
  let members, targets;
  try {
    [members, targets] = await Promise.all([
      api('api/groups.php', { query: { action: 'members', group_id: groupId } }),
      api('api/targets.php', { query: { action: 'list' } }),
    ]);
  } catch (e) { toast(e.message, 'err'); return; }
  const memberIds = new Set((members.members || []).map((m) => m.id));
  const allTargets = targets.targets || [];
  if (!allTargets.length) {
    showInfoModal('メンバー管理', '<p class="text-muted">対象者が1人も登録されていません。先に「対象者」画面で登録してください。</p>');
    return;
  }
  // チェックボックス一覧(現メンバーは初期チェック済み)。
  const rows = allTargets.map((t) => `
    <div class="form-check">
      <input class="form-check-input gm-chk" type="checkbox" value="${t.id}" id="gm-${t.id}" ${memberIds.has(t.id) ? 'checked' : ''}>
      <label class="form-check-label" for="gm-${t.id}">${esc(t.email)}${t.name ? '（' + esc(t.name) + '）' : ''}${t.department ? ' <span class="text-muted small">' + esc(t.department) + '</span>' : ''}</label>
    </div>`).join('');
  const body = `
    <p class="small text-muted mb-2">グループ「${esc(g ? g.name : '')}」のメンバーを選びます。チェックした対象者がメンバーになります（現メンバーは初期選択済み）。</p>
    <div class="mb-2"><button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('.gm-chk').forEach(c=>c.checked=true)">全選択</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('.gm-chk').forEach(c=>c.checked=false)">全解除</button></div>
    <div style="max-height:340px;overflow-y:auto;border:1px solid var(--tet-border);border-radius:var(--tet-radius-sm);padding:8px">${rows}</div>`;
  showModal(`メンバー管理: ${g ? g.name : ''}`, body, async () => {
    const checked = new Set(Array.from(document.querySelectorAll('.gm-chk')).filter((c) => c.checked).map((c) => Number(c.value)));
    // 追加 = チェックあり かつ 元メンバーでない / 削除 = チェックなし かつ 元メンバー
    const toAdd = [...checked].filter((id) => !memberIds.has(id));
    const toRemove = [...memberIds].filter((id) => !checked.has(id));
    if (toAdd.length) await api('api/groups.php', { method: 'POST', query: { action: 'add_targets' }, body: { group_id: groupId, target_ids: toAdd } });
    if (toRemove.length) await api('api/groups.php', { method: 'POST', query: { action: 'remove_targets' }, body: { group_id: groupId, target_ids: toRemove } });
    toast(`メンバーを更新しました（追加${toAdd.length}・削除${toRemove.length}）`, 'ok');
    renderGroups();
  });
}

/* ========== テンプレート ========== */
// 件名・本文は「シナリオ」タブに統合(連動・連番共通)。偽ログイン/ネタバラシ/eラーニングは種別ごと。
// タブの表示区分。'scenario' は「件名＋本文」を1タブに統合するための表示専用の擬似種別で、
// DB 上の kind ではない(実体は subject と body の2レコード)。
const TPL_KINDS = [['scenario','件名＋本文'],['phish_login','偽ログイン'],['debrief','ネタバラシ'],['elearning','eラーニング']];
// テンプレート登録フォームの種別。バックエンドの TEMPLATE_KINDS と一致させること。
// (2026-08-04) ここに TPL_KINDS を流用していたため 'scenario' が送られ「kind が不正です」で保存できなかった。
const TPL_FORM_KINDS = [['subject','件名'],['body','本文'],['phish_login','偽ログイン'],['debrief','ネタバラシ'],['elearning','eラーニング']];
const KIND_LABELS = { subject:'件名', body:'本文', phish_login:'偽ログイン', debrief:'ネタバラシ', elearning:'eラーニング' };
const AUTH_FLAG_NAME = { '0':'通常（汎用）', '1':'Box', '2':'Microsoft365', '3':'Digital Arts', '4':'Microsoft 365（メールのみ）' };
let tplKindFilter = 'scenario';
let tplTemplates = [];
let tplTemplatesTenantId = null;
let tplTemplatesRequest = 0;
function templatesMatchingSearch(templates, query) {
  const needle = String(query || '').trim().toLowerCase();
  if (!needle) return templates;
  const matchesName = (template) => String(template.name || '').toLowerCase().includes(needle);
  const matchingScenarioKeys = new Set(templates
    .filter((template) => ['subject', 'body'].includes(template.kind) && template.scenario_key && matchesName(template))
    .map((template) => template.scenario_key));
  return templates.filter((template) => matchesName(template) ||
    (['subject', 'body'].includes(template.kind) && matchingScenarioKeys.has(template.scenario_key)));
}
async function renderTemplates() {
  const tenantId = State.activeTenantId;
  const requestId = ++tplTemplatesRequest;
  tplTemplatesTenantId = null;
  $('#templatesBody').innerHTML = '<tr><td colspan="5" class="text-muted">読み込み中...</td></tr>';
  const { templates } = await api('api/templates.php', { query: { action: 'list' } });
  if (State.view !== 'templates' || State.activeTenantId !== tenantId || requestId !== tplTemplatesRequest) return;
  tplTemplates = templates || [];
  tplTemplatesTenantId = tenantId;
  renderTemplateRows();
}
function renderTemplateRows() {
  $('#tplKindTabs').innerHTML = TPL_KINDS.map(([k, l]) =>
    `<li class="nav-item"><a class="nav-link${k===tplKindFilter?' active':''}" href="#" onclick="setTplKind('${k}');return false">${l}</a></li>`).join('');
  if (tplTemplatesTenantId !== State.activeTenantId) return;
  const all = templatesMatchingSearch(tplTemplates, $('#tplSearch').value);
  if (tplKindFilter === 'scenario') return renderTemplatesScenario(all);
  if (tplKindFilter === 'phish_login') return renderTemplatesPhish(all);
  return renderTemplatesSimple(all, tplKindFilter);
}
// 件名+本文をシナリオ連番で統合表示。行クリックで件名・本文を1画面プレビュー/編集。
function renderTemplatesScenario(all) {
  const subjects = all.filter((t) => t.kind === 'subject');
  const bodies = all.filter((t) => t.kind === 'body');
  // scenario_key を持つペアを集約。
  const keys = [...new Set(all.filter((t) => t.scenario_key && (t.kind === 'subject' || t.kind === 'body')).map((t) => t.scenario_key))];
  const pairedIds = new Set();
  let rowNumber = 0;
  const scenRows = keys.map((key) => {
    const s = subjects.find((t) => t.scenario_key === key);
    const b = bodies.find((t) => t.scenario_key === key);
    if (!s || !b) return '';
    pairedIds.add(s.id);
    pairedIds.add(b.id);
    return `<tr style="cursor:pointer" data-scenario-key="${esc(key)}" onclick="scenarioViewer(this.dataset.scenarioKey)">
      <td>${++rowNumber}</td><td>${esc(s.name)}${tplDescriptionHtml(b.description || s.description)}</td><td class="text-muted small">${esc(b.name)}</td>
      <td>${Number(s.is_preset) ? '<span class="badge bg-secondary">共有</span>' : ''}</td>
      <td class="text-end"><i class="bi bi-chevron-right"></i></td></tr>`;
  }).join('');
  // ペアが欠けた場合も、元の件名・本文を一覧から失わない。
  const orphans = all.filter((t) => (t.kind === 'subject' || t.kind === 'body') && !pairedIds.has(t.id));
  const orphanRows = orphans.map((t) => `<tr style="cursor:pointer" onclick="tplViewer(${t.id})">
    <td>${++rowNumber}</td><td>${esc(t.name)}${tplDescriptionHtml(t.description)}</td><td class="text-muted small">${KIND_LABELS[t.kind]}（単独）</td>
    <td>${Number(t.is_preset) ? '<span class="badge bg-secondary">共有</span>' : ''}</td>
    <td class="text-end"><i class="bi bi-chevron-right"></i></td></tr>`).join('');
  $('#templatesHead').innerHTML = '<tr><th>連番</th><th>件名</th><th>本文</th><th></th><th></th></tr>';
  $('#templatesBody').innerHTML = (scenRows + orphanRows) || emptyRow(5);
}
// 一覧の名称の下に出す概要(元の事例、手口、見分けるポイント)。長い概要は1行で切り、全文は title で見せる。
function tplDescriptionHtml(description) {
  const text = String(description || '').trim();
  if (!text) return '';
  return `<div class="small text-muted text-truncate tpl-description" style="max-width:32rem" title="${esc(text)}">${esc(text.replace(/\s+/g, ' '))}</div>`;
}
// 偽ログイン: 種別(auth_flag)ごとにグループ化し、種別内の通番を振る。
function renderTemplatesPhish(all) {
  const phish = all.filter((t) => t.kind === 'phish_login');
  const counters = {};
  const rows = phish.map((t) => {
    const flag = String(t.auth_flag ?? 0);
    counters[flag] = (counters[flag] || 0) + 1;
    return `<tr style="cursor:pointer" onclick="tplViewer(${t.id})">
      <td>${esc(AUTH_FLAG_NAME[flag] || flag)} #${counters[flag]}</td>
      <td>${esc(t.name)}${tplDescriptionHtml(t.description)}</td><td>${esc(t.format)}</td>
      <td>${Number(t.is_preset) ? '<span class="badge bg-secondary">共有</span>' : ''}</td>
      <td class="text-end"><i class="bi bi-chevron-right"></i></td></tr>`;
  }).join('');
  $('#templatesHead').innerHTML = '<tr><th>種別・通番</th><th>名称</th><th>形式</th><th></th><th></th></tr>';
  $('#templatesBody').innerHTML = rows || emptyRow(5);
}
// ネタバラシ/eラーニング等: 一覧+ビューア。
function renderTemplatesSimple(all, kind) {
  const rows = all.filter((t) => t.kind === kind).map((t, i) => `<tr style="cursor:pointer" onclick="tplViewer(${t.id})">
    <td>${i + 1}</td><td>${esc(t.name)}${tplDescriptionHtml(t.description)}</td><td>${esc(t.format)}</td>
    <td>${Number(t.is_preset) ? '<span class="badge bg-secondary">共有</span>' : ''}</td>
    <td class="text-end"><i class="bi bi-chevron-right"></i></td></tr>`).join('');
  $('#templatesHead').innerHTML = '<tr><th>連番</th><th>名称</th><th>形式</th><th></th><th></th></tr>';
  $('#templatesBody').innerHTML = rows || emptyRow(5);
}
function labelKind(k) { return KIND_LABELS[k] || k; }
function setTplKind(k) { tplKindFilter = k; renderTemplateRows(); }
// 本文の差し込みプレースホルダ定義(send_email.py の replace_placeholders と対応)。
const TPL_PLACEHOLDERS = [
  { ph: '#$1$#', label: 'リンク/URL', sample: 'https://example.com/link-XXXX.html' },
  { ph: '#$2$#', label: '姓', sample: '山田' },
  { ph: '#$3$#', label: '差込3', sample: '（差込3）' },
  { ph: '#$4$#', label: '差込4', sample: '（差込4）' },
  { ph: '#$6$#', label: 'メールアドレス', sample: 'taro@example.com' },
];
function templateForm(t = {}) {
  const phButtons = TPL_PLACEHOLDERS.map((p) =>
    `<button type="button" class="btn btn-sm btn-outline-secondary me-1 mb-1 tpl-ph-btn" data-ph="${esc(p.ph)}">${esc(p.label)} <code>${esc(p.ph)}</code></button>`).join('');
  return `<form id="tplForm">
    <div class="mb-2"><label class="form-label">名称</label><input class="form-control" name="name" value="${esc(t.name)}" required></div>
    <div class="row g-2">
      <div class="col-md-6 mb-2"><label class="form-label">種別</label>
        <select class="form-select" name="kind">${TPL_FORM_KINDS.map(([k,l])=>`<option value="${k}"${(t.kind||(tplKindFilter==='scenario'?'body':tplKindFilter))===k?' selected':''}>${l}</option>`).join('')}</select></div>
      <div class="col-md-6 mb-2"><label class="form-label">形式</label>
        <select class="form-select" name="format"><option value="html"${t.format==='html'?' selected':''}>HTML</option><option value="text"${t.format==='text'?' selected':''}>テキスト</option></select></div>
    </div>
    <div class="mb-2" id="tplAuthField"><label class="form-label" for="tplAuthFlag">認証の種別（偽ログインのみ）</label>
      <select class="form-select" name="auth_flag" id="tplAuthFlag">${Object.entries(AUTH_FLAG_NAME).map(([k, l]) => `<option value="${k}"${String(t.auth_flag ?? 0) === k ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select></div>
    <div class="mb-1"><label class="form-label mb-1">差し込み支援（カーソル位置に挿入）</label><div>${phButtons}</div></div>
    <div class="mb-2"><label class="form-label">内容</label><textarea class="form-control" name="content" id="tplContent" rows="8" required>${esc(t.content)}</textarea></div>
    ${tplDescriptionField('tplDescription', t.description)}
    <div class="mb-2">
      <button type="button" class="btn btn-sm btn-outline-info" id="tplPreviewBtn"><i class="bi bi-eye"></i> プレビュー（差込をサンプル値で表示）</button>
    </div>
    <div id="tplPreviewArea" class="d-none"></div>
  </form>`;
}
// テンプレートフォームの差込ボタン/プレビューを配線(showModal 後に呼ぶ)。
function bindTemplateForm() {
  const ta = document.getElementById('tplContent');
  // (2026-08-05) 件名テンプレートは「名称＝そのまま件名」なので、名称を打つと内容欄へ複写する。
  // 内容を直接編集した後は追随しない(手で書いたものを名称の変更で消さないため)。
  // 種別が「件名」以外のときは何もしない(本文などは名称と中身が別物のため)。
  const nameInput = document.querySelector('#tplForm [name=name]');
  const kindSel = document.querySelector('#tplForm [name=kind]');
  const authField = document.getElementById('tplAuthField');
  if (kindSel && authField) {
    const syncAuthField = () => { authField.hidden = kindSel.value !== 'phish_login'; };
    kindSel.addEventListener('change', syncAuthField);
    syncAuthField();
  }
  if (nameInput && kindSel && ta) {
    // 空、または直前に名称から複写した値のままなら「未編集」とみなす。
    let mirrored = ta.value === '' ? '' : null;
    ta.addEventListener('input', () => { mirrored = null; });   // 手編集された時点で同期を止める
    const syncNameToContent = () => {
      if (kindSel.value !== 'subject') return;
      if (mirrored === null && ta.value !== '') return;
      ta.value = nameInput.value;
      mirrored = ta.value;
    };
    nameInput.addEventListener('input', syncNameToContent);
    kindSel.addEventListener('change', syncNameToContent);
  }
  document.querySelectorAll('.tpl-ph-btn').forEach((btn) => btn.addEventListener('click', () => {
    const ph = btn.dataset.ph;
    const s = ta.selectionStart ?? ta.value.length, e = ta.selectionEnd ?? ta.value.length;
    ta.value = ta.value.slice(0, s) + ph + ta.value.slice(e);
    ta.focus(); ta.selectionStart = ta.selectionEnd = s + ph.length;
  }));
  const pvBtn = document.getElementById('tplPreviewBtn');
  if (pvBtn) pvBtn.addEventListener('click', () => {
    const area = document.getElementById('tplPreviewArea');
    if (!area.classList.contains('d-none')) { area.classList.add('d-none'); area.innerHTML = ''; return; }
    let content = ta.value;
    for (const p of TPL_PLACEHOLDERS) content = content.split(p.ph).join(p.sample);
    const format = document.querySelector('#tplForm [name=format]').value;
    if (format === 'html') {
      area.innerHTML = `<div class="small text-muted mb-1">差込をサンプル値で置換した表示（スクリプトは無効化）。</div>
        <iframe sandbox srcdoc="${esc(content)}" style="width:100%;height:40vh;border:1px solid var(--tet-border);border-radius:var(--tet-radius-sm);background:#fff"></iframe>`;
    } else {
      area.innerHTML = `<div class="small text-muted mb-1">差込をサンプル値で置換した表示。</div>
        <pre class="border rounded p-2 bg-light" style="max-height:40vh;overflow:auto;white-space:pre-wrap">${esc(content)}</pre>`;
    }
    area.classList.remove('d-none');
  });
}
// 件名と本文は 1 つの訓練シナリオを構成する対なので、1 画面でまとめて登録する。
// scenario_key はサーバ側で自動採番されるため利用者には見せない。
function scenarioForm() {
  const phButtons = TPL_PLACEHOLDERS.map((p) =>
    `<button type="button" class="btn btn-sm btn-outline-secondary me-1 mb-1 tpl-ph-btn" data-ph="${esc(p.ph)}">${esc(p.label)} <code>${esc(p.ph)}</code></button>`).join('');
  return `<form id="scenForm">
    <div class="mb-2"><label class="form-label">シナリオ名</label>
      <input class="form-control" name="name" placeholder="例: 人事部からのマイナンバー確認" required>
      <div class="form-text">件名・本文の管理名として使われます。</div></div>
    <div class="mb-2"><label class="form-label">件名</label>
      <textarea class="form-control" name="subject_content" id="scenSubject" rows="2" required></textarea></div>
    <div class="mb-2"><label class="form-label">本文の形式</label>
      <select class="form-select" name="format"><option value="html">HTML</option><option value="text">テキスト</option></select></div>
    <div class="mb-1"><label class="form-label mb-1">差し込み支援（カーソル位置に挿入）</label><div>${phButtons}</div></div>
    <div class="mb-2"><label class="form-label">本文</label>
      <textarea class="form-control" name="body_content" id="tplContent" rows="10" required></textarea></div>
    ${tplDescriptionField('scenDescription', '')}
  </form>`;
}
// 概要の入力欄(テンプレートとシナリオの作成・編集で共通)。受講者には見せない、管理者向けの説明。
const TPL_DESCRIPTION_MAX = 2000;
function tplDescriptionField(id, value) {
  return `<div class="mb-2"><label class="form-label" for="${id}">概要（任意）</label>
      <textarea class="form-control" name="description" id="${id}" rows="3" maxlength="${TPL_DESCRIPTION_MAX}" placeholder="元の事例、手口、見分けるポイント">${esc(value || '')}</textarea>
      <div class="form-text">テンプレートの一覧と訓練の作成画面に出ます。訓練メールの本文には入りません。</div></div>`;
}
function newScenario() {
  showModal('新規シナリオ（件名＋本文）', scenarioForm(), async () => {
    const f = $('#scenForm');
    await api('api/templates.php', { method: 'POST', query: { action: 'create_scenario' },
      body: {
        name: f.name.value.trim(),
        subject_content: f.subject_content.value.trim(),
        body_content: f.body_content.value,
        format: f.format.value,
        description: f.description.value.trim(),
      } });
    toast('シナリオを作成しました', 'ok');
    tplKindFilter = 'scenario';
    renderTemplates();
  });
  // 差し込みボタンは本文(#tplContent)に挿す。既存フォームと同じ id を使うため流用できる。
  bindTemplateForm();
}
// 偽ログインの時だけ auth_flag を送る(API はほかの種別の auth_flag を拒否する)
function templateAuthFlag(f) {
  return f.kind.value === 'phish_login' ? { auth_flag: Number(f.auth_flag.value) } : {};
}
function newTemplate() {
  showModal('新規テンプレート', templateForm(), async () => {
    const f = $('#tplForm');
    await api('api/templates.php', { method: 'POST', query: { action: 'create' },
      body: { name: f.name.value.trim(), kind: f.kind.value, format: f.format.value, content: f.content.value, description: f.description.value.trim(), ...templateAuthFlag(f) } });
    toast('作成しました', 'ok'); renderTemplates();
  });
  bindTemplateForm();
}
async function editTemplate(id) {
  const { template } = await api('api/templates.php', { query: { action: 'get', id } });
  showModal('テンプレート編集', templateForm(template), async () => {
    const f = $('#tplForm');
    await api('api/templates.php', { method: 'POST', query: { action: 'update' },
      body: { id, name: f.name.value.trim(), kind: f.kind.value, format: f.format.value, content: f.content.value, description: f.description.value.trim(), ...templateAuthFlag(f) } });
    toast('更新しました', 'ok'); renderTemplates();
  });
  bindTemplateForm();
}
async function deleteTemplate(id) {
  if (!confirm('このテンプレートを削除しますか？')) return;
  try { await api('api/templates.php', { method: 'POST', query: { action: 'delete' }, body: { id } });
    toast('削除しました', 'ok'); renderTemplates(); } catch (e) { toast(e.message, 'err'); }
}
// 指定 kind のテンプレートを CSV でダウンロード(CSV は非JSONなので直 fetch)。
async function downloadTplCsv(kind) {
  const qs = new URLSearchParams({ action: 'export_csv', kind });
  if (State.user && State.user.role === 'superadmin' && State.activeTenantId) qs.set('tenant_id', State.activeTenantId);
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), 30000);
  let res;
  try { res = await fetch(`api/templates.php?${qs}`, { credentials: 'same-origin', signal: ctrl.signal }); }
  finally { clearTimeout(timer); }
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = `templates_${kind}_${new Date().toISOString().slice(0, 10)}.csv`;
  document.body.appendChild(a); a.click(); a.remove();
  URL.revokeObjectURL(url);
}
// 現在のタブのテンプレートを CSV 出力。件名+本文タブは subject/body を両方DL。
async function exportTemplatesCsv() {
  try {
    if (tplKindFilter === 'scenario') { await downloadTplCsv('subject'); await downloadTplCsv('body'); }
    else await downloadTplCsv(tplKindFilter);
    toast('CSV を出力しました', 'ok');
  } catch (e) { toast(`出力に失敗しました（${e.message}）`, 'err'); }
}
// CSV でテンプレートを一括登録(mode=add:追加/upsert:上書き更新)。
function importTemplatesCsv(mode) {
  const label = mode === 'upsert' ? 'CSV上書き更新' : 'CSV追加';
  const body = `<p class="small text-muted">列: <code>name, kind, format, content, auth_flag, scenario_key</code>（name/kind/content 必須）。
    kind は subject/body/phish_login/debrief/elearning。${mode === 'upsert' ? '同名（name+kind）は<strong>上書き更新</strong>、無ければ追加。' : '同名は<strong>スキップ</strong>、無ければ追加。'}
    登録先は<strong>全テナント共通</strong>のテンプレートです。</p>
    <input type="file" class="form-control mb-2" id="tplCsvFile" accept=".csv,text/csv">
    <textarea class="form-control" id="tplCsvText" rows="8" placeholder="ファイルを選ぶか、CSVを貼り付け"></textarea>`;
  showModal(label, body, async () => {
    const csv = $('#tplCsvText').value.trim();
    if (!csv) throw new Error('CSV を選択または貼り付けてください');
    if (!confirm('全テナント共通のテンプレートに登録します。よろしいですか？')) return;
    const r = await api('api/templates.php', { method: 'POST', query: { action: 'import_csv' }, body: { csv, mode } });
    const errs = (r.errors || []).length ? `／エラー ${r.errors.length}` : '';
    toast(`追加 ${r.added}／更新 ${r.updated}／スキップ ${r.skipped}${errs}`, 'ok');
    renderTemplates();
  });
  // ファイル選択→textarea に読み込み
  const fileEl = document.getElementById('tplCsvFile');
  if (fileEl) fileEl.addEventListener('change', () => {
    const f = fileEl.files && fileEl.files[0]; if (!f) return;
    const reader = new FileReader();
    reader.onload = () => { document.getElementById('tplCsvText').value = String(reader.result || ''); };
    reader.readAsText(f, 'utf-8');
  });
}

// 差込プレースホルダをサンプル値に置換する。
function tplApplyPlaceholders(content) {
  let s = content || '';
  for (const p of TPL_PLACEHOLDERS) s = s.split(p.ph).join(p.sample);
  return s;
}
// プレビュー(差込サンプル置換)のHTMLを返す。html=iframe sandbox / text=pre。
function tplPreviewHtml(content, format, height = '40vh') {
  const c = tplApplyPlaceholders(content);
  if (format === 'html') {
    return `<iframe sandbox srcdoc="${esc(c)}" style="width:100%;height:${height};border:1px solid var(--tet-border);border-radius:var(--tet-radius-sm);background:#fff"></iframe>`;
  }
  return `<pre class="border rounded p-2 bg-light" style="max-height:${height};overflow:auto;white-space:pre-wrap">${esc(c)}</pre>`;
}

// 編集できない人に見せる概要(読むだけ)。
function tplDescriptionView(description) {
  const text = String(description || '').trim();
  return text ? `<div class="border rounded p-2 mb-2 small bg-light" style="white-space:pre-wrap"><div class="fw-semibold mb-1">概要</div>${esc(text)}</div>` : '';
}
// 単一テンプレート(偽ログイン/ネタバラシ/eラーニング等)のプレビュー/HTML/編集ビューア。
// テンプレート編集可否: 共有プリセットはシステム管理者だけ(全テナントに効く)、自テナント分は operator 以上。
function canEditTemplate(isPreset) {
  return Number(isPreset) ? State.user.role === 'superadmin' : roleAtLeast(State.user.role, 'operator');
}
async function tplViewer(id) {
  let t;
  try { t = (await api('api/templates.php', { query: { action: 'get', id } })).template; }
  catch (e) { toast(e.message, 'err'); return; }
  const editable = canEditTemplate(t.is_preset);
  const shared = Number(t.is_preset);
  const sharedBanner = shared
    ? (editable
        ? '<div class="alert alert-warning py-2 small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>これは<strong>全テナント共通</strong>のテンプレートです。変更はすべてのテナントに反映されます。</div>'
        : '<div class="alert alert-secondary py-2 small mb-2">共有テンプレート（編集にはテナント管理者以上の権限が必要です）。</div>')
    : '';
  const body = `${sharedBanner}
    <div class="small text-muted mb-2">${esc(labelKind(t.kind))}：${esc(t.name)}（形式：${esc(t.format)}）</div>
    ${editable ? tplDescriptionField('tvDescription', t.description) : tplDescriptionView(t.description)}
    <ul class="nav nav-pills mb-2" id="tvTabs">
      <li class="nav-item"><a class="nav-link active" href="#" data-tv="preview">プレビュー</a></li>
      <li class="nav-item"><a class="nav-link" href="#" data-tv="html">HTMLソース</a></li>
      ${editable ? '<li class="nav-item"><a class="nav-link" href="#" data-tv="edit">修正</a></li>' : ''}
    </ul>
    <div id="tvPreview">${tplPreviewHtml(t.content, t.format, '45vh')}</div>
    <div id="tvHtml" class="d-none"><pre class="border rounded p-2 bg-light" style="max-height:45vh;overflow:auto"><code>${esc(t.content || '')}</code></pre></div>
    ${editable ? `<div id="tvEdit" class="d-none"><textarea class="form-control" id="tvContent" rows="14">${esc(t.content || '')}</textarea></div>` : ''}
    ${editable ? `<div class="mt-2 text-end"><button type="button" class="btn btn-sm btn-outline-danger" id="tvDeleteBtn"><i class="bi bi-trash"></i> このテンプレートを削除</button></div>` : ''}`;
  if (editable) {
    showModal(`テンプレート: ${esc(t.name)}`, body, async () => {
      const content = $('#tvContent').value;
      if (!content.trim()) throw new Error('内容を入力してください');
      if (shared && !confirm('全テナント共通のテンプレートです。すべてのテナントに反映されます。保存しますか？')) return;
      await api('api/templates.php', { method: 'POST', query: { action: 'update' },
        body: { id, name: t.name, kind: t.kind, format: t.format, content, description: $('#tvDescription').value.trim() } });
      toast('保存しました', 'ok'); renderTemplates();
    });
  } else {
    showInfoModal(`テンプレート: ${esc(t.name)}`, body);
  }
  const tabs = document.getElementById('tvTabs');
  if (tabs) tabs.addEventListener('click', (e) => {
    const a = e.target.closest('.nav-link'); if (!a) return;
    e.preventDefault();
    tabs.querySelectorAll('.nav-link').forEach((x) => x.classList.toggle('active', x === a));
    ['preview', 'html', 'edit'].forEach((k) => { const el = document.getElementById('tv' + k[0].toUpperCase() + k.slice(1)); if (el) el.classList.toggle('d-none', a.dataset.tv !== k); });
  });
  const delBtn = document.getElementById('tvDeleteBtn');
  if (delBtn) delBtn.addEventListener('click', () => deleteTemplateAndClose([id], shared));
}

// ビューアからテンプレートを削除し、モーダルを閉じて再描画する。ids は複数(シナリオ=件名+本文)対応。
async function deleteTemplateAndClose(ids, shared) {
  const msg = shared
    ? `全テナント共通のテンプレートを削除します（${ids.length}件）。すべてのテナントに影響します。よろしいですか？`
    : `このテンプレートを削除します（${ids.length}件）。よろしいですか？`;
  if (!confirm(msg)) return;
  try {
    for (const id of ids) {
      await api('api/templates.php', { method: 'POST', query: { action: 'delete' }, body: { id } });
    }
    toast('削除しました', 'ok');
    if (modalInstance) modalInstance.hide();
    renderTemplates();
  } catch (e) { toast(e.message, 'err'); } // 使用中(409)等はここで表示
}

// シナリオ(件名+本文)を1画面で見るビューア。件名・本文を並べてプレビュー/HTML/編集。
async function scenarioViewer(scenarioKey) {
  let list;
  try { list = (await api('api/templates.php', { query: { action: 'list' } })).templates || []; }
  catch (e) { toast(e.message, 'err'); return; }
  const subj = list.find((t) => t.scenario_key === scenarioKey && t.kind === 'subject');
  const bodyT = list.find((t) => t.scenario_key === scenarioKey && t.kind === 'body');
  if (!subj || !bodyT) { toast('シナリオの件名/本文が見つかりません', 'err'); return; }
  const preset = Number(subj.is_preset) || Number(bodyT.is_preset);
  const editable = canEditTemplate(preset);
  const section = (t, editId) => `
    <div class="mb-3">
      <div class="fw-bold small mb-1">${esc(labelKind(t.kind))}：${esc(t.name)}</div>
      <ul class="nav nav-pills nav-sm mb-1" data-sv="${t.kind}">
        <li class="nav-item"><a class="nav-link active py-0 px-2" href="#" data-svt="preview">プレビュー</a></li>
        <li class="nav-item"><a class="nav-link py-0 px-2" href="#" data-svt="html">HTML</a></li>
        ${editable ? `<li class="nav-item"><a class="nav-link py-0 px-2" href="#" data-svt="edit">修正</a></li>` : ''}
      </ul>
      <div data-svpanel="${t.kind}-preview">${tplPreviewHtml(t.content, t.format, '22vh')}</div>
      <div class="d-none" data-svpanel="${t.kind}-html"><pre class="border rounded p-2 bg-light" style="max-height:22vh;overflow:auto"><code>${esc(t.content || '')}</code></pre></div>
      ${editable ? `<div class="d-none" data-svpanel="${t.kind}-edit"><textarea class="form-control" id="${editId}" rows="6">${esc(t.content || '')}</textarea></div>` : ''}
    </div>`;
  const sharedBanner = preset
    ? (editable
        ? '<div class="alert alert-warning py-2 small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>この件名・本文は<strong>全テナント共通</strong>です。変更はすべてのテナントに反映されます。</div>'
        : '<div class="alert alert-secondary py-2 small mb-2">共有テンプレート（編集にはテナント管理者以上の権限が必要です）。</div>')
    : '';
  const scenarioDescription = bodyT.description || subj.description || '';
  const body = `${sharedBanner}<div class="small text-muted mb-2">シナリオ「${esc(subj.name)}」の件名と本文</div>
    ${editable ? tplDescriptionField('svDescription', scenarioDescription) : tplDescriptionView(scenarioDescription)}
    ${section(subj, 'svSubject')}${section(bodyT, 'svBody')}
    ${editable ? `<div class="mt-2 text-end"><button type="button" class="btn btn-sm btn-outline-danger" id="svDeleteBtn"><i class="bi bi-trash"></i> このシナリオ（件名＋本文）を削除</button></div>` : ''}`;
  if (editable) {
    showModal('シナリオ内容', body, async () => {
      if (preset && !confirm('全テナント共通の件名・本文です。すべてのテナントに反映されます。保存しますか？')) return;
      await api('api/templates.php', { method: 'POST', query: { action: 'update' }, body: { id: subj.id, name: subj.name, kind: 'subject', format: subj.format, content: $('#svSubject').value } });
      await api('api/templates.php', { method: 'POST', query: { action: 'update' }, body: { id: bodyT.id, name: bodyT.name, kind: 'body', format: bodyT.format, content: $('#svBody').value, description: $('#svDescription').value.trim() } });
      toast('保存しました', 'ok'); renderTemplates();
    });
  } else {
    showInfoModal('シナリオ内容', body);
  }
  // 各セクションのタブ切替
  document.querySelectorAll('[data-sv]').forEach((navEl) => {
    navEl.addEventListener('click', (e) => {
      const a = e.target.closest('.nav-link'); if (!a) return;
      e.preventDefault();
      const kind = navEl.dataset.sv;
      navEl.querySelectorAll('.nav-link').forEach((x) => x.classList.toggle('active', x === a));
      ['preview', 'html', 'edit'].forEach((k) => {
        const p = document.querySelector(`[data-svpanel="${kind}-${k}"]`); if (p) p.classList.toggle('d-none', a.dataset.svt !== k);
      });
    });
  });
  const svDel = document.getElementById('svDeleteBtn');
  if (svDel) svDel.addEventListener('click', () => deleteTemplateAndClose([subj.id, bodyT.id], preset));
}

/* ========== セキュリティ教育: 配信 ========== */
const EDU_DTYPE = { elearning: 'eラーニング', awareness_quiz: 'アウェアネス' };
const EDU_STATUS = { draft: '下書き', scheduled: '予約', running: '配信中', done: '終了', cancelled: '中止' };
const EDU_RISK_RESULTS = [
  ['opened', '開いた（リンクか偽サイトを開いた）'], ['submitted', '入力した'],
  ['reported', '報告した'], ['not_opened', '開かなかった'],
];
function eduStatusCell(d) {
  const color = d.status === 'running' ? 'success' : d.status === 'done' ? 'secondary' : d.status === 'scheduled' ? 'info text-dark' : 'light text-dark';
  const when = d.status === 'scheduled' && d.scheduled_at ? `<div class="small text-muted">${esc(String(d.scheduled_at).slice(0, 16))}</div>` : '';
  const monthly = d.series_id ? ' <span class="badge bg-light text-dark border">毎月</span>' : '';
  return `<span class="badge bg-${color}">${esc(EDU_STATUS[d.status] || d.status)}</span>${monthly}${when}`;
}
async function renderEduDeliveries() {
  const { deliveries } = await api('api/edu_report.php', { query: { action: 'deliveries' } });
  cacheRows('eduDeliveries', deliveries || []);
  // #列は表示上の通し番号(古い順に1,2,3…)。削除しても詰まる。
  const deliveriesAsc = (deliveries || []).slice().sort((a, b) => a.id - b.id);
  const beforeStart = (d) => d.status === 'draft' || d.status === 'scheduled';
  $('#eduDeliveriesBody').innerHTML = deliveriesAsc.length ? deliveriesAsc.map((d, i) => `
    <tr><td>${i + 1}</td><td>${esc(d.title)}</td><td>${EDU_DTYPE[d.delivery_type] || esc(d.delivery_type)}</td>
      <td>${eduStatusCell(d)}</td>
      <td>${d.assigned}</td><td>${d.completed}</td><td>${d.completion_rate}%</td><td>${d.average_score}%</td>
      <td class="text-nowrap">
        <button class="btn btn-sm btn-outline-primary" onclick="viewEduDelivery(${d.id})" title="配信レポート"><i class="bi bi-graph-up"></i> レポート</button>
        ${roleAtLeast(State.user.role,'operator') && beforeStart(d)?`
        <button class="btn btn-sm btn-outline-secondary" data-edu-delivery-edit="${Number(d.id)}" title="配信を編集"><i class="bi bi-pencil"></i> 編集</button>
        <button class="btn btn-sm btn-outline-success" onclick="launchEduDelivery(${d.id})" title="${d.status === 'scheduled' ? '予約を待たずに今すぐ開始' : '配信開始'}"><i class="bi bi-send"></i></button>`:''}
        ${roleAtLeast(State.user.role,'operator') && d.status==='running'?`
        <button class="btn btn-sm btn-outline-warning" onclick="remindEduDelivery(${d.id})" title="未完了者へ催促メール"><i class="bi bi-envelope-exclamation"></i></button>
        <button class="btn btn-sm btn-outline-secondary" onclick="openEduReminderSettings(${d.id})" title="自動の催促の設定" aria-label="自動の催促の設定" data-edu-reminder="${Number(d.id)}"><i class="bi bi-alarm"></i></button>`:''}
        ${roleAtLeast(State.user.role,'operator') ? (Number(d.completed) > 0 || Number(d.started_count) > 0
          ? '<span class="small text-muted ms-1">受講履歴を保持（削除不可）</span>'
          : `<button class="btn btn-sm btn-outline-danger" onclick="deleteEduDelivery(${d.id})" title="受講開始前の配信を削除"><i class="bi bi-trash"></i> 削除</button>`) : ''}
      </td></tr>`).join('') : emptyRow(9);
  for (const button of $('#eduDeliveriesBody').querySelectorAll?.('[data-edu-delivery-edit]') || []) {
    button.addEventListener('click', () => editEduDelivery(Number(button.dataset.eduDeliveryEdit)));
  }
}
function refreshEduDeliveries() {
  return Promise.all([renderEduDeliveries(), renderEduSeries(), renderEduContact(), renderEduSummarySetting()]);
}
/** テナントの社内の問い合わせ先(受講者のマイページに出す)。変えられるのは組織管理者とシステム管理者。 */
let eduContact = null;
async function renderEduContact() {
  const el = $('#eduContactSummary');
  const btn = $('#editEduContactBtn');
  if (!el) return;
  try { eduContact = await api('api/edu_deliveries.php', { query: { action: 'contact' } }); }
  catch (e) { el.textContent = `読み込めませんでした（${e.message}）`; return; }
  el.textContent = eduContact.edu_contact || '未設定（マイページには出しません）';
  btn.classList.toggle('d-none', !eduContact.can_edit);
  btn.onclick = editEduContact;
}
function editEduContact() {
  const body = `<form id="eduContactForm">
    <label class="form-label" for="eduContactText">問い合わせ先</label>
    <textarea class="form-control" id="eduContactText" name="edu_contact" rows="4" maxlength="500">${esc(eduContact?.edu_contact || '')}</textarea>
    <div class="form-text">例: 情報システム部 内線 1234。受講者のマイページのホームに出ます。空にすると出しません（500文字まで）。</div>
  </form>`;
  showModal('社内の問い合わせ先', body, async () => {
    await api('api/edu_deliveries.php', { method: 'POST', query: { action: 'contact' }, body: { edu_contact: $('#eduContactText').value } });
    toast('問い合わせ先を保存しました', 'ok');
    renderEduContact();
  });
}
/**
 * 受講期間の終了時の集計通知(段D の D5)。期限を過ぎた配信の受講率と合格率を、登録した担当者へ1回だけ送る。
 * 既定は切。送るのは自動の処理(edu_delivery_summary の timer)で、この画面はメールを送らない。変えられるのは組織管理者以上。
 */
let eduSummary = null;
async function renderEduSummarySetting() {
  const el = $('#eduSummarySetting');
  const btn = $('#editEduSummaryBtn');
  if (!el) return;
  if (!roleAtLeast(State.user?.role, 'operator')) { $('#eduSummaryBox')?.classList.add('d-none'); return; }
  try { eduSummary = await api('api/edu_summary.php', { query: { action: 'setting' } }); }
  catch (e) { el.textContent = `読み込めませんでした（${e.message}）`; return; }
  const s = eduSummary.setting;
  const last = eduSummary.history[0];
  el.textContent = (s.enabled ? `有効: ${s.recipients.join('、')} へ送ります` : '切（送りません）')
    + (last ? `。最後の通知: ${last.title}（${fmtDate(last.finished_at || last.created_at)}、${last.sent}/${last.recipients}通）` : '');
  btn.classList.toggle('d-none', !eduSummary.can_edit);
  btn.onclick = editEduSummarySetting;
}
function editEduSummarySetting() {
  const s = eduSummary?.setting || { enabled: false, recipients: [] };
  const body = `<form id="eduSummaryForm">
    <div class="form-check form-switch mb-2">
      <input class="form-check-input" type="checkbox" role="switch" id="eduSummaryEnabled" ${s.enabled ? 'checked' : ''}>
      <label class="form-check-label" for="eduSummaryEnabled">配信の期限が過ぎたら、集計を担当者へ送る</label>
    </div>
    <label class="form-label" for="eduSummaryRecipients">送り先（社内の担当者のメールアドレス。1行に1つ、${Number(eduSummary?.max_recipients || 10)}件まで）</label>
    <textarea class="form-control" id="eduSummaryRecipients" name="recipients" rows="4">${esc(s.recipients.join('\n'))}</textarea>
    <div class="form-text">送るのは、対象の人数、受講を終えた人数と受講率、合格率、期限内合格率だけです（個人の名前やアドレスは入りません）。配信1件につき1回だけ送ります。有効にする前に期限を過ぎた配信の分は送りません。文面はユーザ管理の「通知の文面」で変えられます。自動の処理（edu_delivery_summary）を動かしている時だけ届きます。</div>
  </form>`;
  showModal('受講期間の終了時の集計通知', body, async () => {
    const recipients = $('#eduSummaryRecipients').value.split('\n').map((v) => v.trim()).filter(Boolean);
    await api('api/edu_summary.php', { method: 'POST', query: { action: 'save' }, body: { enabled: $('#eduSummaryEnabled').checked, recipients } });
    toast('集計通知の設定を保存しました', 'ok');
    renderEduSummarySetting();
  });
}
/**
 * 配信ごとの受講の設定(作成と編集の画面で共通)。[項目, 表示, 補足, 新しい配信の既定]。
 * 選択肢の並べ替えだけ新しい配信の既定で有効にする(既存の配信は migration で無効のまま)。
 */
const EDU_DELIVERY_OPTIONS = [
  ['shuffle_options', '確認テストの選択肢の順を人ごとに並べ替える', '同じ人が開き直しても同じ順です。採点は元の選択肢で行います。', true],
  ['lock_material_during_test', 'テスト中は教材を見られないようにする', 'テストを始めてから提出するまで、教材を開けません。提出した後は見直せます。', false],
  ['allow_after_deadline', '期限の後も受講できるようにする', '期限の後に終えた受講は、教育レポートで「期限後」として数え、期限内合格率には入れません。', false],
  ['retake_from_test', '不合格の時は確認テストから受け直す', '不合格の後の受け直しは、教材を飛ばして確認テストから始めます。', false],
];
function eduDeliveryOptionFields(prefix, values = null) {
  return `<fieldset class="border rounded p-2 mb-2"><legend class="form-label small fw-semibold float-none w-auto px-1 mb-0">受講の設定</legend>
    ${EDU_DELIVERY_OPTIONS.map(([key, label, help, def]) => {
      const on = values ? Number(values[key] ?? 0) === 1 : def;
      return `<div class="form-check"><input class="form-check-input" type="checkbox" id="${prefix}_${key}" data-edu-option="${key}"${on ? ' checked' : ''}>
        <label class="form-check-label" for="${prefix}_${key}">${label}</label><div class="form-text mt-0 mb-1">${help}</div></div>`;
    }).join('')}</fieldset>`;
}
function eduDeliveryOptionValues(form) {
  return Object.fromEntries(Array.from(form.querySelectorAll('[data-edu-option]')).map((input) => [input.dataset.eduOption, input.checked]));
}
/** 毎月の配信(系列)。回ごとの配信は edu_scheduler が予約の状態で作り、予約の日時に開始する。 */
async function renderEduSeries() {
  const { series } = await api('api/edu_deliveries.php', { query: { action: 'series_list' } });
  const operator = roleAtLeast(State.user.role, 'operator');
  $('#eduSeriesBody').innerHTML = (series || []).length ? series.map((r) => `
    <tr><td>${esc(r.title)}</td><td>毎月${Number(r.day_of_month)}日 ${esc(r.time_of_day)}</td>
      <td>${Number(r.deadline_days)}日</td>
      <td>${Number(r.is_active) === 1 ? esc(String(r.next_run_at).slice(0, 16)) : '—'}</td>
      <td>${r.end_date ? esc(r.end_date) : 'なし'}</td><td>${Number(r.delivery_count)}</td>
      <td>${Number(r.is_active) === 1 ? '<span class="badge bg-success">有効</span>' : '<span class="badge bg-secondary">停止</span>'}</td>
      <td>${operator && Number(r.is_active) === 1 ? `<button class="btn btn-sm btn-outline-danger" onclick="stopEduSeries(${Number(r.id)})"><i class="bi bi-stop-circle"></i> 停止</button>` : ''}</td></tr>`).join('')
    : emptyRow(8);
}
async function stopEduSeries(id) {
  if (!confirm('この毎月の配信を停止しますか？\n作成済みの予約の配信は残ります（不要なら配信の一覧から削除してください）。')) return;
  try {
    await api('api/edu_deliveries.php', { method: 'POST', query: { action: 'series_stop' }, body: { id } });
    toast('毎月の配信を停止しました', 'ok');
    await renderEduSeries();
  } catch (e) { toast(e.message, 'err'); }
}
function eduDeliveryForm() {
  const catOpts = (Cache.eduCats || []).map((c) =>
    `<option value="${c.id}"${c.slug === 'phishing' ? ' selected' : ''}>${esc(c.name)}</option>`).join('');
  const materialOpts = (Cache.eduMaterialList || []).map((m) =>
    `<option value="${m.id}">${esc(m.title)}（${m.slide_count}枚）</option>`).join('');
  const targetOpts = (Cache.eduTargets || []).map((t) =>
    `<option value="${t.id}">${Number(t.is_test) === 1 ? '[テスト] ' : ''}${esc(t.email)}（${esc(t.name || '')}）</option>`).join('');
  // 訓練の結果で選べるのは終わったキャンペーンだけ(訓練の期間中に出すと測定が崩れる)
  const finished = (Cache.eduCampaigns || []).filter((c) => c.status === 'done' || c.status === 'cancelled');
  const campaignOpts = finished.map((c) => `<option value="${c.id}">${esc(c.name)}（${esc(EDU_STATUS[c.status] || c.status)}）</option>`).join('');
  const positionChecks = POSITION_CATEGORIES.map((p, i) => `<div class="form-check form-check-inline">
      <input class="form-check-input" type="checkbox" name="target_positions" id="eduPos${i}" value="${esc(p)}">
      <label class="form-check-label" for="eduPos${i}">${esc(p)}</label></div>`).join('');
  const resultChecks = EDU_RISK_RESULTS.map(([value, label]) => `<div class="form-check form-check-inline">
      <input class="form-check-input" type="checkbox" name="risk_results" id="eduRisk_${value}" value="${value}"${value === 'opened' || value === 'submitted' ? ' checked' : ''}>
      <label class="form-check-label" for="eduRisk_${value}">${label}</label></div>`).join('');
  // 「開いた」は偽サイトの表示(ビーコン)も含むため、訓練レポートの失敗(クリックと入力)より広い
  const resultNote = '<div class="form-text">「開いた」には、偽サイトを表示しただけの人も含みます。訓練レポートの「失敗」（リンクのクリックと入力）より広い範囲です。</div>';
  return `<form id="eduDeliveryForm">
    <div class="alert alert-primary py-2"><button type="button" class="btn btn-sm btn-primary me-2" id="eduRiskPreset">訓練失敗者向けを設定</button><span class="small">標的型メール訓練の直後に、スライド教材と確認テストを配信します。</span></div>
    <div class="mb-2"><label class="form-label">タイトル</label><input class="form-control" name="title" value="標的型メール訓練 フォローアップ" required></div>
    <div class="mb-2"><label class="form-label">種別</label>
      <select class="form-select" name="delivery_type" id="eduDType">
        <option value="elearning">eラーニング（合格点まで再受講）</option>
        <option value="awareness_quiz">アウェアネス（回答提出で完了・合否なし）</option>
      </select><div class="form-text" id="eduTypeHelp"></div></div>
    <div class="mb-2"><label class="form-label" for="eduFeedbackMode">答え合わせの時機</label>
      <select class="form-select" name="feedback_mode" id="eduFeedbackMode">
        <option value="after_submit">提出後にまとめて</option>
        <option value="immediate">1問ごとに答え合わせ</option>
      </select></div>
    <div class="mb-2" id="eduMaterialField"><label class="form-label">スライド教材</label>
      <select class="form-select" name="material_id"><option value="">教材なし</option>${materialOpts}</select></div>
    <div class="mb-2"><label class="form-label">配信対象</label>
      <select class="form-select" name="target_type" id="eduTargetType">
        <option value="risk">訓練の結果で選ぶ（実対象者）</option>
        <option value="all">全対象者（テスト宛先を除く）</option>
        <option value="position">役職区分で選ぶ</option>
        <option value="new_target">新入社員（登録から指定の日数以内）</option>
        <option value="individual">個別選択（テスト宛先も選択可）</option>
      </select></div>
    <div class="mb-2 d-none" id="eduAutoEnrollField">
      <div class="form-check"><input class="form-check-input" type="checkbox" name="auto_enroll" id="eduAutoEnroll">
        <label class="form-check-label" for="eduAutoEnroll">訓練失敗者を自動で追加し続ける</label></div>
      <div class="form-text">開始後も毎時、新たに訓練で失敗した人を自動で受講対象に加えます。配信を作成した時点より後の失敗が対象です。</div></div>
    <div class="mb-2 d-none" id="eduRiskResultField"><label class="form-label" for="eduRiskCampaign">訓練のキャンペーン（終わったもの）</label>
      <select class="form-select" name="phish_campaign_id" id="eduRiskCampaign"><option value="">選択してください</option>${campaignOpts}</select>
      <div class="mt-1">${resultChecks}</div>${resultNote}
      <div class="form-text">訓練の期間中に同じ手口の教育を出すと測定が崩れるため、終了・中止したキャンペーンだけを選べます。</div></div>
    <div class="mb-2 d-none" id="eduPositionField"><label class="form-label">役職区分</label><div>${positionChecks}</div></div>
    <div class="mb-2 d-none" id="eduNewTargetField"><label class="form-label" for="eduNewTargetDays">登録からの日数</label>
      <input class="form-control" type="number" name="new_target_days" id="eduNewTargetDays" min="1" max="365" value="30">
      <div class="form-text">開始後も、対象者の登録から指定の日数以内の人を自動で受講対象に加えます（自動の処理を動かしている場合）。</div></div>
    <div class="mb-2 d-none" id="eduIndividualTargets"><label class="form-label">個別対象者</label>
      <input class="form-control form-control-sm mb-2" id="eduTargetSearch" placeholder="氏名またはメールアドレスで絞り込み">
      <select class="form-select" name="target_ids" multiple size="7">${targetOpts}</select>
      <div class="form-text"><span id="eduTargetCount">0名選択</span>・Ctrl / commandキーで複数選択できます。</div></div>
    <div class="mb-2"><label class="form-label">確認テストのカテゴリ（複数選択可）</label>
      <select class="form-select" name="category_ids" multiple size="4">${catOpts}</select></div>
    <div class="row">
      <div class="col-6 mb-2"><label class="form-label">出題数</label><input class="form-control" type="number" name="question_count" min="1" value="3"></div>
      <div class="col-6 mb-2" id="eduPassScoreField"><label class="form-label">合格点</label><input class="form-control" type="number" name="pass_score" min="1" max="100" value="80"></div>
    </div>
    <div class="border rounded p-2 mb-2">
      <div class="form-check mb-1" id="eduRepeatField"><input class="form-check-input" type="checkbox" name="repeat_monthly" id="eduRepeatMonthly">
        <label class="form-check-label" for="eduRepeatMonthly">毎月くり返す</label></div>
      <div class="row" id="eduOnceFields">
        <div class="col-6 mb-2"><label class="form-label" for="eduScheduledAt">予約の日時（任意）</label><input class="form-control" type="datetime-local" name="scheduled_at" id="eduScheduledAt"></div>
        <div class="col-6 mb-2"><label class="form-label" for="eduDeadline">締切（任意）</label><input class="form-control" type="date" name="deadline" id="eduDeadline"></div>
        <div class="form-text">予約の日時を入れると「予約」になり、その日時に自動で開始します（自動の処理を動かしている場合）。空なら下書きで作り、一覧の開始ボタンで開始します。</div>
      </div>
      <div class="row d-none" id="eduSeriesFields">
        <div class="col-3 mb-2"><label class="form-label" for="eduDayOfMonth">毎月の日</label><input class="form-control" type="number" name="day_of_month" id="eduDayOfMonth" min="1" max="28" value="1"></div>
        <div class="col-3 mb-2"><label class="form-label" for="eduTimeOfDay">時刻</label><input class="form-control" type="time" name="time_of_day" id="eduTimeOfDay" value="09:00"></div>
        <div class="col-3 mb-2"><label class="form-label" for="eduDeadlineDays">締切までの日数</label><input class="form-control" type="number" name="deadline_days" id="eduDeadlineDays" min="1" max="90" value="14"></div>
        <div class="col-3 mb-2"><label class="form-label" for="eduEndDate">終了日（任意）</label><input class="form-control" type="date" name="end_date" id="eduEndDate"></div>
        <div class="form-text">毎月その日時に、この設定の配信を予約の状態で作って開始します。前の回で出した設問は次の回で外します。</div>
      </div>
    </div>
    <div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="send_invites" id="eduSendInvites">
      <label class="form-check-label" for="eduSendInvites">受講の案内メールを送る</label></div>
    <div class="form-text mb-2">既定では送りません。送らない場合は、開始後に一覧の催促ボタンで案内するか、社内の連絡で受講を依頼してください。</div>
    <div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="allow_retake_after_pass" id="eduAllowRetake" checked>
      <label class="form-check-label" for="eduAllowRetake">完了（合格）した後も受け直せる</label></div>
    <div class="form-text mb-2">受講者はマイページの「もう一度受講する」から受け直せます。前の回の結果は残り、レポートは最新の回で数えます。</div>
    ${eduDeliveryOptionFields('eduOpt')}
  </form>`;
}

function syncEduDeliveryForm() {
  const f = $('#eduDeliveryForm');
  const elearning = f.delivery_type.value === 'elearning';
  const target = f.target_type.value;
  $('#eduMaterialField').classList.toggle('d-none', !elearning);
  $('#eduPassScoreField').classList.toggle('d-none', !elearning);
  $('#eduIndividualTargets').classList.toggle('d-none', target !== 'individual');
  $('#eduPositionField').classList.toggle('d-none', target !== 'position');
  $('#eduNewTargetField').classList.toggle('d-none', target !== 'new_target');
  // 自動連携は「訓練の結果」を対象にしたときだけ意味を持つ(他の対象種別では二重投入になる)
  const risk = target === 'risk';
  $('#eduAutoEnrollField').classList.toggle('d-none', !risk);
  if (!risk) f.auto_enroll.checked = false;
  $('#eduRiskResultField').classList.toggle('d-none', !risk || f.auto_enroll.checked);
  // 毎月くり返せるのは、手動の配信(全員・役職・個別)だけ
  const repeatable = target === 'all' || target === 'position' || target === 'individual';
  $('#eduRepeatField').classList.toggle('d-none', !repeatable);
  if (!repeatable) f.repeat_monthly.checked = false;
  $('#eduOnceFields').classList.toggle('d-none', f.repeat_monthly.checked);
  $('#eduSeriesFields').classList.toggle('d-none', !f.repeat_monthly.checked);
  $('#eduTypeHelp').textContent = elearning
    ? '教材を読んで確認テストに合格すると完了します。不合格の場合は再受講できます。'
    : '理解度を測る継続教育です。回答提出で完了し、合格・不合格は付けません。';
}

function defaultEduFeedbackMode() {
  $('#eduFeedbackMode').value = $('#eduDType').value === 'awareness_quiz' ? 'immediate' : 'after_submit';
}

function filterEduIndividualTargets() {
  const form = $('#eduDeliveryForm');
  const keyword = $('#eduTargetSearch').value.trim().toLowerCase();
  Array.from(form.target_ids.options).forEach((option) => {
    option.hidden = keyword !== '' && !option.textContent.toLowerCase().includes(keyword);
  });
  $('#eduTargetCount').textContent = `${form.target_ids.selectedOptions.length}名選択`;
}

function eduCheckedValues(form, name) {
  return Array.from(form.querySelectorAll(`input[name="${name}"]:checked`)).map((input) => input.value);
}
function eduDeliveryAction(form) {
  return form.repeat_monthly.checked ? 'series_create' : 'create';
}
function eduDeliveryPayload(form) {
  const categories = Array.from(form.category_ids.selectedOptions).map((o) => Number(o.value));
  const targets = Array.from(form.target_ids.selectedOptions).map((o) => Number(o.value));
  const type = form.delivery_type.value;
  const target = form.target_type.value;
  if (target === 'individual' && !targets.length) throw new Error('個別対象者を選択してください');
  const body = { title: form.title.value.trim(), delivery_type: type, feedback_mode: form.feedback_mode.value,
    target_type: target === 'new_target' ? 'all' : target,
    question_count: Number(form.question_count.value) || 3, category_ids: categories.length ? categories : undefined,
    send_invites: form.send_invites.checked, allow_retake_after_pass: form.allow_retake_after_pass.checked,
    ...eduDeliveryOptionValues(form) };
  if (type === 'elearning') {
    body.pass_score = Number(form.pass_score.value) || 80;
    body.material_id = Number(form.material_id.value) || undefined;
  }
  if (target === 'individual') body.target_ids = targets;
  if (target === 'position') {
    body.target_positions = eduCheckedValues(form, 'target_positions');
    if (!body.target_positions.length) throw new Error('役職区分を1つ以上選んでください');
  }
  if (target === 'new_target') {
    body.triggered_by = 'new_target';
    body.new_target_days = Number(form.new_target_days.value);
  }
  if (target === 'risk' && form.auto_enroll.checked) body.triggered_by = 'phishing_failure';
  if (target === 'risk' && !form.auto_enroll.checked) {
    body.phish_campaign_id = Number(form.phish_campaign_id.value) || undefined;
    body.risk_results = eduCheckedValues(form, 'risk_results');
    if (!body.phish_campaign_id) throw new Error('訓練のキャンペーンを選んでください');
    if (!body.risk_results.length) throw new Error('訓練の結果の区分を1つ以上選んでください');
  }
  if (form.repeat_monthly.checked) {
    const day = Number(form.day_of_month.value);
    if (!Number.isInteger(day) || day < 1 || day > 28) throw new Error('毎月の日は1〜28で入力してください');
    body.day_of_month = day;
    body.time_of_day = form.time_of_day.value;
    body.deadline_days = Number(form.deadline_days.value) || 14;
    if (form.end_date.value) body.end_date = form.end_date.value;
  } else {
    if (form.scheduled_at.value) body.scheduled_at = form.scheduled_at.value;
    if (form.deadline.value) body.deadline = form.deadline.value;
  }
  return body;
}

async function newEduDelivery() {
  const [cats, materials, targets, campaigns] = await Promise.all([
    api('api/edu_categories.php', { query: { action: 'list' } }),
    api('api/edu_materials.php', { query: { action: 'list' } }),
    api('api/targets.php', { query: { action: 'list' } }),
    api('api/campaigns.php', { query: { action: 'list' } }),
  ]);
  Cache.eduCats = cats.categories || []; Cache.eduMaterialList = materials.materials || []; Cache.eduTargets = targets.targets || [];
  Cache.eduCampaigns = campaigns.campaigns || [];
  showModal('新規教育配信', eduDeliveryForm(), async () => {
    const form = $('#eduDeliveryForm');
    const action = eduDeliveryAction(form);
    await api('api/edu_deliveries.php', { method: 'POST', query: { action }, body: eduDeliveryPayload(form) });
    toast(action === 'series_create' ? '毎月の配信を作成しました' : '配信を作成しました', 'ok'); refreshEduDeliveries();
  }, { size: 'lg' });
  $('#eduDType').addEventListener('change', () => { defaultEduFeedbackMode(); syncEduDeliveryForm(); });
  $('#eduTargetType').addEventListener('change', syncEduDeliveryForm);
  $('#eduAutoEnroll').addEventListener('change', syncEduDeliveryForm);
  $('#eduRepeatMonthly').addEventListener('change', syncEduDeliveryForm);
  $('#eduTargetSearch').addEventListener('input', filterEduIndividualTargets);
  $('#eduDeliveryForm').target_ids.addEventListener('change', filterEduIndividualTargets);
  $('#eduRiskPreset').addEventListener('click', () => {
    const f = $('#eduDeliveryForm'); f.delivery_type.value = 'elearning'; f.target_type.value = 'risk';
    const preset = Array.from(f.material_id.options).find((option) => option.textContent.includes('フォローアップ基礎'));
    if (preset) f.material_id.value = preset.value;
    // 訓練直後のジャストインタイム教育が本来の用途なので、自動追加を既定で入れる
    f.auto_enroll.checked = true;
    syncEduDeliveryForm();
  });
  $('#eduRiskPreset').click();
}
async function editEduDelivery(id) {
  let delivery;
  try { ({ delivery } = await api('api/edu_deliveries.php', { query: { action: 'get', id } })); }
  catch (error) { toast(error.message, 'err'); return; }
  if (delivery.status !== 'draft' && delivery.status !== 'scheduled') return toast('開始前の配信だけ編集できます', 'err');
  const type = EDU_DTYPE[delivery.delivery_type] || delivery.delivery_type;
  const scheduled = delivery.scheduled_at ? String(delivery.scheduled_at).slice(0, 16).replace(' ', 'T') : '';
  const body = `<form id="eduDeliveryEditForm">
    <div class="mb-2 small text-muted">種別: ${esc(type)}・状態: ${esc(EDU_STATUS[delivery.status] || delivery.status)}</div>
    <div class="mb-2"><label class="form-label" for="eduEditTitle">タイトル</label><input class="form-control" id="eduEditTitle" value="${esc(delivery.title)}" required></div>
    <div class="mb-2"><label class="form-label" for="eduEditFeedback">答え合わせの時機</label><select class="form-select" id="eduEditFeedback">
      <option value="after_submit"${delivery.feedback_mode === 'after_submit' ? ' selected' : ''}>提出後にまとめて</option>
      <option value="immediate"${delivery.feedback_mode === 'immediate' ? ' selected' : ''}>1問ごとに答え合わせ</option>
    </select></div>
    <div class="mb-2"><label class="form-label" for="eduEditScheduledAt">予約の日時</label>
      <input class="form-control" type="datetime-local" id="eduEditScheduledAt" value="${esc(scheduled)}">
      <div class="form-text">日時を入れると予約になり、その日時に自動で開始します。</div></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="eduEditSendInvites"${Number(delivery.send_invites) === 1 ? ' checked' : ''}>
      <label class="form-check-label" for="eduEditSendInvites">受講の案内メールを送る</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="eduEditAllowRetake"${Number(delivery.allow_retake_after_pass ?? 1) === 1 ? ' checked' : ''}>
      <label class="form-check-label" for="eduEditAllowRetake">完了（合格）した後も受け直せる</label></div>
    <div class="mt-2">${eduDeliveryOptionFields('eduEditOpt', delivery)}</div>
    ${eduReminderFields('eduEditRem', delivery)}
  </form>`;
  showModal('教育配信を編集', body, async () => {
    const title = $('#eduEditTitle').value.trim();
    if (!title) throw new Error('タイトルを入力してください');
    const payload = { id, title, feedback_mode: $('#eduEditFeedback').value, send_invites: $('#eduEditSendInvites').checked,
      allow_retake_after_pass: $('#eduEditAllowRetake').checked, ...eduDeliveryOptionValues($('#eduDeliveryEditForm')),
      ...eduReminderValues($('#eduDeliveryEditForm')) };
    // 空にして保存したら予約を解除する(null を送る。送らないと API は予約をそのまま残す)
    payload.scheduled_at = $('#eduEditScheduledAt').value || null;
    await api('api/edu_deliveries.php', { method: 'POST', query: { action: 'update' }, body: payload });
    toast('更新しました', 'ok'); renderEduDeliveries();
  });
}
async function deleteEduDelivery(id) {
  if (!roleAtLeast(State.user.role, 'operator')) return;
  const delivery = Cache.eduDeliveries?.[id];
  if (!delivery) return toast('配信が見つかりません。画面を更新してください', 'err');
  if (Number(delivery.completed) > 0 || Number(delivery.started_count) > 0) return toast('受講履歴を保持するため、受講開始・完了の履歴がある配信は削除できません', 'err');
  if (!confirm(`教育配信「${delivery.title}」を削除しますか？\n未受講の割当と受講リンクも削除されます。受講開始・完了の履歴がある配信は削除できません。`)) return;
  try {
    await api('api/edu_deliveries.php', { method: 'POST', query: { action: 'delete' }, body: { id } });
    toast('教育配信を削除しました', 'ok');
    await renderEduDeliveries();
  } catch (e) { toast(e.message, 'err'); }
}
async function launchEduDelivery(id) {
  const delivery = Cache.eduDeliveries?.[id];
  const mail = Number(delivery?.send_invites) === 1 ? '受講の案内メールを送ります。' : '案内メールは送りません。';
  if (!confirm(`この配信を今すぐ開始し、対象者に受講を割り当てますか？\n${mail}`)) return;
  try {
    const r = await api('api/edu_deliveries.php', { method: 'POST', query: { action: 'launch' }, body: { id } });
    toast(`${r.assigned}名に割り当てました（案内メール ${r.mail_sent}通）`, 'ok'); renderEduDeliveries();
  } catch (e) { toast(e.message, 'err'); }
}
async function remindEduDelivery(id) {
  if (!confirm('未完了の受講者へ受講催促メールを送信しますか？')) return;
  try {
    const r = await api('api/edu_deliveries.php', { method: 'POST', query: { action: 'remind' }, body: { id } });
    if (r.targets === 0) { toast(r.message || '未完了の受講者はいません', 'info'); return; }
    const msg = r.failed > 0 ? `${r.sent}名に送信（${r.failed}名は失敗）` : `${r.sent}名に催促メールを送信しました`;
    toast(msg, r.failed > 0 ? 'err' : 'ok');
  } catch (e) { toast(e.message, 'err'); }
}
// 教育配信の一覧の「レポート」は、教育レポートの「配信ごと」のタブで、その配信の詳細を開く
function viewEduDelivery(id) {
  eduRep.tab = 'deliveries';
  selectEduRepDelivery(id);
  navigate('eduReport');
}

/* ========== セキュリティ教育: 教材バンク ========== */
async function renderEduMaterials() {
  const { materials } = await api('api/edu_materials.php', { query: { action: 'list' } });
  Cache.eduMaterialList = materials || [];
  if (typeof document !== 'undefined') {
    const pptxButton = document.getElementById('eduPptxImportBtn');
    if (pptxButton && !$('#eduPdfImportBtn')) {
      const button = document.createElement('button');
      button.type = 'button'; button.id = 'eduPdfImportBtn';
      button.className = 'btn btn-outline-primary btn-sm';
      button.textContent = 'PDF読込';
      button.addEventListener('click', importEduMaterialPdf);
      pptxButton.after(button);
    }
    $('#eduPdfImportBtn')?.classList.toggle('d-none', !roleAtLeast(State.user.role, 'operator'));
  }
  renderEduMaterialRows();
}
// 教材の形式(API の kind)。本の版とスライド版は、題名とページの向きから API が推定する
const EDU_MATERIAL_KIND = { book: '本の版', slide: 'スライド版' };
function eduMaterialSize(material) {
  if (material.format !== 'page_images') return `文字 ${Number(material.slide_count)}枚`;
  const kind = EDU_MATERIAL_KIND[material.kind];
  return `${kind ? `${kind} ` : ''}PDF ${Number(material.page_count)}ページ`;
}
/** 教材の一覧を、形式と題名の絞り込みに合わせて描く(取り直さない)。 */
function renderEduMaterialRows() {
  const all = Cache.eduMaterialList || [];
  const kind = $('#eduMaterialKind')?.value || '';
  const q = String($('#eduMaterialSearch')?.value || '').trim().toLowerCase();
  const list = all.filter((m) => (!kind || m.kind === kind) && (!q || String(m.title).toLowerCase().includes(q)));
  const count = $('#eduMaterialCount');
  if (count && all.length) count.textContent = list.length === all.length ? `${all.length}本` : `${all.length}本中 ${list.length}本`;
  const canEdit = (material) => roleAtLeast(State.user.role, 'operator')
    && (Number(material.is_shared) !== 1 || State.user.role === 'superadmin');
  $('#eduMaterialsBody').innerHTML = list.length ? list.map((material, index) => `
    <tr><td>${index + 1}</td><td>${esc(material.title)}${Number(material.is_shared) === 1 ? ' <span class="badge bg-info">共有</span>' : ''}</td>
      <td class="small text-muted">${esc(material.description || '')}</td><td>${esc(eduMaterialSize(material))}</td>
      <td>${Number(material.delivery_count || 0)}</td>
      <td class="text-nowrap"><button class="btn btn-sm btn-outline-primary" onclick="previewEduMaterial(${material.id})"><i class="bi bi-play-circle"></i> 教材を試行</button>
        ${!canEdit(material) ? '<span class="small text-muted ms-1">閲覧のみ</span>'
          : material.format === 'page_images' ? `<button class="btn btn-sm btn-outline-secondary" onclick="replaceEduMaterialPdf(${material.id})"><i class="bi bi-file-earmark-arrow-up"></i> PDF差し替え</button>`
          : `<button class="btn btn-sm btn-outline-secondary" onclick="editEduMaterial(${material.id})"><i class="bi bi-pencil"></i> 差し替え</button>`}</td></tr>`).join('')
    : all.length ? '<tr><td colspan="6" class="text-center text-muted py-4">条件に合う教材がありません</td></tr>' : emptyRow(6);
}

let eduMaterialPreviewIndex = 0;
function eduMaterialPreviewHtml() {
  return `<div class="alert alert-info py-2 small"><i class="bi bi-eye me-1"></i>プレビューです。受講履歴や採点結果は保存されません。</div>
    <div class="mx-auto" style="max-width:760px">
      <div class="d-flex justify-content-between mb-2"><span id="eduMaterialPreviewProgress" class="small text-muted" aria-live="polite"></span><span class="badge bg-primary">教材試行</span></div>
      <div class="progress mb-3" style="height:6px"><div id="eduMaterialPreviewBar" class="progress-bar" style="width:0%"></div></div>
      <div class="card shadow-sm"><div class="card-body p-4" style="min-height:280px">
        <h4 id="eduMaterialPreviewTitle"></h4><div id="eduMaterialPreviewBody" class="mt-3" style="white-space:pre-wrap;line-height:1.9"></div>
      </div></div>
      <div class="d-flex justify-content-between mt-3"><button id="eduMaterialPreviewPrev" class="btn btn-outline-secondary"><i class="bi bi-chevron-left"></i> 前へ</button>
        <button id="eduMaterialPreviewNext" class="btn btn-primary">次へ <i class="bi bi-chevron-right"></i></button></div>
    </div>`;
}
function renderEduMaterialPreview(material) {
  const pages = material.format === 'page_images' ? material.pages || [] : material.slides || [];
  const page = pages[eduMaterialPreviewIndex];
  $('#eduMaterialPreviewProgress').textContent = `${eduMaterialPreviewIndex + 1} / ${pages.length}`;
  $('#eduMaterialPreviewBar').style.width = `${Math.round((eduMaterialPreviewIndex + 1) * 100 / pages.length)}%`;
  // ページ画像はページ番号が上の進み具合に出るので、見出しは出さない
  $('#eduMaterialPreviewTitle').textContent = material.format === 'page_images' ? '' : page.title;
  const body = $('#eduMaterialPreviewBody');
  body.replaceChildren();
  if (material.format === 'page_images') {
    const img = document.createElement('img');
    img.className = 'edu-page-image'; img.alt = page.page_text;
    img.src = eduMediaUrl('api/edu_materials.php', { action: 'page_image', id: material.id, page: page.page_no, v: material.rev || '' });
    body.appendChild(img);
  } else $('#eduMaterialPreviewBody').textContent = page.body;
  $('#eduMaterialPreviewPrev').disabled = eduMaterialPreviewIndex === 0;
  $('#eduMaterialPreviewNext').innerHTML = eduMaterialPreviewIndex === pages.length - 1
    ? '<i class="bi bi-arrow-counterclockwise"></i> 最初に戻る' : '次へ <i class="bi bi-chevron-right"></i>';
}
let eduMaterialPreviewLoading = false;
let eduMaterialPreviewKeyHandler = null;
async function previewEduMaterial(id) {
  // 「教材を試行」の連打で取得が並走し、キーボードの操作が重複して登録されないようにする
  if (eduMaterialPreviewLoading) return;
  eduMaterialPreviewLoading = true;
  let material;
  try { ({ material } = await api('api/edu_materials.php', { query: { action: 'get', id } })); }
  catch (error) { toast(error.message, 'err'); return; }
  finally { eduMaterialPreviewLoading = false; }
  const pages = material.format === 'page_images' ? material.pages || [] : material.slides || [];
  if (!pages.length) return toast('試行できるページがありません', 'err');
  eduMaterialPreviewIndex = 0;
  showInfoModal(`教材試行: ${material.title}`, eduMaterialPreviewHtml(), { size: 'xl' });
  $('#eduMaterialPreviewPrev').addEventListener('click', () => {
    if (eduMaterialPreviewIndex > 0) eduMaterialPreviewIndex--;
    renderEduMaterialPreview(material);
  });
  $('#eduMaterialPreviewNext').addEventListener('click', () => {
    eduMaterialPreviewIndex = eduMaterialPreviewIndex === pages.length - 1 ? 0 : eduMaterialPreviewIndex + 1;
    renderEduMaterialPreview(material);
  });
  const onKey = (event) => {
    if (!$('#appModal').classList.contains('show') || !$('#eduMaterialPreviewNext')) {
      document.removeEventListener('keydown', onKey); return;
    }
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
      event.preventDefault();
      $(`#eduMaterialPreview${event.key === 'ArrowLeft' ? 'Prev' : 'Next'}`).click();
    }
  };
  if (eduMaterialPreviewKeyHandler) document.removeEventListener('keydown', eduMaterialPreviewKeyHandler);
  eduMaterialPreviewKeyHandler = onKey;
  document.addEventListener('keydown', onKey);
  $('#appModal').addEventListener('hidden.bs.modal', () => {
    document.removeEventListener('keydown', onKey);
    if (eduMaterialPreviewKeyHandler === onKey) eduMaterialPreviewKeyHandler = null;
  }, { once: true });
  renderEduMaterialPreview(material);
}

function importEduMaterialPdf() {
  const body = `<form id="eduPdfImportForm">
    <div class="mb-2"><label class="form-label" for="eduPdfFile">PDFファイル（60MBまで）</label>
      <input class="form-control" id="eduPdfFile" type="file" accept=".pdf,application/pdf" required></div>
    <div class="mb-2"><label class="form-label" for="eduPdfTitle">教材名</label><input class="form-control" id="eduPdfTitle" maxlength="200" required></div>
    <div class="mb-2"><label class="form-label" for="eduPdfDescription">説明（任意）</label><textarea class="form-control" id="eduPdfDescription" maxlength="1000"></textarea></div>
    <div id="eduPdfProgress" class="small text-muted" role="status" aria-live="polite"></div>
  </form>`;
  showModal('PDF教材を読み込む', body, async () => {
    const file = $('#eduPdfFile').files[0];
    if (!file || !/\.pdf$/i.test(file.name)) throw new Error('PDFファイルを選択してください');
    const title = $('#eduPdfTitle').value.trim();
    if (!title) throw new Error('教材名を入力してください');
    const progress = $('#eduPdfProgress');
    try {
      const uploadId = await uploadEduChunks(file, progress);
      progress.textContent = 'PDFを教材に変換しています…';
      const result = await api('api/edu_materials.php', { method: 'POST', query: { action: 'import_pdf' },
        body: { upload_id: uploadId, filename: file.name, title, description: $('#eduPdfDescription').value.trim() }, timeout: 180000 });
      toast(`PDF ${result.material.page_count}ページを読み込みました`, 'ok');
      renderEduMaterials();
    } catch (error) { progress.textContent = `失敗: ${error.message}`; throw error; }
  }, { size: 'lg' });
  $('#eduPdfFile').addEventListener('change', () => {
    const file = $('#eduPdfFile').files[0];
    $('#eduPdfTitle').value = file ? file.name.replace(/\.pdf$/i, '') : '';
  });
}

/** PDF の教材の PDF を差し替える(表紙を足した改訂版など)。教材の id は変わらないので、配信の受講リンクはそのまま使える。 */
function replaceEduMaterialPdf(id) {
  const material = Cache.eduMaterialList.find((m) => Number(m.id) === Number(id));
  if (!material) return;
  const body = `<form id="eduPdfReplaceForm">
    <p class="small">「${esc(material.title)}」（現在 PDF ${Number(material.page_count)}ページ）のページを、新しい PDF に入れ替えます。
      教材名、説明、配信と受講リンクは変わりません。受講中の人には、次にページを開いたときから新しいページが表示されます。</p>
    <div class="mb-2"><label class="form-label" for="eduPdfReplaceFile">新しい PDF ファイル（60MBまで）</label>
      <input class="form-control" id="eduPdfReplaceFile" type="file" accept=".pdf,application/pdf" required></div>
    <div id="eduPdfReplaceProgress" class="small text-muted" role="status" aria-live="polite"></div>
  </form>`;
  showModal('PDFを差し替える', body, async () => {
    const file = $('#eduPdfReplaceFile').files[0];
    if (!file || !/\.pdf$/i.test(file.name)) throw new Error('PDFファイルを選択してください');
    const progress = $('#eduPdfReplaceProgress');
    try {
      const uploadId = await uploadEduChunks(file, progress);
      progress.textContent = 'PDFを変換して差し替えています…';
      const result = await api('api/edu_materials.php', { method: 'POST', query: { action: 'replace_pdf' },
        body: { id: material.id, upload_id: uploadId, filename: file.name }, timeout: 180000 });
      toast(`PDF ${result.material.page_count}ページに差し替えました`, 'ok');
      renderEduMaterials();
    } catch (error) { progress.textContent = `失敗: ${error.message}`; throw error; }
  }, { size: 'lg' });
}

function eduMaterialSlideRow(slide = {}) {
  return `<div class="border rounded p-2 mb-2 edu-material-slide">
    <div class="d-flex justify-content-between mb-2"><strong class="small">スライド</strong><button type="button" class="btn btn-sm btn-outline-danger eduMaterialRemove"><i class="bi bi-trash"></i></button></div>
    <input class="form-control mb-2" name="slide_title" placeholder="スライドタイトル" value="${esc(slide.title || '')}" required>
    <textarea class="form-control" name="slide_body" rows="4" placeholder="本文（改行可）" required>${esc(slide.body || '')}</textarea>
  </div>`;
}

function eduMaterialForm(material = null) {
  const slides = material?.slides?.length ? material.slides : [{ title: '', body: '' }];
  return `<form id="eduMaterialForm">
    <div class="mb-2"><label class="form-label">教材名</label><input class="form-control" name="title" value="${esc(material?.title || '')}" required></div>
    <div class="mb-3"><label class="form-label">説明</label><textarea class="form-control" name="description" rows="2">${esc(material?.description || '')}</textarea></div>
    <div class="d-flex justify-content-between align-items-center mb-2"><label class="form-label mb-0">スライド</label><button type="button" class="btn btn-sm btn-outline-primary" id="eduMaterialAdd"><i class="bi bi-plus-lg"></i> 追加</button></div>
    <div id="eduMaterialSlides">${slides.map(eduMaterialSlideRow).join('')}</div>
  </form>`;
}

function bindEduMaterialEditor() {
  $('#eduMaterialAdd').addEventListener('click', () => $('#eduMaterialSlides').insertAdjacentHTML('beforeend', eduMaterialSlideRow()));
  $('#eduMaterialSlides').addEventListener('click', (event) => {
    const remove = event.target.closest('.eduMaterialRemove');
    if (!remove) return;
    if ($('#eduMaterialSlides').querySelectorAll('.edu-material-slide').length <= 1) return toast('スライドは1枚以上必要です', 'err');
    remove.closest('.edu-material-slide').remove();
  });
}

function readEduMaterialForm() {
  const form = $('#eduMaterialForm');
  const slides = Array.from(form.querySelectorAll('.edu-material-slide')).map((row) => ({
    title: row.querySelector('[name=slide_title]').value.trim(),
    body: row.querySelector('[name=slide_body]').value.trim(),
  }));
  return { title: form.title.value.trim(), description: form.description.value.trim(), slides };
}

function openEduMaterial(material = null) {
  const isEdit = Boolean(material?.id);
  showModal(isEdit ? 'スライド教材の差し替え' : '新規スライド教材', eduMaterialForm(material), async () => {
    const body = readEduMaterialForm();
    if (isEdit) body.id = material.id;
    await api('api/edu_materials.php', { method: 'POST', query: { action: isEdit ? 'update' : 'create' }, body });
    toast(isEdit ? '教材を差し替えました' : '教材を作成しました', 'ok'); renderEduMaterials();
  }, { size: 'lg' });
  bindEduMaterialEditor();
}

function newEduMaterial() { openEduMaterial(); }
function editEduMaterial(id) {
  const material = (Cache.eduMaterialList || []).find((row) => Number(row.id) === Number(id));
  if (material) openEduMaterial(material);
}

function importEduMaterialPptx() {
  const body = `<form id="eduPptxImportForm">
    <div class="alert alert-info py-2 small">PowerPoint（.pptx）の各スライドからタイトルと本文の文字列を取り込みます。画像・動画・アニメーション・レイアウトは取り込みません。</div>
    <label class="form-label" for="eduPptxFile">PowerPointファイル（5MB・50枚まで）</label>
    <input class="form-control" id="eduPptxFile" type="file" accept=".pptx,application/vnd.openxmlformats-officedocument.presentationml.presentation" required>
  </form>`;
  showModal('PowerPoint教材を読み込む', body, async () => {
    const file = $('#eduPptxFile').files[0];
    if (!file || !/\.pptx$/i.test(file.name)) throw new Error('PowerPoint（.pptx）ファイルを選択してください');
    const fileBase64 = await fileToBase64(file);
    const result = await api('api/edu_materials.php', {
      method: 'POST', query: { action: 'import_pptx' }, body: { filename: file.name, file_base64: fileBase64 }, timeout: 60000,
    });
    toast(`${result.slides.length}枚のスライドを読み込みました。内容を確認して保存してください`, 'ok');
    setTimeout(() => openEduMaterial({ title: result.title, description: 'PowerPointから取り込み', slides: result.slides }), 250);
  }, { size: 'lg' });
}

let eduCatFilter = null;
async function renderEduQuestions() {
  await renderEduMaterials();
  const { categories } = await api('api/edu_categories.php', { query: { action: 'list' } });
  Cache.eduCats = categories || [];
  if (eduCatFilter === null) eduCatFilter = 0;
  $('#eduCatTabs').innerHTML = `<li class="nav-item"><a class="nav-link${eduCatFilter===0?' active':''}" href="#" onclick="setEduCat(0);return false">全カテゴリ</a></li>` + (categories || []).map((c) =>
    `<li class="nav-item"><a class="nav-link${c.id===eduCatFilter?' active':''}" href="#" onclick="setEduCat(${c.id});return false">${esc(c.name)} <span class="badge bg-secondary">${c.question_count}</span></a></li>`).join('');
  renderEduCatToolbar();
  await refreshEduTags();   // 分野のタグ(assets/edu-tags.js): タグのタブ、絞り込みの選択肢、設問の編集の選択肢
  const query = { action: 'list' };
  if (eduCatFilter > 0) query.category_id = eduCatFilter;
  if (eduTagFilterId()) query.tag_id = eduTagFilterId();
  const { questions } = await api('api/edu_questions.php', { query });
  cacheRows('eduQuestions', questions || []);
  // #列は表示上の通し番号(古い順に1,2,3…)。削除しても詰まる。
  const questionsAsc = (questions || []).slice().sort((a, b) => a.id - b.id);
  const typeName = { single_choice: '単一選択', true_false: '正誤', multiple_choice: '複数選択' };
  const canEdit = (q) => roleAtLeast(State.user.role, 'operator')
    && (Number(q.is_shared) !== 1 || State.user.role === 'superadmin');
  $('#eduQuestionsBody').innerHTML = questionsAsc.length ? questionsAsc.map((q, i) => `
    <tr><td>${i + 1}</td><td>${esc(q.category_name || '')}</td><td>${esc(q.title)}${Number(q.is_shared)===1?' <span class="badge bg-info">共有</span>':''}${Number(q.is_active)!==1?' <span class="badge bg-secondary">無効</span>':''}${q.image_name ? `<img class="edu-question-thumb ms-2" src="${esc(eduMediaUrl('api/edu_questions.php', { action: 'image', id: q.id }))}" alt="設問画像">` : ''}${eduTagBadges(q.tags)}</td><td>${typeName[q.question_type]||esc(q.question_type)}</td><td>難${q.difficulty}</td>
      <td class="text-nowrap"><button class="btn btn-sm btn-outline-primary" onclick="previewEduQuestion(${q.id})"><i class="bi bi-play-circle"></i> 試行</button>
        ${canEdit(q) ? `
        <button class="btn btn-sm btn-outline-secondary" onclick="editEduQuestion(${q.id})" title="設問を編集"><i class="bi bi-pencil"></i> 編集</button>
        <button class="btn btn-sm btn-outline-danger" onclick="deleteEduQuestion(${q.id})" title="設問を削除"><i class="bi bi-trash"></i> 削除</button>`
        : '<span class="text-muted small ms-1">閲覧のみ</span>'}</td></tr>`).join('') : emptyRow(6);
}
function setEduCat(id) { eduCatFilter = id; renderEduQuestions(); }

function eduPreviewArray(value) {
  if (Array.isArray(value)) return value;
  try { const parsed = JSON.parse(value || '[]'); return Array.isArray(parsed) ? parsed : []; }
  catch (_) { return []; }
}
function eduQuestionPreviewHtml() {
  return `<div class="alert alert-info py-2 small"><i class="bi bi-eye me-1"></i>プレビューです。受講履歴や採点結果は保存されません。</div>
    <div class="mx-auto" style="max-width:760px"><div class="card shadow-sm"><div class="card-body p-4">
      <div id="eduQuestionPreviewTitle" class="fw-bold mb-3"></div><img id="eduQuestionPreviewImage" class="edu-question-image mb-3 d-none" alt="設問画像"><div id="eduQuestionPreviewOptions" class="d-grid gap-2"></div>
      <button id="eduQuestionPreviewCheck" class="btn btn-primary w-100 mt-3">回答を確認</button>
      <div id="eduQuestionPreviewFeedback" class="alert mt-3 mb-0 d-none" style="white-space:pre-wrap" aria-live="polite"></div>
    </div></div></div>`;
}
function renderEduQuestionPreviewOptions(question, selected) {
  const box = $('#eduQuestionPreviewOptions');
  box.innerHTML = '';
  eduPreviewArray(question.options).forEach((option, index) => {
    const button = document.createElement('button');
    button.type = 'button'; button.className = 'btn btn-outline-secondary text-start'; button.textContent = option;
    button.addEventListener('click', () => {
      if (question.question_type !== 'multiple_choice') selected.clear();
      if (selected.has(index)) selected.delete(index); else selected.add(index);
      box.querySelectorAll('button').forEach((item, itemIndex) => item.classList.toggle('active', selected.has(itemIndex)));
      $('#eduQuestionPreviewFeedback').classList.add('d-none');
    });
    box.appendChild(button);
  });
}
function previewEduQuestion(id) {
  const question = Cache.eduQuestions?.[id];
  if (!question) return toast('設問が見つかりません', 'err');
  const selected = new Set();
  showInfoModal('確認テストを試行', eduQuestionPreviewHtml(), { size: 'lg' });
  $('#eduQuestionPreviewTitle').textContent = question.title;
  const image = $('#eduQuestionPreviewImage');
  if (question.image_name) {
    image.src = eduMediaUrl('api/edu_questions.php', { action: 'image', id: question.id });
    image.classList.remove('d-none');
  }
  renderEduQuestionPreviewOptions(question, selected);
  $('#eduQuestionPreviewCheck').addEventListener('click', () => {
    if (!selected.size) return toast('回答を選択してください', 'err');
    const actual = [...selected].sort((a, b) => a - b);
    const correct = eduPreviewArray(question.correct_answer).map(Number).sort((a, b) => a - b);
    const passed = actual.length === correct.length && actual.every((value, index) => value === correct[index]);
    const correctLabels = correct.map((index) => eduPreviewArray(question.options)[index]).filter(Boolean).join(' / ');
    const feedback = $('#eduQuestionPreviewFeedback');
    feedback.className = `alert mt-3 mb-0 ${passed ? 'alert-success' : 'alert-danger'}`;
    const optionExplanations = eduPreviewArray(question.option_explanations);
    const details = eduPreviewArray(question.options).map((option, index) => optionExplanations[index]
      ? `${index + 1}. ${option}: ${optionExplanations[index]}` : '').filter(Boolean).join('\n');
    feedback.textContent = `${passed ? '正解です' : '不正解です'}\n正解: ${correctLabels}${question.explanation ? `\n解説: ${question.explanation}` : ''}${details ? `\n選択肢ごとの解説:\n${details}` : ''}`;
  });
}

async function previewEduQuestionsWithAnswers() {
  const { questions } = await api('api/edu_questions.php', { query: { action: 'list' } });
  const typeName = { single_choice: '単一選択', true_false: '正誤', multiple_choice: '複数選択' };
  const cards = (questions || []).map((question, questionIndex) => {
    const options = eduPreviewArray(question.options);
    const correct = new Set(eduPreviewArray(question.correct_answer).map(Number));
    const optionExplanations = eduPreviewArray(question.option_explanations);
    const optionList = options.map((option, optionIndex) =>
      `<li class="list-group-item"><div class="d-flex justify-content-between gap-2"><span>${optionIndex + 1}. ${esc(option)}</span>${correct.has(optionIndex) ? '<span class="badge bg-success">正解</span>' : ''}</div>${optionExplanations[optionIndex] ? `<div class="small text-muted mt-1 edu-option-explanation">${esc(optionExplanations[optionIndex])}</div>` : ''}</li>`).join('');
    return `<section class="card mb-3"><div class="card-header d-flex justify-content-between gap-2">
      <strong>${questionIndex + 1}. ${esc(question.title)}</strong><span class="text-muted small text-nowrap">${esc(question.category_name || '')} / ${typeName[question.question_type] || esc(question.question_type)} / 難${question.difficulty}</span>
      </div>${question.image_name ? `<img class="edu-question-image m-3" src="${esc(eduMediaUrl('api/edu_questions.php', { action: 'image', id: question.id }))}" alt="設問画像">` : ''}<ul class="list-group list-group-flush">${optionList}</ul>
      <div class="card-footer small"><strong>解説:</strong> ${esc(question.explanation || '（なし）')}</div></section>`;
  }).join('');
  showInfoModal('確認テスト 全設問・回答付き一覧', cards || '<p class="text-muted">設問がありません。</p>', { size: 'xl' });
}

function exportEduQuestionsXlsx() {
  window.location.href = eduDownloadUrl('export_xlsx');
}

function downloadEduQuestionsTemplate() {
  window.location.href = eduDownloadUrl('template_xlsx');
}

function importEduQuestionsXlsx() {
  const body = `<form id="eduXlsxImportForm">
    <div class="alert alert-info py-2 small">テンプレートの列名を変更せず、1行につき1設問を入力してください。不正な行が1つでもある場合は全件を取り込みません。</div>
    <label class="form-label" for="eduXlsxFile">Excelファイル（.xlsx、5MB・1000設問まで）</label>
    <input class="form-control mb-2" id="eduXlsxFile" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
    <label class="form-label" for="eduXlsxImagesZip">画像のZIP（任意・60MBまで）</label>
    <input class="form-control" id="eduXlsxImagesZip" type="file" accept=".zip,application/zip">
    <div id="eduXlsxProgress" class="small text-muted mt-2" role="status" aria-live="polite"></div>
  </form>`;
  showModal('確認テスト設問をExcelから追加', body, async () => {
    const file = $('#eduXlsxFile').files[0];
    if (!file || !/\.xlsx$/i.test(file.name)) throw new Error('Excel（.xlsx）ファイルを選択してください');
    const fileBase64 = await fileToBase64(file);
    const zip = $('#eduXlsxImagesZip').files[0];
    if (zip && !/\.zip$/i.test(zip.name)) throw new Error('画像のZIPファイルを選択してください');
    const imagesUploadId = zip ? await uploadEduChunks(zip, $('#eduXlsxProgress')) : undefined;
    $('#eduXlsxProgress').textContent = '設問を取り込んでいます…';
    let result;
    try {
      result = await api('api/edu_questions.php', {
        method: 'POST', query: { action: 'import_xlsx' }, body: { filename: file.name, file_base64: fileBase64, images_upload_id: imagesUploadId }, timeout: 60000,
      });
    } catch (error) {
      await discardEduUpload(imagesUploadId);   // 取込に失敗したら、送った画像の ZIP も残さない
      throw error;
    }
    toast(`${result.imported}問を追加しました`, 'ok');
    eduCatFilter = 0;
    renderEduQuestions();
  }, { size: 'lg' });
}

/* ---- カテゴリ管理 ---- */
/* 選択中カテゴリの操作ツールバー。共有カテゴリはコピー(fork)、テナント固有は編集/削除。 */
function renderEduCatToolbar() {
  const el = $('#eduCatToolbar'); if (!el) return;
  const c = (Cache.eduCats || []).find((x) => x.id === eduCatFilter);
  if (!c) { el.innerHTML = ''; return; }
  const isShared = Number(c.is_shared) === 1;
  const canManage = roleAtLeast(State.user.role, 'operator');
  const canEditShared = State.user.role === 'superadmin';
  let btns = '';
  if (isShared) {
    if (canManage) btns += `<button class="btn btn-sm btn-outline-success" onclick="forkEduCategory(${c.id})"><i class="bi bi-files me-1"></i>自組織にコピー</button>`;
    if (canEditShared) btns += ` <button class="btn btn-sm btn-outline-secondary" onclick="editEduCategory(${c.id})"><i class="bi bi-pencil me-1"></i>編集</button>`;
  } else if (canManage) {
    btns += `<button class="btn btn-sm btn-outline-secondary" onclick="editEduCategory(${c.id})"><i class="bi bi-pencil me-1"></i>編集</button>`;
    btns += ` <button class="btn btn-sm btn-outline-danger" onclick="deleteEduCategory(${c.id})"><i class="bi bi-trash me-1"></i>削除</button>`;
  }
  const tag = isShared ? '<span class="badge bg-info">共有教材</span>' : '<span class="badge bg-primary">自組織</span>';
  el.innerHTML = btns ? `<div class="d-flex align-items-center gap-2 small text-muted">${tag}<span>${esc(c.name)}（設問 ${c.question_count}）</span>${btns}</div>` : '';
}
function eduCategoryForm(c = {}) {
  return `<form id="eduCatForm">
    <div class="mb-2"><label class="form-label">カテゴリ名</label><input class="form-control" name="name" value="${esc(c.name)}" required></div>
    <div class="mb-2"><label class="form-label">スラッグ（英小文字・数字・ハイフン）</label><input class="form-control" name="slug" value="${esc(c.slug)}" ${c.id?'readonly':'required'} pattern="[a-z0-9-]+" placeholder="例: cloud-security"></div>
    <div class="mb-2"><label class="form-label">色（任意・#rrggbb）</label><input class="form-control" name="color" value="${esc(c.color)}" placeholder="#4f46e5"></div>
    ${c.id?`<div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="eduCatActive"${Number(c.is_active)===1?' checked':''}><label class="form-check-label" for="eduCatActive">有効</label></div>`:''}
  </form>`;
}
function newEduCategory() {
  showModal('新規カテゴリ', eduCategoryForm({}), async () => {
    const f = $('#eduCatForm');
    const name = f.name.value.trim(), slug = f.slug.value.trim();
    if (!name || !slug) { toast('カテゴリ名とスラッグは必須です', 'err'); return false; }
    if (!/^[a-z0-9-]+$/.test(slug)) { toast('スラッグは英小文字・数字・ハイフンのみです', 'err'); return false; }
    const body = { name, slug };
    const color = f.color.value.trim(); if (color) body.color = color;
    await api('api/edu_categories.php', { method: 'POST', query: { action: 'create' }, body });
    toast('作成しました', 'ok'); eduCatFilter = null; renderEduQuestions();
  });
}
function editEduCategory(id) {
  const c = (Cache.eduCats || []).find((x) => x.id === id); if (!c) return;
  showModal('カテゴリ編集', eduCategoryForm(c), async () => {
    const f = $('#eduCatForm');
    const body = { id, name: f.name.value.trim(), is_active: f.is_active.checked ? 1 : 0 };
    const color = f.color.value.trim(); if (color) body.color = color;
    await api('api/edu_categories.php', { method: 'POST', query: { action: 'update' }, body });
    toast('更新しました', 'ok'); renderEduQuestions();
  });
}
async function deleteEduCategory(id) {
  const c = (Cache.eduCats || []).find((x) => x.id === id); if (!c) return;
  if (!confirm(`カテゴリ「${c.name}」を削除しますか？（設問が残っていると削除できません）`)) return;
  try {
    await api('api/edu_categories.php', { method: 'POST', query: { action: 'delete' }, body: { id } });
    toast('削除しました', 'ok'); eduCatFilter = null; renderEduQuestions();
  } catch (e) { /* api() が 409 等を toast 表示 */ }
}
async function forkEduCategory(id) {
  const c = (Cache.eduCats || []).find((x) => x.id === id); if (!c) return;
  if (!confirm(`共有教材「${c.name}」を自組織にコピーしますか？（設問ごと複製され、編集可能になります）`)) return;
  try {
    const r = await api('api/edu_categories.php', { method: 'POST', query: { action: 'fork' }, body: { id } });
    toast('コピーしました', 'ok');
    eduCatFilter = r.category ? r.category.id : null;
    renderEduQuestions();
  } catch (e) { /* api() が表示 */ }
}

/* 設問フォーム: 選択肢は改行区切りテキスト、正答は0始まりindex(複数はカンマ区切り) */
function eduQuestionForm(q = {}) {
  const opts = Array.isArray(q.options) ? q.options : (q.options ? JSON.parse(q.options) : []);
  const correct = Array.isArray(q.correct_answer) ? q.correct_answer : (q.correct_answer ? JSON.parse(q.correct_answer) : []);
  const type = q.question_type || 'single_choice';
  const cats = (Cache.eduCats || []).filter((c) => Number(c.is_shared) !== 1 || State.user.role === 'superadmin');
  const catOpts = cats.map((c) => `<option value="${c.id}"${Number(q.category_id)===c.id?' selected':''}>${esc(c.name)}</option>`).join('');
  const typeOpts = [['single_choice','単一選択'],['true_false','正誤'],['multiple_choice','複数選択']]
    .map(([v,l]) => `<option value="${v}"${type===v?' selected':''}>${l}</option>`).join('');
  const explanations = eduPreviewArray(q.option_explanations);
  const selectedCat = cats.find((c) => c.id === Number(q.category_id)) || cats[0];
  return `<form id="eduQForm">
    <div class="mb-2"><label class="form-label">カテゴリ</label><select class="form-select" name="category_id" required>${catOpts}</select></div>
    <div class="mb-2"><label class="form-label">設問文</label><input class="form-control" name="title" value="${esc(q.title)}" required></div>
    ${eduTagPickerHtml((q.tags || []).map((t) => Number(t.id)), Number(selectedCat?.is_shared) === 1)}
    <div class="mb-2"><label class="form-label">種別</label><select class="form-select" name="question_type">${typeOpts}</select></div>
    <div class="mb-2"><label class="form-label">選択肢（1行に1つ）</label><textarea class="form-control" name="options" rows="4">${esc(opts.join('\n'))}</textarea></div>
    <div class="mb-2"><label class="form-label">選択肢ごとの解説（任意）</label><div id="eduQOptionExplanations">${eduQExplanationRows(opts, explanations)}</div></div>
    <div class="mb-2"><label class="form-label" for="eduQImageFile">設問画像（PNG・JPEG、5MB以内）</label>
      <input class="form-control" id="eduQImageFile" type="file" accept="image/png,image/jpeg,.png,.jpg,.jpeg">
      <div id="eduQImagePreview" class="mt-2${q.image_name ? '' : ' d-none'}"> <img class="edu-question-image" ${q.image_name ? `src="${esc(eduMediaUrl('api/edu_questions.php', { action: 'image', id: q.id }))}"` : ''} alt="設問画像のプレビュー">
        <button type="button" class="btn btn-sm btn-outline-danger ms-2" id="eduQImageRemove">画像を外す</button></div></div>
    <div class="mb-2"><label class="form-label">正答の番号（1始まり・複数選択はカンマ区切り）</label><input class="form-control" name="correct" value="${esc(correct.map((i)=>Number(i)+1).join(','))}" placeholder="例: 1"></div>
    <div class="mb-2"><label class="form-label">難易度（1〜3）</label><input class="form-control" name="difficulty" type="number" min="1" max="3" value="${q.difficulty||1}"></div>
    <div class="mb-2"><label class="form-label">解説</label><textarea class="form-control" name="explanation" rows="2">${esc(q.explanation)}</textarea></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="eduQActive"${q.id===undefined||Number(q.is_active)===1?' checked':''}><label class="form-check-label" for="eduQActive">有効（出題対象にする）</label></div>
  </form>`;
}
function eduQExplanationRows(options, explanations = []) {
  return options.map((option, index) => `<div class="mb-2"><label class="form-label small">${index + 1}. ${esc(option)}：この選択肢の解説</label>
    <textarea class="form-control edu-option-explanation-input" rows="2" maxlength="2000">${esc(explanations[index] || '')}</textarea></div>`).join('');
}
function bindEduQuestionForm() {
  const form = $('#eduQForm');
  // 共有カテゴリの設問には共有のタグだけを付けられるので、カテゴリを変えたらタグの選択肢を選び直す
  form.category_id.addEventListener('change', () => {
    const cat = (Cache.eduCats || []).find((c) => c.id === Number(form.category_id.value));
    eduTagPickerRefresh(Number(cat?.is_shared) === 1);
  });
  form.options.addEventListener('input', () => {
    const explanations = Array.from(form.querySelectorAll('.edu-option-explanation-input')).map((field) => field.value);
    const options = form.options.value.split('\n').map((value) => value.trim()).filter(Boolean);
    $('#eduQOptionExplanations').innerHTML = eduQExplanationRows(options, explanations);
  });
  $('#eduQImageFile').addEventListener('change', async (event) => {
    const file = event.target.files[0]; if (!file) return;
    if (file.size > 5_000_000 || !['image/png', 'image/jpeg'].includes(file.type)) {
      event.target.value = ''; return toast('画像は5MB以内のPNGかJPEGを選択してください', 'err');
    }
    try {
      const base64 = await fileToBase64(file);
      $('#eduQImagePreview img').src = `data:${file.type};base64,${base64}`;
      $('#eduQImagePreview').classList.remove('d-none');
      $('#eduQImagePreview').dataset.removed = '';
    } catch (error) { toast(error.message, 'err'); }
  });
  $('#eduQImageRemove').addEventListener('click', () => {
    $('#eduQImageFile').value = '';
    $('#eduQImagePreview img').removeAttribute('src');
    $('#eduQImagePreview').classList.add('d-none');
    $('#eduQImagePreview').dataset.removed = '1';
  });
}
/* フォーム値 → API body。選択肢テキストと正答番号を配列化して検証する。 */
function eduQFormBody(f) {
  const options = f.options.value.split('\n').map((s) => s.trim()).filter((s) => s !== '');
  if (options.length < 2) { toast('選択肢は2つ以上入力してください', 'err'); return null; }
  const correct = f.correct.value.split(',').map((s) => parseInt(s.trim(), 10) - 1).filter((n) => !Number.isNaN(n));
  if (correct.length === 0) { toast('正答の番号を入力してください', 'err'); return null; }
  if (correct.some((i) => i < 0 || i >= options.length)) { toast('正答の番号が選択肢の範囲外です', 'err'); return null; }
  const explanations = Array.from(f.querySelectorAll('.edu-option-explanation-input')).map((field) => field.value.trim());
  return {
    category_id: parseInt(f.category_id.value, 10),
    title: f.title.value.trim(),
    question_type: f.question_type.value,
    options,
    option_explanations: explanations.some(Boolean) ? explanations : [],
    correct_answer: correct,
    difficulty: parseInt(f.difficulty.value, 10) || 1,
    explanation: f.explanation.value.trim(),
    is_active: f.is_active.checked ? 1 : 0,
    tag_ids: eduTagPickerSelected(),
  };
}
async function saveEduQuestionImage(id) {
  const file = $('#eduQImageFile').files[0];
  if (file) {
    const fileBase64 = await fileToBase64(file);
    await api('api/edu_questions.php', { method: 'POST', query: { action: 'upload_image' }, body: { id, file_base64: fileBase64 } });
  } else if ($('#eduQImagePreview').dataset.removed === '1') {
    await api('api/edu_questions.php', { method: 'POST', query: { action: 'remove_image' }, body: { id } });
  }
}
function newEduQuestion() {
  if (!Cache.eduCats || !Cache.eduCats.length) { toast('先にカテゴリを用意してください', 'err'); return; }
  const preset = eduCatFilter ? { category_id: eduCatFilter } : {};
  let savedId = null;
  showModal('新規設問', eduQuestionForm(preset), async () => {
    const body = eduQFormBody($('#eduQForm')); if (!body) return false;
    if (savedId) {
      await api('api/edu_questions.php', { method: 'POST', query: { action: 'update' }, body: { ...body, id: savedId } });
    } else {
      const result = await api('api/edu_questions.php', { method: 'POST', query: { action: 'create' }, body });
      savedId = result.question.id;
    }
    await saveEduQuestionImage(savedId);
    toast('作成しました', 'ok'); renderEduQuestions();
  });
  bindEduQuestionForm();
}
function editEduQuestion(id) {
  const q = Cache.eduQuestions[id]; if (!q) return;
  showModal('設問編集', eduQuestionForm(q), async () => {
    const body = eduQFormBody($('#eduQForm')); if (!body) return false;
    body.id = q.id;
    await api('api/edu_questions.php', { method: 'POST', query: { action: 'update' }, body });
    await saveEduQuestionImage(q.id);
    toast('更新しました', 'ok'); renderEduQuestions();
  });
  bindEduQuestionForm();
}
async function deleteEduQuestion(id) {
  const q = Cache.eduQuestions[id]; if (!q) return;
  if (!confirm(`設問「${q.title}」を削除しますか？`)) return;
  try {
    await api('api/edu_questions.php', { method: 'POST', query: { action: 'delete' }, body: { id } });
    toast('削除しました', 'ok'); renderEduQuestions();
  } catch (e) { /* api() が 409 等を toast 表示 */ }
}

/* ========== セキュリティ教育: レポート ========== */
// タブ(概要、配信ごと、受講者ごと、訓練と教育)の状態。配信ごとの詳細は、同じタブの中で一覧と切り替える
const eduRep = { tab: 'overview', deliveryId: null, sub: 'people', incomplete: false, q: '', learnerQ: '', personId: null, campaignId: null,
  aw: { deliveryId: '', from: '', to: '' } };
function resetEduRep() { Object.assign(eduRep, { deliveryId: null, personId: null, campaignId: null, incomplete: false, q: '', learnerQ: '', aw: { deliveryId: '', from: '', to: '' } }); }
const EDU_ASSIGN_STATUS = {
  assigned: ['未受講', 'bg-light text-dark border'], started: ['受講中', 'bg-info text-dark'],
  completed: ['完了', 'bg-success'], expired: ['期限切れ', 'bg-secondary'],
};
function eduAssignStatusBadge(status) {
  const [label, cls] = EDU_ASSIGN_STATUS[status] || [status, 'bg-light text-dark border'];
  return `<span class="badge ${cls}">${esc(label)}</span>`;
}
function eduRate(v) { return v === null || v === undefined ? '—' : `${esc(String(v))}%`; }
// 合否: 点数のない人(未受講)と合格点のない配信は「—」。期限の後の合格は注記する
function eduPassedCell(p) {
  if (p.passed === null || p.passed === undefined || p.score === null) return '—';
  if (!p.passed) return '<span class="text-danger">不合格</span>';
  return `<span class="text-success">合格</span>${p.on_time === false ? ' <span class="small text-muted">期限後</span>' : ''}`;
}
function eduNoteRow(cols, text) { return `<tr><td colspan="${cols}" class="text-center text-muted py-4">${esc(text)}</td></tr>`; }
/** 表を「読み込み中」にしてから load の返す HTML で埋める。失敗は理由と再読み込み。後から来た古い応答は捨てる。 */
async function eduRepFill(selector, load) {
  const tb = $(selector);
  if (!tb) return;
  const token = `${Date.now()}-${Math.random()}`;
  tb.dataset.req = token;
  tb.innerHTML = loadingRow(tableColumns(tb));
  try {
    const html = await load(() => tb.dataset.req === token);
    if (tb.dataset.req === token) tb.innerHTML = html;
  } catch (e) {
    if (tb.dataset.req === token) tb.innerHTML = errorRow(tableColumns(tb), e.message);
  }
}
function eduRepCsvUrl(action, extra = {}) {
  const params = new URLSearchParams({ action, id: String(eduRep.deliveryId), format: 'csv', ...extra });
  if (State.user?.role === 'superadmin' && State.activeTenantId) params.set('tenant_id', State.activeTenantId);
  return `api/edu_report.php?${params}`;
}
/** 解答の CSV を落とす。上限で打ち切った時は X-Tet2-Truncated が来るので知らせる。 */
async function eduRepDownloadAnswers(params, fallbackName) {
  const qs = new URLSearchParams({ action: 'answers', ...params });
  if (State.user?.role === 'superadmin' && State.activeTenantId) qs.set('tenant_id', State.activeTenantId);
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), 60000);
  try {
    const res = await fetch(`api/edu_report.php?${qs}`, { credentials: 'same-origin', signal: ctrl.signal });
    if (!res.ok) {
      const j = await res.json().catch(() => ({}));
      throw new Error(j.error || `HTTP ${res.status}`);
    }
    const truncated = res.headers.get('X-Tet2-Truncated');
    const name = (res.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/)?.[1] || fallbackName;
    const url = URL.createObjectURL(await res.blob());
    const a = document.createElement('a');
    a.href = url; a.download = name;
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
    if (truncated) toast(`行が多いため、先頭の ${Number(truncated).toLocaleString()} 行で打ち切りました。期間を短くして分けて出してください`, 'warn', 10000);
    else toast('CSV を出力しました', 'ok');
  } catch (e) {
    toast(`出力に失敗しました（${e.name === 'AbortError' ? '時間切れ' : e.message}）`, 'err');
  } finally { clearTimeout(timer); }
}
async function downloadAllAnswersCsv() {
  const from = $('#eduRepAnswersFrom').value;
  const to = $('#eduRepAnswersTo').value;
  if (!from || !to) return toast('解答の CSV は、提出日の期間（始まりと終わり）を選んでください', 'err');
  const btn = $('#eduRepAnswersCsvBtn');
  btn.disabled = true;
  try { await eduRepDownloadAnswers({ from, to }, 'edu_answers.csv'); } finally { btn.disabled = false; }
}
async function downloadDeliveryAnswersCsv() {
  const btn = $('#eduRepAnswersDeliveryCsv');
  btn.disabled = true;
  try { await eduRepDownloadAnswers({ id: String(eduRep.deliveryId) }, `edu_delivery_${eduRep.deliveryId}_answers.csv`); } finally { btn.disabled = false; }
}
function eduRepPeopleFilter() {
  return { ...(eduRep.incomplete ? { incomplete: '1' } : {}), ...(eduRep.q ? { q: eduRep.q } : {}) };
}

async function renderEduReport() {
  const overview = renderEduReportOverview();
  const tab = $(`#eduRepTab-${eduRep.tab}`);
  // タブを切り替えると shown.bs.tab でそのタブを読む。今のタブのままなら、ここで読み直す
  if (tab && !tab.classList.contains('active')) bootstrap.Tab.getOrCreateInstance(tab).show();
  else await eduRepRenderTab(eduRep.tab);
  await overview;
}
function eduRepRenderTab(tab) {
  if (tab === 'deliveries') return eduRep.deliveryId ? renderEduRepDetail() : renderEduRepDeliveries();
  if (tab === 'people') return renderEduRepLearners();
  if (tab === 'awareness') return renderEduRepAwareness();
  if (tab === 'cross') return renderEduRepCross();
  if (tab === 'tags') return renderEduRepTags();   // assets/edu-tags.js
  return Promise.resolve();
}

function renderEduRepDeliveries() {
  $('#eduRepDeliveryList').classList.remove('d-none');
  $('#eduRepDeliveryDetail').classList.add('d-none');
  return eduRepFill('#eduRepDeliveriesBody', async () => {
    const { deliveries } = await api('api/edu_report.php', { query: { action: 'deliveries' } });
    return (deliveries || []).length ? deliveries.map((d) => `
      <tr><td>${esc(d.title)}<div class="small text-muted">${esc(EDU_DTYPE[d.delivery_type] || d.delivery_type)}</div></td>
        <td>${eduStatusCell(d)}</td><td>${esc(fmtDate(d.scheduled_at || d.created_at))}</td>
        <td>${d.deadline ? esc(fmtDate(d.deadline)) : 'なし'}</td>
        <td>${Number(d.assigned)}</td><td>${Number(d.completed)}</td><td>${Number(d.incomplete)}</td>
        <td>${eduRate(d.pass_rate)}</td><td>${eduRate(d.on_time_pass_rate)}</td>
        <td><button type="button" class="btn btn-sm btn-outline-primary" onclick="openEduRepDelivery(${Number(d.id)})"><i class="bi bi-list-check" aria-hidden="true"></i> 詳細</button></td></tr>`).join('')
      : emptyRow(10);
  });
}
// 配信を選ぶ。前に開いた配信の絞り込み(未完了だけ、検索)は持ち越さない
function selectEduRepDelivery(id) {
  Object.assign(eduRep, { deliveryId: Number(id), q: '' });
  $('#eduRepPeopleSearch').value = '';
  setEduRepIncomplete(false);
}
function openEduRepDelivery(id) {
  selectEduRepDelivery(id);
  renderEduRepDetail();
  $('#eduRepBackBtn').focus();
}
function closeEduRepDelivery() {
  eduRep.deliveryId = null;
  renderEduRepDeliveries();
  $('#eduRepTab-deliveries').focus();
}
function setEduRepIncomplete(on) {
  eduRep.incomplete = on;
  for (const b of $$('[data-edu-rep-incomplete]')) {
    const active = (b.dataset.eduRepIncomplete === '1') === on;
    b.classList.toggle('active', active);
    b.setAttribute('aria-pressed', String(active));
  }
}
function renderEduRepDetail() {
  $('#eduRepDeliveryList').classList.add('d-none');
  $('#eduRepDeliveryDetail').classList.remove('d-none');
  const jobs = [renderEduRepPeople()];
  if (eduRep.sub === 'depts') jobs.push(renderEduRepDepts());
  if (eduRep.sub === 'questions') jobs.push(renderEduRepQuestions());
  if (eduRep.sub === 'auto') jobs.push(renderEduRepAuto());
  return Promise.all(jobs);
}
function renderEduRepDetailHeader(delivery, s) {
  $('#eduRepDetailTitle').textContent = delivery.title;
  $('#eduRepDetailMeta').textContent = [EDU_DTYPE[delivery.delivery_type] || delivery.delivery_type,
    delivery.pass_score !== null ? `合格点 ${delivery.pass_score}%` : '合格点なし',
    `期限 ${delivery.deadline ? fmtDate(delivery.deadline) : 'なし'}`].join(' ・ ');
  // 自動の投入の履歴は、自動の配信(訓練の失敗、新入社員)だけに出す
  const auto = Object.hasOwn(EDU_AUTO_SOURCE, delivery.triggered_by || '');
  $('#eduRepSubItem-auto').classList.toggle('d-none', !auto);
  if (!auto && eduRep.sub === 'auto') bootstrap.Tab.getOrCreateInstance($('#eduRepSub-people')).show();
  $('#eduRepDetailKpi').innerHTML = [
    { label: '完了 / 対象', value: `${s.completed} / ${s.assigned}`, icon: 'bi-clipboard-check' },
    { label: '未完了', value: s.incomplete, icon: 'bi-hourglass-split', cls: s.incomplete > 0 ? 'val-warning' : '' },
    { label: '合格率', value: s.pass_rate === null ? '—' : `${s.pass_rate}%`, icon: 'bi-award' },
    { label: '期限内合格率', value: s.on_time_pass_rate === null ? '—' : `${s.on_time_pass_rate}%`, icon: 'bi-alarm' },
  ].map(kpiCard).join('');
}
function renderEduRepPeople() {
  const id = eduRep.deliveryId;
  $('#eduRepPeopleCsv').href = eduRepCsvUrl('delivery_people', eduRepPeopleFilter());
  return eduRepFill('#eduRepPeopleBody', async (current) => {
    const r = await api('api/edu_report.php', { query: { action: 'delivery_people', id, ...eduRepPeopleFilter() } });
    if (!current() || eduRep.deliveryId !== id) return '';
    renderEduRepDetailHeader(r.delivery, r.summary);
    if (!(r.people || []).length) return eduNoteRow(8, eduRep.incomplete || eduRep.q ? '条件に合う受講者がいません' : 'データがありません');
    return r.people.map((p) => `
      <tr><td>${eduAssignStatusBadge(p.status)}</td>
        <td>${esc(p.name || p.email)}${Number(p.is_test) === 1 ? ' <span class="badge bg-light text-dark border">テスト</span>' : ''}<div class="small text-muted">${esc(p.email)}</div></td>
        <td>${esc(p.department)}</td><td>${p.score === null ? '—' : `${Number(p.score)}%`}</td>
        <td>${eduPassedCell(p)}</td><td>${Number(p.attempt_count)}</td>
        <td>${p.deadline ? esc(fmtDate(p.deadline)) : 'なし'}</td><td>${esc(fmtDate(p.completed_at))}${p.late ? ' <span class="small text-muted">（期限後）</span>' : ''}${p.material_version ? ` <span class="small text-muted">教材v${Number(p.material_version)}</span>` : ''}</td></tr>`).join('');
  });
}
function renderEduRepDepts() {
  const id = eduRep.deliveryId;
  return eduRepFill('#eduRepDeptsBody', async () => {
    const level = deptLevelQuery(await deptLevelFor('eduRepDeptsLevel'));
    $('#eduRepDeptsCsv').href = eduRepCsvUrl('delivery_depts', level);
    const r = await api('api/edu_report.php', { query: { action: 'delivery_depts', id, ...level } });
    return (r.departments || []).length ? r.departments.map((d) => `
      <tr><td>${esc(d.department)}</td><td>${Number(d.assigned)}</td><td>${Number(d.completed)}</td><td>${Number(d.incomplete)}</td>
        <td>${d.passed === null ? '—' : Number(d.passed)}</td><td>${eduRate(d.pass_rate)}</td><td>${eduRate(d.on_time_pass_rate)}</td></tr>`).join('')
      : emptyRow(7);
  });
}
function renderEduRepQuestions() {
  const id = eduRep.deliveryId;
  return eduRepFill('#eduRepQuestionsBody', async () => {
    const r = await api('api/edu_report.php', { query: { action: 'delivery', id } });
    const s = r.summary;
    $('#eduRepQuestionsNote').textContent = `回答者 ${s.respondent_count}人 ・ 平均正答率 ${s.average_score}%${s.pass_rate !== null ? ` ・ 合格率(回答者のうち) ${s.pass_rate}%` : ''}`;
    return (r.by_question || []).length ? r.by_question.map((q) => `
      <tr><td>${esc(q.title)}</td><td>難${Number(q.difficulty)}</td><td>${Number(q.correct)}/${Number(q.answered)}</td><td>${eduRate(q.correct_rate)}</td></tr>`).join('')
      : emptyRow(4);
  });
}

const EDU_AUTO_SOURCE = { phishing_failure: '訓練の失敗', new_target: '新入社員' };
function renderEduRepAuto() {
  const id = eduRep.deliveryId;
  const load = api('api/edu_report.php', { query: { action: 'auto_runs', id } });
  const runs = eduRepFill('#eduRepAutoRunsBody', async () => {
    const r = await load;
    if (!(r.runs || []).length) return eduNoteRow(6, 'まだ実行の記録がありません');
    return r.runs.map((x) => `
      <tr><td class="text-nowrap">${esc(fmtDate(x.started_at))}</td><td class="text-nowrap">${esc(fmtDate(x.finished_at))}</td>
        <td>${esc(EDU_AUTO_SOURCE[x.source] || x.source)}</td><td>${Number(x.matched_count)}人</td><td>${Number(x.enrolled_count)}人</td>
        <td>${x.error ? `<span class="text-danger">${esc(x.error)}</span>` : '<span class="text-success">正常</span>'}</td></tr>`).join('');
  });
  const learners = eduRepFill('#eduRepAutoLearnersBody', async () => {
    const r = await load;
    if (!(r.learners || []).length) return eduNoteRow(5, '当てはまった人はまだいません');
    return r.learners.map((p) => `
      <tr><td>${esc(p.name || p.email)}${Number(p.is_test) === 1 ? ' <span class="badge bg-light text-dark border">テスト</span>' : ''}${p.archived ? ' <span class="badge bg-secondary">削除済</span>' : ''}<div class="small text-muted">${esc(p.email)}</div></td>
        <td>${esc(p.employee_no || '')}</td><td>${esc(p.department)}</td><td class="text-nowrap">${esc(fmtDate(p.matched_at))}</td><td>${eduAssignStatusBadge(p.status)}</td></tr>`).join('');
  });
  return Promise.all([runs, learners]);
}

function eduRepAwFilter() {
  const f = eduRep.aw;
  return { ...(f.deliveryId ? { delivery_id: f.deliveryId } : {}), ...(f.from ? { from: f.from } : {}), ...(f.to ? { to: f.to } : {}) };
}
function eduRepAwCsvUrl() {
  const params = new URLSearchParams({ action: 'awareness_people', format: 'csv', ...eduRepAwFilter() });
  if (State.user?.role === 'superadmin' && State.activeTenantId) params.set('tenant_id', State.activeTenantId);
  return `api/edu_report.php?${params}`;
}
async function renderEduRepAwareness() {
  const select = $('#eduRepAwDelivery');
  try {
    const { deliveries } = await api('api/edu_report.php', { query: { action: 'deliveries' } });
    const aw = (deliveries || []).filter((d) => d.delivery_type === 'awareness_quiz');
    if (!aw.some((d) => String(d.id) === eduRep.aw.deliveryId)) eduRep.aw.deliveryId = '';
    select.innerHTML = '<option value="">すべてのアウェアネスの配信</option>' + aw.map((d) =>
      `<option value="${Number(d.id)}"${String(d.id) === eduRep.aw.deliveryId ? ' selected' : ''}>${esc(d.title)}（${esc(fmtDate(d.scheduled_at || d.created_at))}）</option>`).join('');
  } catch (e) { /* 配信の選択肢が読めなくても、全部の配信の成績は出せる */ }
  return renderEduRepAwarenessRows();
}
function renderEduRepAwarenessRows() {
  $('#eduRepAwCsv').href = eduRepAwCsvUrl();
  const filter = eduRepAwFilter();
  return eduRepFill('#eduRepAwBody', async (current) => {
    const r = await api('api/edu_report.php', { query: { action: 'awareness_people', ...filter } });
    if (!current()) return '';
    const s = r.summary || {};
    $('#eduRepAwNote').textContent = `受講者 ${Number(s.learners || 0)}人 ・ 設問 ${Number(s.total || 0)} ・ 正解 ${Number(s.correct || 0)} ・ 不正解 ${Number(s.incorrect || 0)} ・ 未回答 ${Number(s.unanswered || 0)}`;
    if (!(r.people || []).length) return eduNoteRow(8, Object.keys(filter).length ? '条件に合う受講者がいません' : 'アウェアネスの配信の割当がまだありません');
    return r.people.map((p) => `
      <tr><td>${esc(p.name || p.email)}<div class="small text-muted">${esc(p.email)}${p.employee_no ? ` ・ ${esc(p.employee_no)}` : ''}</div></td>
        <td>${esc(p.department)}</td><td>${Number(p.completed)} / ${Number(p.deliveries)}</td><td>${Number(p.total)}</td>
        <td>${Number(p.correct)}</td><td>${Number(p.incorrect)}</td><td>${Number(p.unanswered)}</td><td>${eduRate(p.correct_rate)}</td></tr>`).join('');
  });
}

function renderEduRepLearners() {
  const learners = eduRepFill('#eduRepLearnersBody', async () => {
    const { learners: rows } = await api('api/edu_report.php', { query: { action: 'learners', q: eduRep.learnerQ } });
    if (!(rows || []).length) return eduNoteRow(5, eduRep.learnerQ ? '条件に合う受講者がいません' : 'データがありません');
    return rows.map((p) => `
      <tr${p.id === eduRep.personId ? ' class="table-active"' : ''}><td>${esc(p.name || p.email)}<div class="small text-muted">${esc(p.email)}</div></td>
        <td>${esc(p.department)}</td><td>${Number(p.completed)} / ${Number(p.assigned)}</td><td>${Number(p.incomplete)}</td>
        <td><button type="button" class="btn btn-sm btn-outline-primary" onclick="openEduRepPerson(${Number(p.id)})" aria-label="${esc(p.name || p.email)} の受講状況を見る">見る</button></td></tr>`).join('');
  });
  return Promise.all([learners, renderEduRepPerson()]);
}
function openEduRepPerson(id) {
  eduRep.personId = Number(id);
  for (const tr of $$('#eduRepLearnersBody tr')) tr.classList.toggle('table-active', tr.querySelector(`[onclick="openEduRepPerson(${Number(id)})"]`) !== null);
  renderEduRepPerson();
}
function renderEduRepPerson() {
  const id = eduRep.personId;
  if (!id) {
    $('#eduRepPersonTitle').textContent = '受講者を選んでください';
    $('#eduRepPersonBody').innerHTML = eduNoteRow(7, '左の一覧から受講者を選ぶと、配信ごとの状況が出ます');
    return Promise.resolve();
  }
  return eduRepFill('#eduRepPersonBody', async (current) => {
    const r = await api('api/edu_report.php', { query: { action: 'person', target_id: id } });
    if (!current()) return '';
    $('#eduRepPersonTitle').textContent = `${r.person.name || r.person.email}（${r.person.department}）`;
    return (r.deliveries || []).length ? r.deliveries.map((p) => `
      <tr><td>${esc(p.delivery_title)}<div class="small text-muted">${esc(EDU_DTYPE[p.delivery_type] || p.delivery_type)}</div></td>
        <td>${eduAssignStatusBadge(p.status)}</td><td>${p.score === null ? '—' : `${Number(p.score)}%`}</td>
        <td>${eduPassedCell(p)}</td><td>${Number(p.attempt_count)}</td>
        <td>${p.deadline ? esc(fmtDate(p.deadline)) : 'なし'}</td><td>${esc(fmtDate(p.completed_at))}${p.late ? ' <span class="small text-muted">（期限後）</span>' : ''}${p.material_version ? ` <span class="small text-muted">教材v${Number(p.material_version)}</span>` : ''}</td></tr>`).join('')
      : emptyRow(7);
  });
}

async function renderEduRepCross() {
  const select = $('#eduRepCrossCampaign');
  const { campaigns } = await api('api/campaigns.php', { query: { action: 'list' } }).catch((e) => {
    $('#eduRepCrossBody').innerHTML = errorRow(6, e.message);
    return { campaigns: null };
  });
  if (!campaigns) return;
  if (!campaigns.length) {
    select.innerHTML = '<option value="">キャンペーンがありません</option>';
    $('#eduRepCrossBody').innerHTML = emptyRow(6);
    return;
  }
  if (!campaigns.some((c) => Number(c.id) === eduRep.campaignId)) eduRep.campaignId = Number(campaigns[0].id);
  select.innerHTML = campaigns.map((c) => `<option value="${Number(c.id)}"${Number(c.id) === eduRep.campaignId ? ' selected' : ''}>${esc(c.name)}${Number(c.is_test) === 1 ? '（テスト）' : ''}</option>`).join('');
  await renderEduRepCrossResult();
}
function renderEduRepCrossResult() {
  const campaignId = eduRep.campaignId;
  return eduRepFill('#eduRepCrossBody', async () => {
    const { cross: c } = await api('api/edu_report.php', { query: { action: 'cross', campaign_id: campaignId } });
    return `<tr><td>${Number(c.failer_count)}人</td><td>${Number(c.educated_count)}人</td><td>${eduRate(c.education_coverage_rate)}</td>
      <td>${Number(c.completed_count)}人</td><td>${eduRate(c.education_completion_rate)}</td><td>${eduRate(c.average_score_after)}</td></tr>`;
  });
}

let eduRepSearchTimer = null;
function eduRepDebounce(fn) {
  clearTimeout(eduRepSearchTimer);
  eduRepSearchTimer = setTimeout(fn, 300);
}
// 教育レポートと教材バンクのタブ、絞り込み、戻るボタン(起動時に1回だけつなぐ)
function bindEduReportControls() {
  $$('#eduReportTabs [data-edu-rep-tab]').forEach((tab) => tab.addEventListener('shown.bs.tab', () => {
    eduRep.tab = tab.dataset.eduRepTab;
    if (eduRep.tab === 'overview') eduTrendChart?.resize();
    else eduRepRenderTab(eduRep.tab);
  }));
  $$('#eduRepDetailTabs [data-edu-rep-sub]').forEach((tab) => tab.addEventListener('shown.bs.tab', () => {
    eduRep.sub = tab.dataset.eduRepSub;
    if (eduRep.sub === 'depts') renderEduRepDepts();
    if (eduRep.sub === 'questions') renderEduRepQuestions();
    if (eduRep.sub === 'auto') renderEduRepAuto();
  }));
  $('#eduRepAnswersCsvBtn')?.addEventListener('click', downloadAllAnswersCsv);
  $('#eduRepAnswersDeliveryCsv')?.addEventListener('click', downloadDeliveryAnswersCsv);
  $('#eduRepAwDelivery')?.addEventListener('change', (e) => { eduRep.aw.deliveryId = e.target.value; renderEduRepAwarenessRows(); });
  for (const [sel, key] of [['#eduRepAwFrom', 'from'], ['#eduRepAwTo', 'to']]) {
    $(sel)?.addEventListener('change', (e) => { eduRep.aw[key] = e.target.value; renderEduRepAwarenessRows(); });
  }
  $('#eduRepBackBtn')?.addEventListener('click', closeEduRepDelivery);
  $$('[data-edu-rep-incomplete]').forEach((b) => b.addEventListener('click', () => {
    setEduRepIncomplete(b.dataset.eduRepIncomplete === '1');
    renderEduRepPeople();
  }));
  $('#eduRepPeopleSearch')?.addEventListener('input', (e) => eduRepDebounce(() => {
    eduRep.q = e.target.value.trim();
    renderEduRepPeople();
  }));
  $('#eduRepLearnerSearch')?.addEventListener('input', (e) => eduRepDebounce(() => {
    eduRep.learnerQ = e.target.value.trim();
    renderEduRepLearners();
  }));
  $('#eduRepCrossCampaign')?.addEventListener('change', (e) => {
    eduRep.campaignId = Number(e.target.value);
    renderEduRepCrossResult();
  });
  $('#eduMaterialKind')?.addEventListener('change', renderEduMaterialRows);
  $('#eduMaterialSearch')?.addEventListener('input', renderEduMaterialRows);
}

/** 部署別ランキングの行。部署のまとめ方(C3)を変えた時は、この表だけを取り直す。 */
function fillEduReportDeptRank(rows) {
  $('#eduReportDeptBody').innerHTML = (rows || []).length ? rows.map((d) =>
    `<tr><td>${esc(d.department)}</td><td>${d.average_score}%</td><td>${d.respondent_count}</td></tr>`).join('') : emptyRow(3);
}
async function renderEduReportDeptRank() {
  const level = await deptLevelFor('eduReportDeptLevel');
  try {
    const r = await api('api/edu_report.php', { query: { action: 'overview', ...deptLevelQuery(level) } });
    fillEduReportDeptRank(r.by_department);
  } catch (e) { toast(e.message, 'err'); }
}
async function renderEduReportOverview() {
  const level = await deptLevelFor('eduReportDeptLevel');
  const r = await api('api/edu_report.php', { query: { action: 'overview', ...deptLevelQuery(level) } });
  const s = r.summary;
  // eラーニング(合格制)とアウェアネス(小問)は点数の意味が違うので、平均点を分けて出す。テスト用と削除済みの対象者は数えない
  const el = (r.by_type || {}).elearning || {};
  const aw = (r.by_type || {}).awareness_quiz || {};
  const pct = (v) => (v === null || v === undefined ? '—' : `${v}%`);
  $('#eduReportKpi').innerHTML = [
    { label: '受講完了率（全体）', value: `${s.completion_rate}%`, icon: 'bi-check2-circle' },
    { label: `eラーニングの平均点（合格率 ${pct(el.pass_rate)}）`, value: pct(el.average_score), icon: 'bi-mortarboard' },
    { label: `アウェアネスの平均点（回答 ${Number(aw.respondent_count || 0)}件）`, value: pct(aw.average_score), icon: 'bi-lightbulb' },
    { label: '割当 / 完了', value: `${s.assigned} / ${s.completed}`, icon: 'bi-people' },
  ].map(kpiCard).join('');
  fillEduReportDeptRank(r.by_department);
  $('#eduReportCatBody').innerHTML = (r.by_category || []).filter((c) => c.answered > 0).length ?
    r.by_category.filter((c) => c.answered > 0).map((c) =>
      `<tr><td>${esc(c.name)}</td><td>${c.correct_rate}%</td><td>${c.answered}</td></tr>`).join('') : emptyRow(3);
  await renderEduTrend();
}

let eduTrendChart = null;
/** 月ごとの平均点の推移(受講の記録から集計)。eラーニングとアウェアネスを別の線にする。 */
async function renderEduTrend() {
  let t;
  try { t = await api('api/edu_report.php', { query: { action: 'trend' } }); }
  catch (e) { return; }
  const months = t.months || [];
  const series = t.series || {};
  const note = $('#eduTrendNote');
  if (eduTrendChart) { eduTrendChart.destroy(); eduTrendChart = null; }
  if (!months.length) {
    if (note) note.textContent = '受講の記録がまだありません';
    return;
  }
  if (note) note.textContent = `${months.length}か月分（受講を終えた月で集計）`;
  const lines = [
    ['elearning', 'eラーニングの平均点（%）', '#2563eb', 'rgba(37,99,235,.12)'],
    ['awareness_quiz', 'アウェアネスの平均点（%）', '#067647', 'rgba(6,118,71,.12)'],
  ];
  eduTrendChart = new Chart($('#eduTrendChart'), {
    type: 'line',
    data: {
      labels: months,
      datasets: lines.map(([type, label, color, fill]) => ({
        label, data: (series[type] || []).map((p) => p.average_score),
        borderColor: color, backgroundColor: fill, fill: false, tension: 0.25, pointRadius: 3, spanGaps: true,
      })),
    },
    options: {
      responsive: true, maintainAspectRatio: true,
      scales: { y: { beginAtZero: true, max: 100, ticks: { callback: (v) => v + '%' } } },
      plugins: { legend: { display: true, position: 'bottom' },
        tooltip: { callbacks: { afterLabel: (ctx) => {
          const p = (series[lines[ctx.datasetIndex][0]] || [])[ctx.dataIndex] || {};
          return `回答者 ${Number(p.respondents || 0)} 名 / 完了 ${Number(p.completions || 0)} 件`;
        } } } },
    },
  });
}

/* ========== ユーザ管理 ========== */
async function renderUsers() {
  await renderTargets();
  if (!roleAtLeast(State.user.role, 'tenant_admin')) return;
  await renderAdminUsers();
}

async function renderAdminUsers() {
  const { users, password_policy: policy } = await api('api/users.php', { query: { action: 'list' } });
  cacheRows('users', users);
  if (policy) State.passwordPolicy = policy;
  // #列は表示上の通し番号(古い順に1,2,3…)。削除しても詰まる。
  const usersAsc = users.slice().sort((a, b) => a.id - b.id);
  $('#usersBody').innerHTML = usersAsc.length ? usersAsc.map((u, i) => {
    const pending = Number(u.password_pending) === 1;
    const mailLabel = pending ? '招待メールを送る' : 'パスワード再設定のメールを送る';
    return `
    <tr data-user-id="${u.id}"><td>${i + 1}</td><td>${esc(u.email)}</td><td>${esc(u.name)}</td><td>${roleLabel(u.role)}</td>
      <td>${u.status==='active'?'<span class="badge bg-success">有効</span>':'<span class="badge bg-secondary">停止</span>'}</td>
      <td class="text-nowrap" data-col="last-login">${u.last_login_at ? esc(fmtDate(u.last_login_at)) : '<span class="text-muted">なし</span>'}</td>
      <td data-col="password">${userPasswordCell(u)}</td>
      <td data-col="mfa">${u.mfa_enabled_at ? '<span class="badge user-badge-mfa">有効</span>' : '<span class="text-muted small">未登録</span>'}</td>
      <td class="text-nowrap">
        <button class="btn btn-sm btn-outline-secondary" onclick="editUser(${u.id})" title="ユーザを編集" aria-label="ユーザを編集"><i class="bi bi-pencil" aria-hidden="true"></i></button>
        ${u.mfa_enabled_at && u.id !== State.user.id ? `<button class="btn btn-sm btn-outline-secondary" data-action="mfa-reset" onclick="resetUserMfa(${u.id})" title="多要素認証を解除" aria-label="多要素認証を解除"><i class="bi bi-shield-x" aria-hidden="true"></i></button>` : ''}
        <button class="btn btn-sm btn-outline-secondary" data-action="send-password-mail" onclick="sendUserPasswordMail(${u.id})" title="${mailLabel}" aria-label="${mailLabel}"${u.status==='active'?'':' disabled'}><i class="bi bi-envelope" aria-hidden="true"></i></button>
        <button class="btn btn-sm btn-outline-danger" onclick="deleteUser(${u.id})" title="ユーザを削除" aria-label="ユーザを削除"><i class="bi bi-trash" aria-hidden="true"></i></button>
      </td></tr>`;
  }).join('') : emptyRow(9);
  renderSecurityPolicy();
}
// パスワードの列: 設定済み、または「未設定」(送ったリンクの期限。なければ未送信か期限切れ)
function userPasswordCell(u) {
  if (Number(u.password_pending) !== 1) return '<span class="text-muted small">設定済み</span>';
  const note = u.password_link_expires_at
    ? `リンクの期限 ${esc(fmtDate(u.password_link_expires_at))}`
    : '有効なリンクなし。メールを送ってください';
  return `<span class="badge user-badge-pending">パスワード未設定</span><div class="small text-muted">${note}</div>`;
}
function userRoleOptions(cur) {
  const roles = State.user.role === 'superadmin'
    ? [['tenant_admin','組織管理者'],['operator','オペレータ'],['viewer','閲覧者']]
    : [['operator','オペレータ'],['viewer','閲覧者']];
  return roles.map(([v,l])=>`<option value="${v}"${cur===v?' selected':''}>${l}</option>`).join('');
}
// サーバの PasswordPolicy::DESCRIPTION と同じ文(一覧の API が返した値があればそちらを使う)
const PASSWORD_POLICY_TEXT = '12文字以上で、英大文字・英小文字・数字・記号のうち3種類以上を含めてください';
function userForm(u = {}, isNew = true) {
  const policy = esc(State.passwordPolicy || PASSWORD_POLICY_TEXT);
  const passwordField = `<div class="mb-2" id="userPasswordField"${isNew ? ' hidden' : ''}><label class="form-label" for="ufPassword">${isNew ? '初期パスワード' : 'パスワード（変更時のみ入力）'}</label>
      <input class="form-control" id="ufPassword" name="password" type="password" autocomplete="new-password" aria-describedby="ufPasswordHelp">
      <div class="form-text" id="ufPasswordHelp">${policy}</div></div>`;
  return `<form id="userForm">
    <div class="mb-2"><label class="form-label" for="ufEmail">メール</label><input class="form-control" id="ufEmail" name="email" type="email" value="${esc(u.email)}" ${isNew?'required':'readonly'}></div>
    <div class="mb-2"><label class="form-label" for="ufName">氏名</label><input class="form-control" id="ufName" name="name" value="${esc(u.name)}"${isNew?' required':''}></div>
    <div class="mb-2"><label class="form-label" for="ufRole">ロール</label><select class="form-select" id="ufRole" name="role">${userRoleOptions(u.role)}</select></div>
    ${isNew ? `<fieldset class="mb-2"><legend class="form-label fs-6 mb-1">パスワード</legend>
      <div class="form-check"><input class="form-check-input" type="radio" name="pwmode" id="ufModeInvite" value="invite" checked>
        <label class="form-check-label" for="ufModeInvite">パスワード設定のメールを送る（推奨）</label></div>
      <div class="form-text ms-4 mb-1">1回だけ使える設定用のリンク（72時間有効）を、ご本人のメールへ送ります。</div>
      <div class="form-check"><input class="form-check-input" type="radio" name="pwmode" id="ufModePassword" value="password">
        <label class="form-check-label" for="ufModePassword">初期パスワードを入れる（ご本人へは別の方法で伝える）</label></div>
    </fieldset>` : ''}
    ${passwordField}
    ${!isNew?`<div class="mb-2"><label class="form-label" for="ufStatus">状態</label><select class="form-select" id="ufStatus" name="status"><option value="active"${u.status==='active'?' selected':''}>有効</option><option value="suspended"${u.status==='suspended'?' selected':''}>停止</option></select></div>`:''}
  </form>`;
}
function newUser() {
  showModal('新規ユーザ', userForm({}, true), async () => {
    const f = $('#userForm');
    const invite = f.pwmode.value === 'invite';
    if (!invite && !f.password.value) {
      markModalField(f.password, '初期パスワードを入力してください');
      f.password.focus();
      const err = new Error('初期パスワードを入力してください'); err.handled = true; throw err;
    }
    const body = { email: f.email.value.trim(), name: f.name.value.trim(), role: f.role.value };
    if (invite) body.send_invite = true; else body.password = f.password.value;
    const r = await api('api/users.php', { method: 'POST', query: { action: 'create' }, body });
    if (r.invite && !r.invite.sent) {
      toast(`ユーザは作りましたが、招待メールを送れませんでした（${r.invite.error}）。一覧から送り直してください`, 'err', 10000);
    } else {
      toast(invite ? '作成し、パスワード設定のメールを送りました' : '作成しました', 'ok');
    }
    renderAdminUsers();
  });
  // 「初期パスワードを入れる」を選んだ時だけ入力欄を出す
  const f = $('#userForm');
  f.querySelectorAll('input[name="pwmode"]').forEach((radio) => radio.addEventListener('change', () => {
    const show = f.pwmode.value === 'password';
    $('#userPasswordField').hidden = !show;
    f.password.required = show;
  }));
}
function editUser(id) {
  const u = Cache.users[id];
  if (!u) return;
  showModal('ユーザ編集', userForm(u, false), async () => {
    const f = $('#userForm');
    const body = { id: u.id, name: f.name.value.trim(), role: f.role.value, status: f.status.value };
    if (f.password.value) body.password = f.password.value;
    await api('api/users.php', { method: 'POST', query: { action: 'update' }, body });
    toast('更新しました', 'ok'); renderAdminUsers();
  });
}
async function sendUserPasswordMail(id) {
  const u = Cache.users[id];
  if (!u) return;
  const pending = Number(u.password_pending) === 1;
  const what = pending ? '招待（パスワード設定）のメール' : 'パスワード再設定のメール';
  if (!confirm(`${u.email} へ${what}を送りますか？\n前に送ったリンクは使えなくなります。`)) return;
  try {
    const r = await api('api/users.php', { method: 'POST', query: { action: 'send_password_mail' }, body: { id } });
    toast(`${what}を送りました（リンクの期限 ${fmtDate(r.expires_at)}）`, 'ok', 6000);
    renderAdminUsers();
  } catch (e) { toast(e.message, 'err', 10000); }
}
async function deleteUser(id) {
  if (!confirm('このユーザを削除しますか？')) return;
  try { await api('api/users.php', { method: 'POST', query: { action: 'delete' }, body: { id } });
    toast('削除しました', 'ok'); renderAdminUsers(); } catch (e) { toast(e.message, 'err'); }
}
// ユーザの CSV 一括登録(列は email、name、role)。パスワードは入れず、招待メールを送るかを選ぶ。
function importUsersCsv() {
  const body = `<p class="small text-muted mb-2">1行目に見出し <code>email,name,role</code>（日本語の「メールアドレス,氏名,ロール」も可）。role は viewer（閲覧者）、operator（オペレータ）、tenant_admin（組織管理者）のどれか（付けられるのは、あなたが付けられる役割だけ）。パスワードは入れず、「パスワード未設定」で作ります。</p>
    <div class="mb-2"><label class="form-label" for="userCsvFile">CSV ファイル</label><input class="form-control" type="file" id="userCsvFile" accept=".csv,text/csv"></div>
    <div class="mb-2"><label class="form-label" for="userCsvText">または貼り付け</label>
      <textarea class="form-control" id="userCsvText" rows="6" placeholder="email,name,role&#10;hanako@example.com,山田花子,operator"></textarea></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="userCsvInvite" checked>
      <label class="form-check-label" for="userCsvInvite">登録した人に、パスワード設定のメールを送る</label></div>
    <div class="form-text">送らない場合は、後で一覧の <i class="bi bi-envelope" aria-hidden="true"></i> から1人ずつ送れます。</div>`;
  showModal('ユーザの CSV 一括登録', body, async () => {
    const csv = $('#userCsvText').value.trim();
    if (!csv) throw new Error('CSV を選ぶか、貼り付けてください');
    const invite = $('#userCsvInvite').checked;
    const r = await api('api/users.php', { method: 'POST', query: { action: 'import_csv' },
      body: { csv, send_invite: invite }, timeout: 120000 });
    renderAdminUsers();
    const problems = [...(r.errors || []).map((e) => ({ ...e, kind: '登録しなかった' })),
      ...(r.invite_errors || []).map((e) => ({ ...e, kind: 'メールを送れなかった' }))];
    const summary = `登録 ${r.created} 件、飛ばした行 ${r.skipped} 件${invite ? `、招待メール ${r.invited} 件` : ''}`;
    if (!problems.length) { toast(summary, 'ok', 6000); return; }
    // 保存のモーダルが閉じてから、行ごとの結果を出す
    setTimeout(() => showInfoModal('CSV 一括登録の結果', `<p id="userCsvSummary">${esc(summary)}</p>
      <div class="table-responsive"><table class="table table-sm" id="userCsvErrors"><thead><tr><th>行</th><th>内容</th><th>理由</th></tr></thead><tbody>
      ${problems.map((p) => `<tr><td>${Number(p.line)}</td><td>${esc(p.kind)}${p.email ? `（${esc(p.email)}）` : ''}</td><td>${esc(p.reason)}</td></tr>`).join('')}
      </tbody></table></div>`, { size: 'lg' }), 400);
  }, { size: 'lg', saveLabel: '登録する' });
  $('#userCsvFile').addEventListener('change', async (ev) => {
    const file = ev.target.files[0];
    if (!file) return;
    if (file.size > 1024 * 1024) { toast('CSV は1MB以内にしてください', 'err'); ev.target.value = ''; return; }
    // 先頭の BOM はサーバが取り除く
    $('#userCsvText').value = await file.text();
  });
}
async function exportUsersCsv() {
  const btn = $('#exportUsersCsvBtn');
  btn.disabled = true;
  try {
    const qs = new URLSearchParams({ action: 'export_csv' });
    if (State.user && State.user.role === 'superadmin' && State.activeTenantId) qs.set('tenant_id', State.activeTenantId);
    const res = await fetch(`api/users.php?${qs}`, { credentials: 'same-origin' });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const url = URL.createObjectURL(await res.blob());
    const a = document.createElement('a');
    a.href = url;
    a.download = `users_${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
    toast('CSV を出力しました', 'ok');
  } catch (e) { toast(`出力に失敗しました（${e.message}）`, 'err'); }
  finally { btn.disabled = false; }
}

/* ========== テナント管理 ========== */
// 状態の絞り込み。current = 削除済みを除く全部(既定)。削除済みは一覧から分けて出す。
let tenantStatusFilter = 'current';
let tenantDetailId = null;
let tenantDetailRequest = 0;
// 論理削除から完全削除できるまでの日数(サーバの TenantStatus::RETENTION_DAYS。一覧の API の retention_days で上書きする)
let tenantRetentionDays = 90;
const TENANT_STATUS_LABEL = { active: '有効', suspended: '停止中', deleted: '削除済み' };
function tenantStatusBadge(status) {
  const cls = { active: 'bg-success', suspended: 'bg-secondary', deleted: 'bg-danger' }[status] || 'bg-secondary';
  return `<span class="badge ${cls}">${esc(TENANT_STATUS_LABEL[status] || status)}</span>`;
}
function tenantSwitcherLabel(t) {
  return t.status === 'active' ? t.name : `${t.name}（${TENANT_STATUS_LABEL[t.status] || t.status}）`;
}
function tenantRetentionNote(t) {
  if (t.status !== 'deleted') return '';
  if (t.purge_available) return '<div class="small tenant-note-danger">保持期間が過ぎました。完全削除できます</div>';
  return `<div class="small text-muted">完全削除まで あと ${Number(t.retention_days_left ?? 0)} 日</div>`;
}
function tenantContractCell(t) {
  if (!t.contract_end_date) return '<span class="text-muted">—</span>';
  let mark = '';
  if (t.contract_expiring) {
    const left = Number(t.contract_days_left);
    mark = ` <span class="badge tenant-badge-warning" title="契約の終了日が近い、または過ぎています">${left < 0 ? '終了済み' : `あと ${left} 日`}</span>`;
  }
  return `${esc(t.contract_end_date)}${mark}`;
}
function tenantTargetCell(t) {
  const limit = t.target_limit ? ` / ${Number(t.target_limit)}` : '';
  const over = t.over_target_limit ? ' <span class="badge tenant-badge-warning" title="対象者数が上限を超えています">上限超え</span>' : '';
  return `${Number(t.target_count)}${limit}${over}`;
}
function tenantActionButtons(t) {
  const btn = (fn, icon, label, cls = 'btn-outline-secondary') =>
    `<button type="button" class="btn btn-sm ${cls}" onclick="${fn}(${t.id})" title="${label}" aria-label="${label}"><i class="bi ${icon}" aria-hidden="true"></i></button>`;
  if (t.status === 'deleted') {
    return btn('restoreTenant', 'bi-arrow-counterclockwise', 'テナントを復元（停止中に戻す）')
      + (t.purge_available ? ' ' + btn('purgeTenant', 'bi-x-octagon', 'テナントを完全削除', 'btn-outline-danger') : '');
  }
  return btn('editTenant', 'bi-pencil', 'テナントを編集')
    + (t.status === 'suspended' ? ' ' + btn('deleteTenant', 'bi-trash', 'テナントを削除', 'btn-outline-danger') : '');
}
function setTenantStatusFilter(value) {
  tenantStatusFilter = value;
  renderTenantRows();
}
async function renderTenants() {
  const data = await api('api/tenants.php', { query: { action: 'list' } });
  if (State.view !== 'tenants') return;
  State.tenants = data.tenants;
  if (data.retention_days) tenantRetentionDays = Number(data.retention_days);
  cacheRows('tenants', data.tenants);
  refreshTenantSwitcherLabels();
  if (tenantDetailId !== null && Cache.tenants[tenantDetailId]) { await openTenantDetail(tenantDetailId); return; }
  tenantDetailId = null;
  showTenantArea('list');
  renderTenantRows();
}
function renderTenantRows() {
  const counts = { current: 0, active: 0, suspended: 0, deleted: 0 };
  for (const t of State.tenants) {
    counts[t.status] = (counts[t.status] || 0) + 1;
    if (t.status !== 'deleted') counts.current++;
  }
  const filters = [['current', '全部（削除済みを除く）'], ['active', '有効'], ['suspended', '停止中'], ['deleted', '削除済み']];
  $('#tenantStatusFilter').innerHTML = filters.map(([v, label]) => {
    const on = tenantStatusFilter === v;
    return `<button type="button" class="btn btn-outline-secondary${on ? ' active' : ''}" aria-pressed="${on}" data-tenant-filter="${v}" onclick="setTenantStatusFilter('${v}')">${label} <span class="tenant-filter-count">${counts[v] || 0}</span></button>`;
  }).join('');
  const rows = State.tenants
    .filter((t) => (tenantStatusFilter === 'current' ? t.status !== 'deleted' : t.status === tenantStatusFilter))
    .sort((a, b) => a.id - b.id);
  $('#tenantsBody').innerHTML = rows.length ? rows.map((t, i) => `
    <tr data-tenant-id="${t.id}">
      <td>${i + 1}</td>
      <td><button type="button" class="btn btn-link p-0 text-start tenant-name-link" onclick="openTenantDetail(${t.id})">${esc(t.name)}</button>${tenantRetentionNote(t)}</td>
      <td><code>${esc(t.slug)}</code></td>
      <td>${tenantStatusBadge(t.status)}</td>
      <td class="text-end">${Number(t.user_count)}</td>
      <td class="text-end text-nowrap">${tenantTargetCell(t)}</td>
      <td class="text-end text-nowrap">${Number(t.campaign_count)}${t.campaign_active_count ? ` <span class="small text-muted">（実行・予約 ${Number(t.campaign_active_count)}）</span>` : ''}</td>
      <td class="text-end text-nowrap">${Number(t.edu_delivery_count)}${t.edu_active_count ? ` <span class="small text-muted">（実行・予約 ${Number(t.edu_active_count)}）</span>` : ''}</td>
      <td class="text-nowrap">${esc(fmtDate(t.last_sent_at))}</td>
      <td class="text-nowrap">${esc(fmtDate(t.last_login_at))}</td>
      <td class="text-nowrap">${tenantContractCell(t)}</td>
      <td class="text-nowrap">${tenantActionButtons(t)}</td>
    </tr>`).join('') : emptyRow(12);
}
// 上のバーのテナントの切り替えの候補。削除済みは出さない(復元と完全削除はテナント管理の画面で行う)
function tenantSwitcherChoices() { return (State.tenants || []).filter((t) => t.status !== 'deleted'); }
function tenantSwitcherOptions(choices) {
  return choices.map((t) => `<option value="${t.id}">${esc(tenantSwitcherLabel(t))}</option>`).join('');
}
// 上のバーのテナントの切り替えの表示名に、停止中を添える(一覧を読み直した時)
function refreshTenantSwitcherLabels() {
  const sw = $('#tenantSwitcher');
  if (!sw || State.user?.role !== 'superadmin') return;
  const current = State.activeTenantId;
  const choices = tenantSwitcherChoices();
  sw.innerHTML = tenantSwitcherOptions(choices);
  if (current && choices.some((t) => Number(t.id) === Number(current))) sw.value = current;
}
function showTenantArea(area) {
  $('#tenantListArea').classList.toggle('d-none', area !== 'list');
  $('#tenantDetailArea').classList.toggle('d-none', area !== 'detail');
}
function closeTenantDetail() {
  tenantDetailId = null;
  tenantDetailRequest++;
  showTenantArea('list');
  renderTenantRows();
}
// 詳細は同じ画面の中で一覧と入れ替えて出す(削除などの確認が共通のモーダルを使うため、詳細はモーダルにしない)
async function openTenantDetail(id) {
  tenantDetailId = id;
  const requestId = ++tenantDetailRequest;
  const data = await api('api/tenants.php', { query: { action: 'get', id } });
  if (requestId !== tenantDetailRequest || State.view !== 'tenants') return;
  const t = data.tenant;
  Cache.tenants[t.id] = t;
  const stat = (label, value) => `<div class="tenant-stat"><div class="tenant-stat-label">${label}</div><div class="tenant-stat-value">${value}</div></div>`;
  const users = data.users.length ? data.users.map((u) => `
      <tr><td>${esc(u.name)}</td><td>${esc(u.email)}</td><td>${roleLabel(u.role)}</td>
        <td>${u.status === 'active' ? '<span class="badge bg-success">有効</span>' : '<span class="badge bg-secondary">停止</span>'}</td>
        <td class="text-nowrap">${esc(fmtDate(u.last_login_at))}</td></tr>`).join('') : emptyRow(5);
  const audit = data.audit.length ? data.audit.map((a) => `
      <tr><td class="text-nowrap">${esc(fmtDate(a.occurred_at))}</td><td><code>${esc(a.action)}</code></td>
        <td class="small">${esc(a.user_email || '（自動の処理）')}</td><td class="small text-break">${esc(a.detail)}</td></tr>`).join('') : emptyRow(4);
  const actions = [];
  if (t.status !== 'deleted') {
    actions.push(`<button type="button" class="btn btn-sm btn-outline-secondary" onclick="editTenant(${t.id})"><i class="bi bi-pencil" aria-hidden="true"></i> 編集</button>`);
  }
  if (t.status === 'suspended') {
    actions.push(`<button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteTenant(${t.id})"><i class="bi bi-trash" aria-hidden="true"></i> 削除</button>`);
  }
  if (t.status === 'deleted') {
    actions.push(`<button type="button" class="btn btn-sm btn-outline-secondary" onclick="restoreTenant(${t.id})"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> 復元</button>`);
    if (t.purge_available) actions.push(`<button type="button" class="btn btn-sm btn-outline-danger" onclick="purgeTenant(${t.id})"><i class="bi bi-x-octagon" aria-hidden="true"></i> 完全削除</button>`);
  }
  const notice = t.status === 'active' ? '' : `<div class="tenant-notice mb-3" role="note">${t.status === 'deleted'
    ? `このテナントは削除済みです（${esc(fmtDate(t.deleted_at))}）。ユーザはログインできず、自動の処理と送信の操作は止まっています。${t.purge_available ? '保持期間が過ぎたため、完全削除できます。' : `完全削除できるのは あと ${Number(t.retention_days_left ?? 0)} 日後です。`}`
    : 'このテナントは停止中です。ユーザはログインできず、自動の処理と送信の操作は止まっています。送信 worker が処理中の送信は止まりません（止めるにはキャンペーンの緊急停止を使ってください）。'}</div>`;
  $('#tenantDetailArea').innerHTML = `
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="closeTenantDetail()"><i class="bi bi-arrow-left" aria-hidden="true"></i> 一覧に戻る</button>
        <h5 class="mb-0" id="tenantDetailTitle">${esc(t.name)}</h5><code>${esc(t.slug)}</code>${tenantStatusBadge(t.status)}
      </div>
      <div class="d-flex gap-2 flex-wrap">${actions.join('')}
        ${t.status === 'deleted' ? '' : `<button type="button" class="btn btn-sm btn-primary" onclick="switchToTenant(${t.id})"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> このテナントに切り替えて開く</button>`}
      </div>
    </div>
    ${notice}
    <div class="card mb-3"><div class="card-header py-2">概要</div><div class="card-body">
      <div class="tenant-stats">
        ${stat('ユーザ', Number(t.user_count))}
        ${stat('対象者（有効、検証用を除く）', tenantTargetCell(t))}
        ${stat('キャンペーン', `${Number(t.campaign_count)} <span class="small text-muted">（実行・予約 ${Number(t.campaign_active_count)}）</span>`)}
        ${stat('教育配信', `${Number(t.edu_delivery_count)} <span class="small text-muted">（実行・予約 ${Number(t.edu_active_count)}）</span>`)}
        ${stat('最後の送信', esc(fmtDate(t.last_sent_at)))}
        ${stat('最後のログイン', esc(fmtDate(t.last_login_at)))}
      </div>
      <dl class="row small mb-0 mt-3">
        <dt class="col-sm-3">担当者</dt><dd class="col-sm-9">${esc(t.contact_name || '—')}${t.contact_email ? `（${esc(t.contact_email)}）` : ''}</dd>
        <dt class="col-sm-3">契約の終了日</dt><dd class="col-sm-9">${tenantContractCell(t)}</dd>
        <dt class="col-sm-3">対象者数の上限</dt><dd class="col-sm-9">${t.target_limit ? `${Number(t.target_limit)} 人` : '上限なし'}</dd>
        <dt class="col-sm-3">メモ</dt><dd class="col-sm-9 tenant-memo">${esc(t.memo || '—')}</dd>
        <dt class="col-sm-3">作成日</dt><dd class="col-sm-9">${esc(fmtDate(t.created_at))}</dd>
      </dl>
    </div></div>
    <div class="card mb-3"><div class="card-header py-2">ユーザ</div>
      <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
        <thead><tr><th>名前</th><th>メール</th><th>役割</th><th>状態</th><th>最後のログイン</th></tr></thead>
        <tbody id="tenantDetailUsers">${users}</tbody></table></div></div>
    <div class="card mb-3"><div class="card-header py-2">最近の操作（20件）</div>
      <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
        <thead><tr><th>日時</th><th>操作</th><th>操作した人</th><th>内容</th></tr></thead>
        <tbody id="tenantDetailAudit">${audit}</tbody></table></div></div>`;
  showTenantArea('detail');
}
// 既存のテナントの切り替え(上のバーの tenantSwitcher)と同じ手順で、そのテナントの画面を開く
function switchToTenant(id) {
  const sw = $('#tenantSwitcher');
  if (!sw || ![...sw.options].some((o) => Number(o.value) === Number(id))) { toast('切り替え先のテナントが見つかりません', 'err'); return; }
  sw.value = String(id);
  tenantDetailId = null;
  applyActiveTenant(Number(id));
  navigate('dashboard');
}
function tenantForm(t = {}, isNew = true) {
  const v = (key) => esc(t[key] ?? '');
  return `<form id="tenantForm" novalidate>
    <div class="mb-2"><label class="form-label" for="tfName">組織名</label><input class="form-control" id="tfName" name="name" value="${v('name')}" required></div>
    <div class="mb-2"><label class="form-label" for="tfSlug">slug（英小文字・数字・ハイフン）</label><input class="form-control" id="tfSlug" name="slug" value="${v('slug')}" ${isNew ? 'required' : 'readonly'}></div>
    ${!isNew ? `<div class="mb-2"><label class="form-label" for="tfStatus">状態</label><select class="form-select" id="tfStatus" name="status"><option value="active"${t.status === 'active' ? ' selected' : ''}>有効</option><option value="suspended"${t.status === 'suspended' ? ' selected' : ''}>停止</option></select>
      <div class="form-text">停止すると、このテナントのユーザはログインできず、自動の処理と送信の操作も止まります。送信 worker が処理中の送信は止まりません（止めるにはキャンペーンの緊急停止を使います）。</div></div>` : ''}
    <fieldset class="border-top pt-2 mt-3"><legend class="fs-6 fw-semibold">管理の項目（任意）</legend>
      <div class="row g-2">
        <div class="col-md-6 mb-2"><label class="form-label" for="tfContactName">担当者の名前</label><input class="form-control" id="tfContactName" name="contact_name" value="${v('contact_name')}" maxlength="100"></div>
        <div class="col-md-6 mb-2"><label class="form-label" for="tfContactEmail">担当者のメール</label><input class="form-control" id="tfContactEmail" name="contact_email" type="email" value="${v('contact_email')}"></div>
        <div class="col-md-6 mb-2"><label class="form-label" for="tfContractEnd">契約の終了日</label><input class="form-control" id="tfContractEnd" name="contract_end_date" type="date" value="${v('contract_end_date')}"></div>
        <div class="col-md-6 mb-2"><label class="form-label" for="tfTargetLimit">対象者数の上限</label><input class="form-control" id="tfTargetLimit" name="target_limit" type="number" min="1" step="1" value="${t.target_limit ?? ''}" placeholder="空なら上限なし">
          <div class="form-text">超えても登録は止めず、警告を出します。</div></div>
      </div>
      <div class="mb-2"><label class="form-label" for="tfMemo">メモ</label><textarea class="form-control" id="tfMemo" name="memo" rows="2" maxlength="2000">${v('memo')}</textarea></div>
    </fieldset>
    ${isNew ? `<fieldset class="border-top pt-2 mt-3"><legend class="fs-6 fw-semibold">最初の管理者（任意）</legend>
      <div class="form-text mb-2">メールを入れると、このテナントの組織管理者を同時に作ります。初期パスワードは、ユーザの作成と同じく、ここで決めてご本人へ伝えてください（メールは送りません）。</div>
      <div class="row g-2">
        <div class="col-md-6 mb-2"><label class="form-label" for="tfAdminEmail">管理者のメール</label><input class="form-control" id="tfAdminEmail" name="admin_email" type="email"></div>
        <div class="col-md-6 mb-2"><label class="form-label" for="tfAdminName">管理者の名前</label><input class="form-control" id="tfAdminName" name="admin_name"></div>
      </div>
      <div class="mb-2"><label class="form-label" for="tfAdminPassword">初期パスワード</label><input class="form-control" id="tfAdminPassword" name="admin_password" type="password" autocomplete="new-password" aria-describedby="tfAdminPasswordHelp">
        <div class="form-text" id="tfAdminPasswordHelp">${esc(PASSWORD_POLICY_TEXT)}</div></div>
    </fieldset>` : ''}
  </form>`;
}
function collectTenantManagedFields(f) {
  const limit = f.target_limit.value.trim();
  return {
    contact_name: f.contact_name.value.trim(), contact_email: f.contact_email.value.trim(),
    contract_end_date: f.contract_end_date.value, target_limit: limit === '' ? null : Number(limit), memo: f.memo.value.trim(),
  };
}
function newTenant() {
  showModal('新規テナント', tenantForm({}, true), async () => {
    const f = $('#tenantForm');
    const body = { name: f.name.value.trim(), slug: f.slug.value.trim(), ...collectTenantManagedFields(f) };
    if (f.admin_email.value.trim()) {
      Object.assign(body, { admin_email: f.admin_email.value.trim(), admin_name: f.admin_name.value.trim(), admin_password: f.admin_password.value });
    }
    const r = await api('api/tenants.php', { method: 'POST', query: { action: 'create' }, body });
    toast(r.admin_user_id ? '作成しました（最初の管理者も作りました）' : '作成しました', 'ok');
    await setupTenantSwitcher(); renderTenants();
  }, { size: 'lg' });
}
function editTenant(id) {
  const t = Cache.tenants[id];
  if (!t) return;
  showModal('テナント編集', tenantForm(t, false), async () => {
    const f = $('#tenantForm');
    await api('api/tenants.php', { method: 'POST', query: { action: 'update' },
      body: { id: t.id, name: f.name.value.trim(), status: f.status.value, ...collectTenantManagedFields(f) } });
    toast('更新しました', 'ok');
    await setupTenantSwitcher(); renderTenants();
  }, { size: 'lg' });
}
// slug を入力させて確認する(API 側でも一致を確かめる)
function tenantSlugConfirmBody(t, lead) {
  return `${lead}<div class="mt-3"><label class="form-label" for="tenantConfirmSlug">確認のため、slug（<code>${esc(t.slug)}</code>）を入力してください</label>
    <input class="form-control" id="tenantConfirmSlug" autocomplete="off" spellcheck="false"></div>`;
}
function readConfirmSlug(t) {
  const value = $('#tenantConfirmSlug').value.trim();
  if (value !== t.slug) throw new Error('入力した slug が一致しません');
  return value;
}
function deleteTenant(id) {
  const t = Cache.tenants[id];
  if (!t) return;
  const lead = `<p><strong>${esc(t.name)}</strong> を削除します。</p>
    <ul class="small mb-0">
      <li>データは消さずに残し、${tenantRetentionDays} 日間は復元できます（復元すると停止中に戻ります）。</li>
      <li>ユーザはログインできず、自動の処理と送信の操作も止まったままになります。</li>
      <li>${tenantRetentionDays} 日を過ぎると、システム管理者が「完全削除」でデータを消せるようになります（自動では消しません）。</li>
    </ul>`;
  showModal('テナントを削除', tenantSlugConfirmBody(t, lead), async () => {
    const confirmSlug = readConfirmSlug(t);
    await api('api/tenants.php', { method: 'POST', query: { action: 'delete' }, body: { id: t.id, confirm_slug: confirmSlug } });
    toast('削除しました（「削除済み」から復元できます）', 'ok');
    await setupTenantSwitcher(); renderTenants();
  }, { saveLabel: '削除する', saveClass: 'btn-danger' });
}
async function restoreTenant(id) {
  const t = Cache.tenants[id];
  if (!t) return;
  if (!confirm(`${t.name} を復元します。復元すると停止中に戻ります（有効にするのは編集から行います）。よろしいですか？`)) return;
  try {
    await api('api/tenants.php', { method: 'POST', query: { action: 'restore' }, body: { id } });
    toast('復元しました（停止中）', 'ok');
    await setupTenantSwitcher(); renderTenants();
  } catch (e) { toast(e.message, 'err'); }
}
function purgeTenant(id) {
  const t = Cache.tenants[id];
  if (!t) return;
  const lead = `<p class="tenant-note-danger"><strong>${esc(t.name)}</strong> のデータを完全に削除します。元に戻せません。</p>
    <ul class="small mb-0">
      <li>先に DB 全体のバックアップを取ります。</li>
      <li>このテナントの対象者、キャンペーン、教育、アンケートなどの行をすべて消します。監査ログは残します。</li>
      <li>テナントのファイルは消さずに、退避のフォルダ（_deleted）へ移します。</li>
    </ul>`;
  showModal('テナントを完全削除', tenantSlugConfirmBody(t, lead), async () => {
    const confirmSlug = readConfirmSlug(t);
    const r = await api('api/tenants.php', { method: 'POST', query: { action: 'purge' }, body: { id: t.id, confirm_slug: confirmSlug }, timeout: 120000 });
    toast(`完全削除しました（${Number(r.total)} 行）`, 'ok');
    tenantDetailId = null;
    await setupTenantSwitcher(); renderTenants();
  }, { saveLabel: '完全削除する', saveClass: 'btn-danger' });
}

/* ========== モーダル ========== */
let modalInstance = null;
function setAppModalSize(size = null) {
  const dialog = $('#appModal .modal-dialog');
  dialog.classList.remove('modal-sm', 'modal-lg', 'modal-xl', 'modal-fullscreen');
  if (['sm', 'lg', 'xl', 'fullscreen'].includes(size)) dialog.classList.add(`modal-${size}`);
}
let modalOpener = null;
function rememberModalOpener() {
  const active = document.activeElement;
  if (active && active !== document.body && !$('#appModal').contains(active)) modalOpener = active;
}
function showModal(title, bodyHtml, onSave, options = {}) {
  rememberModalOpener();
  setAppModalSize(options.size || null);
  $('#appModalTitle').textContent = title;
  $('#appModalBody').innerHTML = bodyHtml;
  const saveBtn = $('#appModalSave');
  const newBtn = saveBtn.cloneNode(true);
  // 削除などの確認では、ボタンの文言と色を変える(次に開くモーダルへ持ち越さないよう毎回決め直す)
  newBtn.textContent = options.saveLabel || '保存';
  newBtn.className = `btn ${options.saveClass || 'btn-primary'}`;
  saveBtn.parentNode.replaceChild(newBtn, saveBtn);
  newBtn.addEventListener('click', async () => {
    clearModalErrors();
    if (!checkModalRequired()) return;
    newBtn.disabled = true;
    let failed = false;
    try { await onSave(); modalInstance.hide(); }
    catch (e) { failed = true; if (!e.handled) showModalError(e.message); }
    finally {
      newBtn.disabled = false;
      // 失敗した時は、入力欄に移していなければ保存ボタンにフォーカスを戻す(body に落ちると Escape が効かない)
      if (failed && !$('#appModalBody').contains(document.activeElement)) newBtn.focus();
    }
  });
  if (!modalInstance) modalInstance = new bootstrap.Modal($('#appModal'));
  modalInstance.show();
}
// モーダルのフォームのエラーを、起きた欄の下に出す(B3)。
function modalFieldLabel(field) {
  const byFor = field.id ? document.querySelector(`#appModalBody label[for="${CSS.escape(field.id)}"]`) : null;
  const label = byFor || field.closest('.mb-2, .mb-3, [class*="col-"]')?.querySelector('label');
  return (label?.textContent || field.name).replace(/（.*?）|\(.*?\)/g, '').trim();
}
function markModalField(field, message) {
  field.classList.add('is-invalid');
  field.setAttribute('aria-invalid', 'true');
  const fb = document.createElement('div');
  fb.className = 'invalid-feedback modal-field-error';
  fb.textContent = message;
  fb.id = `${field.name || 'field'}-error-${Date.now()}`;
  field.setAttribute('aria-describedby', fb.id);
  field.insertAdjacentElement('afterend', fb);
  field.addEventListener('input', () => {
    field.classList.remove('is-invalid');
    field.removeAttribute('aria-invalid');
    fb.remove();
  }, { once: true });
}
function clearModalErrors() {
  $$('#appModalBody .modal-field-error, #appModalBody .modal-form-error').forEach((el) => el.remove());
  $$('#appModalBody .is-invalid').forEach((el) => { el.classList.remove('is-invalid'); el.removeAttribute('aria-invalid'); });
}
function checkModalRequired() {
  // キャンペーン作成の手順の中の欄は、手順を切り替えて示す専用の確認(validateBeforeSave)に任せる
  const missing = $$('#appModalBody [required]').filter((f) => !f.disabled && f.offsetParent !== null
    && !f.closest('[data-campaign-step]') && !String(f.value).trim());
  for (const f of missing) markModalField(f, `${modalFieldLabel(f)}を入力してください`);
  if (missing.length) missing[0].focus();
  return !missing.length;
}
function showModalError(message) {
  // API の「name は必須です」「slug が不正です」の形なら、該当の欄に出す
  const m = /^([a-z_]+) (は必須です|が不正です|は既に使用されています)$/.exec(String(message));
  const field = m ? $('#appModalBody').querySelector(`[name="${CSS.escape(m[1])}"]`) : null;
  if (field && field.offsetParent !== null) {
    markModalField(field, `${modalFieldLabel(field)}${m[2]}`);
    field.focus();
    return;
  }
  const box = document.createElement('div');
  box.className = 'alert alert-danger py-2 modal-form-error';
  box.setAttribute('role', 'alert');
  box.textContent = message;
  $('#appModalBody').prepend(box);
}
function showInfoModal(title, bodyHtml, options = {}) {
  rememberModalOpener();
  setAppModalSize(options.size || null);
  $('#appModalTitle').textContent = title;
  $('#appModalBody').innerHTML = bodyHtml;
  const saveBtn = $('#appModalSave');
  const newBtn = saveBtn.cloneNode(true);
  saveBtn.parentNode.replaceChild(newBtn, saveBtn);
  newBtn.classList.add('d-none');
  if (!modalInstance) modalInstance = new bootstrap.Modal($('#appModal'));
  modalInstance.show();
}
// 数字のカード(運用ホームと教育レポートで共通)。value は数値か API が返した整形済みの文字列。
function kpiCard(k) {
  return `
    <div class="col-6 col-lg-3">
      <div class="card kpi-card"><div class="card-body d-flex justify-content-between align-items-center">
        <div><div class="kpi-value ${k.cls || ''}">${esc(String(k.value))}</div><div class="kpi-label">${esc(k.label)}</div></div>
        <i class="bi ${k.icon} kpi-icon" aria-hidden="true"></i>
      </div></div>
    </div>`;
}
function emptyRow(cols) { return `<tr><td colspan="${cols}" class="text-center text-muted py-4">データがありません</td></tr>`; }

/* ========== 起動 ========== */
document.addEventListener('DOMContentLoaded', () => {
  window.addEventListener('hashchange', () => {
    if (!State.user) return;
    const view = routeFromHash(window.location.hash);
    const campaignId = campaignIdFromHash(window.location.hash);
    if (view !== State.view || (view === 'campaignWorkspace' && campaignId !== State.campaignWorkspaceId)) {
      State.campaignWorkspaceId = campaignId;
      navigate(view);
    }
  });
  contextHelp = createContextHelp($('#contextHelp'), () => State.view);
  $('#helpToggle').addEventListener('click', () => {
    const opened = !$('#contextHelp').classList.contains('d-none');
    if (opened) contextHelp.close(); else contextHelp.open();
    $('#helpToggle').setAttribute('aria-expanded', String(!opened));
  });
  $('#helpClose').addEventListener('click', () => {
    contextHelp.close();
    $('#helpToggle').setAttribute('aria-expanded', 'false');
    $('#helpToggle').focus();
  });
  $('#appModal').addEventListener('hidden.bs.modal', () => {
    // 閉じたら開いたボタンへ戻す。ボタンが消えていれば中央へ(フォーカスを body に落とさない)
    const target = modalOpener && modalOpener.isConnected && modalOpener.offsetParent !== null ? modalOpener : $('#appMain');
    modalOpener = null;
    if (target === $('#appMain')) target.setAttribute('tabindex', '-1');
    target.focus({ preventScroll: true });
  });
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    // 保存の失敗などでフォーカスがモーダルの外にある時も、Escape でモーダルを閉じる
    if ($('#appModal').classList.contains('show') && !$('#appModal').contains(document.activeElement) && modalInstance) {
      modalInstance.hide();
      return;
    }
    if ($('#sidebar').classList.contains('open')) { setSidebarOpen(false); $('#sidebarToggle').focus(); return; }
    if (!$('#contextHelp').classList.contains('d-none')) $('#helpClose').click();
  });
  $('#loginForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = $('#loginBtn'), spin = $('#loginSpin'), err = $('#loginError');
    err.classList.add('d-none'); btn.disabled = true; spin.classList.remove('d-none');
    try { await login($('#loginEmail').value.trim(), $('#loginPassword').value); }
    catch (ex) { err.textContent = ex.message; err.classList.remove('d-none'); }
    finally { btn.disabled = false; spin.classList.add('d-none'); }
  });
  $('#logoutBtn').addEventListener('click', () => logout());
  $('#mfaEnrollLogout').addEventListener('click', () => logout());
  $('#loginMfaForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = $('#loginMfaBtn'), err = $('#loginMfaError');
    err.classList.add('d-none'); btn.disabled = true;
    try { await submitMfa(); }
    catch (ex) {
      // 期限切れ・ロック・停止は、パスワードの入力からやり直す
      if ([401, 403, 423].includes(ex.status) && !/確認コードが正しくありません/.test(ex.message)) {
        hideMfaStep(); showLoginNotice(ex.message); $('#loginPassword').focus();
      } else { err.textContent = ex.message; err.classList.remove('d-none'); }
    }
    finally { btn.disabled = false; }
  });
  $('#loginRecoveryToggle').addEventListener('click', () => setMfaRecoveryMode(!mfaRecoveryMode));
  $('#loginMfaCancel').addEventListener('click', async () => {
    try { await api('api/auth.php', { method: 'POST', query: { action: 'logout' } }); } catch {}
    hideMfaStep();
    $('#loginPassword').value = '';
    $('#loginPassword').focus();
  });
  $('#sidebarToggle').addEventListener('click', () => setSidebarOpen(!$('#sidebar').classList.contains('open')));
  $('#sidebarBackdrop').addEventListener('click', () => setSidebarOpen(false));
  $$('.app-sidebar .nav-link').forEach((a) => a.addEventListener('click', () => navigate(a.dataset.view)));
  // 引数なしで呼ぶ(click Event が campaignId に渡ると編集モードと誤判定されるため)。
  $('#newCampaignBtn').addEventListener('click', () => openCampaignModal());
  $('#ingestBtn').addEventListener('click', ingestLogs);
  $('#individualsBtn')?.addEventListener('click', toggleIndividuals);
  $('#individualsRefreshBtn')?.addEventListener('click', renderIndividuals);
  $('#toGroupBtn').addEventListener('click', openToGroupModal);
  // 非表示のタブの中のグラフは大きさ0で描かれるので、タブを開いた時に描き直す
  $$('#reportTabs [data-bs-toggle="tab"]').forEach((tab) => tab.addEventListener('shown.bs.tab', () => {
    reportChart?.resize();
    reportTimelineChart?.resize();
  }));
  $('#reportPeriodBtn')?.addEventListener('click', () => { if (reportSelectedId) renderReportDetail(reportSelectedId); });
  $('#reportPeriodClearBtn')?.addEventListener('click', () => {
    $('#reportStartDate').value = ''; $('#reportEndDate').value = '';
    if (reportSelectedId) renderReportDetail(reportSelectedId);
  });
  $('#reportExportBtn')?.addEventListener('click', exportReportXlsx);
  bindEduReportControls();
  $('#reportCommitBtn')?.addEventListener('click', commitReport);
  $('#reportUncommitBtn')?.addEventListener('click', uncommitReport);
  $('#reportCloseBtn')?.addEventListener('click', closeReport);
  $('#logsTabs')?.addEventListener('click', (e) => {
    const a = e.target.closest('.nav-link'); if (!a) return;
    e.preventDefault(); switchLogTab(a.dataset.log);
  });
  for (const selector of ['#reportMailStatus', '#reportMailUnmatched']) {
    $(selector)?.addEventListener('change', () => { logsState.offset = 0; loadLogs(); });
  }
  $('#logsBody')?.addEventListener('click', (e) => {
    const button = e.target.closest('[data-report-decision]');
    if (button) decideReportMail(button);
    const captureButton = e.target.closest('[data-capture-id]');
    if (captureButton) revealCredentialCapture(captureButton);
  });
  $('#credentialRevealDialog')?.addEventListener('close', closeCredentialDialog);
  $('#credentialRevealDialog')?.addEventListener('cancel', closeCredentialDialog);
  $('#credentialRevealDialog button')?.addEventListener('click', closeCredentialDialog);
  document.addEventListener('visibilitychange', () => { if (document.hidden) closeCredentialDialog(); });
  window.addEventListener('pagehide', closeCredentialDialog);
  $('#logsRefreshBtn')?.addEventListener('click', () => { logsState.offset = 0; loadLogs(); });
  $('#logsCampaignFilter')?.addEventListener('change', () => { logsState.offset = 0; loadLogs(); });
  $('#logsPrevBtn')?.addEventListener('click', () => {
    if (logsState.type === 'webaccess') { logsState.webPage = Math.max(1, (logsState.webPage || 1) - 1); }
    else { logsState.offset = Math.max(0, logsState.offset - logsState.limit); }
    loadLogs();
  });
  $('#logsNextBtn')?.addEventListener('click', () => {
    if (logsState.type === 'webaccess') { logsState.webPage = (logsState.webPage || 1) + 1; }
    else { logsState.offset += logsState.limit; }
    loadLogs();
  });
  $('#logsApplyBtn')?.addEventListener('click', () => { logsState.webPage = 1; loadLogs(); });
  $('#logsStatusFilter')?.addEventListener('change', () => loadLogs());
  $('#logsTypeFilter')?.addEventListener('change', () => loadLogs());
  $('#logsInclSystem')?.addEventListener('change', () => loadLogs());
  $('#logsSenderFilter')?.addEventListener('change', () => loadLogs());
  $('#logsKeyword')?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { logsState.webPage = 1; loadLogs(); } });
  $('#logsPathFilter')?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { logsState.webPage = 1; loadLogs(); } });
  // CSV出力はタブ別にディスパッチ。
  $('#logsCsvBtn')?.addEventListener('click', () => {
    switch (logsState.type) {
      case 'training_results': return downloadTrainingResultsCsv();
      case 'training_log_detail': return downloadTrainingLogDetailCsv();
      case 'webaccess': return downloadWebAccessCsv();
      case 'reply_maildir': return downloadReplyMaildirCsv();
      case 'campaign_files': return downloadCampaignFilesCsv();
      default: return downloadTrainingResultsCsv();
    }
  });
  // 生ログ全件DLはタブ(raw_mail/raw_web)別。
  $('#logsRawDlBtn')?.addEventListener('click', () => {
    if (logsState.type === 'raw_mail' || logsState.type === 'raw_web') downloadRawLog(logsState.type);
  });
  // Excel(XLSX)出力はタブ別にディスパッチ。
  $('#logsXlsxBtn')?.addEventListener('click', () => {
    const type = logsState.type;
    const qs = new URLSearchParams({ action: type + '_xlsx' });
    const cid = $('#logsCampaignFilter')?.value; if (cid) qs.set('campaign_id', cid);
    const tid = State.activeTenantId || State.user?.tenant_id;
    if (tid) qs.set('tenant_id', tid);
    // タブ固有のフィルタを引き継ぐ
    if (type === 'training_log_detail') {
      const tp = $('#logsTypeFilter')?.value; if (tp) qs.set('type', tp);
      const sd = logDateVal('#logsStartDate'); if (sd) qs.set('start_date', sd);
      const ed = logDateVal('#logsEndDate'); if (ed) qs.set('end_date', ed);
    }
    if (type === 'webaccess') {
      const sd = logDateVal('#logsStartDate'); if (sd) qs.set('start_date', sd);
      const ed = logDateVal('#logsEndDate'); if (ed) qs.set('end_date', ed);
      const pf = $('#logsPathFilter')?.value.trim(); if (pf) qs.set('path', pf);
    }
    if (type === 'reply_maildir') {
      const sender = $('#logsSenderFilter')?.value; if (sender) qs.set('sender', sender);
      const kw = $('#logsKeyword')?.value.trim(); if (kw) qs.set('q', kw);
      const sd = logDateVal('#logsStartDate'); if (sd) qs.set('start_date', sd);
      const ed = logDateVal('#logsEndDate'); if (ed) qs.set('end_date', ed);
    }
    if (type === 'training_results') {
      const st = $('#logsStatusFilter')?.value; if (st) qs.set('status', st);
    }
    window.open(`api/logs.php?${qs}`, '_blank');
  });
  // 受信メール本文表示ボタン(イベント委譲)。
  $('#logsBody')?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-mailview]'); if (!btn) return;
    const row = btn.closest('tr');
    const cells = row ? row.querySelectorAll('td') : [];
    const meta = cells.length >= 4
      ? `<dt class="col-3">受信日時</dt><dd class="col-9">${cells[0].textContent}</dd>
         <dt class="col-3">差出人</dt><dd class="col-9">${esc(cells[2].textContent)}</dd>
         <dt class="col-3">件名</dt><dd class="col-9">${esc(cells[3].textContent)}</dd>` : '';
    viewMailBody(btn.dataset.account, btn.dataset.file, meta);
  });
  $('#newTargetBtn').addEventListener('click', newTarget);
  $('#importCsvBtn').addEventListener('click', importCsv);
  // click イベントを引数に渡さない(Event オブジェクトが includeArchived=true 扱いになるため)。
  $('#exportCsvBtn')?.addEventListener('click', () => exportTargetsCsv(false));
  $('#exportCsvAllBtn')?.addEventListener('click', () => exportTargetsCsv(true));
  $('#showArchivedTargets')?.addEventListener('change', renderTargets);
  let targetSearchTimer = null;
  $('#targetSearch')?.addEventListener('input', () => {
    clearTimeout(targetSearchTimer);
    targetSearchTimer = setTimeout(renderTargets, 300);
  });
  $('#inviteMyPageBtn')?.addEventListener('click', () => inviteMyPage($$('#targetsBody .tgt-select:checked').map((el) => Number(el.value))));
  $('#targetsSelectAll')?.addEventListener('change', (ev) => { $$('#targetsBody .tgt-select').forEach((el) => { el.checked = ev.target.checked; }); });
  $('#newGroupBtn').addEventListener('click', newGroup);
  $('#newScenarioBtn').addEventListener('click', newScenario);
$('#newTemplateBtn').addEventListener('click', newTemplate);
  $('#tplSearch').addEventListener('input', () => {
    if (State.view === 'templates') renderTemplateRows();
  });
  $('#tplExportBtn')?.addEventListener('click', exportTemplatesCsv);
  $('#tplImportAddBtn')?.addEventListener('click', () => importTemplatesCsv('add'));
  $('#tplImportUpsertBtn')?.addEventListener('click', () => importTemplatesCsv('upsert'));
  $('#newEduDeliveryBtn').addEventListener('click', newEduDelivery);
  $('#newUserBtn').addEventListener('click', newUser);
  $('#editSecurityPolicyBtn').addEventListener('click', editSecurityPolicy);
  $('#accountSecurityBtn').addEventListener('click', openAccountSecurity);
  $('#importUsersCsvBtn')?.addEventListener('click', importUsersCsv);
  $('#exportUsersCsvBtn')?.addEventListener('click', exportUsersCsv);
  $('#newTenantBtn').addEventListener('click', newTenant);
  checkSession();
});

// インライン onclick から呼ぶためグローバル公開
Object.assign(window, {
  launchCampaign, stopCampaign, resumeCampaign, showCampaignProgress, showCampaignData, loadCampaignFile, viewMaster, editMaster, downloadMaster, deleteCampaign, editTarget, deleteTarget, restoreTarget,
  editGroup, deleteGroup, setTplKind, editTemplate, deleteTemplate, tplViewer, scenarioViewer,
  editUser, deleteUser, sendUserPasswordMail, editTenant, showReportDetail, generateCampaign,
  openTenantDetail, closeTenantDetail, switchToTenant, setTenantStatusFilter, deleteTenant, restoreTenant, purgeTenant,
});

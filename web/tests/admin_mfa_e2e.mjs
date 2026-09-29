// 管理画面の保護(段階1、G43 と G44)のブラウザ E2E(本物の API の入口を通す)。合成 DB に向けたローカルのサーバーで動かす(本番に向けない)。
//   - 本人の登録(秘密鍵を出す → 認証アプリの代わりにこのテストが TOTP を計算 → 回復コード10個)
//   - ログインの2段目: 違うコードは止まる、コード待ちのセッションでは API は 401、正しいコードで入れる、回復コードでも入れる
//   - 方針: 組織管理者が多要素認証を必須にすると、未登録のオペレータはログインの後に登録の画面だけになり、ほかの API は 403
//   - 方針の最小の文字数より短いパスワードでユーザを作れない
//   - 組織管理者がオペレータの多要素認証を一覧から解除できる
// 準備: php fixtures/admin_mfa_e2e_db.php <db> <password>
//       [mfa] secret_key = <32バイトの base64> を書いた一時の ini を作り、ドキュメントルートに web への symlink tet2 を置いて起動する:
//       TET2_DB_PATH=<db> TET2_SECRETS_FILE=<ini> php -S 127.0.0.1:<port> -t <docroot>
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:<port>/tet2 TET2_E2E_PASSWORD=... node admin_mfa_e2e.mjs
import assert from 'node:assert/strict';
import crypto from 'node:crypto';

let chromium;
try { ({ chromium } = await import('playwright')); } catch { ({ chromium } = await import('/root/node_modules/playwright/index.mjs')); }

const env = (k) => process.env[k] || '';
for (const k of ['TET2_E2E_BASE_URL', 'TET2_E2E_PASSWORD']) {
  if (!env(k)) throw new Error(`${k} is required`);
}
const BASE = env('TET2_E2E_BASE_URL');
const PASSWORD = env('TET2_E2E_PASSWORD');
const ADMIN = 'mfa-e2e-admin@example.test';
const OPERATOR = 'mfa-e2e-op@example.test';
// 方針(16文字)より短い、12文字・3種の試験用の値(本物の資格情報ではない)
const WEAK_12 = 'Kqzwmvtr123!';
let passed = 0;
const ok = (m) => { passed += 1; console.log(`PASS: ${m}`); };

// ---- 認証アプリの代わり(RFC 6238、SHA1、30秒、6桁) ----
function base32Decode(text) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const c of text.replace(/[\s=]/g, '').toUpperCase()) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
  const bytes = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  return Buffer.from(bytes);
}
function totp(secret, step) {
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(step));
  const hmac = crypto.createHmac('sha1', base32Decode(secret)).update(counter).digest();
  const offset = hmac[19] & 0x0f;
  const bin = ((hmac[offset] & 0x7f) << 24) | (hmac[offset + 1] << 16) | (hmac[offset + 2] << 8) | hmac[offset + 3];
  return String(bin % 1_000_000).padStart(6, '0');
}
const nowStep = () => Math.floor(Date.now() / 30000);
// サーバは使った時刻窓とそれより前を拒むので、利用者ごとに使った窓を覚え、次の窓(±1 の範囲)を使う
const lastStep = new Map();
async function nextCode(email, secret) {
  let step = Math.max(nowStep(), (lastStep.get(email) ?? -Infinity) + 1);
  while (step > nowStep() + 1) await new Promise((r) => setTimeout(r, 1000));
  step = Math.max(step, nowStep() - 1);
  lastStep.set(email, step);
  return totp(secret, step);
}

const browser = await chromium.launch({ headless: true });
const allErrors = [];
const newPage = async () => {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  page.on('pageerror', (e) => allErrors.push(e.message));
  page.on('dialog', (d) => d.accept());
  return page;
};
const submitPassword = async (email) => {
  const page = await newPage();
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.locator('#loginEmail').fill(email);
  await page.locator('#loginPassword').fill(PASSWORD);
  await page.locator('#loginBtn').click();
  return page;
};
const status = (page, url) => page.evaluate((u) => fetch(u, { credentials: 'same-origin' }).then((r) => r.status), url);
// 画面の「登録を始める」から回復コードを控えるところまで。秘密鍵と回復コードを返す
async function enrollIn(page, container, email) {
  await page.locator(`${container} #mfaStartBtn`).click();
  const secret = (await page.locator(`${container} #mfaSecret`).textContent()).replace(/\s/g, '');
  assert.match(secret, /^[A-Z2-7]{32}$/);
  assert.match(await page.locator(`${container} #mfaUri`).inputValue(), /^otpauth:\/\/totp\/TET%20v2:/);
  await page.locator(`${container} #mfaEnableCode`).fill(await nextCode(email, secret));
  await page.locator(`${container} #mfaEnableBtn`).click();
  await page.locator(`${container} #mfaRecoveryCodes li`).first().waitFor();
  const codes = await page.locator(`${container} #mfaRecoveryCodes li`).allTextContents();
  assert.equal(codes.length, 10);
  assert.equal(await page.locator(`${container} #mfaDoneBtn`).isDisabled(), true);
  await page.locator(`${container} #mfaSavedCheck`).check();
  await page.locator(`${container} #mfaDoneBtn`).click();
  return { secret, codes };
}
const openAdminUsers = async (page) => {
  await page.locator('.app-sidebar [data-view="users"]').click();
  await page.locator('[data-panel="users"]:not(.d-none)').waitFor();
  await page.locator('#adminUsersTab').click();
  await page.locator('#usersBody tr').first().waitFor();
};
const userRow = (page, email) => page.locator('#usersBody tr', { has: page.locator(`td:text-is("${email}")`) });

try {
  // --- 1. 組織管理者が自分で登録する ---
  const admin = await submitPassword(ADMIN);
  await admin.locator('#appView:not(.d-none)').waitFor();
  await openAdminUsers(admin);
  assert.equal((await userRow(admin, ADMIN).locator('[data-col="mfa"]').textContent()).trim(), '未登録');
  assert.match(await admin.locator('#securityPolicySummary').textContent(), /12文字以上.*多要素認証は任意/);
  ok('一覧に多要素認証の列と、方針の説明が出る');

  await admin.locator('#accountSecurityBtn').click();
  await admin.locator('#accountMfaBody #mfaStartBtn').waitFor();
  const adminMfa = await enrollIn(admin, '#accountMfaBody', ADMIN);
  await admin.locator('#appModal').waitFor({ state: 'hidden' });
  await openAdminUsers(admin);
  assert.equal((await userRow(admin, ADMIN).locator('[data-col="mfa"]').textContent()).trim(), '有効');
  ok('本人が登録すると、秘密鍵と URI を出し、コードの確認の後に回復コードを10個出す');
  await admin.context().close();

  // --- 2. ログインの2段目 ---
  const pending = await submitPassword(ADMIN);
  await pending.locator('#loginMfaForm:not(.d-none)').waitFor();
  assert.equal(await pending.locator('#appView').isHidden(), true);
  assert.equal(await status(pending, 'api/users.php?action=list'), 401);
  assert.equal(await status(pending, 'api/campaigns.php?action=list'), 401);
  ok('パスワードの後はコードの入力になり、コード待ちのセッションでは API が 401');
  await pending.locator('#loginMfaCode').fill('000000');
  await pending.locator('#loginMfaBtn').click();
  await pending.locator('#loginMfaError:not(.d-none)').waitFor();
  assert.match(await pending.locator('#loginMfaError').textContent(), /確認コードが正しくありません/);
  await pending.locator('#loginMfaCode').fill(await nextCode(ADMIN, adminMfa.secret));
  await pending.locator('#loginMfaBtn').click();
  await pending.locator('#appView:not(.d-none)').waitFor();
  ok('違うコードでは入れず、正しいコードで入れる');
  await pending.context().close();

  const recovery = await submitPassword(ADMIN);
  await recovery.locator('#loginMfaForm:not(.d-none)').waitFor();
  await recovery.locator('#loginRecoveryToggle').click();
  await recovery.locator('#loginRecoveryCode').fill(adminMfa.codes[0].toLowerCase());
  await recovery.locator('#loginMfaBtn').click();
  await recovery.locator('#appView:not(.d-none)').waitFor();
  ok('回復コードで入れる');
  await recovery.context().close();
  const reuse = await submitPassword(ADMIN);
  await reuse.locator('#loginMfaForm:not(.d-none)').waitFor();
  await reuse.locator('#loginRecoveryToggle').click();
  await reuse.locator('#loginRecoveryCode').fill(adminMfa.codes[0]);
  await reuse.locator('#loginMfaBtn').click();
  await reuse.locator('#loginMfaError:not(.d-none)').waitFor();
  ok('使った回復コードはもう使えない');
  await reuse.locator('#loginRecoveryCode').fill(adminMfa.codes[1]);
  await reuse.locator('#loginMfaBtn').click();
  await reuse.locator('#appView:not(.d-none)').waitFor();

  // --- 3. 方針: 最小の文字数と多要素認証の必須化 ---
  const page = reuse;
  await openAdminUsers(page);
  await page.locator('#editSecurityPolicyBtn').click();
  await page.locator('#policyForm').waitFor();
  await page.locator('#pfMinLength').fill('16');
  await page.locator('#pfRequireMfa').check();
  await page.locator('#appModalSave').click();
  await page.locator('#appModal').waitFor({ state: 'hidden' });
  await page.locator('#securityPolicySummary', { hasText: '16文字以上' }).waitFor();
  assert.match(await page.locator('#securityPolicySummary').textContent(), /多要素認証は必須/);
  const weak = await page.evaluate((pw) => api('api/users.php', { method: 'POST', query: { action: 'create' },
    body: { email: 'weak-e2e@example.test', name: 'Weak', role: 'viewer', password: pw } }).then(() => 'ok').catch((e) => e.message), WEAK_12);
  assert.match(weak, /16文字以上/);
  ok('組織管理者が方針(16文字、多要素認証を必須)を保存し、方針より短いパスワードは拒まれる');

  // --- 4. 必須の組織の未登録のオペレータは、登録の画面だけ ---
  const op = await submitPassword(OPERATOR);
  await op.locator('#mfaEnrollView:not(.d-none)').waitFor();
  assert.equal(await op.locator('#appView').isHidden(), true);
  assert.equal(await status(op, 'api/campaigns.php?action=list'), 403);
  assert.equal(await status(op, 'api/users.php?action=mfa_status'), 200);
  ok('未登録のオペレータはログインの後に登録の画面だけになり、ほかの API は 403');
  await enrollIn(op, '#mfaEnrollBody', OPERATOR);
  await op.locator('#appView:not(.d-none)').waitFor();
  assert.equal(await status(op, 'api/campaigns.php?action=list'), 200);
  ok('登録を済ませると、そのまま管理画面を使える');
  await op.context().close();

  // --- 5. 組織管理者がオペレータの多要素認証を解除する ---
  await openAdminUsers(page);
  const opRow = userRow(page, OPERATOR);
  assert.equal((await opRow.locator('[data-col="mfa"]').textContent()).trim(), '有効');
  assert.equal(await userRow(page, ADMIN).locator('[data-action="mfa-reset"]').count(), 0);
  await opRow.locator('[data-action="mfa-reset"]').click();
  await page.locator('#usersBody tr', { has: page.locator(`td:text-is("${OPERATOR}")`) }).locator('[data-col="mfa"]', { hasText: '未登録' }).waitFor();
  ok('組織管理者は一覧からほかの人の多要素認証を解除できる(自分の行には解除のボタンを出さない)');

  assert.deepEqual(allErrors, []);
  ok('画面の JavaScript のエラーがない');
} finally {
  await browser.close();
}
console.log(`\n${passed} passed`);

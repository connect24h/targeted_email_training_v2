// ユーザ管理(A1〜A4)のブラウザ E2E(本物の API の入口を通す)。合成 DB に向けたローカルのサーバーで動かす(本番に向けない)。
//   - 招待を送る → 送られるはずのメール(TET2_MAIL_OUTBOX_DIR のファイル)から URL を取り出す → パスワードを設定 → ログインできる
//   - 使ったリンクは使えない。形の違うリンクは理由を出す。
//   - 再設定のメール → 新しいパスワードを設定すると、ログイン中の古いセッションは切れる
//   - CSV の一括登録(行ごとのエラー、招待メール)と出力、一覧の最終ログインと「パスワード未設定」
// 準備: php fixtures/user_management_e2e_db.php <db> <password>
//       サーバーは TET2_DB_PATH=<db> TET2_MAIL_OUTBOX_DIR=<outbox> TET2_ADMIN_BASE_URL=<BASE_URL> で起動する(メールは投函せずファイルに書く)
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:<port>/tet2 TET2_E2E_EMAIL=e2e-admin@example.test TET2_E2E_PASSWORD=...
//       TET2_E2E_OUTBOX=<outbox> node user_management_e2e.mjs
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

let chromium;
try { ({ chromium } = await import('playwright')); } catch { ({ chromium } = await import('/root/node_modules/playwright/index.mjs')); }

const env = (k) => process.env[k] || '';
for (const k of ['TET2_E2E_BASE_URL', 'TET2_E2E_EMAIL', 'TET2_E2E_PASSWORD', 'TET2_E2E_OUTBOX']) {
  if (!env(k)) throw new Error(`${k} is required`);
}
const BASE = env('TET2_E2E_BASE_URL');
const OUTBOX = env('TET2_E2E_OUTBOX');
let passed = 0;
const ok = (m) => { passed += 1; console.log(`PASS: ${m}`); };

// ---- 送られるはずのメール(ファイル)を読む ----
const seen = new Set(fs.readdirSync(OUTBOX));
async function waitForMail(to, timeoutMs = 10000) {
  const until = Date.now() + timeoutMs;
  while (Date.now() < until) {
    for (const f of fs.readdirSync(OUTBOX).sort()) {
      if (seen.has(f)) continue;
      const mail = JSON.parse(fs.readFileSync(path.join(OUTBOX, f), 'utf8'));
      if (mail.to === to) { seen.add(f); return mail; }
    }
    await new Promise((r) => setTimeout(r, 200));
  }
  throw new Error(`メールが見つかりません: ${to}`);
}
const urlIn = (mail) => {
  const m = mail.body.match(/(https?:\/\/\S+\/set_password\.php\?token=[0-9a-f]{64})/);
  assert.ok(m, 'メールの本文にパスワード設定の URL がある');
  return m[1];
};
const stripBom = (text) => (text.charCodeAt(0) === 0xfeff ? text.slice(1) : text);

const browser = await chromium.launch({ headless: true });
const allErrors = [];
const newPage = async () => {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, acceptDownloads: true });
  const page = await context.newPage();
  page.on('pageerror', (e) => allErrors.push(e.message));
  page.on('dialog', (d) => d.accept());
  return page;
};
const login = async (email, password) => {
  const page = await newPage();
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.locator('#loginEmail').fill(email);
  await page.locator('#loginPassword').fill(password);
  await page.locator('#loginBtn').click();
  await page.locator('#appView:not(.d-none)').waitFor();
  return page;
};
const loginFails = async (email, password) => {
  const page = await newPage();
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  const status = await page.evaluate(([e, p]) => fetch('api/auth.php?action=login', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: e, password: p }),
  }).then((r) => r.status), [email, password]);
  await page.context().close();
  return status !== 200;
};
const openUsers = async (page) => {
  await page.locator('.app-sidebar [data-view="users"]').click();
  await page.locator('[data-panel="users"]:not(.d-none)').waitFor();
  await page.locator('#adminUsersTab').click();
  await page.locator('#usersBody tr').first().waitFor();
};
const userRow = (page, email) => page.locator('#usersBody tr', { has: page.locator(`td:text-is("${email}")`) });
const setPassword = async (url, password) => {
  const page = await newPage();
  await page.goto(url, { waitUntil: 'networkidle' });
  await page.locator('#formView:not(.d-none)').waitFor();
  await page.locator('#spPassword').fill(password);
  await page.locator('#spConfirm').fill(password);
  await page.locator('#spSubmit').click();
  await page.locator('#doneView:not(.d-none)').waitFor();
  return page;
};

const INVITEE = 'e2e-invitee@example.test';
const FIRST_PW = 'E2e-First-Heron7';
const SECOND_PW = 'E2e-Second-Heron7';

try {
  // ---- 一覧: 最終ログインとパスワードの列 ----
  const admin = await login(env('TET2_E2E_EMAIL'), env('TET2_E2E_PASSWORD'));
  await openUsers(admin);
  const heads = await admin.locator('#adminUsersPane thead th').allTextContents();
  assert.ok(heads.includes('最終ログイン') && heads.includes('パスワード'));
  assert.match(await userRow(admin, env('TET2_E2E_EMAIL')).locator('[data-col="last-login"]').textContent(), /\d{4}-\d{2}-\d{2} \d{2}:\d{2}/);
  assert.match(await userRow(admin, env('TET2_E2E_EMAIL')).locator('[data-col="password"]').textContent(), /設定済み/);
  ok('一覧に最終ログイン日時とパスワードの状態の列がある(ログインした管理者は日時が出る)');

  // ---- 招待つきでユーザを作る ----
  await admin.locator('#newUserBtn').click();
  await admin.locator('#userForm').waitFor();
  assert.equal(await admin.locator('#ufModeInvite').isChecked(), true);
  assert.equal(await admin.locator('#userPasswordField').isVisible(), false);
  await admin.locator('#ufModePassword').check();
  assert.equal(await admin.locator('#userPasswordField').isVisible(), true);
  assert.match(await admin.locator('#ufPasswordHelp').textContent(), /12文字以上/);
  await admin.locator('#ufModeInvite').check();
  await admin.locator('#ufEmail').fill(INVITEE);
  await admin.locator('#ufName').fill('E2E 招待');
  await admin.locator('#ufRole').selectOption('operator');
  await admin.locator('#appModalSave').click();
  await admin.locator('#toast .app-toast.ok', { hasText: 'パスワード設定のメールを送りました' }).waitFor();
  await userRow(admin, INVITEE).waitFor();
  assert.match(await userRow(admin, INVITEE).locator('[data-col="password"]').textContent(), /パスワード未設定[\s\S]*リンクの期限/);
  assert.match(await userRow(admin, INVITEE).locator('[data-col="last-login"]').textContent(), /なし/);
  ok('「パスワード設定のメールを送る」で作ると、一覧に「パスワード未設定」とリンクの期限が出る');

  const invite = await waitForMail(INVITEE);
  assert.match(invite.subject, /パスワード設定/);
  const inviteUrl = urlIn(invite);
  assert.ok(inviteUrl.startsWith(`${BASE}/set_password.php?token=`));
  ok('招待メール(差し替えた送信口のファイル)に、管理画面の基点のパスワード設定の URL が入る');

  // ---- パスワード設定のページ ----
  const sp = await newPage();
  await sp.goto(inviteUrl, { waitUntil: 'networkidle' });
  await sp.locator('#formView:not(.d-none)').waitFor();
  assert.match(await sp.locator('#spEmail').textContent(), /^e2\*+@example\.test$/);
  await sp.locator('#spPassword').fill('weakpassword1');
  await sp.locator('#spConfirm').fill('weakpassword1');
  await sp.locator('#spSubmit').click();
  await sp.locator('#spError:not(.d-none)', { hasText: '3種類以上' }).waitFor();
  ok('パスワードの決まりに合わないと、理由を出して設定しない');
  await sp.locator('#spPassword').fill(FIRST_PW);
  await sp.locator('#spConfirm').fill(FIRST_PW);
  await sp.locator('#spSubmit').click();
  await sp.locator('#doneView:not(.d-none)').waitFor();
  await sp.locator('#spLoginLink').click();
  await sp.locator('#loginEmail').waitFor();
  ok('招待のリンクからパスワードを設定し、ログイン画面へ案内する');

  const invitee = await login(INVITEE, FIRST_PW);
  assert.equal(await invitee.evaluate(() => State.user.email), INVITEE);
  ok('設定したパスワードでログインできる');

  const reused = await newPage();
  await reused.goto(inviteUrl, { waitUntil: 'networkidle' });
  await reused.locator('#errorView:not(.d-none)').waitFor();
  assert.match(await reused.locator('#errorMsg').textContent(), /既に使われています/);
  const bad = await newPage();
  await bad.goto(`${BASE}/set_password.php?token=abc`, { waitUntil: 'networkidle' });
  assert.match(await bad.locator('#errorView').textContent(), /リンクが正しくありません/);
  ok('使ったリンクと形の違うリンクは、理由を日本語で出す');

  // ---- 再設定: 新しいパスワードを設定すると、ログイン中の古いセッションは切れる ----
  await admin.reload({ waitUntil: 'networkidle' });
  await openUsers(admin);
  await userRow(admin, INVITEE).locator('[data-action="send-password-mail"]').click();
  await admin.locator('#toast .app-toast.ok', { hasText: 'パスワード再設定のメールを送りました' }).waitFor();
  const reset = await waitForMail(INVITEE);
  assert.match(reset.subject, /再設定/);
  await setPassword(urlIn(reset), SECOND_PW);
  const me = await invitee.evaluate(() => fetch('api/auth.php?action=me', { credentials: 'same-origin' }).then((r) => r.json()));
  assert.equal(me.user, null);
  assert.ok(await loginFails(INVITEE, FIRST_PW));
  await (await login(INVITEE, SECOND_PW)).context().close();
  ok('再設定のメールで新しいパスワードにすると、古いセッションは切れ、古いパスワードは使えない');

  // ---- CSV の一括登録 ----
  await admin.locator('#importUsersCsvBtn').click();
  await admin.locator('#userCsvText').waitFor();
  await admin.locator('#userCsvText').fill([
    'email,name,role',
    'e2e-csv1@example.test,CSV 一郎,viewer',
    'e2e-csv2@example.test,CSV 二郎,operator',
    'e2e-csv3@example.test,CSV 三郎,superadmin',
    'not-an-email,不正,viewer',
  ].join('\n'));
  assert.equal(await admin.locator('#userCsvInvite').isChecked(), true);
  await admin.locator('#appModalSave').click();
  await admin.locator('#userCsvErrors tbody tr').first().waitFor();
  const errorRows = await admin.locator('#userCsvErrors tbody tr').allTextContents();
  assert.equal(errorRows.length, 2);
  assert.match(errorRows[0], /^4[\s\S]*CSV では作れません/);
  assert.match(errorRows[1], /^5[\s\S]*メールアドレスの形/);
  assert.match(await admin.locator('#userCsvSummary').textContent(), /登録 2 件、飛ばした行 2 件、招待メール 2 件/);
  await waitForMail('e2e-csv1@example.test');
  await waitForMail('e2e-csv2@example.test');
  await admin.locator('#appModal .btn-close').click();
  await admin.locator('#appModal').waitFor({ state: 'hidden' });
  await userRow(admin, 'e2e-csv2@example.test').waitFor();
  assert.match(await userRow(admin, 'e2e-csv1@example.test').locator('[data-col="password"]').textContent(), /パスワード未設定/);
  ok('CSV の一括登録: 正しい行だけを作って招待を送り、範囲外の役割と不正なメールは行ごとに理由を出す');

  // ---- CSV の出力 ----
  const [download] = await Promise.all([admin.waitForEvent('download'), admin.locator('#exportUsersCsvBtn').click()]);
  const csv = stripBom(fs.readFileSync(await download.path(), 'utf8'));
  assert.match(csv.split('\n')[0], /^email,name,role,status,last_login_at,password/);
  // 空白を含む値は fputcsv が引用符で囲む
  assert.match(csv, /e2e-csv1@example\.test,"?CSV 一郎"?,viewer,active,,未設定/);
  assert.match(csv, new RegExp(`${INVITEE.replace(/\./g, '\\.')},"?E2E 招待"?,operator,active,"?\\d{4}-`));
  ok('CSV の出力: 一括登録と同じ列に、状態、最終ログイン、パスワードの状態が出る');

  assert.deepEqual(allErrors, []);
  ok('画面に JavaScript のエラーが出ない');
  console.log(`\n${passed} passed`);
} finally {
  await browser.close();
}

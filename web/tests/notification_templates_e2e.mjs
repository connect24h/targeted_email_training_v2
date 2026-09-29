// 通知の文面(C2、G35)のブラウザ E2E(本物の API の入口を通す)。合成 DB に向けたローカルのサーバーで動かす(本番に向けない)。
//   - ユーザ管理の「通知の文面」タブ: 種類の選択、既定の文面、差し込みのボタン、プレビュー、保存、既定に戻す
//   - URL の差し込みを消した保存と、知らない差し込みは理由を出して拒む
//   - テスト送信は本人のアドレスにだけ届く(送られるはずのメールを TET2_MAIL_OUTBOX_DIR のファイルで確かめる)、10分に5回まで
//   - オペレータにはタブが出ず、API も 403。CSRF のない POST は 403
// 準備: php fixtures/notification_templates_e2e_db.php <db> <password>
//       ドキュメントルートに web への symlink tet2 を置き、
//       TET2_DB_PATH=<db> TET2_MAIL_OUTBOX_DIR=<outbox> php -S 127.0.0.1:<port> -t <docroot> で起動する(メールは投函せずファイルに書く)
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:<port>/tet2 TET2_E2E_PASSWORD=... TET2_E2E_OUTBOX=<outbox> node notification_templates_e2e.mjs
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

let chromium;
try { ({ chromium } = await import('playwright')); } catch { ({ chromium } = await import('/root/node_modules/playwright/index.mjs')); }

const env = (k) => process.env[k] || '';
for (const k of ['TET2_E2E_BASE_URL', 'TET2_E2E_PASSWORD', 'TET2_E2E_OUTBOX']) {
  if (!env(k)) throw new Error(`${k} is required`);
}
const BASE = env('TET2_E2E_BASE_URL');
const OUTBOX = env('TET2_E2E_OUTBOX');
const PASSWORD = env('TET2_E2E_PASSWORD');
const ADMIN = 'e2e-admin@example.test';
const OPERATOR = 'e2e-op@example.test';
let passed = 0;
const ok = (m) => { passed += 1; console.log(`PASS: ${m}`); };

const readOutbox = () => fs.readdirSync(OUTBOX).sort()
  .map((f) => JSON.parse(fs.readFileSync(path.join(OUTBOX, f), 'utf8')));
const startCount = readOutbox().length;

const browser = await chromium.launch({ headless: true });
const allErrors = [];
const dialogs = [];
const login = async (email) => {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  page.on('pageerror', (e) => allErrors.push(e.message));
  page.on('dialog', (d) => { dialogs.push(d.message()); d.accept(); });
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.locator('#loginEmail').fill(email);
  await page.locator('#loginPassword').fill(PASSWORD);
  await page.locator('#loginBtn').click();
  await page.locator('#appView:not(.d-none)').waitFor();
  return page;
};
const openUsers = async (page) => {
  await page.locator('.app-sidebar [data-view="users"]').click();
  await page.locator('[data-panel="users"]:not(.d-none)').waitFor();
};
const waitText = async (locator, re, timeout = 5000) => {
  const until = Date.now() + timeout;
  let text = '';
  while (Date.now() < until) {
    text = (await locator.textContent()) || '';
    if (re.test(text)) return text;
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error(`待っていた表示になりません: ${re} (今: ${text})`);
};

try {
  const admin = await login(ADMIN);
  await openUsers(admin);
  await admin.locator('#notifyTemplatesTab').click();
  await admin.locator('#ntEditor:not(.d-none)').waitFor();

  // ---- 一覧と既定の文面 ----
  assert.equal(await admin.locator('#ntKind option').count(), 14);
  assert.equal(await admin.locator('#ntKind').inputValue(), 'edu_invite');
  assert.match(await admin.locator('#ntState').textContent(), /既定の文面/);
  assert.equal(await admin.locator('#ntSubject').inputValue(), '【受講のご案内】{配信名}');
  assert.ok((await admin.locator('#ntBody').inputValue()).includes('{受講URL}'));
  await waitText(admin.locator('#ntPreviewSubject'), /^【受講のご案内】見本の教育/);
  assert.match(await admin.locator('#ntPreviewBody').textContent(), /^見本 太郎 様\n[\s\S]*take\.php\?token=sample-token-for-preview/);
  assert.equal(await admin.locator('#ntResetBtn').isDisabled(), true);
  ok('通知の文面のタブ: 14種類、既定の文面、見本の値で差し込んだプレビュー');

  // ---- 検証: URL の差し込みを消す、知らない差し込み ----
  await admin.locator('#ntBody').fill('{氏名} 様\nURL を消した本文');
  await admin.locator('#ntSaveBtn').click();
  await waitText(admin.locator('#ntMessage'), /\{受講URL\} を入れてください/);
  await admin.locator('#ntBody').fill('{受講URL}\n{社長の名前}');
  await admin.locator('#ntSaveBtn').click();
  await waitText(admin.locator('#ntMessage'), /使えない差し込み.*\{社長の名前\}/);
  await waitText(admin.locator('#ntPreviewError'), /\{社長の名前\}/);
  ok('URL の差し込みを消した本文と、知らない差し込みは理由を出して保存しない');

  // ---- 差し込みのボタンで編集して保存 ----
  await admin.locator('#ntSubject').fill('[{組織名}] ');
  await admin.locator('#ntSubject').press('End');
  await admin.locator('#ntVars [data-nt-var="配信名"]').click();
  assert.equal(await admin.locator('#ntSubject').inputValue(), '[{組織名}] {配信名}');
  await admin.locator('#ntBody').fill('{氏名} さん\n下記から受講してください。\n');
  await admin.locator('#ntBody').press('Control+End');
  await admin.locator('#ntVars [data-nt-var="受講URL"]').click();
  assert.equal(await admin.locator('#ntBody').inputValue(), '{氏名} さん\n下記から受講してください。\n{受講URL}');
  await waitText(admin.locator('#ntPreviewSubject'), /^\[Example Tenant\] 見本の教育/);
  await admin.locator('#ntSaveBtn').click();
  await waitText(admin.locator('#ntMessage'), /保存しました/);
  assert.match(await admin.locator('#ntState').textContent(), /この組織の文面/);
  assert.match(await admin.locator('#ntKind option[value="edu_invite"]').textContent(), /変更あり/);
  assert.equal(await admin.locator('#ntResetBtn').isDisabled(), false);
  ok('差し込みのボタンでカーソルの位置に入れ、保存するとこの組織の文面になる');

  // 読み直しても残る(ほかのタブへ行って戻る)
  await admin.locator('#adminUsersTab').click();
  await admin.locator('#usersBody tr').first().waitFor();
  await admin.locator('#notifyTemplatesTab').click();
  await waitText(admin.locator('#ntState'), /この組織の文面/);
  assert.equal(await admin.locator('#ntSubject').inputValue(), '[{組織名}] {配信名}');
  ok('保存した文面は読み直しても残る');

  // ---- テスト送信: 本人のアドレスにだけ ----
  await admin.locator('#ntTestSendBtn').click();
  await waitText(admin.locator('#ntMessage'), new RegExp(`${ADMIN.replace(/\./g, '\\.')} にテストのメールを送りました`));
  let mails = readOutbox().slice(startCount);
  assert.equal(mails.length, 1);
  assert.equal(mails[0].to, ADMIN);
  assert.equal(mails[0].subject, '[テスト送信] [Example Tenant] 見本の教育（標的型メールの見分け方）');
  assert.match(mails[0].body, /^見本 太郎 さん\n下記から受講してください。\nhttps?:\/\/\S+take\.php\?token=sample-token-for-preview$/);
  ok('テスト送信: 保存した文面を見本の値で、ログインしている本人のアドレスにだけ送る');

  // 画面を通さず宛先を指定しても、本人にしか送らない
  const forged = await admin.evaluate(async () => {
    const r = await api('api/notification_templates.php', { method: 'POST', query: { action: 'test_send' },
      body: { kind: 'admin_invite', to: 'someone-else@example.test', email: 'someone-else@example.test' } });
    return r.to;
  });
  assert.equal(forged, ADMIN);
  mails = readOutbox().slice(startCount);
  assert.equal(mails.length, 2);
  assert.ok(mails.every((m) => m.to === ADMIN), '送られたメールはすべて本人宛て');
  ok('API に別の宛先を渡しても、本人のアドレスにだけ送る');

  // 10分に5回まで
  for (let i = 0; i < 3; i++) {
    await admin.locator('#ntTestSendBtn').click();
    await waitText(admin.locator('#ntMessage'), /にテストのメールを送りました/);
    await admin.evaluate(() => { document.querySelector('#ntMessage').textContent = ''; });
  }
  await admin.locator('#ntTestSendBtn').click();
  await waitText(admin.locator('#ntMessage'), /10分に5回まで/);
  mails = readOutbox().slice(startCount);
  assert.equal(mails.length, 5);
  assert.ok(mails.every((m) => m.to === ADMIN));
  ok('テスト送信は10分に5回までで、6回目は送らずに理由を出す');

  // ---- CSRF のない POST は 403 ----
  const noCsrf = await admin.evaluate(async () => (await fetch('api/notification_templates.php?action=reset', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ kind: 'edu_invite' }),
  })).status);
  assert.equal(noCsrf, 403);
  ok('CSRF トークンのない POST は 403');

  // ---- 既定に戻す ----
  await admin.locator('#ntResetBtn').click();
  await waitText(admin.locator('#ntMessage'), /既定の文面に戻しました/);
  assert.ok(dialogs.some((d) => d.includes('既定の文面に戻しますか')));
  assert.match(await admin.locator('#ntState').textContent(), /既定の文面/);
  assert.equal(await admin.locator('#ntSubject').inputValue(), '【受講のご案内】{配信名}');
  ok('既定の文面に戻すと、確認の後に上書きを消す');

  // ---- 種類の切り替え(アンケート) ----
  await admin.locator('#ntKind').selectOption('survey_reminder');
  await waitText(admin.locator('#ntPreviewSubject'), /^【回答のお願い（締切間近）】見本のアンケート/);
  assert.ok(await admin.locator('#ntVars [data-nt-var="回答URL"]').count() === 1);
  assert.ok(await admin.locator('#ntVars [data-nt-var="受講URL"]').count() === 0);
  ok('種類を切り替えると、その種類の文面と差し込みの一覧になる');

  // ---- オペレータ: タブが出ない、API は 403 ----
  const op = await login(OPERATOR);
  await openUsers(op);
  assert.equal(await op.locator('#notifyTemplatesTab').isVisible(), false);
  const opStatus = await op.evaluate(async () => (await fetch('api/notification_templates.php?action=list')).status);
  assert.equal(opStatus, 403);
  ok('オペレータには通知の文面のタブが出ず、API も 403');

  assert.equal(readOutbox().slice(startCount).length, 5);
  assert.deepEqual(allErrors, []);
  ok('画面に JavaScript のエラーが出ない');
  console.log(`\n${passed} passed`);
} finally {
  await browser.close();
}

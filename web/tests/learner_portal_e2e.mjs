// 受講者のマイページ(L1〜L5、L7)のブラウザ E2E。合成 DB に向けたローカルのサーバーで動かす(本番に向けない)。
//   招待(管理画面の対象者の一覧) → 招待メール(ファイル)の URL でパスワード設定 → マイページにログイン → ホームの ToDo
//   → 受講(不合格 → 続けて合格) → 成績(回数、答え合わせ) → もう一度受講する(3回目) → アウェアネスの推移
//   → アンケートに回答して履歴 → パスワードを忘れた時の応答 → ログアウト
// 準備: php fixtures/learner_portal_e2e_db.php <db> <password>
//       ドキュメントルートに web への symlink tet2 を置き、次で起動する(メールは投函せずファイルに書く):
//       TET2_DB_PATH=<db> TET2_MAIL_OUTBOX_DIR=<outbox> TET2_LEARNER_BASE_URL=http://127.0.0.1:<port>/tet2
//       TET2_ADMIN_BASE_URL=http://127.0.0.1:<port>/tet2 php -S 127.0.0.1:<port> -t <docroot>
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:<port>/tet2 TET2_E2E_EMAIL=e2e-admin@example.test TET2_E2E_PASSWORD=...
//       TET2_E2E_OUTBOX=<outbox> [TET2_E2E_SHOTS=<画面を撮る先>] node learner_portal_e2e.mjs
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
const SHOTS = env('TET2_E2E_SHOTS');
const LEARNER = 'target1@example.test';
const LEARNER_PW = 'Learner-E2E-Pass-2026';
if (SHOTS) fs.mkdirSync(SHOTS, { recursive: true });
let passed = 0;
const ok = (m) => { passed += 1; console.log(`PASS: ${m}`); };
const shot = async (page, name) => { if (SHOTS) await page.screenshot({ path: path.join(SHOTS, `${name}.png`), fullPage: true }); };

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
const newMails = () => fs.readdirSync(OUTBOX).filter((f) => !seen.has(f)).length;

const browser = await chromium.launch({ headless: true });
const errors = [];
const newPage = async (viewport = { width: 1280, height: 900 }) => {
  const context = await browser.newContext({ viewport });
  const page = await context.newPage();
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('dialog', (d) => d.accept());
  return page;
};

// 受講の画面で2問に答えて提出する(教材なし、提出後にまとめて答え合わせ)
async function answerAndSubmit(page, choices) {
  await page.locator('#startBtn').click();
  await page.locator('#quizView:not(.d-none)').waitFor();
  for (let i = 0; i < choices.length; i += 1) {
    await page.locator('#qOptions .opt').nth(choices[i]).click();
    if (i < choices.length - 1) await page.locator('#qNext').click();
  }
  await page.locator('#qSubmit').click();
  await page.locator('#resultView:not(.d-none)').waitFor();
  return page.locator('#rPct').innerText();
}
const card = (page, title) => page.locator('article[data-delivery]', { has: page.locator(`h3:text-is("${title}")`) });
async function openGrades(page) {
  await page.goto(`${BASE}/my.php`);
  await page.locator('#appView:not(.d-none)').waitFor();
  await page.locator('#tabGrades').click();
  await page.locator('#gradeList article').first().waitFor();
}

try {
  // ---- 1. 管理画面: 対象者の一覧から選んでマイページの招待を送る ----
  const admin = await newPage({ width: 1440, height: 900 });
  await admin.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await admin.locator('#loginEmail').fill(env('TET2_E2E_EMAIL'));
  await admin.locator('#loginPassword').fill(env('TET2_E2E_PASSWORD'));
  await admin.locator('#loginBtn').click();
  await admin.locator('#appView:not(.d-none)').waitFor();
  await admin.locator('.app-sidebar [data-view="users"]').click();
  await admin.locator('[data-panel="users"]:not(.d-none)').waitFor();
  const row = admin.locator('#targetsBody tr', { has: admin.locator(`td:text-is("${LEARNER}")`) });
  await row.waitFor();
  assert.match(await row.innerText(), /未招待/);
  await row.locator('.tgt-select').check();
  await admin.locator('#inviteMyPageBtn').click();
  const mail = await waitForMail(LEARNER);
  const m = mail.body.match(/(https?:\/\/\S+\/set_password\.php\?token=[0-9a-f]{64}&site=my)/);
  assert.ok(m, 'メールに受講者のサイトのパスワード設定の URL がある');
  assert.match(mail.subject, /マイページのパスワード設定/);
  await row.locator('text=パスワード未設定').waitFor();
  ok('招待: 対象者の一覧で選んで送ると、受講者のサイトのパスワード設定の URL がメールで届き、一覧に「パスワード未設定」');
  await shot(admin, '01-admin-targets');
  await admin.context().close();

  // ---- 2. パスワードの設定 → マイページのログインへ ----
  const page = await newPage();
  await page.goto(m[1]);
  await page.locator('#formView:not(.d-none)').waitFor();
  await page.locator('#spPassword').fill(LEARNER_PW);
  await page.locator('#spConfirm').fill(LEARNER_PW);
  await page.locator('#spSubmit').click();
  await page.locator('#doneView:not(.d-none)').waitFor();
  assert.equal(await page.locator('#spLoginLink').innerText(), 'マイページのログインへ');
  await page.locator('#spLoginLink').click();
  await page.locator('#loginView:not(.d-none)').waitFor();
  assert.ok(page.url().endsWith('/my.php'));
  ok('パスワード設定: 設定の後にマイページのログインへ案内する');

  // ---- 3. ログイン(誤り → 正しい) → ホーム ----
  await page.locator('#loginEmail').fill(LEARNER);
  await page.locator('#loginPassword').fill('Wrong-Password-2026');
  await page.locator('#loginBtn').click();
  await page.locator('#loginError:not(.d-none)').waitFor();
  assert.match(await page.locator('#loginError').innerText(), /正しくありません/);
  await page.locator('#loginPassword').fill(LEARNER_PW);
  await page.locator('#loginBtn').click();
  await page.locator('#appView:not(.d-none)').waitFor();
  await page.locator('#todoList .todo').first().waitFor();
  const todoTitles = await page.locator('#todoList .todo .fw-bold').allInnerTexts();
  assert.deepEqual(todoTitles, ['アウェアネス 小問', '教育のふりかえり', '情報セキュリティ基礎']);
  ok('ログイン: 誤りは理由を出し、正しいパスワードでホームに期限の近い順の ToDo(教育2件、アンケート1件)');
  await shot(page, '02-home');

  // ---- 4. 受講: 不合格 → 成績は受講中・1回 → 続けて合格 ----
  await page.locator('#todoList .todo', { hasText: '情報セキュリティ基礎' }).locator('a').click();
  assert.match(await answerAndSubmit(page, [0, 1]), /0%/);
  await openGrades(page);
  let c = card(page, '情報セキュリティ基礎');
  assert.match(await c.innerText(), /受講中/);
  assert.equal(await c.locator('.attempt-count').innerText(), '1回');
  ok('成績: 不合格の後は受講中で、受講回数1回');
  await c.locator('a', { hasText: '続ける' }).click();
  assert.match(await answerAndSubmit(page, [1, 0]), /100%/);
  await openGrades(page);
  c = card(page, '情報セキュリティ基礎');
  const text = await c.innerText();
  assert.match(text, /完了/);
  assert.match(text, /合格/);
  assert.equal(await c.locator('.attempt-count').innerText(), '2回');
  await c.locator('summary', { hasText: '問題ごとの解答と解説' }).click();
  assert.equal(await c.locator('.review-q').count(), 2);
  assert.match(await c.locator('.review-q').first().innerText(), /開かずに報告します。/);
  assert.equal(await c.locator('.review-q .opt.is-correct').count(), 2);
  ok('成績: 合格の後は完了・合格・100%・2回、問題ごとの解答と正解と解説');
  await shot(page, '03-grades');

  // ---- 5. もう一度受講する(3回目) ----
  await c.locator('[data-retake]').click();
  await page.waitForURL(/take\.php\?token=/);
  assert.match(await answerAndSubmit(page, [1, 0]), /100%/);
  await openGrades(page);
  c = card(page, '情報セキュリティ基礎');
  assert.equal(await c.locator('.attempt-count').innerText(), '3回');
  await c.locator('summary', { hasText: '回ごとの結果' }).click();
  const attemptRows = await c.locator('tbody tr').allInnerTexts();
  assert.equal(attemptRows.length, 3);
  assert.match(attemptRows[2], /受け直し/);
  assert.match(attemptRows[0], /不合格/);
  ok('再受講: もう一度受講すると新しい回(3回目)になり、前の回の結果も残る');

  // ---- 6. アウェアネス → 推移 ----
  await page.locator('#tabHome').click();
  await page.locator('#todoList .todo', { hasText: 'アウェアネス 小問' }).locator('a').click();
  assert.match(await answerAndSubmit(page, [1, 1]), /50%/);
  await openGrades(page);
  await page.locator('#trendCard:not(.d-none)').waitFor();
  assert.match(await page.locator('#trendBody').innerText(), /アウェアネス 小問[\s\S]*50%/);
  ok('成績: アウェアネスの正答率の推移を出す');

  // ---- 7. アンケート → 履歴 ----
  await page.locator('#tabHome').click();
  await page.locator('#todoList .todo', { hasText: '教育のふりかえり' }).locator('a').click();
  await page.locator('#surveyView:not(.d-none)').waitFor();
  await page.locator('input[name="q0"]').first().check();
  await page.locator('#svSubmit').click();
  await page.locator('#doneView:not(.d-none)').waitFor();
  await page.goto(`${BASE}/my.php`);
  await page.locator('#appView:not(.d-none)').waitFor();
  await page.locator('#todoList').filter({ hasText: '今やることはありません' }).waitFor();
  await page.locator('#tabSurveys').click();
  await page.locator('#surveyHistory [data-history]').first().waitFor();
  assert.match(await page.locator('#surveyOpen').innerText(), /未回答のアンケートはありません/);
  await page.locator('#surveyHistory summary').click();
  assert.match(await page.locator('#surveyHistory').innerText(), /教育のふりかえり[\s\S]*役に立った/);
  ok('アンケート: 回答するとホームの ToDo から消え、履歴に回答日時と自分の回答');
  await shot(page, '04-surveys');

  // ---- 8. スマートフォンの幅で横にはみ出さない ----
  await page.setViewportSize({ width: 390, height: 844 });
  await page.locator('#tabGrades').click();
  await page.locator('#gradeList article').first().waitFor();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  assert.ok(overflow <= 1, `横にはみ出していない(${overflow}px)`);
  ok('スマホ: 成績の画面が横にはみ出さない');
  await shot(page, '05-mobile-grades');
  await page.setViewportSize({ width: 1280, height: 900 });

  // ---- 9. ログアウト ----
  await page.locator('#logoutBtn').click();
  await page.locator('#loginView:not(.d-none)').waitFor();
  assert.match(await page.locator('#loginNotice').innerText(), /ログアウトしました/);
  await page.reload();
  await page.locator('#loginView:not(.d-none)').waitFor();
  const status = await page.evaluate(() => fetch('api/my.php?action=grades').then((r) => r.status));
  assert.equal(status, 401);
  ok('ログアウト: ログイン画面に戻り、読み込み直しても受講者の API は 401');

  // ---- 10. パスワードを忘れた時: 登録の有無で同じ応答 ----
  const before = newMails();
  const forgot = async (email) => {
    await page.locator('#toForgot').click();
    await page.locator('#forgotView:not(.d-none)').waitFor();
    await page.locator('#forgotEmail').fill(email);
    await page.locator('#forgotBtn').click();
    await page.locator('#forgotMsg.alert-success').waitFor();
    const msg = await page.locator('#forgotMsg').innerText();
    await page.locator('#backToLogin').click();
    return msg;
  };
  const known = await forgot(LEARNER);
  const unknown = await forgot('nobody@example.test');
  assert.equal(known, unknown);
  await waitForMail(LEARNER);
  assert.equal(newMails(), before);
  ok('パスワードを忘れた時: 登録の有無で同じ応答で、登録のある人にだけメールが届く');

  assert.deepEqual(errors, [], `画面のスクリプトのエラーがない: ${errors.join(' / ')}`);
  ok('画面のスクリプトのエラーがない');
  console.log(`ALL ${passed} CHECKS PASSED`);
} finally {
  await browser.close();
}

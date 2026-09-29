// 配信ごとの受講の設定とマイページの改善のブラウザ E2E(計画 tet2-parity-stage0-A の A-1〜A-5)。
// 合成 DB に向けたローカルのサーバーで動かす(本番に向けない)。
//   管理画面: 新しい配信の画面に受講の設定4つ(並べ替えだけ既定で有効)、社内の問い合わせ先の設定
//   受講(アカウントあり): 選択肢がサーバーの順で出る → テスト中は「教材を見直す」を出さず API も教材を返さない
//     → 不合格 → マイページへ戻るリンク → もう一度でテストから始まる → 表示の順で正解を選ぶと 100%
//   受講(アカウントなし): 戻るリンクを出さない
//   マイページ: 問い合わせ先、配信日時、「正解 n/m」、自分の受講完了率
// 準備: php fixtures/edu_delivery_options_e2e_db.php <db> <password> > fixture.json
//       ドキュメントルートに web への symlink tet2 を置き、TET2_DB_PATH=<db> php -S 127.0.0.1:<port> -t <docroot> で起動する
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:<port>/tet2 TET2_E2E_PASSWORD=... TET2_E2E_FIXTURE=<fixture.json>
//       [TET2_E2E_SHOTS=<画面を撮る先>] node edu_delivery_options_e2e.mjs
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

let chromium;
try { ({ chromium } = await import('playwright')); } catch { ({ chromium } = await import('/root/node_modules/playwright/index.mjs')); }

const env = (k) => process.env[k] || '';
for (const k of ['TET2_E2E_BASE_URL', 'TET2_E2E_PASSWORD', 'TET2_E2E_FIXTURE']) {
  if (!env(k)) throw new Error(`${k} is required`);
}
const BASE = env('TET2_E2E_BASE_URL');
const PASSWORD = env('TET2_E2E_PASSWORD');
const FX = JSON.parse(fs.readFileSync(env('TET2_E2E_FIXTURE'), 'utf8'));
const SHOTS = env('TET2_E2E_SHOTS');
if (SHOTS) fs.mkdirSync(SHOTS, { recursive: true });
let passed = 0;
const ok = (m) => { passed += 1; console.log(`PASS: ${m}`); };
const shot = async (page, name) => { if (SHOTS) await page.screenshot({ path: path.join(SHOTS, `${name}.png`), fullPage: true }); };

const browser = await chromium.launch({ headless: true });
const errors = [];
const newPage = async (viewport = { width: 1280, height: 900 }) => {
  const context = await browser.newContext({ viewport });
  const page = await context.newPage();
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('dialog', (d) => d.accept());
  return page;
};
const optionTexts = (page) => page.locator('#qOptions .opt .txt').allInnerTexts();
// 表示の中から、その文の選択肢を押す(番号ではなく文で選ぶ = 並べ替えの後の順で答える)
const choose = (page, text) => page.locator('#qOptions .opt', { has: page.locator(`.txt:text-is("${text}")`) }).click();

try {
  // ---- 1. 管理画面: 受講の設定と社内の問い合わせ先 ----
  const admin = await newPage({ width: 1440, height: 900 });
  await admin.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await admin.locator('#loginEmail').fill('e2e-admin@example.test');
  await admin.locator('#loginPassword').fill(PASSWORD);
  await admin.locator('#loginBtn').click();
  await admin.locator('#appView:not(.d-none)').waitFor();
  await admin.evaluate(() => document.querySelector('.app-sidebar [data-view="eduDeliveries"]').click());
  await admin.locator('#editEduContactBtn:not(.d-none)').waitFor();
  assert.match(await admin.locator('#eduContactSummary').innerText(), /未設定/);
  await admin.locator('#newEduDeliveryBtn').click();
  await admin.locator('#eduOpt_shuffle_options').waitFor();
  const defaults = await admin.evaluate(() => Object.fromEntries(
    ['shuffle_options', 'lock_material_during_test', 'allow_after_deadline', 'retake_from_test']
      .map((k) => [k, document.getElementById(`eduOpt_${k}`).checked])));
  assert.deepEqual(defaults, { shuffle_options: true, lock_material_during_test: false, allow_after_deadline: false, retake_from_test: false });
  ok('新しい配信の画面に受講の設定4つがあり、選択肢の並べ替えだけ既定で有効');
  await shot(admin, '01-admin-new-delivery');
  await admin.locator('#appModal .modal-footer [data-bs-dismiss="modal"]').click();
  await admin.locator('#appModal').waitFor({ state: 'hidden' });
  await admin.locator('#editEduContactBtn').click();
  await admin.locator('#eduContactText').fill('情報システム部 内線 1234');
  await admin.locator('#appModalSave').click();
  await admin.locator('#appModal').waitFor({ state: 'hidden' });
  await admin.waitForFunction(() => document.querySelector('#eduContactSummary').textContent.includes('内線 1234'));
  ok('組織管理者が社内の問い合わせ先を設定できる');

  // ---- 2. 受講(マイページのアカウントあり) ----
  const take = await newPage();
  await take.goto(`${BASE}/take.php?token=${FX.with_account}`);
  await take.locator('#landingView:not(.d-none)').waitFor();
  assert.match(await take.locator('#landingNote').innerText(), /提出するまで教材は見られません/);
  await take.locator('#startBtn').click();
  await take.locator('#lessonView:not(.d-none)').waitFor();
  await take.locator('#lessonNext').click();
  await take.locator('#quizView:not(.d-none)').waitFor();
  assert.deepEqual(await optionTexts(take), FX.displayed['1']);
  assert.notDeepEqual(FX.displayed['1'], FX.original['1']);
  ok('選択肢はサーバーが決めた順(元の順と違う)で出る');
  assert.equal(await take.locator('#backToLesson').isVisible(), false);
  const locked = await take.evaluate(async (t) => (await fetch(`api/edu_take.php?action=start&token=${t}`)).json(), FX.with_account);
  assert.equal(locked.material, null);
  assert.equal(locked.material_locked, true);
  ok('テスト中は「教材を見直す」を出さず、API も教材を返さない');
  await take.reload();
  await take.locator('#landingView:not(.d-none)').waitFor();
  assert.deepEqual((await take.evaluate(async (t) => (await fetch(`api/edu_take.php?action=start&token=${t}`)).json(), FX.with_account))
    .questions[0].options, FX.displayed['1']);
  ok('開き直しても同じ順');
  // 不合格にする(正解でない選択肢を選ぶ)
  await take.locator('#startBtn').click();
  await take.locator('#quizView:not(.d-none)').waitFor();
  await choose(take, FX.original['1'][0]);
  await take.locator('#qNext').click();
  await choose(take, FX.original['2'][1]);
  await take.locator('#qSubmit').click();
  await take.locator('#resultView:not(.d-none)').waitFor();
  assert.match(await take.locator('#rBadge').innerText(), /不合格/);
  assert.equal(await take.locator('#rPortal').isVisible(), true);
  assert.match(await take.locator('#rPortal').getAttribute('href'), /my\.php$/);
  ok('受講を終えた画面に、マイページへ戻るリンクを出す(アカウントがある人)');
  await shot(take, '02-take-failed');
  await take.locator('#rRetry').click();
  await take.locator('#quizView:not(.d-none)').waitFor();
  ok('不合格の後の「もう一度受講する」は教材を飛ばして確認テストから');
  await choose(take, FX.correct_text['1']);
  await take.locator('#qNext').click();
  await choose(take, FX.correct_text['2']);
  await take.locator('#qSubmit').click();
  await take.locator('#resultView:not(.d-none)').waitFor();
  assert.equal(await take.locator('#rPct').innerText(), '100%');
  assert.match(await take.locator('#reviewList').innerText(), new RegExp(`${FX.correct_text['1']}\\s*正解、あなたの回答`));
  ok('並べた順で正解を選ぶと 100%、振り返りの正解と自分の解答がそろう');
  await shot(take, '03-take-passed');

  // ---- 3. 受講(アカウントなし) ----
  const noAccount = await newPage();
  await noAccount.goto(`${BASE}/take.php?token=${FX.without_account}`);
  await noAccount.locator('#startBtn').click();
  await noAccount.locator('#lessonNext').click();
  await noAccount.locator('#quizView:not(.d-none)').waitFor();
  await noAccount.locator('#qNext').click();
  await noAccount.locator('#qSubmit').click();
  await noAccount.locator('#resultView:not(.d-none)').waitFor();
  assert.equal(await noAccount.locator('#rPortal').isVisible(), false);
  ok('アカウントのない人には戻るリンクを出さない');

  // ---- 4. マイページ ----
  const my = await newPage();
  await my.goto(`${BASE}/my.php`);
  await my.locator('#loginEmail').fill('target1@example.test');
  await my.locator('#loginPassword').fill(PASSWORD);
  await my.locator('#loginBtn').click();
  await my.locator('#contactCard:not(.d-none)').waitFor();
  assert.match(await my.locator('#contactText').innerText(), /情報システム部 内線 1234/);
  ok('マイページのホームに社内の問い合わせ先');
  await my.locator('#tabGrades').click();
  await my.locator('#gradeList article').first().waitFor();
  assert.match(await my.locator('#gradeList article .correct-count').innerText(), /正解 2\/2/);
  assert.notEqual((await my.locator('#gradeList article .delivered-at').innerText()).trim(), '—');
  assert.equal(await my.locator('#gradeRate').innerText(), '100%');
  // マイページの答え合わせは元の順(元の2番目の選択肢が正解)
  const details = my.locator('#gradeList article details').last();
  await details.locator('summary').click();
  const firstQuestion = details.locator('.review-q').first();
  assert.deepEqual((await firstQuestion.locator('.opt').allInnerTexts()).map((t) => t.split(' （')[0].split('（')[0].trim()),
    FX.original['1']);
  assert.match(await firstQuestion.locator('.opt.is-correct').innerText(), new RegExp(FX.correct_text['1']));
  ok('成績に配信日時、「正解 2/2」、自分の受講完了率');
  await shot(my, '04-my-grades');

  // 狭い幅でも横にはみ出さない
  const phone = await newPage({ width: 375, height: 800 });
  await phone.goto(`${BASE}/take.php?token=${FX.without_account}`);
  await phone.locator('#resultView:not(.d-none), #errorView:not(.d-none), #landingView:not(.d-none)').first().waitFor();
  assert.equal(await phone.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
  ok('受講の画面は幅 375px で横にはみ出さない');

  assert.deepEqual(errors, [], 'ページのエラーがない');
  ok('ページのエラーがない');
} finally {
  await browser.close();
}
console.log(`ALL ${passed} PASSED`);

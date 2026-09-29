// 分野のタグ(C1、G09・G59・G60)のブラウザ E2E。合成 DB に向けたローカルのサーバーで動かす(本番に向けない)。
//   教材バンクの「分野のタグ」(共有は閲覧のみ、子のタグの作成、削除の拒否) → 設問にタグを付ける → タグで絞る
//   → 教育レポートの「分野」(分野ごと、部署×分野と CSV、月の推移、狭い幅) → マイページの分野ごとの正答率
// 準備: php fixtures/edu_tags_e2e_db.php <db> <password>
//       ドキュメントルートに web への symlink tet2 を置き、TET2_DB_PATH=<db> php -S 127.0.0.1:<port> -t <docroot> で起動する
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:<port>/tet2 TET2_E2E_EMAIL=e2e-admin@example.test TET2_E2E_PASSWORD=...
//       [TET2_E2E_SHOTS=<画面を撮る先>] node edu_tags_e2e.mjs
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

let chromium;
try { ({ chromium } = await import('playwright')); } catch { ({ chromium } = await import('/root/node_modules/playwright/index.mjs')); }

const env = (k) => process.env[k] || '';
for (const k of ['TET2_E2E_BASE_URL', 'TET2_E2E_EMAIL', 'TET2_E2E_PASSWORD']) {
  if (!env(k)) throw new Error(`${k} is required`);
}
const BASE = env('TET2_E2E_BASE_URL');
const SHOTS = env('TET2_E2E_SHOTS');
if (SHOTS) fs.mkdirSync(SHOTS, { recursive: true });
let passed = 0;
const ok = (m) => { passed += 1; console.log(`PASS: ${m}`); };
const shot = async (page, name) => {
  if (!SHOTS) return;
  await page.waitForTimeout(400);
  await page.screenshot({ path: path.join(SHOTS, `${name}.png`), fullPage: true });
};

const browser = await chromium.launch({ headless: true });
const login = async (width, height) => {
  const context = await browser.newContext({ viewport: { width, height }, acceptDownloads: true });
  const page = await context.newPage();
  page.errors = [];
  page.on('pageerror', (e) => page.errors.push(e.message));
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.locator('#loginEmail').fill(env('TET2_E2E_EMAIL'));
  await page.locator('#loginPassword').fill(env('TET2_E2E_PASSWORD'));
  await page.locator('#loginBtn').click();
  await page.locator('#appView:not(.d-none)').waitFor();
  return page;
};
const go = (page, view) => page.evaluate((v) => document.querySelector(`.app-sidebar [data-view="${v}"]`).click(), view);
const rows = (page, sel) => page.locator(`${sel} tr:not(.table-state-row)`);
const waitRows = async (page, sel, n) => {
  await page.waitForFunction(([s, count]) => {
    const tb = document.querySelector(s);
    return tb && !tb.querySelector('.table-state-row') && tb.querySelectorAll('tr').length === count;
  }, [sel, n], { timeout: 10000 });
};
// モーダルは開き切ってから操作する(フェードの途中で閉じる操作は Bootstrap に無視される。admin_ux_states_e2e.mjs と同じ)
const modalShown = (page) => page.waitForFunction(() => {
  const el = document.getElementById('appModal');
  const m = el && window.bootstrap?.Modal.getInstance(el);
  return !!m && el.classList.contains('show') && !m._isTransitioning;
});
const modalHidden = (page) => page.locator('#appModal').waitFor({ state: 'hidden', timeout: 5000 });
const closeModal = async (page) => {
  await modalShown(page);
  await page.locator('#appModal .modal-footer [data-bs-dismiss="modal"]').click();
  await modalHidden(page);
};
const noHorizontalOverflow = (page) => page.evaluate(() => {
  const main = document.querySelector('#appMain');
  return document.documentElement.scrollWidth <= window.innerWidth && main.scrollWidth <= main.clientWidth;
});

try {
  const page = await login(1440, 1000);

  // --- 教材バンク: 分野のタグのタブ ---
  await go(page, 'eduQuestions');
  await page.locator('#eduBankTab-tags').click();
  await waitRows(page, '#eduTagsBody', 2);
  const phishRow = rows(page, '#eduTagsBody').filter({ hasText: 'フィッシング' });
  const pwRow = rows(page, '#eduTagsBody').filter({ hasText: 'パスワード' });
  assert.match(await phishRow.innerText(), /メールとリンクの見分け方[\s\S]*自組織\s+1/);
  assert.equal(await phishRow.getByRole('button', { name: /編集/ }).count(), 1);
  assert.match(await pwRow.innerText(), /共有/);
  assert.equal(await pwRow.getByRole('button', { name: /編集|削除/ }).count(), 0, '共有のタグは組織管理者には編集も削除も出さない');
  ok('分野のタグのタブに、自組織のタグ(編集できる)と共有のタグ(編集できない)が出る');
  await shot(page, '01-tags');

  // 子のタグを作る(フィッシングの下)
  await phishRow.getByRole('button', { name: /子のタグ/ }).click();
  await modalShown(page);
  assert.equal(await page.locator('#eduTagParent').inputValue(), await phishRow.getAttribute('data-tag-row'));
  await page.locator('#eduTagName').fill('添付ファイル');
  await page.locator('#eduTagDesc').fill('添付ファイルを開く前の確認');
  await page.locator('#appModalSave').click();
  await modalHidden(page);
  await waitRows(page, '#eduTagsBody', 3);
  const texts = await rows(page, '#eduTagsBody').allInnerTexts();
  assert.match(texts[0], /^フィッシング/);
  assert.match(texts[1], /└\s*添付ファイル\s+添付ファイルを開く前の確認/, '子のタグは親の直後に字下げして出る');
  ok('親のタグの下に子のタグを作れる(親の直後に出る)');

  // 子のある親は削除できない(理由をモーダルに出す)
  await phishRow.getByRole('button', { name: /削除/ }).click();
  await modalShown(page);
  await page.locator('#appModalSave').click();
  await page.locator('#appModal .modal-form-error').waitFor();
  assert.match(await page.locator('#appModal .modal-form-error').innerText(), /子のタグがあるタグは削除できません/);
  await closeModal(page);
  ok('子のタグがある親の削除は、理由つきで断る');

  // --- 設問にタグを付けて、タグで絞る ---
  await page.locator('#eduBankTab-questions').click();
  await waitRows(page, '#eduQuestionsBody', 3);
  const q3 = rows(page, '#eduQuestionsBody').filter({ hasText: '添付ファイルを開く前に' });
  await q3.getByRole('button', { name: /編集/ }).click();
  await modalShown(page);
  await page.locator('#eduQTagPicker').getByLabel('添付ファイル').check();
  await page.locator('#eduQTagPicker').getByLabel('パスワード').check();
  await page.locator('#appModalSave').click();
  await modalHidden(page);
  await page.waitForFunction(() => /フィッシング > 添付ファイル/.test(document.querySelector('#eduQuestionsBody')?.innerText || ''));
  assert.match(await q3.innerText(), /フィッシング > 添付ファイル[\s\S]*パスワード/);
  ok('設問の編集で、タグを複数付けられ、一覧の設問の下に出る');

  await page.locator('#eduQTagFilter').selectOption({ label: 'フィッシング' });
  await waitRows(page, '#eduQuestionsBody', 2);
  assert.deepEqual((await rows(page, '#eduQuestionsBody').allInnerTexts()).map((t) => /不審なリンク|添付ファイルを開く前に/.test(t)), [true, true]);
  await page.locator('#eduQTagFilter').selectOption({ label: '　└ 添付ファイル' });
  await waitRows(page, '#eduQuestionsBody', 1);
  assert.match(await rows(page, '#eduQuestionsBody').innerText(), /添付ファイルを開く前に/);
  ok('タグで絞れる(親なら子のタグの設問も入る)');
  await shot(page, '02-questions-filter');
  await page.locator('#eduQTagFilter').selectOption('');
  await waitRows(page, '#eduQuestionsBody', 3);

  // 設問に付いているタグは削除できない
  await page.locator('#eduBankTab-tags').click();
  await rows(page, '#eduTagsBody').filter({ hasText: '添付ファイル' }).getByRole('button', { name: /削除/ }).click();
  await modalShown(page);
  await page.locator('#appModalSave').click();
  await page.locator('#appModal .modal-form-error').waitFor();
  assert.match(await page.locator('#appModal .modal-form-error').innerText(), /設問に付いているタグは削除できません/);
  await closeModal(page);
  ok('設問に付いているタグの削除は、理由つきで断る');

  // --- 教育レポートの「分野」 ---
  await go(page, 'eduReport');
  await page.locator('#eduReportKpi .kpi-card').first().waitFor();
  await page.locator('#eduRepTab-tags').click();
  await waitRows(page, '#eduRepTagsBody', 3);
  const tagTexts = await rows(page, '#eduRepTagsBody').allInnerTexts();
  assert.match(tagTexts[0], /フィッシング\s+2\s+50%/, 'テスト用の対象者は数えない');
  assert.match(tagTexts[1], /添付ファイル\s+0\s+解答なし$/, '解答のない分野は正答率が空欄(読み上げには「解答なし」)');
  assert.match(tagTexts[2], /パスワード\s+共有\s+2\s+50%/);
  ok('分野ごとの回答数と正答率(親と子)が出る');

  await waitRows(page, '#eduRepTagMatrixBody', 2);
  assert.deepEqual(await page.locator('#eduRepTagMatrixHead th').allInnerTexts(), ['部署', 'フィッシング', 'パスワード']);
  assert.match(await rows(page, '#eduRepTagMatrixBody').filter({ hasText: '営業部' }).innerText(), /営業部\s+100%\s+0%/);
  assert.match(await rows(page, '#eduRepTagMatrixBody').filter({ hasText: '総務部' }).innerText(), /総務部\s+0%\s+100%/);
  ok('部署×分野の表に、部署ごとの親のタグの正答率が出る');
  const [csvFile] = await Promise.all([page.waitForEvent('download'), page.locator('#eduRepTagMatrixCsv').click()]);
  const csv = fs.readFileSync(await csvFile.path());
  assert.deepEqual([...csv.subarray(0, 3)], [0xef, 0xbb, 0xbf]);
  assert.match(csv.toString('utf8'), /部署,フィッシングの正答率\(%\),フィッシングの回答数,パスワードの正答率\(%\),パスワードの回答数\r\n[\s\S]*営業部,100,1,0,1\r\n/);
  ok('部署×分野の表を CSV で出せる(BOM つき)');

  await waitRows(page, '#eduRepTagTrendBody', 2);
  assert.equal(await page.locator('#eduRepTagTrendHead th').count(), 13, '分野の列と12か月');
  const month = new Date().toISOString().slice(2, 7).replace('-', '/');
  assert.equal(await page.locator('#eduRepTagTrendHead th').last().innerText(), month);
  assert.match(await rows(page, '#eduRepTagTrendBody').first().innerText(), /^フィッシング(\s+解答なし){11}\s+50%$/);
  ok('分野ごとの月の推移に、今月の正答率が出る(解答のない月は空欄)');
  await shot(page, '03-report-tags');
  assert.deepEqual(page.errors, []);

  // 狭い幅でもページは横にはみ出さない(表は枠の中で送る)
  const phone = await login(390, 844);
  await go(phone, 'eduReport');
  await phone.locator('#eduReportKpi .kpi-card').first().waitFor();
  await phone.locator('#eduRepTab-tags').click();
  await waitRows(phone, '#eduRepTagTrendBody', 2);
  assert.equal(await noHorizontalOverflow(phone), true);
  await shot(phone, '04-report-tags-phone');
  ok('狭い幅でも分野のタブはページを横にはみ出さない');
  await phone.context().close();

  // --- マイページ: 本人の分野ごとの正答率 ---
  const learnerCtx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const my = await learnerCtx.newPage();
  await my.goto(`${BASE}/my.php`);
  await my.locator('#loginEmail').fill('sales1@example.test');
  await my.locator('#loginPassword').fill(env('TET2_E2E_PASSWORD'));
  await my.locator('#loginBtn').click();
  await my.locator('#appView:not(.d-none)').waitFor();
  await my.locator('#tabGrades').click();
  await my.locator('#tagCard:not(.d-none)').waitFor();
  const mine = await my.locator('#tagBody tr').allInnerTexts();
  assert.equal(mine.length, 2);
  assert.match(mine[0], /フィッシング\s+1\/1\s+100%/);
  assert.match(mine[1], /パスワード\s+0\/1\s+0%/);
  ok('マイページの成績に、自分の解答だけの分野ごとの正答率が出る');
  await shot(my, '05-my-tags');
  await learnerCtx.close();

  console.log(`\n${passed} checks passed`);
} finally {
  await browser.close();
}

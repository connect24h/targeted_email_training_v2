// 運用の B2B(B2-5..B2-7)のブラウザ E2E。合成 DB に向けたローカルのサーバーで動かす(本番に向けない)。
// 準備: php fixtures/ops_b2b_e2e_db.php <db> <password> <data_dir>
//       ドキュメントルートに web への symlink tet2 を置き、TET2_DB_PATH=<db> php -S 127.0.0.1:<port> -t <docroot> で起動する
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:<port>/tet2 TET2_E2E_EMAIL=e2e-admin@example.test TET2_E2E_PASSWORD=...
//       [TET2_E2E_SHOTS=<画面を撮る先>] node ops_b2b_e2e.mjs
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

let chromium;
try { ({ chromium } = await import('playwright')); } catch { ({ chromium } = await import('/root/node_modules/playwright/index.mjs')); }

const env = (k) => process.env[k] || '';
for (const k of ['TET2_E2E_BASE_URL', 'TET2_E2E_EMAIL', 'TET2_E2E_PASSWORD']) {
  if (!env(k)) throw new Error(`${k} is required`);
}
const SHOTS = env('TET2_E2E_SHOTS');
if (SHOTS) fs.mkdirSync(SHOTS, { recursive: true });
let passed = 0;
const ok = (m) => { passed += 1; console.log(`PASS: ${m}`); };
const shot = async (page, name) => {
  if (!SHOTS) return;
  await page.waitForTimeout(300);
  await page.screenshot({ path: path.join(SHOTS, `${name}.png`) });
};

const browser = await chromium.launch({ headless: true });
const login = async () => {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, acceptDownloads: true });
  const page = await context.newPage();
  page.errors = [];
  page.on('pageerror', (e) => page.errors.push(e.message));
  await page.goto(`${env('TET2_E2E_BASE_URL')}/`, { waitUntil: 'networkidle' });
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

try {
  const page = await login();

  // ===== B2-5 (G11/G39): 不審メールの報告ダッシュボード =====
  await go(page, 'suspiciousMails');
  await page.locator('#smDashboardCard summary').click();
  await page.waitForFunction(() => {
    const b = document.querySelector('#smDashboardBody');
    return b && b.textContent.includes('中央値');
  }, null, { timeout: 10000 });
  const dash = await page.locator('#smDashboardBody').innerText();
  assert.match(dash, /合計\s*4\s*件/, '合計4件');
  assert.match(dash, /訓練メール\s*2/, '訓練メール2件');
  assert.match(dash, /実メール\s*2/, '実メール2件');
  assert.match(dash, /月ごとの受付件数/, '月ごとの受付件数の見出し');
  assert.match(dash, /受付から初動まで/, '初動までの指標');
  assert.match(dash, /受付から対応済まで/, '対応済までの指標');
  await shot(page, 'sm-dashboard');
  ok('B2-5: 不審メールの報告ダッシュボード(月ごと・訓練/実・確認までの時間)が出る');

  // ===== B2-6 (G29): 種明かしページの複数管理 =====
  await go(page, 'masters');
  await waitRows(page, '#revealPagesBody', 1);
  assert.match(await page.locator('#revealPagesBody').innerText(), /営業部向けの種明かし/, '既存の種明かしページが一覧に出る');
  // 追加(名前 + HTML)
  await page.locator('button[onclick="addRevealPage()"]').click();
  await page.locator('#revealPageForm [name="name"]').waitFor();
  await page.locator('#revealPageForm [name="name"]').fill('総務部向けの種明かし');
  await page.locator('#revealPageForm [name="html"]').fill('<!DOCTYPE html><html><body><h1>訓練でした（総務部）</h1></body></html>');
  await page.locator('#appModalSave').click();
  await waitRows(page, '#revealPagesBody', 2);
  assert.match(await page.locator('#revealPagesBody').innerText(), /総務部向けの種明かし/, '追加したページが一覧に出る');
  await shot(page, 'reveal-pages');
  ok('B2-6: 種明かしページを追加でき、一覧に出る');

  // 危険な HTML は拒否される(既存の種明かし upload と同じ検証)
  await page.locator('button[onclick="addRevealPage()"]').click();
  await page.locator('#revealPageForm [name="name"]').waitFor();
  await page.locator('#revealPageForm [name="name"]').fill('危険');
  await page.locator('#revealPageForm [name="html"]').fill('<html><script>alert(1)</script></html>');
  await page.locator('#appModalSave').click();
  await page.waitForFunction(() => document.body.textContent.includes('script タグは使用できません'), null, { timeout: 5000 });
  ok('B2-6: script タグの HTML は拒否される(sanitization reuse)');
  // 開く途中(フェードイン中)に閉じる操作をすると Bootstrap が無視するので、開き切るのを待ってから閉じ、閉じたことも待つ
  await page.waitForFunction(() => {
    const el = document.getElementById('appModal');
    const m = el && window.bootstrap?.Modal.getInstance(el);
    return !!m && el.classList.contains('show') && !m._isTransitioning;
  });
  await page.locator('#appModal .btn-close, #appModal [data-bs-dismiss="modal"]').first().click();
  await page.locator('#appModal').waitFor({ state: 'hidden' });

  // キャンペーンの編集で種明かしページを選べる
  await go(page, 'campaigns');
  await page.locator('#newCampaignBtn').click();
  // 種明かしの選択欄は編集の「送信環境」の手順にあり、既定では隠れている。DOM から選択肢を読む。
  await page.locator('select[name="reveal_page_id"]').waitFor({ state: 'attached', timeout: 10000 });
  const revealOpts = await page.locator('select[name="reveal_page_id"]').evaluate((el) => el.textContent);
  assert.match(revealOpts, /既定/, '既定の選択肢がある');
  assert.match(revealOpts, /営業部向けの種明かし/, 'キャンペーン編集で種明かしページを選べる');
  ok('B2-6: キャンペーンの編集で、種明かしページを選べる');
  // 開く途中(フェードイン中)に閉じる操作をすると Bootstrap が無視するので、開き切るのを待ってから閉じ、閉じたことも待つ
  await page.waitForFunction(() => {
    const el = document.getElementById('appModal');
    const m = el && window.bootstrap?.Modal.getInstance(el);
    return !!m && el.classList.contains('show') && !m._isTransitioning;
  });
  await page.locator('#appModal .btn-close, #appModal [data-bs-dismiss="modal"]').first().click();
  await page.locator('#appModal').waitFor({ state: 'hidden' });

  // ===== B2-7 (G20): 教材の版が受講者ごとの行に出る =====
  await go(page, 'eduReport');
  await page.locator('#eduRepTab-deliveries').click();
  await waitRows(page, '#eduRepDeliveriesBody', 1);
  await rows(page, '#eduRepDeliveriesBody').getByRole('button', { name: /詳細/ }).click();
  await waitRows(page, '#eduRepPeopleBody', 1);
  assert.match(await page.locator('#eduRepPeopleBody').innerText(), /教材v2/, '受講者ごとの行に受けた教材の版(v2)が出る');
  await shot(page, 'edu-version');
  ok('B2-7: 教育レポートの受講者ごとの行に、受けた教材の版が出る');

  assert.deepEqual(page.errors, [], 'JS エラーが出ていない');
  ok('ページの JS エラーがない');

  console.log(`\n${passed} checks passed`);
} finally {
  await browser.close();
}

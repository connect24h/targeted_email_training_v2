// シナリオ画面(テンプレートの「件名＋本文」タブ)のブラウザ E2E。合成 DB に向けたローカルのサーバーで動かす(本番に向けない)。
//   - 一覧の列: シナリオ名と件名の文面を出し、本文列にシナリオ名を重ねない
//   - 行を選ぶと右の欄に件名と本文のプレビュー。新規作成したシナリオは作成後に選ばれる
//   - 修正: 新規作成で入力した全項目(シナリオ名、件名、本文の形式、本文、概要)が入った状態で開き、すべて直せる
//   - 共有プリセットと閲覧者には修正ボタンを出さない。検索で消えたら選択を外す。ほかのタブではプレビュー欄を出さない
//   - 幅の狭い画面ではプレビュー欄が一覧の下に来る
// 準備: php fixtures/scenario_templates_e2e_db.php <db> <password>
//       ドキュメントルートに web への symlink tet2 を置き、TET2_DB_PATH=<db> php -S 127.0.0.1:<port> -t <docroot> で起動する
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:<port>/tet2 TET2_E2E_PASSWORD=... node scenario_templates_e2e.mjs
import assert from 'node:assert/strict';

let chromium;
try { ({ chromium } = await import('playwright')); } catch { ({ chromium } = await import('/root/node_modules/playwright/index.mjs')); }

const env = (k) => process.env[k] || '';
for (const k of ['TET2_E2E_BASE_URL', 'TET2_E2E_PASSWORD']) {
  if (!env(k)) throw new Error(`${k} is required`);
}
const BASE = env('TET2_E2E_BASE_URL');
const PASSWORD = env('TET2_E2E_PASSWORD');
let passed = 0;
const ok = (m) => { passed += 1; console.log(`PASS: ${m}`); };

const browser = await chromium.launch({ headless: true });
const allErrors = [];
const login = async (email, viewport = { width: 1440, height: 900 }) => {
  const context = await browser.newContext({ viewport });
  const page = await context.newPage();
  page.on('pageerror', (e) => allErrors.push(e.message));
  page.on('dialog', (d) => d.accept());
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.locator('#loginEmail').fill(email);
  await page.locator('#loginPassword').fill(PASSWORD);
  await page.locator('#loginBtn').click();
  await page.locator('#appView:not(.d-none)').waitFor();
  // 幅の狭い画面ではサイドバーが畳まれているので、URL の # で開く
  const navLink = page.locator('.app-sidebar [data-view="templates"]');
  if (await navLink.isVisible()) await navLink.click();
  else await page.evaluate(() => { location.hash = 'templates'; });
  await page.locator('[data-panel="templates"]:not(.d-none)').waitFor();
  await page.locator('#templatesBody tr[data-scenario-key="sc1"]').waitFor();
  return page;
};
const row = (page, name) => page.locator('#templatesBody tr', { hasText: name });
const pane = (page) => page.locator('#tplPreviewPane');
const bodyFrameText = async (page) => (await page.frameLocator('#tplPreviewPane iframe').locator('body').textContent()).trim();

try {
  const admin = await login('e2e-admin@example.test');

  // ---- 一覧の列 ----
  assert.deepEqual(await admin.locator('#templatesHead th').allTextContents(), ['連番', 'シナリオ名', '件名', '', '']);
  const existing = row(admin, '既存の経費精算');
  assert.match(await existing.textContent(), /経費精算の差し戻し/);
  assert.equal((await existing.textContent()).split('既存の経費精算').length - 1, 1);
  assert.doesNotMatch(await existing.textContent(), /既存本文/);
  assert.equal(await admin.locator('#tplPreviewCol').isVisible(), true);
  assert.match(await pane(admin).textContent(), /行を選ぶと/);
  ok('一覧: シナリオ名と件名の文面を出し、本文列にシナリオ名を重ねない。未選択のプレビュー欄は案内だけ');

  // ---- 行を選ぶと右にプレビュー ----
  await existing.click();
  assert.match(await existing.getAttribute('class'), /table-active/);
  assert.match(await pane(admin).textContent(), /既存の経費精算[\s\S]*経費精算の差し戻し/);
  assert.equal(await bodyFrameText(admin), '既存本文');
  assert.equal(await admin.locator('#appModal.show').count(), 0, '行のクリックでモーダルは開かない');
  await admin.locator('#tplPaneSource').click();
  assert.match(await admin.locator('#tplPaneSourceView').textContent(), /<p>既存本文<\/p>/);
  await admin.locator('#tplPaneSource').click();
  assert.equal(await admin.locator('#tplPaneSourceView').isVisible(), false);
  ok('行の選択: 行を強調し、右の欄に件名と本文(sandbox iframe)を出す。HTMLソースと切り替えられる');

  // ---- 新規作成 → 作ったシナリオが選ばれる ----
  await admin.locator('#newScenarioBtn').click();
  await admin.locator('#scenForm').waitFor();
  await admin.locator('#scenForm [name=name]').fill('人事部のマイナンバー確認');
  await admin.locator('#scenSubject').fill('【至急】マイナンバーの確認 #$2$#様');
  await admin.locator('#scenForm [name=format]').selectOption('html');
  await admin.locator('#tplContent').fill('<p>新規本文 #$2$# 様</p>');
  await admin.locator('#scenDescription').fill('新規の概要');
  await admin.locator('#appModalSave').click();
  await admin.locator('#appModal').waitFor({ state: 'hidden' });
  const created = row(admin, '人事部のマイナンバー確認');
  await created.waitFor();
  assert.match(await created.getAttribute('class'), /table-active/);
  assert.match(await pane(admin).textContent(), /【至急】マイナンバーの確認 山田様/);
  assert.equal(await bodyFrameText(admin), '新規本文 山田 様');
  ok('新規作成: 作ったシナリオを選んだ状態で一覧とプレビューに出す(差込はサンプル値)');

  // ---- 修正: 全項目が入った状態で開き、全部直せる ----
  await admin.locator('#tplPaneEdit').click();
  await admin.locator('#scenForm').waitFor();
  assert.equal(await admin.locator('#scenForm [name=name]').inputValue(), '人事部のマイナンバー確認');
  assert.equal(await admin.locator('#scenSubject').inputValue(), '【至急】マイナンバーの確認 #$2$#様');
  assert.equal(await admin.locator('#scenForm [name=format]').inputValue(), 'html');
  assert.equal(await admin.locator('#tplContent').inputValue(), '<p>新規本文 #$2$# 様</p>');
  assert.equal(await admin.locator('#scenDescription').inputValue(), '新規の概要');
  ok('修正画面: 新規作成で入力した5項目がすべて入った状態で開く');

  await admin.locator('#scenForm [name=name]').fill('総務部の年末調整');
  await admin.locator('#scenSubject').fill('年末調整の書類提出のお願い');
  await admin.locator('#scenForm [name=format]').selectOption('text');
  await admin.locator('#tplContent').fill('テキストの本文です');
  await admin.locator('#scenDescription').fill('改訂した概要');
  await admin.locator('#appModalSave').click();
  await admin.locator('#appModal').waitFor({ state: 'hidden' });
  const edited = row(admin, '総務部の年末調整');
  await edited.waitFor();
  assert.equal(await row(admin, '人事部のマイナンバー確認').count(), 0);
  assert.match(await edited.textContent(), /年末調整の書類提出のお願い[\s\S]*/);
  assert.match(await edited.textContent(), /改訂した概要/);
  assert.match(await pane(admin).textContent(), /総務部の年末調整[\s\S]*改訂した概要[\s\S]*年末調整の書類提出のお願い[\s\S]*本文（テキスト）/);
  assert.equal(await admin.locator('#tplPaneRendered pre').textContent(), 'テキストの本文です');
  await admin.locator('#tplPaneEdit').click();
  await admin.locator('#scenForm').waitFor();
  assert.equal(await admin.locator('#scenForm [name=format]').inputValue(), 'text');
  await admin.locator('#appModal .btn-close, #appModal [data-bs-dismiss="modal"]').first().click();
  await admin.locator('#appModal').waitFor({ state: 'hidden' });
  ok('修正の保存: シナリオ名・件名・本文の形式・本文・概要がすべて変わり、一覧とプレビューに反映する');

  // ---- 共有プリセット: 組織管理者には修正と削除を出さない ----
  await row(admin, '共有の宅配不在通知').click();
  assert.equal(await bodyFrameText(admin), '共有本文のプレビュー');
  assert.equal(await admin.locator('#tplPaneEdit').count(), 0);
  assert.equal(await admin.locator('#tplPaneDelete').count(), 0);
  ok('共有プリセット: プレビューは見えるが、システム管理者でなければ修正と削除は出ない');

  // ---- 検索で消えたら選択を外す、ほかのタブではプレビュー欄を出さない ----
  await admin.locator('#tplSearch').fill('経費');
  await admin.waitForFunction(() => document.querySelectorAll('#templatesBody tr').length === 1);
  assert.match(await pane(admin).textContent(), /行を選ぶと/);
  await admin.locator('#tplSearch').fill('');
  await admin.locator('#tplKindTabs a', { hasText: 'ネタバラシ' }).click();
  assert.equal(await admin.locator('#tplPreviewCol').isVisible(), false);
  await admin.locator('#tplKindTabs a', { hasText: '件名＋本文' }).click();
  assert.equal(await admin.locator('#tplPreviewCol').isVisible(), true);
  ok('検索で見えなくなった選択は外す。ほかのタブではプレビュー欄を出さない');

  // ---- 閲覧者: プレビューだけ ----
  const viewer = await login('e2e-viewer@example.test');
  await row(viewer, '総務部の年末調整').click();
  assert.equal(await viewer.locator('#tplPaneRendered pre').textContent(), 'テキストの本文です');
  assert.equal(await viewer.locator('#tplPaneEdit').count(), 0);
  ok('閲覧者: プレビューは見えるが修正ボタンは出ない');

  // ---- 幅の狭い画面: プレビュー欄は一覧の下 ----
  const mobile = await login('e2e-admin@example.test', { width: 390, height: 844 });
  await row(mobile, '既存の経費精算').click();
  const tableBox = await mobile.locator('#tplListCol').boundingBox();
  const paneBox = await mobile.locator('#tplPreviewCol').boundingBox();
  assert.ok(paneBox.y >= tableBox.y + tableBox.height - 1, '欄は一覧の下');
  await mobile.waitForFunction(() => {
    const r = document.querySelector('#tplPreviewCol').getBoundingClientRect();
    return r.top < window.innerHeight && r.bottom > 0;
  }, null, { timeout: 3000 });
  ok('幅の狭い画面: プレビュー欄は一覧の下に出て、行を選ぶとそこへスクロールする');

  assert.deepEqual(allErrors, [], `ページのエラー: ${allErrors.join(' / ')}`);
  ok('ページのエラーなし');
} finally {
  await browser.close();
}
console.log(`ALL ${passed} CHECKS PASSED`);

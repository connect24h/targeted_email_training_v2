// 管理画面の画面ごとの作りのブラウザ E2E(計画 tet2-admin-ux-states の B1〜B4)。
// 合成 DB に向けたローカルのサーバーで動かす(本番に向けない)。偽ログインのテンプレートが0件の合成 DB から始める。
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:8765/tet2 TET2_E2E_EMAIL=... TET2_E2E_PASSWORD=... node admin_ux_states_e2e.mjs
import assert from 'node:assert/strict';
import { chromium } from 'playwright';

const env = (k) => process.env[k] || '';
for (const k of ['TET2_E2E_BASE_URL', 'TET2_E2E_EMAIL', 'TET2_E2E_PASSWORD']) {
  if (!env(k)) throw new Error(`${k} is required`);
}
let passed = 0;
const ok = (m) => { passed += 1; console.log(`PASS: ${m}`); };

const browser = await chromium.launch({ headless: true });
const page = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('dialog', (d) => d.accept());
const go = (view) => page.locator(`.app-sidebar [data-view="${view}"]`).click();
const closeModal = async () => {
  await page.locator('#appModal .modal-footer [data-bs-dismiss="modal"]').click();
  await page.locator('#appModal').waitFor({ state: 'hidden', timeout: 5000 });
};
const templates = () => page.evaluate(() => api('api/templates.php', { query: { action: 'list' } }).then((r) => r.templates));

try {
  await page.goto(`${env('TET2_E2E_BASE_URL')}/`, { waitUntil: 'networkidle' });
  await page.locator('#loginEmail').fill(env('TET2_E2E_EMAIL'));
  await page.locator('#loginPassword').fill(env('TET2_E2E_PASSWORD'));
  await page.locator('#loginBtn').click();
  await page.locator('#appView:not(.d-none)').waitFor();

  // --- B1 偽ログインのテンプレート ---
  assert.equal((await templates()).filter((t) => t.kind === 'phish_login').length, 0, '偽ログイン0件から始める');
  await go('campaigns');
  await page.locator('#newCampaignBtn').click();
  await page.locator('.c-phish').first().waitFor({ state: 'attached' });
  assert.match(await page.locator('#appModal').innerText(), /偽ログインのテンプレートがありません/);
  ok('偽ログインが0件なら、キャンペーンの作成に作り方の案内が出る');
  await closeModal();

  await go('templates');
  await page.locator('#newTemplateBtn').click();
  await page.locator('#tplForm').waitFor();
  assert.equal(await page.locator('#tplAuthField').isVisible(), false);
  await page.locator('#tplForm [name=kind]').selectOption('phish_login');
  assert.equal(await page.locator('#tplAuthField').isVisible(), true);
  ok('種別を偽ログインにした時だけ、認証の種別を選べる');
  await page.locator('#tplForm [name=name]').fill('E2E 偽ログイン M365');
  await page.locator('#tplAuthFlag').selectOption('2');
  await page.locator('#tplForm [name=content]').fill('<html><body><form>ログイン</form></body></html>');
  await page.locator('#appModalSave').click();
  await page.waitForFunction(() => !document.querySelector('#appModal.show'), null, { timeout: 10000 });
  const created = (await templates()).find((t) => t.name === 'E2E 偽ログイン M365');
  assert.ok(created, '偽ログインのテンプレートが作られた');
  assert.equal(created.kind, 'phish_login');
  assert.equal(Number(created.auth_flag), 2);
  ok('画面から偽ログインのテンプレートを作れ、認証の種別が保存される');

  await go('campaigns');
  await page.locator('#newCampaignBtn').click();
  await page.locator('.c-phish').first().waitFor({ state: 'attached' });
  assert.match(await page.locator('.c-phish').first().innerText(), /E2E 偽ログイン M365/);
  assert.doesNotMatch(await page.locator('#appModal').innerText(), /偽ログインのテンプレートがありません/);
  ok('作った偽ログインがキャンペーンの作成で選べ、案内は消える');
  await closeModal();

  assert.deepEqual(errors, []);
  console.log(`\n${passed} passed`);
} finally {
  await browser.close();
}

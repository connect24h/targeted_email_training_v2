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
  // 開く途中(フェードイン中)に閉じる操作をすると Bootstrap が無視するので、開き切るのを待ってから閉じる
  await page.waitForFunction(() => {
    const el = document.getElementById('appModal');
    const m = el && window.bootstrap?.Modal.getInstance(el);
    return !!m && el.classList.contains('show') && !m._isTransitioning;
  });
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

  // --- B2 読み込みの状態 ---
  let release;
  const gate = new Promise((resolve) => { release = resolve; });
  await page.route('**/api/groups.php?action=list*', async (route) => { await gate; await route.continue(); });
  await go('groups');
  await page.locator('#groupsBody tr[data-state="loading"]').waitFor();
  ok('一覧を読み込んでいる間は「読み込み中」の行が出る');
  release();
  await page.locator('#groupsBody tr[data-state="loading"]').waitFor({ state: 'detached' });
  await page.unroute('**/api/groups.php?action=list*');
  await page.route('**/api/groups.php?action=list*', (route) => route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false, error: 'サーバエラー' }) }));
  await go('dashboard');
  await go('groups');
  const errorRow = page.locator('#groupsBody tr[data-state="error"]');
  await errorRow.waitFor();
  assert.match(await errorRow.innerText(), /読み込めませんでした/);
  await page.unroute('**/api/groups.php?action=list*');
  await errorRow.getByRole('button', { name: /再読み込み/ }).click();
  // 失敗の行は、まず読み込み中の行に替わり、そのあと一覧になる
  await page.waitForFunction(() => !document.querySelector('#groupsBody tr[data-state]'), null, { timeout: 5000 });
  ok('読み込みに失敗すると理由と再読み込みが出て、押すと読み直す');

  // --- B3 フォームのエラー ---
  let createCalls = 0;
  await page.route('**/api/groups.php?action=create*', (route) => { createCalls += 1; return route.continue(); });
  await page.locator('#newGroupBtn').click();
  await page.locator('#groupForm').waitFor();
  await page.locator('#appModalSave').click();
  const nameField = page.locator('#groupForm [name=name]');
  assert.match(await nameField.getAttribute('class'), /is-invalid/);
  assert.equal(await page.locator('#groupForm .invalid-feedback').innerText(), '名称を入力してください');
  assert.equal(createCalls, 0);
  assert.equal(await page.evaluate(() => document.activeElement?.name), 'name');
  ok('必須の欄が空なら、送らずに欄の下へラベルの名前で理由を出し、その欄に移る');
  await nameField.fill('E2E グループ');
  assert.equal(await page.locator('#groupForm .invalid-feedback').count(), 0);
  await page.unroute('**/api/groups.php?action=create*');
  await page.route('**/api/groups.php?action=create*', (route) => route.fulfill({ status: 400, contentType: 'application/json', body: JSON.stringify({ success: false, error: 'kind が不正です' }) }));
  await page.locator('#appModalSave').click();
  await page.locator('#groupForm .invalid-feedback').waitFor();
  assert.equal(await page.locator('#groupForm .invalid-feedback').innerText(), '種別が不正です');
  ok('サーバーが項目の名前で返したエラーも、該当の欄の下に画面のラベルで出す');

  // --- B4 フォーカス ---
  await page.unroute('**/api/groups.php?action=create*');
  await page.route('**/api/groups.php?action=create*', (route) => route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false, error: 'サーバエラー' }) }));
  await page.locator('#appModalSave').click();
  await page.locator('#appModalBody .modal-form-error').waitFor();
  assert.equal(await page.evaluate(() => document.activeElement?.id), 'appModalSave');
  ok('保存に失敗すると、モーダルの中に理由が出て、フォーカスは保存ボタンに戻る');
  await page.evaluate(() => document.activeElement.blur());
  await page.keyboard.press('Escape');
  await page.locator('#appModal').waitFor({ state: 'hidden', timeout: 5000 });
  // 背景の幕が消えてから閉じた合図(hidden.bs.modal)が出るので、フォーカスが移るのを待つ
  await page.waitForFunction(() => document.activeElement?.id === 'newGroupBtn', null, { timeout: 3000 });
  ok('フォーカスがモーダルの外でも Escape で閉じ、閉じたら開いたボタンに戻る');
  await page.unroute('**/api/groups.php?action=create*');

  await go('campaigns');
  await page.locator('#newCampaignBtn').click();
  await page.locator('[data-editor-mode]').waitFor();
  await page.locator('[data-editor-mode]').click();
  await page.locator('#appModal [name=name]').first().fill('E2E 対象者なし');
  await page.locator('#appModal [name=start_at]').fill('2030-05-01T09:00');
  await page.locator('#appModal [name=end_at]').fill('2030-05-02T18:00');
  await page.locator('#appModal [name=from_address]').fill('info@example.test');
  await page.locator('#appModalSave').click();
  await page.locator('#appModal [name=group_ids].is-invalid').waitFor();
  assert.equal(await page.locator('[data-campaign-step="targets"]').isVisible(), true);
  assert.equal(await page.locator('[data-campaign-step="review"]').isVisible(), false);
  assert.equal(await page.evaluate(() => document.activeElement?.name), 'group_ids');
  ok('キャンペーンの対象者が空なら、対象者の手順へ移って欄の下に理由を出す');
  await closeModal();

  // --- B5 訓練レポートの詳細のタブ ---
  await go('reports');
  // 読み込み中の行ではなく、押すと詳細を開く行が出るのを待つ
  await page.locator('#reportsBody tr[onclick]').first().click();
  await page.locator('#reportDetail:not(.d-none)').waitFor();
  // 概要、防衛失敗者、会社・役職別、コンテンツ別、ビーコン明細、利用者ごと、行動履歴(段B1 で2つ足した)
  assert.equal(await page.locator('#reportTabs [role="tab"]').count(), 7);
  assert.equal(await page.locator('#reportPane-overview').isVisible(), true);
  assert.equal(await page.locator('#reportBeaconsBody').isVisible(), false);
  assert.ok(await page.locator('#reportChart').evaluate((c) => c.getBoundingClientRect().width) > 100, '概要のグラフが描かれる');
  assert.equal(await page.locator('#reportPeriodBtn').isVisible(), true);
  await page.locator('#reportTab-beacons').click();
  await page.locator('#reportPane-beacons.show').waitFor();
  assert.equal(await page.locator('#reportTab-beacons').getAttribute('aria-selected'), 'true');
  assert.equal(await page.locator('#reportPane-overview').isVisible(), false);
  assert.equal(await page.locator('#reportPeriodBtn').isVisible(), true);
  await page.locator('#reportTab-beacons').focus();
  await page.keyboard.press('ArrowLeft');
  await page.locator('#reportPane-contents.show').waitFor();
  await page.locator('#reportTab-overview').click();
  await page.locator('#reportPane-overview.show').waitFor();
  assert.ok(await page.locator('#reportTimelineChart').evaluate((c) => c.getBoundingClientRect().width) > 100, '戻った時もグラフが描かれている');
  ok('訓練レポートの詳細は7つのタブに分かれ、期間と確定の操作はどのタブでも使え、矢印キーでも移れる');

  await page.locator('#logoutBtn').click();
  await page.locator('#loginView:not(.d-none)').waitFor();
  assert.equal(new URL(page.url()).hash, '');
  assert.equal(await page.evaluate(() => document.activeElement?.id), 'loginEmail');
  ok('ログアウトすると URL の画面の位置が消え、メールアドレスの欄から始まる');

  assert.deepEqual(errors, []);
  console.log(`\n${passed} passed`);
} finally {
  await browser.close();
}

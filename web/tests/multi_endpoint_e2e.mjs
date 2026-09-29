// マルチエンドポイント(ビーコンURL・送信元アドレスの複数選択＋振り分け)のブラウザ E2E。
// 合成 DB に向けたローカルのサーバーで動かす(本番に向けない)。
// 準備: php fixtures/multi_endpoint_e2e_db.php <db> <password>
//       ドキュメントルートに web への symlink tet2 を置き、TET2_DB_PATH=<db> php -S 127.0.0.1:<port> -t <docroot> で起動する
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:<port>/tet2 TET2_E2E_EMAIL=e2e-admin@example.test TET2_E2E_PASSWORD=...
//       [TET2_E2E_SUPER_EMAIL=e2e-super@example.test] node multi_endpoint_e2e.mjs
import assert from 'node:assert/strict';

let chromium;
try { ({ chromium } = await import('playwright')); } catch { ({ chromium } = await import('/root/node_modules/playwright/index.mjs')); }

const env = (k) => process.env[k] || '';
for (const k of ['TET2_E2E_BASE_URL', 'TET2_E2E_EMAIL', 'TET2_E2E_PASSWORD']) {
  if (!env(k)) throw new Error(`${k} is required`);
}
let passed = 0;
const ok = (m) => { passed += 1; console.log(`PASS: ${m}`); };

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('dialog', (d) => d.accept());
const go = (view) => page.evaluate((v) => document.querySelector(`.app-sidebar [data-view="${v}"]`).click(), view);
const closeModal = async () => {
  // Bootstrap のモーダルは開き切ってから閉じる(admin_ux_states_e2e.mjs と同じ)。
  await page.waitForFunction(() => {
    const el = document.getElementById('appModal');
    const m = el && window.bootstrap?.Modal.getInstance(el);
    return !!m && el.classList.contains('show') && !m._isTransitioning;
  });
  await page.locator('#appModal .modal-footer [data-bs-dismiss="modal"]').click();
  await page.locator('#appModal').waitFor({ state: 'hidden', timeout: 5000 });
};
const login = async (email) => {
  await page.goto(`${env('TET2_E2E_BASE_URL')}/`, { waitUntil: 'networkidle' });
  await page.locator('#loginEmail').fill(email);
  await page.locator('#loginPassword').fill(env('TET2_E2E_PASSWORD'));
  await page.locator('#loginBtn').click();
  await page.locator('#appView:not(.d-none)').waitFor();
};

try {
  await login(env('TET2_E2E_EMAIL'));

  // ===== 到達画面: 送信エンドポイントのマスタ管理 =====
  await go('masters');
  await page.locator('#sendEndpointsBody').waitFor();
  await page.waitForFunction(() => !document.querySelector('#sendEndpointsBody tr td.text-muted'));
  const epText = await page.locator('#sendEndpointsBody').innerText();
  assert.match(epText, /track1\.example\.test/, 'テナントの beacon がマスタ一覧に出る');
  assert.match(epText, /sales@example\.test/, 'テナントの from がマスタ一覧に出る');
  assert.match(epText, /85\.131\.251\.224/, '共有の既定 beacon がマスタ一覧に出る');
  assert.match(epText, /共有/, '共有の範囲バッジが出る');
  ok('マスタ管理に共有＋テナントのエンドポイントが一覧表示される');

  // マスタから from を1件追加する
  await page.locator('button[onclick="addSendEndpoint(\'from\')"]').click();
  await page.locator('#sendEndpointForm').waitFor();
  await page.locator('#sendEndpointForm [name=value]').fill('newsletter@example.test');
  await page.locator('#sendEndpointForm [name=label]').fill('お知らせ');
  await page.locator('#appModalSave').click();
  await page.waitForFunction(() => !document.querySelector('#appModal.show'), null, { timeout: 10000 });
  await page.waitForFunction(() => document.querySelector('#sendEndpointsBody').innerText.includes('newsletter@example.test'));
  ok('マスタから送信元を追加できる');

  // 不正な beacon(非URL)は弾かれる(サーバの検証)
  const badRes = await page.evaluate(() => api('api/send_endpoints.php', { method: 'POST', query: { action: 'create' }, body: { kind: 'beacon', value: 'not-a-url' } }).then(() => 'ok').catch((e) => e.message));
  assert.match(String(badRes), /URL/, '非URLの beacon はマスタ API が拒否する');
  ok('kind=beacon の非URLはサーバで拒否される');

  // ===== キャンペーン編集: 複数選択＋直接入力 =====
  await go('campaigns');
  await page.locator('#newCampaignBtn').click();
  await page.locator('#campaignForm [data-ep-name="from"]').waitFor({ state: 'attached' });
  // 手順表示だと「送信環境」が隠れているので「すべて表示」にして全欄を出す。
  await page.locator('#campaignForm [data-editor-mode]').click();
  await page.locator('#campaignForm [data-ep-name="from"]').waitFor({ state: 'visible' });
  ok('送信環境に送信元・ビーコンの複数選択ウィジェットが出る');

  // 送信元: 候補から2件チェック + 直接入力1件
  const fromWidget = page.locator('#campaignForm [data-ep-name="from"]');
  // value で確実に候補2件を選ぶ
  await page.evaluate(() => {
    const w = document.querySelector('#campaignForm [data-ep-name="from"]');
    w.querySelectorAll('.ep-check').forEach((c) => { if (['sales@example.test', 'support@example.test'].includes(c.value)) c.checked = true; });
  });
  await fromWidget.locator('.ep-add-input').fill('direct@example.test');
  await fromWidget.locator('.ep-add-btn').click();
  assert.match(await fromWidget.locator('.ep-added').innerText(), /direct@example\.test/, '直接入力の送信元がチップになる');
  ok('送信元を候補から複数選び、直接入力も足せる');

  // ビーコン: 共有の既定 + テナント beacon の2件を選ぶ
  await page.evaluate(() => {
    const w = document.querySelector('#campaignForm [data-ep-name="beacon"]');
    w.querySelectorAll('.ep-check').forEach((c) => { if (['http://85.131.251.224/', 'https://track1.example.test/'].includes(c.value)) c.checked = true; });
  });

  // 収集関数が期待どおりの配列を返す
  const collected = await page.evaluate(() => ({
    from: collectEndpointWidget(document.querySelector('#campaignForm [data-ep-name="from"]')),
    beacon: collectEndpointWidget(document.querySelector('#campaignForm [data-ep-name="beacon"]')),
  }));
  assert.deepEqual(collected.from, ['sales@example.test', 'support@example.test', 'direct@example.test'], '送信元の選択が配列で集まる');
  assert.deepEqual(collected.beacon, ['http://85.131.251.224/', 'https://track1.example.test/'], 'ビーコンの選択が配列で集まる');
  ok('複数選択＋直接入力が配列として収集される');

  // シナリオ(件名・本文・偽ログイン)を選ぶ。既定でシナリオが選ばれるので偽ログインだけ選ぶ。
  await page.evaluate(() => {
    const row = document.querySelector('#contentsList .content-row');
    const phish = row.querySelector('.c-phish');
    if (phish && phish.options.length > 1) phish.value = phish.options[1].value;
  });

  // 対象者(全職員グループ)を選ぶ
  await page.evaluate(() => {
    const sel = document.querySelector('#campaignForm [name=group_ids]');
    if (sel && sel.options.length) { sel.options[0].selected = true; sel.dispatchEvent(new Event('change')); }
  });
  // 名前・日時
  await page.locator('#campaignForm [name=name]').fill('E2E マルチエンドポイント');
  await page.locator('#campaignForm [name=start_at]').fill('2027-01-10T09:00');
  await page.locator('#campaignForm [name=end_at]').fill('2027-01-20T18:00');

  await page.locator('#appModalSave').click();
  await page.waitForFunction(() => !document.querySelector('#appModal.show'), null, { timeout: 15000 });
  ok('複数選択のキャンペーンを保存できる');

  // 保存された内容を API で確認: 単数列=先頭、JSON=全件
  const saved = await page.evaluate(async () => {
    const list = await api('api/campaigns.php', { query: { action: 'list' } });
    const c = (list.campaigns || []).find((x) => x.name === 'E2E マルチエンドポイント');
    return c ? await api('api/campaigns.php', { query: { action: 'get', id: c.id } }) : null;
  });
  assert.ok(saved, 'キャンペーンが作成された');
  assert.equal(saved.campaign.from_address, 'sales@example.test', '単数 from_address は先頭要素');
  assert.deepEqual(JSON.parse(saved.campaign.from_addresses), ['sales@example.test', 'support@example.test', 'direct@example.test'], 'from_addresses に全件が JSON で保存される');
  assert.equal(saved.campaign.beacon_base, 'http://85.131.251.224/', '単数 beacon_base は先頭要素');
  assert.deepEqual(JSON.parse(saved.campaign.beacon_bases), ['http://85.131.251.224/', 'https://track1.example.test/'], 'beacon_bases に全件が JSON で保存される');
  ok('複数選択が JSON 配列＋先頭の単数列として保存される');

  // 編集で開くと選択が復元される
  await go('campaigns');
  await page.locator('#campaignsBody').waitFor();
  await page.evaluate(async (name) => {
    const list = await api('api/campaigns.php', { query: { action: 'list' } });
    const c = (list.campaigns || []).find((x) => x.name === name);
    openCampaignModal(c.id);
  }, 'E2E マルチエンドポイント');
  await page.locator('#campaignForm [data-ep-name="from"]').waitFor({ state: 'attached' });
  await page.waitForFunction(() => {
    const w = document.querySelector('#campaignForm [data-ep-name="from"]');
    return w && collectEndpointWidget(w).length === 3;
  });
  const restored = await page.evaluate(() => ({
    from: collectEndpointWidget(document.querySelector('#campaignForm [data-ep-name="from"]')),
    beacon: collectEndpointWidget(document.querySelector('#campaignForm [data-ep-name="beacon"]')),
  }));
  assert.deepEqual(restored.from, ['sales@example.test', 'support@example.test', 'direct@example.test'], '編集で送信元の選択が復元される');
  assert.deepEqual(restored.beacon, ['http://85.131.251.224/', 'https://track1.example.test/'], '編集でビーコンの選択が復元される');
  ok('編集時に複数選択が復元される(候補はチェック・直接入力はチップ)');
  await closeModal();

  assert.deepEqual(errors, [], `ページ内 JS エラーがない: ${errors.join(' / ')}`);
  ok('操作中に JS エラーが出ない');

  console.log(`\nALL ${passed} E2E CHECKS PASSED`);
} finally {
  await browser.close();
}

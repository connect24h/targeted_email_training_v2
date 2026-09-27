// 管理画面のブラウザ E2E: 教育配信の作成画面の新しい項目(予約、毎月くり返す、役職、訓練の結果、新入社員、案内メール)。
// 合成 DB に向けたローカルのサーバーで動かす(本番に向けない)。終わったキャンペーンと役職区分の対象者が要る。
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:8765 TET2_E2E_EMAIL=... TET2_E2E_PASSWORD=... [TET2_E2E_SHOTS=<画面を撮る先>]
//       node edu_delivery_features_e2e.mjs
import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

const env = (k) => process.env[k] || '';
for (const k of ['TET2_E2E_BASE_URL', 'TET2_E2E_EMAIL', 'TET2_E2E_PASSWORD']) {
  if (!env(k)) throw new Error(`${k} is required`);
}
const shots = env('TET2_E2E_SHOTS');
if (shots) await mkdir(shots, { recursive: true });
let passed = 0;
const ok = (m) => { passed += 1; console.log(`PASS: ${m}`); };
const shot = async (page, name) => { if (shots) await page.screenshot({ path: `${shots}/${name}.png`, fullPage: true }); };

const browser = await chromium.launch({ headless: true });
const page = await (await browser.newContext({ viewport: { width: 1440, height: 1100 } })).newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('dialog', (d) => d.accept());
const visible = (sel) => page.locator(sel).isVisible();
const deliveries = () => page.evaluate(() => api('api/edu_deliveries.php', { query: { action: 'list' } }).then((r) => r.deliveries));
const openForm = async () => {
  await page.locator('#newEduDeliveryBtn').click();
  await page.locator('#eduDeliveryForm').waitFor();
};
const save = async () => {
  await page.locator('#appModalSave').click();
  await page.waitForFunction(() => !document.querySelector('#appModal.show'), null, { timeout: 15000 });
};
const form = (name) => page.locator(`#eduDeliveryForm [name="${name}"]`);

try {
  await page.goto(`${env('TET2_E2E_BASE_URL')}/`, { waitUntil: 'networkidle' });
  await page.locator('#loginEmail').fill(env('TET2_E2E_EMAIL'));
  await page.locator('#loginPassword').fill(env('TET2_E2E_PASSWORD'));
  await page.locator('#loginBtn').click();
  await page.locator('#appView:not(.d-none)').waitFor();
  await page.evaluate(() => document.querySelector('[data-view="eduDeliveries"]')?.click());
  await page.locator('#eduSeriesBody').waitFor({ state: 'attached' });
  ok('教育配信の画面に「毎月の配信」の一覧がある');

  // --- 1. 役職区分 + 予約の日時 ---
  await openForm();
  assert.equal(await form('send_invites').isChecked(), false);
  ok('案内メールのチェックは既定で外れている');
  assert.equal(await visible('#eduRiskResultField'), false);
  await page.locator('#eduAutoEnroll').uncheck();
  assert.equal(await visible('#eduRiskResultField'), true);
  assert.match(await page.locator('#eduRiskCampaign').innerText(), /Fixture Campaign/);
  ok('訓練の結果で選ぶと、終わったキャンペーンと結果の区分が出る');
  await shot(page, 'f01-risk-results');
  await form('title').fill('E2E 役員向けの予約');
  await form('delivery_type').selectOption('awareness_quiz');
  await page.locator('#eduTargetType').selectOption('position');
  assert.equal(await visible('#eduPositionField'), true);
  await page.locator('#eduDeliveryForm input[name="target_positions"][value="役員"]').check();
  await form('scheduled_at').fill('2030-04-01T09:00');
  await form('deadline').fill('2030-04-15');
  await shot(page, 'f02-position-scheduled');
  await save();
  await page.locator('#eduDeliveriesBody tr', { hasText: 'E2E 役員向けの予約' }).getByText('2030-04-01 09:00').waitFor();
  let d = (await deliveries()).find((x) => x.title === 'E2E 役員向けの予約');
  assert.equal(d.status, 'scheduled');
  assert.equal(d.target_type, 'position');
  assert.deepEqual(JSON.parse(d.target_positions), ['役員']);
  assert.equal(Number(d.send_invites), 0);
  ok('役職区分と予約の日時で作ると、一覧に予約の日時が出て、案内メールは送らない設定になる');

  // --- 2. 毎月くり返す ---
  await openForm();
  await form('title').fill('E2E 月例の小問');
  await form('delivery_type').selectOption('awareness_quiz');
  await page.locator('#eduTargetType').selectOption('all');
  await page.locator('#eduRepeatMonthly').check();
  assert.equal(await visible('#eduSeriesFields'), true);
  assert.equal(await visible('#eduOnceFields'), false);
  await form('day_of_month').fill('10');
  await form('time_of_day').fill('08:30');
  await form('send_invites').check();
  await shot(page, 'f03-monthly');
  await save();
  await page.locator('#eduSeriesBody tr', { hasText: 'E2E 月例の小問' }).getByText('毎月10日 08:30').waitFor();
  ok('毎月くり返すで作ると、毎月の配信の一覧に出る');
  await shot(page, 'f04-series-list');

  // --- 3. 新入社員 ---
  await openForm();
  await form('title').fill('E2E 入社の日');
  await form('delivery_type').selectOption('awareness_quiz');
  await page.locator('#eduTargetType').selectOption('new_target');
  assert.equal(await visible('#eduNewTargetField'), true);
  assert.equal(await visible('#eduRepeatField'), false);
  await form('new_target_days').fill('45');
  await save();
  d = (await deliveries()).find((x) => x.title === 'E2E 入社の日');
  assert.equal(d.triggered_by, 'new_target');
  assert.equal(Number(d.new_target_days), 45);
  assert.equal(d.target_type, 'all');
  ok('新入社員で作ると、triggered_by=new_target と日数が保存される');

  // --- 4. 訓練の結果(報告した、開かなかった) ---
  await openForm();
  await form('title').fill('E2E 報告した人へ');
  await form('delivery_type').selectOption('awareness_quiz');
  await page.locator('#eduAutoEnroll').uncheck();
  const campaignValue = await page.locator('#eduRiskCampaign option', { hasText: 'Fixture Campaign' }).getAttribute('value');
  await page.locator('#eduRiskCampaign').selectOption(campaignValue);
  await page.locator('#eduRisk_opened').uncheck();
  await page.locator('#eduRisk_submitted').uncheck();
  await page.locator('#eduRisk_reported').check();
  await page.locator('#eduRisk_not_opened').check();
  await save();
  d = (await deliveries()).find((x) => x.title === 'E2E 報告した人へ');
  assert.deepEqual(JSON.parse(d.risk_results), ['reported', 'not_opened']);
  assert.ok(Number(d.phish_campaign_id) > 0);
  ok('訓練の結果の区分とキャンペーンが保存される');

  // --- 5. 毎月の配信を停止する ---
  const seriesRow = page.locator('#eduSeriesBody tr', { hasText: 'E2E 月例の小問' });
  await seriesRow.getByRole('button', { name: /停止/ }).click();
  await seriesRow.getByText('停止').first().waitFor();
  await page.waitForFunction(() => /停止/.test(document.querySelector('#eduSeriesBody').innerText)
    && !document.querySelector('#eduSeriesBody button'));
  ok('毎月の配信を停止できる');
  await shot(page, 'f05-series-stopped');

  assert.deepEqual(errors, []);
  ok('画面のエラーがない');
  console.log(`ALL ${passed} E2E CHECKS PASSED`);
} finally {
  await browser.close();
}

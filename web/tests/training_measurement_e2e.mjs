// 測定の正しさ(段B1)のブラウザ E2E(本物の API の入口を通す)。合成 DB に向けたローカルのサーバーで動かす(本番に向けない)。
//   - 訓練のレポートに誤計上の注意が常に出る
//   - 行動履歴: 装置の行も判定と理由つきで出る。オペレータが「利用者にする」と集計(クリック数)に入る
//   - 利用者ごと: 初回クリック、返信、届かない宛先が1行に出る。概要に届かない宛先の人数、率の分母から外れる
//   - 閲覧者には直す操作が出ず、API でも 403。ほかのテナントの行動は出ない
//   - 対象者の一覧: 続けて届かない宛先に警告が出る
// 準備: php fixtures/training_measurement_e2e_db.php <db> <password>
//       ドキュメントルートに web への symlink tet2 を置いて起動する: TET2_DB_PATH=<db> php -S 127.0.0.1:<port> -t <docroot>
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:<port>/tet2 TET2_E2E_PASSWORD=... node training_measurement_e2e.mjs
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
const login = async (email) => {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  page.on('pageerror', (e) => allErrors.push(e.message));
  page.on('dialog', (d) => d.accept());
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.locator('#loginEmail').fill(email);
  await page.locator('#loginPassword').fill(PASSWORD);
  await page.locator('#loginBtn').click();
  await page.locator('#appView:not(.d-none)').waitFor();
  return page;
};
const openView = async (page, view) => {
  await page.locator(`.app-sidebar [data-view="${view}"]`).click();
  await page.locator(`[data-panel="${view}"]:not(.d-none)`).waitFor();
};
const openReport = async (page) => {
  await openView(page, 'reports');
  await page.locator('#reportsBody tr', { hasText: '測定の確認' }).click();
  await page.locator('#reportOverviewKpis [data-kpi="届かない宛先"]').waitFor();
};
const summary = (page) => page.evaluate(() => fetch('api/report.php?action=summary&campaign_id=41', { credentials: 'same-origin' })
  .then((r) => r.json()).then((d) => d.summary));

try {
  const op = await login('tm-op@example.test');
  await openReport(op);
  assert.match(await op.locator('#reportScannerNote').textContent(), /セキュリティ装置.*多く数えられる/);
  assert.equal(await op.locator('#reportScannerNote').isVisible(), true);
  ok('レポートに誤計上の注意が常に出る');

  assert.match(await op.locator('#reportOverviewKpis [data-kpi="届かない宛先"]').textContent(), /1人/);
  const before = await summary(op);
  assert.equal(before.target_count, 3);
  assert.equal(before.click_count, 1);
  assert.equal(before.undeliverable_count, 1);
  ok('概要に届かない宛先の人数が出て、率の分母(対象数)から外れる(4人 → 3人)');

  // 利用者ごと
  await op.locator('#reportTab-people').click();
  const p11 = op.locator('#reportPeopleBody tr[data-tracking-id="4100000011"]');
  await p11.waitFor();
  const c11 = await p11.locator('td').allTextContents();
  assert.match(c11[4], /2026-09-28 11:00/);
  assert.match(c11[7], /2026-09-28 12:00/);
  assert.match(await op.locator('#reportPeopleBody tr[data-tracking-id="4100000013"]').textContent(), /届かない/);
  assert.match(await op.locator('#reportPeopleBody tr[data-tracking-id="4100000012"] td').nth(8).textContent(), /1/);
  ok('利用者ごと: 初回クリック、返信、届かない宛先、装置の件数が1行に出る');

  // 行動履歴と判定の修正
  await op.locator('#reportTab-actions').click();
  const scanRow = op.locator('#reportActionsBody tr[data-verdict="scanner"]');
  await scanRow.waitFor();
  assert.equal(await scanRow.count(), 1);
  assert.match(await scanRow.textContent(), /装置/);
  assert.match(await scanRow.textContent(), /203\.0\.113\.9/);
  assert.match(await scanRow.textContent(), /Proofpoint/);
  assert.match(await op.locator('#reportActionsSummary').textContent(), /利用者 2件 \/ 装置 1件/);
  assert.equal(await op.locator('#reportActionsBody', { hasText: '203.0.113.1' }).count(), 0);
  ok('行動履歴: 装置の行も判定、IP、端末つきで出る(ほかのテナントの行は出ない)');

  await scanRow.locator('button', { hasText: '利用者にする' }).click();
  await op.locator('.app-toast', { hasText: '判定を直しました' }).waitFor();
  await op.locator('#reportActionsBody tr[data-verdict="scanner"]').waitFor({ state: 'detached' });
  assert.match(await op.locator('#reportActionsBody').textContent(), /利用者（手で修正）/);
  const after = await summary(op);
  assert.equal(after.click_count, 2);
  ok('オペレータが「利用者にする」と判定が変わり、集計のクリックが 1 → 2 になる');

  // 閲覧者
  const viewer = await login('tm-viewer@example.test');
  await openReport(viewer);
  await viewer.locator('#reportTab-actions').click();
  await viewer.locator('#reportActionsBody tr').first().waitFor();
  assert.equal(await viewer.locator('#reportActionsBody button').count(), 0);
  const status = await viewer.evaluate(() => fetch('api/report.php?action=set_verdict', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': State.csrf },
    body: JSON.stringify({ event_id: 1, verdict: 'scanner' }),
  }).then((r) => r.status));
  assert.equal(status, 403);
  ok('閲覧者には直す操作が出ず、API でも 403');

  // CSRF のない POST は拒否(オペレータでも)
  const noCsrf = await op.evaluate(() => fetch('api/report.php?action=set_verdict', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ event_id: 1, verdict: 'scanner' }),
  }).then((r) => r.status));
  assert.equal(noCsrf, 403);
  ok('CSRF のトークンのない判定の修正は拒否される');

  // 対象者の一覧
  await openView(op, 'users');
  const t13 = op.locator('#targetsBody tr', { hasText: 'tm13@example.test' });
  await t13.waitFor();
  assert.match(await t13.locator('[data-undeliverable="warn"]').textContent(), /続けて届かない 2回/);
  assert.equal(await op.locator('#targetsBody tr', { hasText: 'tm14@example.test' }).locator('[data-undeliverable]').count(), 0);
  ok('対象者の一覧: 続けて届かない宛先に警告が出る(届いた宛先には出ない)');

  assert.deepEqual(allErrors, []);
  ok('画面のスクリプトのエラーがない');
} finally {
  await browser.close();
}
console.log(`training_measurement_e2e: ${passed} PASS`);

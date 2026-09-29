// 段D の送信(D-a)の設定の画面のブラウザ E2E(本物の API の入口を通す)。合成 DB に向けたローカルのサーバーで動かす(本番に向けない)。
//   - 種明かしメール(D2): キャンペーンの一覧のボタンから、既定はすべて切。保存しても送らない
//   - 自動の催促(D1): 開始後の配信の「催促の設定」、下書きの編集の催促の欄。上限なしで期限後を選ぶと理由を出して保存しない
//   - 担当者への報告の通知(D3): 不審メールの画面の「担当者への通知」(組織管理者だけ)。形の悪いアドレスは保存しない
//   - オペレータには通知先のボタンが出ず API も 403。CSRF のない POST は 403
//   - どの操作でもメールは1通も出ない(TET2_MAIL_OUTBOX_DIR のファイルが増えない)
// 準備: php fixtures/sending_da_e2e_db.php <db> <password> > fixture.json
//       ドキュメントルートに web への symlink tet2 を置き、
//       TET2_DB_PATH=<db> TET2_MAIL_OUTBOX_DIR=<outbox> php -S 127.0.0.1:<port> -t <docroot> で起動する(メールは投函せずファイルに書く)
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:<port>/tet2 TET2_E2E_PASSWORD=... TET2_E2E_OUTBOX=<outbox> TET2_E2E_FIXTURE=<fixture.json>
//       node sending_da_e2e.mjs
import assert from 'node:assert/strict';
import fs from 'node:fs';

let chromium;
try { ({ chromium } = await import('playwright')); } catch { ({ chromium } = await import('/root/node_modules/playwright/index.mjs')); }

const env = (k) => process.env[k] || '';
for (const k of ['TET2_E2E_BASE_URL', 'TET2_E2E_PASSWORD', 'TET2_E2E_OUTBOX', 'TET2_E2E_FIXTURE']) {
  if (!env(k)) throw new Error(`${k} is required`);
}
const BASE = env('TET2_E2E_BASE_URL');
const PASSWORD = env('TET2_E2E_PASSWORD');
const OUTBOX = env('TET2_E2E_OUTBOX');
const FX = JSON.parse(fs.readFileSync(env('TET2_E2E_FIXTURE'), 'utf8'));
let passed = 0;
const ok = (m) => { passed += 1; console.log(`PASS: ${m}`); };
const outboxCount = () => fs.readdirSync(OUTBOX).length;
const startCount = outboxCount();

const browser = await chromium.launch({ headless: true });
const allErrors = [];
const login = async (email) => {
  const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  page.on('pageerror', (e) => allErrors.push(e.message));
  page.on('dialog', (d) => d.accept());
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.locator('#loginEmail').fill(email);
  await page.locator('#loginPassword').fill(PASSWORD);
  await page.locator('#loginBtn').click();
  await page.locator('#appView:not(.d-none)').waitFor();
  return page;
};
const go = async (page, view) => {
  await page.evaluate((v) => document.querySelector(`.app-sidebar [data-view="${v}"]`).click(), view);
  await page.locator(`[data-panel="${view}"]:not(.d-none)`).waitFor();
};
// 開く途中(フェードイン中)に閉じる操作をすると Bootstrap が無視するので、開き切るのを待つ
const modalShown = (page) => page.waitForFunction(() => {
  const el = document.getElementById('appModal');
  const m = el && window.bootstrap?.Modal.getInstance(el);
  return !!m && el.classList.contains('show') && !m._isTransitioning;
});
const saveModal = async (page) => {
  await modalShown(page);
  await page.locator('#appModalSave').click();
  await page.locator('#appModal').waitFor({ state: 'hidden', timeout: 5000 });
};
const closeModal = async (page) => {
  await modalShown(page);
  await page.locator('#appModal .modal-footer [data-bs-dismiss="modal"]').click();
  await page.locator('#appModal').waitFor({ state: 'hidden', timeout: 5000 });
};
const apiGet = (page, url) => page.evaluate((u) => fetch(u).then((r) => r.json()), url);

try {
  const admin = await login('e2e-admin@example.test');

  // ---- 種明かしメール(D2) ----
  await go(admin, 'campaigns');
  const revealBtn = admin.locator(`[data-reveal-mail="${FX.campaign_id}"]`);
  await revealBtn.waitFor();
  await revealBtn.click();
  await modalShown(admin);
  for (const id of ['#rmOnFail', '#rmOnClose', '#rmOnReport']) assert.equal(await admin.locator(id).isChecked(), false);
  assert.equal(await admin.locator('#rmScope').inputValue(), 'all');
  assert.match(await admin.locator('#appModalBody').innerText(), /実施中（クローズ前）/);
  ok('種明かしメール: 既定は3つとも切で、範囲は全員');
  await admin.locator('#rmOnClose').check();
  await admin.locator('#rmScope').selectOption('failed');
  await admin.locator('#rmOnReport').check();
  await saveModal(admin);
  let rm = await apiGet(admin, `api/reveal_mail.php?action=get&campaign_id=${FX.campaign_id}`);
  assert.deepEqual([rm.settings.on_fail, rm.settings.on_close, rm.settings.close_scope, rm.settings.on_report], [0, 1, 'failed', 1]);
  assert.equal(outboxCount(), startCount);
  ok('種明かしメール: 終了後(失敗した人)と報告した人を保存でき、保存では送らない(閉じる前の訓練)');
  await revealBtn.click();
  await modalShown(admin);
  assert.equal(await admin.locator('#rmOnClose').isChecked(), true);
  assert.equal(await admin.locator('#rmScope').inputValue(), 'failed');
  await admin.locator('#rmOnClose').uncheck();
  await admin.locator('#rmOnReport').uncheck();
  await saveModal(admin);
  rm = await apiGet(admin, `api/reveal_mail.php?action=get&campaign_id=${FX.campaign_id}`);
  assert.deepEqual([rm.settings.on_close, rm.settings.on_report], [0, 0]);
  ok('種明かしメール: 開き直すと保存した値が入り、切に戻せる');

  // ---- 自動の催促(D1): 開始後の配信 ----
  await go(admin, 'eduDeliveries');
  const remBtn = admin.locator(`[data-edu-reminder="${FX.running_delivery_id}"]`);
  await remBtn.waitFor();
  await remBtn.click();
  await modalShown(admin);
  assert.equal(await admin.locator('#eduRemInterval').inputValue(), '');
  assert.equal(await admin.locator('#eduRemAfter').isChecked(), false);
  await admin.locator('#eduRemAfter').check();
  await admin.locator('#appModalSave').click();
  await admin.locator('#appModalBody .modal-form-error').waitFor();
  assert.match(await admin.locator('#appModalBody .modal-form-error').innerText(), /上限の回数を決めてください/);
  ok('催促の設定: 上限の回数なしで期限の後も送る設定は、理由を出して保存しない');
  await admin.locator('#eduRemInterval').fill('2');
  await admin.locator('#eduRemStart').fill('7');
  await admin.locator('#eduRemMax').fill('3');
  await saveModal(admin);
  let d = (await apiGet(admin, `api/edu_deliveries.php?action=get&id=${FX.running_delivery_id}`)).delivery;
  assert.deepEqual([d.remind_interval_days, d.remind_start_days, d.remind_after_deadline, d.remind_max_count], [2, 7, 1, 3]);
  assert.equal(outboxCount(), startCount);
  ok('催促の設定: 開始後の配信で、何日ごと・何日前から・期限の後も(上限の回数まで)を保存でき、送らない');

  // ---- 自動の催促(D1): 下書きの編集 ----
  await admin.locator(`[data-edu-delivery-edit="${FX.draft_delivery_id}"]`).click();
  await modalShown(admin);
  assert.ok(await admin.locator('#eduEditRemFieldset').isVisible());
  await admin.locator('#eduEditRemInterval').fill('5');
  await saveModal(admin);
  d = (await apiGet(admin, `api/edu_deliveries.php?action=get&id=${FX.draft_delivery_id}`)).delivery;
  assert.deepEqual([d.remind_interval_days, d.remind_start_days, d.remind_after_deadline, d.remind_max_count], [5, null, 0, null]);
  ok('催促の設定: 下書きの編集の欄から保存でき、空欄は既定のまま');

  // ---- 担当者への報告の通知(D3) ----
  await go(admin, 'suspiciousMails');
  await admin.locator('#smNotifyBtn').waitFor();
  await admin.locator('#smNotifyBtn').click();
  await modalShown(admin);
  assert.equal(await admin.locator('#rnEmails').inputValue(), '');
  await admin.locator('#rnEmails').fill('not-an-address');
  await admin.locator('#appModalSave').click();
  await admin.locator('#appModalBody .modal-form-error').waitFor();
  assert.match(await admin.locator('#appModalBody .modal-form-error').innerText(), /形が正しくありません/);
  await closeModal(admin);
  ok('報告の通知: 既定は空で、形の悪いアドレスは理由を出して保存しない');
  await admin.locator('#smNotifyBtn').click();
  await modalShown(admin);
  await admin.locator('#rnEmails').fill('sec1@example.test\nSec2@example.test');
  await saveModal(admin);
  const rn = await apiGet(admin, 'api/report_notify.php?action=get');
  assert.deepEqual(rn.emails, ['sec1@example.test', 'sec2@example.test']);
  assert.ok(rn.since);
  assert.equal(outboxCount(), startCount);
  ok('報告の通知: 通知先を保存でき(小文字にそろえる)、保存では送らない');

  // ---- CSRF のない POST は 403 ----
  const noCsrf = await admin.evaluate(async (id) => Promise.all([
    fetch('api/reveal_mail.php?action=save', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ campaign_id: id, on_fail: true, on_close: true, close_scope: 'all', on_report: true }) }).then((r) => r.status),
    fetch('api/report_notify.php?action=save', { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ emails: 'x@example.test' }) }).then((r) => r.status),
  ]), FX.campaign_id);
  assert.deepEqual(noCsrf, [403, 403]);
  ok('CSRF トークンのない POST は 403');

  // ---- オペレータ ----
  const op = await login('e2e-op@example.test');
  await go(op, 'suspiciousMails');
  assert.equal(await op.locator('#smNotifyBtn').isVisible(), false);
  const opStatus = await op.evaluate(async () => (await fetch('api/report_notify.php?action=get')).status);
  assert.equal(opStatus, 403);
  ok('オペレータには通知先のボタンが出ず、API も 403');

  assert.equal(outboxCount(), startCount);
  assert.deepEqual(allErrors, []);
  ok('どの操作でもメールは1通も出ず、画面に JavaScript のエラーが出ない');
  console.log(`\n${passed} passed`);
} finally {
  await browser.close();
}

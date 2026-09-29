// 段D の D4〜D6 のブラウザ E2E(本物の API の入口を通す)。合成 DB に向けたローカルのサーバーで動かす(本番に向けない)。
//   - D4 不審メールの報告者への返信: 報告者にだけ1通、二度押しで二重に送らない、「訓練メールでした」は訓練を閉じた後だけ
//   - D5 受講期間の終了時の集計通知: 既定では CLI が1通も送らない、画面で有効にすると担当者へ1回だけ(人数と率だけ)
//   - D6 訓練後のアンケート: 設定のない訓練を閉じても配らない、設定した訓練を閉じると防衛に失敗した人にだけ配る
//   - アンケートの「その他（自由記述）」: 回答画面で選ぶと記述の欄が出て、結果に内容が出る。雛形の設問に付いている
// 送られるはずのメールは TET2_MAIL_OUTBOX_DIR のファイルで確かめる(投函しない)。アンケートのメール送信は無効のまま。
// 準備: php fixtures/sending_db_e2e_db.php <db> <password>
//       ドキュメントルートに web への symlink tet2 を置き、
//       TET2_DB_PATH=<db> TET2_MAIL_OUTBOX_DIR=<outbox> php -S 127.0.0.1:<port> -t <docroot> で起動する
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:<port>/tet2 TET2_E2E_PASSWORD=... TET2_E2E_OUTBOX=<outbox> TET2_E2E_DB=<db> node sending_db_e2e.mjs
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

let chromium;
try { ({ chromium } = await import('playwright')); } catch { ({ chromium } = await import('/root/node_modules/playwright/index.mjs')); }

const env = (k) => process.env[k] || '';
for (const k of ['TET2_E2E_BASE_URL', 'TET2_E2E_PASSWORD', 'TET2_E2E_OUTBOX', 'TET2_E2E_DB']) {
  if (!env(k)) throw new Error(`${k} is required`);
}
const BASE = env('TET2_E2E_BASE_URL');
const OUTBOX = env('TET2_E2E_OUTBOX');
const DB = env('TET2_E2E_DB');
const PASSWORD = env('TET2_E2E_PASSWORD');
const WEB = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
let passed = 0;
const ok = (m) => { passed += 1; console.log(`PASS: ${m}`); };

const readOutbox = () => fs.readdirSync(OUTBOX).sort()
  .map((f) => JSON.parse(fs.readFileSync(path.join(OUTBOX, f), 'utf8')));
const startCount = readOutbox().length;
const newMails = () => readOutbox().slice(startCount);
// 集計通知の CLI(timer が動かすもの)を、同じ合成 DB と出口で1回動かす
const runSummaryCli = () => execFileSync('php', [path.join(WEB, 'db/edu_delivery_summary.php')],
  { env: { ...process.env, TET2_DB_PATH: DB, TET2_MAIL_OUTBOX_DIR: OUTBOX }, encoding: 'utf8' }).trim();
const sql = (statement) => execFileSync('php', ['-r', '$p = new PDO("sqlite:" . getenv("DB")); $p->exec(getenv("SQL"));'],
  { env: { ...process.env, DB, SQL: statement } });

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
const go = async (page, view) => {
  await page.locator(`.app-sidebar [data-view="${view}"]`).click();
  await page.locator(`[data-panel="${view}"]:not(.d-none)`).waitFor();
};
const modalShown = (page) => page.waitForFunction(() => {
  const el = document.getElementById('appModal');
  const m = el && window.bootstrap?.Modal.getInstance(el);
  return !!m && el.classList.contains('show') && !m._isTransitioning;
});
const closeModal = async (page) => {
  // 開く途中(フェードイン中)に閉じる操作をすると Bootstrap が無視するので、開き切るのを待ってから閉じる
  await modalShown(page);
  await page.locator('#appModal .modal-footer [data-bs-dismiss="modal"]').click();
  await page.locator('#appModal').waitFor({ state: 'hidden', timeout: 5000 });
};
const saveModal = async (page) => {
  await modalShown(page);
  await page.locator('#appModalSave').click();
  await page.locator('#appModal').waitFor({ state: 'hidden', timeout: 5000 });
};
const waitText = async (locator, re, timeout = 5000) => {
  const until = Date.now() + timeout;
  let text = '';
  while (Date.now() < until) {
    text = (await locator.textContent()) || '';
    if (re.test(text)) return text;
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error(`待っていた表示になりません: ${re} (今: ${text})`);
};
const lastToast = (page) => page.locator('#toast .app-toast').last();

try {
  // ================= D4 報告者への返信 =================
  const op = await login('sd-op@example.test');
  await go(op, 'suspiciousMails');
  const openReport = async (subject) => {
    await op.locator('#smBody tr', { hasText: subject }).click();
    await modalShown(op);
    await op.locator('#smReplyTabLink').click();
    await op.locator('#smReplySendBtn').waitFor();
  };
  await openReport('E2E 請求書の確認');
  assert.equal(await op.locator('#smReplyKind option[value="report_reply_training"]').isDisabled(), true);
  assert.match(await op.locator('#smReplyReason').textContent(), /特定できない/);
  assert.equal(newMails().length, 0, '画面を開いただけでは送らない');
  await op.locator('#smReplyKind').selectOption('report_reply_checking');
  assert.match(await op.locator('#smReplyBody').textContent(), /対象11 様[\s\S]*E2E 請求書の確認/);
  await op.locator('#smReplySendBtn').click();
  await waitText(lastToast(op), /報告者へ返信しました/);
  await waitText(op.locator('#smTabReply'), /送信済み/);
  let mails = newMails();
  assert.equal(mails.length, 1);
  assert.equal(mails[0].to, 'sd11@example.test');
  assert.match(mails[0].subject, /確認しています/);
  ok('D4: 選んだ定型文を報告者のアドレスにだけ1通送り、返信の記録に出す');

  await op.locator('#smReplyKind').selectOption('report_reply_checking');
  await op.locator('#smReplySendBtn').click();
  await waitText(lastToast(op), /送ってあります/);
  assert.equal(newMails().length, 1);
  ok('D4: 同じ定型文をもう一度押しても二重に送らない');
  await closeModal(op);

  await openReport('E2E パスワードの期限');
  assert.equal(await op.locator('#smReplyKind option[value="report_reply_training"]').isDisabled(), true);
  assert.match(await op.locator('#smReplyReason').textContent(), /閉じる前/);
  const forced = await op.evaluate(async () => {
    const row = [...document.querySelectorAll('#smBody tr[data-id]')].find((tr) => tr.textContent.includes('E2E パスワードの期限'));
    try { await api('api/suspicious_mail_replies.php', { method: 'POST', query: { action: 'send' }, body: { id: Number(row.dataset.id), kind: 'report_reply_training' } }); return 'sent'; }
    catch (e) { return e.message; }
  });
  assert.match(forced, /閉じる前/);
  assert.equal(newMails().length, 1);
  ok('D4: 閉じる前の訓練の報告には、画面でも API でも「訓練メールでした」を送らない');
  await closeModal(op);

  await openReport('E2E 社内アンケート');
  assert.equal(await op.locator('#smReplyKind option[value="report_reply_training"]').isDisabled(), false);
  await op.locator('#smReplyKind').selectOption('report_reply_training');
  await op.locator('#smReplySendBtn').click();
  await waitText(lastToast(op), /報告者へ返信しました/);
  mails = newMails();
  assert.equal(mails.length, 2);
  assert.equal(mails[1].to, 'sd12@example.test');
  assert.match(mails[1].body, /訓練のメールでした/);
  await op.locator('#smDetailTabs a[href="#smTabHistory"]').click();
  await closeModal(op);
  await op.locator('#smBody tr', { hasText: 'E2E 社内アンケート' }).click();
  await modalShown(op);
  await op.locator('#smDetailTabs a[href="#smTabHistory"]').click();
  await waitText(op.locator('#smTabHistory'), /報告者への返信[\s\S]*訓練メールでした/);
  await closeModal(op);
  ok('D4: 閉じた訓練の報告には「訓練メールでした」を送れ、報告の履歴に残る');

  // ================= D5 受講期間の終了時の集計通知 =================
  assert.match(runSummaryCli(), /deliveries=0 sent=0/);
  assert.equal(newMails().length, 2);
  ok('D5: 既定(設定なし)では、集計通知の CLI は1通も送らない');

  await go(op, 'eduDeliveries');
  await waitText(op.locator('#eduSummarySetting'), /切（送りません）/);
  assert.equal(await op.locator('#editEduSummaryBtn').isVisible(), false);
  ok('D5: オペレータは設定を見られるが、変えるボタンは出ない');

  const admin = await login('sd-admin@example.test');
  await go(admin, 'eduDeliveries');
  await waitText(admin.locator('#eduSummarySetting'), /切（送りません）/);
  await admin.locator('#editEduSummaryBtn').click();
  await modalShown(admin);
  await admin.locator('#eduSummaryEnabled').check();
  await admin.locator('#eduSummaryRecipients').fill('edu-owner@example.test\n');
  await saveModal(admin);
  await waitText(admin.locator('#eduSummarySetting'), /有効: edu-owner@example\.test/);
  ok('D5: 組織管理者が画面で有効にして担当者を登録できる');

  assert.match(runSummaryCli(), /deliveries=0/, '有効にする前に期限を過ぎた配信は送らない');
  sql("UPDATE edu_summary_settings SET enabled_at = '2026-09-01 00:00:00' WHERE tenant_id = 1");
  assert.match(runSummaryCli(), /deliveries=1 sent=1 failed=0/);
  mails = newMails();
  assert.equal(mails.length, 3);
  assert.equal(mails[2].to, 'edu-owner@example.test');
  assert.match(mails[2].body, /対象: 2人[\s\S]*受講率 50\.0%[\s\S]*合格率 50\.0%/);
  assert.doesNotMatch(mails[2].body, /sd11|対象11/);
  assert.match(runSummaryCli(), /deliveries=0/);
  assert.equal(newMails().length, 3);
  ok('D5: 期限を過ぎた配信の人数と率だけを担当者へ1回だけ送る(2回目は送らない)');

  // ================= D6 訓練後のアンケート =================
  await go(op, 'campaigns');
  await op.locator('#campaignsBody button[onclick="openCampaignSurveyFollowup(32)"]').click();
  await modalShown(op);
  assert.equal(await op.locator('#csfEnabled').isChecked(), false);
  await op.locator('#csfEnabled').check();
  await op.locator('#csfSurvey').selectOption({ label: 'E2E 振り返り' });
  await op.locator('#csfAudience').selectOption('failed');
  await op.locator('#csfDeadline').fill('7');
  await saveModal(op);
  await waitText(lastToast(op), /設定を保存しました/);
  ok('D6: キャンペーンの一覧から、訓練後のアンケートを設定できる(既定は切)');

  const sup = await login('sd-super@example.test');
  const closeAs = (id) => sup.evaluate(async (campaignId) => {
    await api('api/report.php', { method: 'POST', query: { action: 'commit', tenant_id: 1 }, body: { campaign_id: campaignId, tenant_id: 1 } });
    return api('api/report.php', { method: 'POST', query: { action: 'close' }, body: { campaign_id: campaignId, tenant_id: 1 } });
  }, id);
  const deliveries = () => admin.evaluate(() => api('api/surveys.php', { query: { action: 'deliveries' } }).then((r) => r.deliveries));
  const before = (await deliveries()).length;
  const off = await closeAs(33);
  assert.equal(off.survey_followup.status, 'off');
  assert.equal((await deliveries()).length, before);
  ok('D6: 設定のない訓練をクローズしても、アンケートを配らない');

  const on = await closeAs(32);
  assert.equal(on.survey_followup.status, 'delivered');
  assert.equal(on.survey_followup.assigned, 1);
  const after = await deliveries();
  assert.equal(after.length, before + 1);
  const created = after.find((d) => d.title === '訓練後のアンケート（アンケートを配る訓練）');
  assert.ok(created && Number(created.assigned) === 1);
  assert.equal(newMails().length, 3, 'アンケートのメール送信が無効なので案内メールは送らない');
  ok('D6: 設定した訓練をクローズすると、防衛に失敗した人にだけ配り、メール送信が無効なら送らない');

  await go(op, 'campaigns');
  await op.locator('#campaignsBody button[onclick="openCampaignSurveyFollowup(32)"]').click();
  await modalShown(op);
  await waitText(op.locator('#appModalBody'), /クローズ済み[\s\S]*1人に配信を作りました/);
  assert.equal(await op.locator('#csfEnabled').isDisabled(), true);
  await closeModal(op);
  ok('D6: クローズ後は設定を変えられず、結果を表示する');

  // ================= アンケートの「その他（自由記述）」 =================
  const resp = await browser.newPage();
  resp.on('pageerror', (e) => allErrors.push(e.message));
  await resp.goto(`${BASE}/survey.php?token=${'ab'.repeat(16)}`, { waitUntil: 'networkidle' });
  await resp.locator('#surveyView:not(.d-none)').waitFor();
  assert.equal(await resp.locator('#q0_other').isVisible(), false);
  await resp.locator('label[for="q0_2"]').click();
  assert.equal(await resp.locator('#q0_other').isVisible(), true);
  await resp.locator('#svSubmit').click();
  await waitText(resp.locator('#svError'), /その他/);
  await resp.locator('#q0_other').fill('貸与のタブレット');
  await resp.locator('#svSubmit').click();
  await resp.locator('#doneView:not(.d-none)').waitFor();
  ok('その他: 回答画面で選ぶと記述の欄が出て、空のままでは送れず、書けば送れる');

  await go(admin, 'surveys');
  await admin.locator('[data-sv-action="results"][data-id="52"]').click();
  await modalShown(admin);
  await waitText(admin.locator('#appModalBody'), /その他（自由記述）[\s\S]*1件[\s\S]*貸与のタブレット/);
  await closeModal(admin);
  const csv = await admin.evaluate(() => fetch('api/surveys.php?action=export_csv&delivery_id=52').then((r) => r.text()));
  assert.match(csv, /その他（自由記述）: 貸与のタブレット/);
  ok('その他: 結果と CSV の出力に内容が出る');

  await admin.locator('#svTemplateSelect').selectOption('remote_work');
  await admin.locator('#svFromTemplateBtn').click();
  await waitText(lastToast(admin), /雛形から下書きを作成しました/);
  await admin.locator('#svBody tr', { hasText: '社外や在宅で仕事をするときの情報の扱い' }).locator('[data-sv-action="edit"]').click();
  await modalShown(admin);
  assert.equal(await admin.locator('#svOther1').isChecked(), true);
  assert.equal(await admin.locator('#svOther0').isChecked(), false);
  await closeModal(admin);
  ok('雛形: 従業員向けの雛形を作ると、その他のある設問は編集画面でも印が付いている');

  assert.deepEqual(allErrors, []);
  ok('画面の JavaScript のエラーがない');
  console.log(`ALL ${passed} CHECKS PASSED`);
} finally {
  await browser.close();
}

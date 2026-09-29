// 訓練、報告、設定の小物(A-6〜A-10)のブラウザ E2E(本物の API の入口を通す)。合成 DB に向けたローカルのサーバーで動かす(本番に向けない)。
//   - A-6 シナリオの概要: 一覧に出る(HTML にならない)、自社のシナリオは編集できる、共有は読むだけ、訓練の作成画面の選択欄の下に出る
//   - A-7 訓練の一覧の報告率と防衛失敗率、レポートの概要の配信エラーの人数
//   - A-8 不審メールの CSV 出力(BOM、式の無害化、自テナントだけ、閲覧者は 403)
//   - A-9 方針のカードで禁止語を足すと、その語を含むパスワードでユーザを作れない(メッセージに語を出さない)
//   - A-10 不審メールの登録した条件の追加、検証のエラー、停止。閲覧者は 403
// 準備: php fixtures/training_parity_e2e_db.php <db> <password>
//       ドキュメントルートに web への symlink tet2 を置いて起動する: TET2_DB_PATH=<db> php -S 127.0.0.1:<port> -t <docroot>
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:<port>/tet2 TET2_E2E_PASSWORD=... node training_parity_e2e.mjs
import assert from 'node:assert/strict';
import fs from 'node:fs';

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
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, acceptDownloads: true });
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
const closeModal = async (page) => {
  // 開く途中(フェードイン中)に閉じる操作をすると Bootstrap が無視するので、開き切るのを待ってから閉じる
  await page.waitForFunction(() => {
    const el = document.getElementById('appModal');
    const m = el && window.bootstrap?.Modal.getInstance(el);
    return !!m && el.classList.contains('show') && !m._isTransitioning;
  });
  await page.locator('#appModal .modal-footer [data-bs-dismiss="modal"]').click();
  await page.locator('#appModal').waitFor({ state: 'hidden' });
};
const apiStatus = (page, url) => page.evaluate((u) => fetch(u, { credentials: 'same-origin' }).then((r) => r.status), url);

try {
  const op = await login('tp-op@example.test');

  // ---- A-6 シナリオの概要 ----
  await openView(op, 'templates');
  const ownRow = op.locator('#templatesBody tr[data-scenario-key="e2eown"]');
  await ownRow.waitFor();
  assert.match(await ownRow.locator('.tpl-description').textContent(), /<b>太字にならない<\/b> 元の事例/);
  assert.equal(await op.locator('#templatesBody b').count(), 0);
  assert.match(await op.locator('#templatesBody tr[data-scenario-key="e2eshared"] .tpl-description').textContent(), /E2E共有の概要/);
  ok('A-6: テンプレートの一覧に概要が出る(HTML の形の文字は文字のまま)');

  await ownRow.click();
  await op.locator('#svDescription').waitFor();
  await op.locator('#svDescription').fill('元の事例: 経費精算を装う\n見分けるポイント: 送信元のドメイン');
  await op.locator('#appModalSave').click();
  await op.locator('#appModal').waitFor({ state: 'hidden' });
  await op.locator('#templatesBody tr[data-scenario-key="e2eown"] .tpl-description', { hasText: '経費精算を装う' }).waitFor();
  ok('A-6: オペレータは自社のシナリオの概要を編集できる');

  await op.locator('#templatesBody tr[data-scenario-key="e2eshared"]').click();
  await op.locator('#appModalBody', { hasText: 'E2E共有の概要' }).waitFor();
  assert.equal(await op.locator('#svDescription').count(), 0);
  await closeModal(op);
  ok('A-6: 共有のシナリオの概要はオペレータには読むだけ');

  await openView(op, 'campaigns');
  await op.locator('#newCampaignBtn').click();
  await op.locator('#contentsList .c-scenario').first().waitFor({ state: 'attached' });
  const desc = await op.evaluate(() => {
    const sel = document.querySelector('#contentsList .c-scenario');
    sel.value = 'e2eown';
    sel.dispatchEvent(new Event('change'));
    return sel.closest('.content-row').querySelector('.c-scenario-desc').textContent;
  });
  assert.match(desc, /^概要: 元の事例: 経費精算を装う/);
  await closeModal(op);
  ok('A-6: 訓練の作成画面でシナリオを選ぶと概要が出る');

  // ---- A-7 報告率、防衛失敗率、配信エラー ----
  await openView(op, 'campaigns');
  const heads = await op.locator('#campaignsHead th').allTextContents();
  const campaignRow = op.locator('#campaignsBody tr', { hasText: '率の確認' });
  await campaignRow.waitFor();
  const cells = await campaignRow.locator('td').allTextContents();
  assert.equal(cells[heads.indexOf('報告率')].replace(/\s+/g, ' ').trim(), '1 (25.0%)');
  assert.equal(cells[heads.indexOf('防衛失敗率')].replace(/\s+/g, ' ').trim(), '2 (50.0%)');
  assert.match(await op.locator('#campaignsHead th', { hasText: '防衛失敗率' }).getAttribute('title'), /認証情報を入力/);
  ok('A-7: 訓練の一覧に報告率と防衛失敗率が出る(定義は見出しの説明)');

  await openView(op, 'reports');
  await op.locator('#reportsBody tr', { hasText: '率の確認' }).click();
  const kpi = op.locator('#reportOverviewKpis [data-kpi="配信エラー"]');
  await kpi.waitFor();
  assert.match(await kpi.textContent(), /2人/);
  assert.match(await op.locator('#reportOverviewKpis [data-kpi="防衛失敗率"]').textContent(), /50\.0%（2人）/);
  ok('A-7: レポートの概要に配信エラーの人数と防衛失敗率が出る');

  // ---- A-8 不審メールの CSV ----
  await openView(op, 'suspiciousMails');
  await op.locator('#smBody tr').first().waitFor();
  const [download] = await Promise.all([op.waitForEvent('download'), op.locator('#smExportBtn').click()]);
  const csv = fs.readFileSync(await download.path(), 'utf8');
  assert.equal(csv.charCodeAt(0), 0xfeff);
  assert.ok(csv.includes("'=cmd E2E の件名") && csv.includes('E2E ふつうの件名') && !csv.includes('他社の不審メールE2E'));
  assert.ok(csv.includes('\r\n') && !csv.replace(/\r\n/g, '').includes('\n'));
  ok('A-8: 不審メールの一覧を CSV に出せる(BOM、CRLF、式の無害化、自テナントだけ)');

  // ---- A-10 登録した条件 ----
  await op.locator('#smRulesCard > summary').click();
  await op.locator('#smRulesBody tr').first().waitFor();
  await op.locator('#smRuleName').fill('E2E 偽の取引先');
  await op.locator('#smRuleKind').selectOption('sender');
  await op.locator('#smRuleValue').fill('*.Bad-Sender.TEST');
  await op.locator('#smRuleAddBtn').click();
  const ruleRow = op.locator('#smRulesBody tr', { hasText: 'E2E 偽の取引先' });
  await ruleRow.waitFor();
  assert.equal(await ruleRow.locator('code').textContent(), 'bad-sender.test');
  await op.locator('#smRuleName').fill('E2E 不正な値');
  await op.locator('#smRuleValue').fill('not a domain');
  await op.locator('#smRuleAddBtn').click();
  await op.locator('#toast .app-toast', { hasText: 'ドメインの形が正しくありません' }).first().waitFor();
  assert.equal(await op.locator('#smRulesBody tr', { hasText: 'E2E 不正な値' }).count(), 0);
  await ruleRow.locator('button[data-rule-action="toggle"]').click();
  await op.locator('#smRulesBody tr', { hasText: 'E2E 偽の取引先' }).locator('text=停止中').waitFor();
  ok('A-10: 条件を登録でき(値は小文字に)、形の違う値は拒み、止められる');

  // ---- 閲覧者: 不審メールの CSV と条件は 403 ----
  const viewer = await login('tp-viewer@example.test');
  assert.equal(await apiStatus(viewer, 'api/suspicious_mails.php?action=export_csv'), 403);
  assert.equal(await apiStatus(viewer, 'api/suspicious_mails.php?action=rules'), 403);
  ok('A-8/A-10: 閲覧者は CSV の出力も条件の一覧も 403');

  // ---- A-9 禁止語 ----
  const admin = await login('tp-admin@example.test');
  await openView(admin, 'users');
  await admin.locator('#adminUsersTab').click();
  await admin.locator('#editSecurityPolicyBtn').click();
  await admin.locator('#pfBannedWords').fill('Kiwifruit\nSakuraya');
  await admin.locator('#appModalSave').click();
  await admin.locator('#appModal').waitFor({ state: 'hidden' });
  await admin.locator('#securityPolicySummary', { hasText: '禁止語 2語' }).waitFor();
  ok('A-9: 方針のカードで禁止語を足せる');

  const tryCreate = async (email, password) => {
    await admin.locator('#newUserBtn').click();
    await admin.locator('#userForm').waitFor();
    await admin.locator('#ufEmail').fill(email);
    await admin.locator('#ufName').fill('E2E 新規');
    await admin.locator('#ufModePassword').check();
    await admin.locator('#ufPassword').fill(password);
    await admin.locator('#appModalSave').click();
  };
  await tryCreate('tp-new@example.test', 'My-KIWIFRUIT-26');
  const err = admin.locator('#appModalBody .modal-form-error');
  await err.waitFor();
  const message = await err.textContent();
  assert.match(message, /推測されやすい語/);
  assert.ok(!/kiwi|sakura/i.test(message));
  await closeModal(admin);
  await tryCreate('tp-new@example.test', 'Example-Heron-26');
  await admin.locator('#appModalBody .modal-form-error', { hasText: '推測されやすい語' }).waitFor();
  await closeModal(admin);
  await tryCreate('tp-new@example.test', 'Heron-Tulip-2026');
  await admin.locator('#appModal').waitFor({ state: 'hidden' });
  await admin.locator('#usersBody tr', { hasText: 'tp-new@example.test' }).waitFor();
  ok('A-9: 禁止語やテナント名を含むパスワードでは作れず(語は出さない)、含まなければ作れる');

  assert.deepEqual(allErrors, []);
  ok('画面のスクリプトのエラーがない');
} finally {
  await browser.close();
}
console.log(`training_parity_e2e: ${passed} PASS`);

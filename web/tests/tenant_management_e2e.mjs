// テナントの管理のブラウザ E2E: 一覧と絞り込み、作成と最初の管理者、詳細と切り替え、停止、削除と復元、完全削除、
// 停止中のテナントのログインの拒否と、ログイン中のセッションの切断。
// 合成 DB(fixtures/tenant_e2e_db.php で作る)に向けたローカルのサーバーで動かす(本番に向けない)。
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:8781/tet2 TET2_E2E_EMAIL=e2e-super@example.test TET2_E2E_PASSWORD=... node tenant_management_e2e.mjs
//   任意: TET2_E2E_DATA_ROOT(サーバーの TET2_DATA_ROOT と同じ一時ディレクトリ)を渡すと、完全削除の退避のフォルダも確かめる。
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { chromium } from 'playwright';

const env = (k) => process.env[k] || '';
for (const k of ['TET2_E2E_BASE_URL', 'TET2_E2E_EMAIL', 'TET2_E2E_PASSWORD']) {
  if (!env(k)) throw new Error(`${k} is required`);
}
let passed = 0;
const ok = (m) => { passed += 1; console.log(`PASS: ${m}`); };

const browser = await chromium.launch({ headless: true });
const openPage = async () => {
  const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  page.errors = [];
  page.on('pageerror', (e) => page.errors.push(e.message));
  page.on('dialog', (d) => d.accept());
  await page.goto(`${env('TET2_E2E_BASE_URL')}/`, { waitUntil: 'networkidle' });
  return page;
};
const submitLogin = async (page, email) => {
  await page.locator('#loginEmail').fill(email);
  await page.locator('#loginPassword').fill(env('TET2_E2E_PASSWORD'));
  await page.locator('#loginBtn').click();
};
const login = async (email) => {
  const page = await openPage();
  await submitLogin(page, email);
  await page.locator('#appView:not(.d-none)').waitFor();
  return page;
};
const row = (page, slug) => page.locator('#tenantsBody tr', { has: page.locator(`code:text-is("${slug}")`) });
const filter = async (page, value) => {
  await page.locator(`#tenantStatusFilter [data-tenant-filter="${value}"]`).click();
  assert.equal(await page.locator(`#tenantStatusFilter [data-tenant-filter="${value}"]`).getAttribute('aria-pressed'), 'true');
};
const saveModal = async (page) => {
  await page.locator('#appModalSave').click();
  await page.locator('#appModal').waitFor({ state: 'hidden' });
};
const lastToast = (page) => page.locator('#toast .app-toast').last();
const openTenants = async (page) => {
  await page.locator('.app-sidebar [data-view="tenants"]').click();
  await page.locator('#tenantListArea:not(.d-none) #tenantsBody tr').first().waitFor();
};

try {
  const admin = await login(env('TET2_E2E_EMAIL'));
  await openTenants(admin);

  // 1. 一覧と絞り込み: 既定は削除済みを除く。削除済みは分けて出し、完全削除できるかを示す
  assert.equal(await row(admin, 'old-co').count(), 0);
  assert.equal(await row(admin, 'globex-e2e').count(), 1);
  assert.match(await row(admin, 'globex-e2e').innerText(), /停止中/);
  assert.equal(await admin.locator('#tenantsBody tr').first().locator('td').count(), 12);
  ok('一覧は既定で削除済みを除き、停止中も含めて12列(数字、最後の送信、最後のログイン、契約)で出す');
  await filter(admin, 'deleted');
  assert.equal(await row(admin, 'old-co').count(), 1);
  assert.equal(await row(admin, 'globex-e2e').count(), 0);
  assert.match(await row(admin, 'old-co').innerText(), /完全削除できます/);
  ok('「削除済み」で絞ると削除済みだけを出し、保持期間を過ぎたことを示す');
  await filter(admin, 'suspended');
  assert.equal(await row(admin, 'globex-e2e').count(), 1);
  assert.equal(await row(admin, 'example-tenant').count(), 0);
  ok('「停止中」で絞ると停止中だけを出す');
  await filter(admin, 'current');

  // 2. 作成と最初の管理者、管理の項目(契約の終了日が10日後 → 一覧に印)
  const in10 = new Date(Date.now() + 10 * 86400000 + 9 * 3600000).toISOString().slice(0, 10);
  await admin.locator('#newTenantBtn').click();
  await admin.locator('#tenantForm').waitFor();
  await admin.locator('#tfName').fill('E2E 新規社');
  await admin.locator('#tfSlug').fill('e2e-new');
  await admin.locator('#tfContactName').fill('担当 E2E');
  await admin.locator('#tfContactEmail').fill('contact@e2e-new.test');
  await admin.locator('#tfContractEnd').fill(in10);
  await admin.locator('#tfTargetLimit').fill('50');
  await admin.locator('#tfAdminEmail').fill('first-admin@e2e-new.test');
  await admin.locator('#tfAdminName').fill('最初の管理者');
  await admin.locator('#tfAdminPassword').fill('FirstHeron123');
  await saveModal(admin);
  await row(admin, 'e2e-new').waitFor();
  assert.match(await lastToast(admin).innerText(), /最初の管理者も作りました/);
  const newRowText = await row(admin, 'e2e-new').innerText();
  assert.match(newRowText, /有効/);
  assert.match(newRowText, /あと 1?\d 日/);
  assert.match(newRowText, /0 \/ 50/);
  ok('作成のフォームで最初の管理者と管理の項目を入れて作成でき、契約の終了日が近い印と上限を出す');

  // 3. 詳細: 概要、ユーザ、最近の操作。「このテナントに切り替えて開く」
  await row(admin, 'e2e-new').locator('.tenant-name-link').click();
  await admin.locator('#tenantDetailArea:not(.d-none) #tenantDetailTitle').waitFor();
  assert.equal(await admin.locator('#tenantDetailTitle').innerText(), 'E2E 新規社');
  assert.match(await admin.locator('#tenantDetailUsers').innerText(), /first-admin@e2e-new\.test[\s\S]*組織管理者/);
  assert.match(await admin.locator('#tenantDetailAudit').innerText(), /tenant\.create/);
  assert.match(await admin.locator('#tenantDetailArea').innerText(), /担当 E2E（contact@e2e-new\.test）/);
  ok('名前を押すと詳細(概要、管理の項目、ユーザの一覧、最近の操作)を出す');
  const newId = await admin.locator('#tenantSwitcher option', { hasText: 'E2E 新規社' }).getAttribute('value');
  await admin.getByRole('button', { name: 'このテナントに切り替えて開く' }).click();
  await admin.locator('[data-panel="dashboard"]:not(.d-none)').waitFor();
  assert.equal(await admin.locator('#tenantSwitcher').inputValue(), newId);
  ok('「このテナントに切り替えて開く」で上の切り替えがそのテナントになり、ダッシュボードを開く');
  await openTenants(admin);
  assert.equal(await admin.locator('#tenantListArea').isVisible(), true);
  ok('ほかの画面からテナント管理に戻ると一覧から出す');

  // 4. 停止(編集)と削除(slug の確認)。有効なテナントには削除のボタンを出さない
  assert.equal(await row(admin, 'e2e-new').locator('[aria-label="テナントを削除"]').count(), 0);
  await row(admin, 'e2e-new').locator('[aria-label="テナントを編集"]').click();
  await admin.locator('#tfStatus').selectOption('suspended');
  await saveModal(admin);
  await admin.waitForFunction(() => /停止中/.test(document.querySelector('#tenantsBody')?.innerText || ''));
  assert.match(await row(admin, 'e2e-new').innerText(), /停止中/);
  ok('有効なテナントには削除のボタンがなく、編集で停止できる');
  await row(admin, 'e2e-new').locator('[aria-label="テナントを削除"]').click();
  await admin.locator('#tenantConfirmSlug').fill('wrong-slug');
  assert.equal(await admin.locator('#appModalSave').innerText(), '削除する');
  await admin.locator('#appModalSave').click();
  // 保存のエラーはモーダルの中に出す(admin_ux_states の B3)
  await admin.locator('#appModal .modal-form-error', { hasText: 'slug が一致しません' }).waitFor();
  assert.equal(await admin.locator('#appModal').isVisible(), true);
  await admin.locator('#tenantConfirmSlug').fill('e2e-new');
  await saveModal(admin);
  await admin.waitForFunction(() => !/e2e-new/.test(document.querySelector('#tenantsBody')?.innerText || ''));
  await filter(admin, 'deleted');
  assert.match(await row(admin, 'e2e-new').innerText(), /完全削除まで あと 90 日/);
  assert.equal(await row(admin, 'e2e-new').locator('[aria-label="テナントを完全削除"]').count(), 0);
  ok('削除は slug の入力で確認し、違えば削除しない。削除済みに移り、保持期間の残り(90日)を出し、完全削除はまだ出さない');
  const switcherHas = (name) => admin.locator('#tenantSwitcher option', { hasText: name }).count();
  assert.equal(await switcherHas('E2E 新規社'), 0);
  assert.equal(await switcherHas('Old Co'), 0);
  await row(admin, 'e2e-new').locator('.tenant-name-link').click();
  await admin.locator('#tenantDetailArea:not(.d-none) #tenantDetailTitle').waitFor();
  assert.equal(await admin.locator('#tenantDetailArea').getByRole('button', { name: /切り替えて開く/ }).count(), 0);
  await admin.locator('#tenantDetailArea').getByRole('button', { name: /一覧に戻る/ }).click();
  await filter(admin, 'deleted');
  ok('削除済みのテナントは、上の切り替えに出さず、詳細にも「切り替えて開く」を出さない');

  // 5. 復元(停止中に戻る)
  await row(admin, 'e2e-new').locator('[aria-label^="テナントを復元"]').click();
  await admin.waitForFunction(() => !/e2e-new/.test(document.querySelector('#tenantsBody')?.innerText || ''));
  await filter(admin, 'suspended');
  assert.equal(await row(admin, 'e2e-new').count(), 1);
  assert.equal(await switcherHas('E2E 新規社'), 1);
  ok('復元すると停止中に戻り、上の切り替えにも戻る');

  // 6. 完全削除(保持期間を過ぎた Old Co)
  await filter(admin, 'deleted');
  await row(admin, 'old-co').locator('[aria-label="テナントを完全削除"]').click();
  await admin.locator('#tenantConfirmSlug').fill('old-co');
  assert.equal(await admin.locator('#appModalSave').innerText(), '完全削除する');
  await saveModal(admin);
  await lastToast(admin).filter({ hasText: '完全削除しました' }).waitFor();
  await admin.waitForFunction(() => !/old-co/.test(document.querySelector('#tenantsBody')?.innerText || ''));
  assert.equal(await admin.locator('#tenantSwitcher option', { hasText: 'Old Co' }).count(), 0);
  if (env('TET2_E2E_DATA_ROOT')) {
    const root = env('TET2_E2E_DATA_ROOT');
    const moved = fs.readdirSync(`${root}/_deleted`).filter((n) => n.startsWith('old-co-'));
    assert.equal(moved.length, 1);
    assert.ok(fs.existsSync(`${root}/_deleted/${moved[0]}/campaign_1/list.csv`));
    assert.ok(!fs.existsSync(`${root}/old-co`));
    const backup = fs.readdirSync(`${root}/_deleted/${moved[0]}`).find((n) => n.endsWith('.sqlite'));
    assert.equal(fs.statSync(`${root}/_deleted/${moved[0]}/${backup}`).mode & 0o777, 0o600);
  }
  ok('保持期間を過ぎた削除済みは、slug の確認で完全削除でき、一覧と切り替えから消え、ファイルは退避される');

  // 7. 停止中のテナントのユーザはログインできない
  const denied = await openPage();
  await submitLogin(denied, 'e2e-suspended@globex.test');
  await denied.locator('#loginError:not(.d-none)').waitFor();
  assert.match(await denied.locator('#loginError').innerText(), /利用が停止されているため、ログインできません/);
  assert.equal(await denied.locator('#appView').isHidden(), true);
  ok('停止中のテナントのユーザはログインできず、理由を日本語で出す');

  // 8. ログイン中のユーザは、テナントが停止されると次の操作でログアウトされる
  const member = await login('e2e-active@example.test');
  await openTenants(admin);
  await filter(admin, 'active');
  await row(admin, 'example-tenant').locator('[aria-label="テナントを編集"]').click();
  await admin.locator('#tfStatus').selectOption('suspended');
  await saveModal(admin);
  await member.locator('.app-sidebar [data-view="campaigns"]').click();
  await member.locator('#loginView:not(.d-none)').waitFor();
  await member.locator('#loginError:not(.d-none)', { hasText: '利用が停止されたため、ログアウトしました' }).waitFor();
  ok('ログイン中のユーザは、所属テナントが停止されると次の操作でログアウトされ、理由を出す');
  await filter(admin, 'suspended');
  await row(admin, 'example-tenant').locator('[aria-label="テナントを編集"]').click();
  await admin.locator('#tfStatus').selectOption('active');
  await saveModal(admin);
  await submitLogin(member, 'e2e-active@example.test');
  await member.locator('#appView:not(.d-none)').waitFor();
  ok('テナントを有効に戻すと、またログインできる');

  // 9. superadmin は停止中のテナントに切り替えて閲覧できるが、送信の操作は止まる(API は 409)
  await admin.locator('#tenantSwitcher').selectOption({ label: 'Globex E2E（停止中）' });
  await admin.locator('.app-sidebar [data-view="campaigns"]').click();
  await admin.locator('[data-panel="campaigns"]:not(.d-none)').waitFor();
  const blocked = await admin.evaluate(async () => {
    const res = await fetch('api/edu_deliveries.php?action=remind', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': State.csrf },
      body: JSON.stringify({ id: 900, tenant_id: State.activeTenantId }),
    });
    return { status: res.status, body: await res.json() };
  });
  assert.equal(blocked.status, 409);
  assert.match(blocked.body.error, /停止中のテナントでは送信の操作はできません/);
  ok('superadmin は停止中のテナントに切り替えて画面を開けるが、送信の操作(教育の催促)は 409 と理由');

  for (const p of [admin, denied, member]) assert.deepEqual(p.errors, []);
  ok('どの画面でも JavaScript のエラーが出ない');
} finally {
  await browser.close();
}
console.log(`\n${passed} passed`);

// 役割ごとの権限のブラウザ E2E(本物の API の入口を通す)。合成 DB に向けたローカルのサーバーで動かす(本番に向けない)。
//   - 閲覧者: 不審メールはメニューにも API にも出ない(読み取りも 403)。ユーザ管理を開いてもエラーにならない。
//   - 組織管理者: 共有の素材(共有テンプレートの CSV 一括登録、認証画面の雛形)は変更できない(403)。
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:8765/tet2
//       TET2_E2E_VIEWER_EMAIL=... TET2_E2E_VIEWER_PASSWORD=...   (role=viewer)
//       TET2_E2E_ADMIN_EMAIL=... TET2_E2E_ADMIN_PASSWORD=...     (role=tenant_admin)
//       node admin_permissions_e2e.mjs
import assert from 'node:assert/strict';
import { chromium } from 'playwright';

const env = (k) => process.env[k] || '';
for (const k of ['TET2_E2E_BASE_URL', 'TET2_E2E_VIEWER_EMAIL', 'TET2_E2E_VIEWER_PASSWORD', 'TET2_E2E_ADMIN_EMAIL', 'TET2_E2E_ADMIN_PASSWORD']) {
  if (!env(k)) throw new Error(`${k} is required`);
}
let passed = 0;
const ok = (m) => { passed += 1; console.log(`PASS: ${m}`); };

const browser = await chromium.launch({ headless: true });
const login = async (email, password) => {
  const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  page.errors = [];
  page.on('pageerror', (e) => page.errors.push(e.message));
  await page.goto(`${env('TET2_E2E_BASE_URL')}/`, { waitUntil: 'networkidle' });
  await page.locator('#loginEmail').fill(email);
  await page.locator('#loginPassword').fill(password);
  await page.locator('#loginBtn').click();
  await page.locator('#appView:not(.d-none)').waitFor();
  return page;
};
// 画面と同じ api() で呼び、失敗ならその文言を返す(CSRF などは画面の仕組みに任せる)
const call = (page, path, options) => page.evaluate(([p, o]) => api(p, o).then(() => 'ok').catch((e) => e.message), [path, options]);
const visibleMenus = (page) => page.evaluate(() => [...document.querySelectorAll('.app-sidebar .nav-link')].filter((a) => a.offsetParent).map((a) => a.dataset.view));

try {
  // --- 閲覧者 ---
  const viewer = await login(env('TET2_E2E_VIEWER_EMAIL'), env('TET2_E2E_VIEWER_PASSWORD'));
  assert.equal((await visibleMenus(viewer)).includes('suspiciousMails'), false);
  assert.notEqual(await call(viewer, 'api/suspicious_mails.php', { query: { action: 'list' } }), 'ok');
  const direct = await viewer.evaluate(() => fetch('api/suspicious_mails.php?action=list', { credentials: 'same-origin' }).then((r) => r.status));
  assert.equal(direct, 403);
  ok('閲覧者には不審メールのメニューが出ず、API の読み取りも 403');

  await viewer.locator('.app-sidebar [data-view="users"]').click();
  await viewer.locator('[data-panel="users"]:not(.d-none) #targetsBody tr').first().waitFor();
  await viewer.waitForTimeout(500);
  assert.equal(await viewer.locator('#toast .app-toast.err').count(), 0);
  assert.equal(await viewer.locator('#adminUsersTab').isVisible(), false);
  assert.equal(await viewer.locator('#usersBody tr[data-state="error"], #targetsBody tr[data-state="error"]').count(), 0);
  ok('閲覧者がユーザ管理を開いてもエラーは出ない(管理画面ユーザのタブは出さない)');

  await viewer.locator('.app-sidebar [data-view="templates"]').click();
  await viewer.locator('[data-panel="templates"]:not(.d-none)').waitFor();
  assert.equal(await viewer.locator('#tplImportAddBtn').isVisible(), false);
  assert.deepEqual(viewer.errors, []);

  // --- 組織管理者 ---
  const admin = await login(env('TET2_E2E_ADMIN_EMAIL'), env('TET2_E2E_ADMIN_PASSWORD'));
  assert.equal(await admin.evaluate(() => State.user.role), 'tenant_admin');
  await admin.locator('.app-sidebar [data-view="templates"]').click();
  await admin.locator('[data-panel="templates"]:not(.d-none)').waitFor();
  assert.equal(await admin.locator('#tplImportAddBtn').isVisible(), false);
  assert.equal(await admin.locator('#tplImportUpsertBtn').isVisible(), false);
  const csv = 'name,kind,format,content\nE2E_共有,subject,text,件名\n';
  assert.match(await call(admin, 'api/templates.php', { method: 'POST', query: { action: 'import_csv' }, body: { csv, mode: 'add' } }), /システム管理者だけ/);
  ok('組織管理者には共有テンプレートの CSV 一括登録のボタンが出ず、API も断る');

  assert.match(await call(admin, 'api/master_upload.php', { method: 'POST', query: { action: 'upload' }, body: { kind: 'auth', file: 'master.html', html: '<html><body>x</body></html>' } }), /システム管理者だけ/);
  ok('組織管理者は認証画面の雛形(全テナント共通)を変更できない');

  const shared = await admin.evaluate(() => api('api/templates.php', { query: { action: 'list' } }).then((r) => r.templates.find((t) => t.tenant_id === null || Number(t.is_preset) === 1)));
  if (shared) {
    assert.match(await call(admin, 'api/templates.php', { method: 'POST', query: { action: 'update' }, body: { id: shared.id, content: 'E2E による変更' } }), /システム管理者だけ/);
    ok('組織管理者は共有テンプレートを編集できない');
  } else {
    console.log('SKIP: 合成 DB に共有テンプレートがないため、共有テンプレートの編集の確認は省いた');
  }
  const own = await call(admin, 'api/templates.php', { method: 'POST', query: { action: 'create' }, body: { name: 'E2E 自社の件名', kind: 'subject', format: 'text', content: '自社の件名' } });
  assert.equal(own, 'ok');
  ok('組織管理者は自分のテナントのテンプレートは作れる');
  assert.deepEqual(admin.errors, []);

  console.log(`\n${passed} passed`);
} finally {
  await browser.close();
}

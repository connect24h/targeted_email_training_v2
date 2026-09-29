// 部署の階層(C3、G14)のブラウザ E2E。合成 DB に向けたローカルのサーバーで動かす(本番に向けない)。
//   教育レポートの部署別ランキング、配信の部署ごと(CSV)、部署×分野(CSV)、訓練のレポートの部署別で
//   「部署のまとめ方: 全部 / 1段目 / 2段目」を切り替える。区切りのないテナントでは選択を出さない。狭い幅ではみ出さない。
// 準備: php fixtures/dept_levels_e2e_db.php <db> <password>
//       ドキュメントルートに web への symlink tet2 を置き、TET2_DB_PATH=<db> php -S 127.0.0.1:<port> -t <docroot> で起動する
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:<port>/tet2 TET2_E2E_PASSWORD=... [TET2_E2E_SHOTS=<画面を撮る先>] node dept_levels_e2e.mjs
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

let chromium;
try { ({ chromium } = await import('playwright')); } catch { ({ chromium } = await import('/root/node_modules/playwright/index.mjs')); }

const env = (k) => process.env[k] || '';
for (const k of ['TET2_E2E_BASE_URL', 'TET2_E2E_PASSWORD']) {
  if (!env(k)) throw new Error(`${k} is required`);
}
const BASE = env('TET2_E2E_BASE_URL');
const SHOTS = env('TET2_E2E_SHOTS');
if (SHOTS) fs.mkdirSync(SHOTS, { recursive: true });
let passed = 0;
const ok = (m) => { passed += 1; console.log(`PASS: ${m}`); };
const shot = async (page, name) => {
  if (!SHOTS) return;
  await page.waitForTimeout(400);
  await page.screenshot({ path: path.join(SHOTS, `${name}.png`), fullPage: true });
};

const browser = await chromium.launch({ headless: true });
const login = async (email, width, height) => {
  const context = await browser.newContext({ viewport: { width, height }, acceptDownloads: true });
  const page = await context.newPage();
  page.errors = [];
  page.apiUrls = [];
  page.on('pageerror', (e) => page.errors.push(e.message));
  page.on('request', (r) => { if (r.url().includes('/api/')) page.apiUrls.push(r.url()); });
  await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
  await page.locator('#loginEmail').fill(email);
  await page.locator('#loginPassword').fill(env('TET2_E2E_PASSWORD'));
  await page.locator('#loginBtn').click();
  await page.locator('#appView:not(.d-none)').waitFor();
  return page;
};
const go = (page, view) => page.evaluate((v) => document.querySelector(`.app-sidebar [data-view="${v}"]`).click(), view);
const rows = (page, sel) => page.locator(`${sel} tr:not(.table-state-row)`);
const waitRows = async (page, sel, n) => {
  await page.waitForFunction(([s, count]) => {
    const tb = document.querySelector(s);
    return tb && !tb.querySelector('.table-state-row') && tb.querySelectorAll('tr').length === count;
  }, [sel, n], { timeout: 10000 });
};
const texts = async (page, sel) => (await rows(page, sel).allInnerTexts()).map((t) => t.replace(/\s+/g, ' ').trim());
const csvLines = async (page, selector) => {
  const [file] = await Promise.all([page.waitForEvent('download'), page.locator(selector).click()]);
  return fs.readFileSync(await file.path()).subarray(3).toString('utf8').split('\r\n').filter(Boolean);
};
const noHorizontalOverflow = (page) => page.evaluate(() => {
  const main = document.querySelector('#appMain');
  return document.documentElement.scrollWidth <= window.innerWidth && main.scrollWidth <= main.clientWidth;
});

try {
  const page = await login('dl-admin@example.test', 1440, 1000);

  // --- 教育レポートの概要: 部署別ランキング ---
  await go(page, 'eduReport');
  await page.locator('#eduReportKpi .kpi-card').first().waitFor();
  await waitRows(page, '#eduReportDeptBody', 6);
  await page.locator('#eduReportDeptLevel').waitFor({ state: 'visible' });
  assert.equal(await page.locator('#eduReportDeptLevel').inputValue(), '0');
  assert.deepEqual(await page.locator('#eduReportDeptLevel option').allInnerTexts(), ['全部', '1段目', '2段目']);
  assert.ok((await texts(page, '#eduReportDeptBody')).includes('営業本部/東日本営業部/第1課 66.7% 3'), '全部は今までと同じ部署の文字列');
  assert.equal(page.apiUrls.filter((u) => u.includes('dept_level=')).length, 0, '全部の時は dept_level を送らない(今までと同じ要求)');
  await page.locator('#eduReportDeptLevel').selectOption('1');
  await waitRows(page, '#eduReportDeptBody', 3);
  assert.deepEqual(await texts(page, '#eduReportDeptBody'), ['営業本部 75% 5', '管理本部 70% 3', '(未設定) 50% 1']);
  await page.locator('#eduReportDeptLevel').selectOption('2');
  await waitRows(page, '#eduReportDeptBody', 4);
  assert.equal((await texts(page, '#eduReportDeptBody'))[0], '営業本部 / 東日本営業部 75% 5');
  await shot(page, 'overview-level2');
  ok('概要の部署別ランキングを、全部、1段目、2段目で切り替えられる(平均は点数を足して割り直す)');

  // --- 配信の部署ごと(JSON と CSV) ---
  await page.locator('#eduRepTab-deliveries').click();
  const elRow = rows(page, '#eduRepDeliveriesBody').filter({ hasText: 'eラーニング(部署の階層)' });
  await elRow.waitFor();
  await elRow.getByRole('button', { name: /詳細/ }).click();
  await page.locator('#eduRepSub-depts').click();
  await waitRows(page, '#eduRepDeptsBody', 7);
  await page.locator('#eduRepDeptsLevel').waitFor({ state: 'visible' });
  await page.locator('#eduRepDeptsLevel').selectOption('1');
  await waitRows(page, '#eduRepDeptsBody', 3);
  assert.deepEqual(await texts(page, '#eduRepDeptsBody'), [
    '(未設定) 1 1 0 0 0% 0%', '営業本部 5 4 1 3 60% 40%', '管理本部 2 2 0 1 50% 50%']);
  const deptCsv = await csvLines(page, '#eduRepDeptsCsv');
  assert.equal(deptCsv[0], '部署,対象,完了,未完了,合格,合格率(%),期限内合格率(%)');
  assert.deepEqual(deptCsv.slice(1), ['(未設定),1,1,0,0,0,0', '営業本部,5,4,1,3,60,40', '管理本部,2,2,0,1,50,50']);
  await page.locator('#eduRepDeptsLevel').selectOption('0');
  await waitRows(page, '#eduRepDeptsBody', 7);
  ok('配信の部署ごとと CSV を1段目でまとめ、全部に戻せる');
  await page.locator('#eduRepBackBtn').click();

  // --- 部署 × 分野(C1) ---
  await page.locator('#eduRepTab-tags').click();
  await waitRows(page, '#eduRepTagMatrixBody', 6);
  await page.locator('#eduRepTagMatrixLevel').waitFor({ state: 'visible' });
  await page.locator('#eduRepTagMatrixLevel').selectOption('1');
  await waitRows(page, '#eduRepTagMatrixBody', 3);
  assert.deepEqual(await page.locator('#eduRepTagMatrixHead th').allInnerTexts(), ['部署', 'フィッシング', 'パスワード']);
  assert.deepEqual(await texts(page, '#eduRepTagMatrixBody'), ['(未設定) 0% 解答なし', '営業本部 50% 66.7%', '管理本部 100% 50%']);
  const matrixCsv = await csvLines(page, '#eduRepTagMatrixCsv');
  assert.ok(matrixCsv.includes('営業本部,50,4,66.7,3'), '部署×分野の CSV も1段目でまとめる');
  await shot(page, 'tag-matrix-level1');
  ok('部署×分野を1段目でまとめ、CSV も同じ');

  // --- 訓練のレポート: 部署別 ---
  await go(page, 'reports');
  await page.locator('#reportsBody tr', { hasText: '部署の階層' }).click();
  await page.locator('#reportTab-attributes').click();
  await waitRows(page, '#reportByDept', 7);
  assert.equal(await page.locator('#reportDeptNote').isVisible(), false, '確定前は注記を出さない');
  await page.locator('#reportDeptLevel').selectOption('1');
  await waitRows(page, '#reportByDept', 3);
  const training = await texts(page, '#reportByDept');
  assert.match(training[1], /^営業本部 5 4 \(80\.0%\) 2 \(50\.0%\) 40\.0% 1 \(20\.0%\)$/);
  assert.match(training[2], /^管理本部 3 2 \(66\.7%\) 0 \(0\.0%\) 0\.0% 1 \(33\.3%\)$/);
  await shot(page, 'training-level1');
  ok('訓練のレポートの部署別を1段目でまとめる(表示、認証、報告の率を計算し直す)');
  assert.deepEqual(page.errors, []);

  // --- 狭い幅 ---
  const phone = await login('dl-admin@example.test', 390, 844);
  await go(phone, 'reports');
  await phone.locator('#reportsBody tr', { hasText: '部署の階層' }).click();
  await phone.locator('#reportTab-attributes').click();
  await waitRows(phone, '#reportByDept', 7);
  await phone.locator('#reportDeptLevel').waitFor({ state: 'visible' });
  assert.ok(await noHorizontalOverflow(phone), '訓練の部署別の選択が狭い幅ではみ出さない');
  await go(phone, 'eduReport');
  await waitRows(phone, '#eduReportDeptBody', 6);
  await phone.locator('#eduReportDeptLevel').waitFor({ state: 'visible' });
  assert.ok(await noHorizontalOverflow(phone), '概要の選択が狭い幅ではみ出さない');
  await shot(phone, 'phone-overview');
  ok('狭い幅(390px)でもはみ出さない');

  // --- 区切りのないテナント: 選択を出さない ---
  const flat = await login('dl-admin2@example.test', 1440, 1000);
  await go(flat, 'eduReport');
  await waitRows(flat, '#eduReportDeptBody', 1);
  assert.equal(await flat.locator('#eduReportDeptLevel').isVisible(), false);
  assert.deepEqual(await texts(flat, '#eduReportDeptBody'), ['他社営業部 100% 1']);
  assert.equal(flat.apiUrls.filter((u) => u.includes('dept_level=')).length, 0);
  ok('「/」で区切った部署がないテナントでは「部署のまとめ方」を出さない');
  assert.deepEqual([...phone.errors, ...flat.errors], []);
} finally {
  await browser.close();
}
console.log(`ALL ${passed} CHECKS PASSED`);

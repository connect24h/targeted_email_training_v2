// 管理画面の骨組みのブラウザ E2E: 上のバーは固定で、左の一覧、中央、右の HELP が別々にスクロールする。
// 合成 DB に向けたローカルのサーバーで動かす(本番に向けない)。
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:8765 TET2_E2E_EMAIL=... TET2_E2E_PASSWORD=... node admin_shell_scroll_e2e.mjs
import assert from 'node:assert/strict';
import { chromium } from 'playwright';

const env = (k) => process.env[k] || '';
for (const k of ['TET2_E2E_BASE_URL', 'TET2_E2E_EMAIL', 'TET2_E2E_PASSWORD']) {
  if (!env(k)) throw new Error(`${k} is required`);
}
let passed = 0;
const ok = (m) => { passed += 1; console.log(`PASS: ${m}`); };

const browser = await chromium.launch({ headless: true });
const login = async (width, height) => {
  const page = await (await browser.newContext({ viewport: { width, height } })).newPage();
  page.errors = [];
  page.on('pageerror', (e) => page.errors.push(e.message));
  await page.goto(`${env('TET2_E2E_BASE_URL')}/`, { waitUntil: 'networkidle' });
  await page.locator('#loginEmail').fill(env('TET2_E2E_EMAIL'));
  await page.locator('#loginPassword').fill(env('TET2_E2E_PASSWORD'));
  await page.locator('#loginBtn').click();
  await page.locator('#appView:not(.d-none)').waitFor();
  return page;
};
const top = (page, sel) => page.locator(sel).evaluate((el) => el.getBoundingClientRect().top);
const scrollTop = (page, sel) => page.locator(sel).evaluate((el) => el.scrollTop);

try {
  // 高さを低くして、中央と左の一覧の両方が画面からはみ出す状態にする
  const page = await login(1440, 480);
  await page.locator('.app-sidebar [data-view="dashboard"]').click();
  await page.locator('#kpiRow .kpi-card').first().waitFor();

  assert.equal(await page.evaluate(() => document.documentElement.scrollHeight <= window.innerHeight), true);
  ok('ページ全体はスクロールしない(高さが画面に収まる)');

  const navTop = await top(page, '.app-navbar');
  const sideTop = await top(page, '#sidebar');
  const scrolled = await page.locator('#appMain').evaluate((el) => { el.scrollTop = el.scrollHeight; return el.scrollTop; });
  assert.ok(scrolled > 0, '中央がスクロールできる');
  assert.equal(await top(page, '.app-navbar'), navTop);
  assert.equal(await top(page, '#sidebar'), sideTop);
  assert.equal(await scrollTop(page, '#sidebar'), 0);
  ok('中央を下までスクロールしても、上のバーと左の一覧は動かない');

  const sideScrolled = await page.locator('#sidebar').evaluate((el) => { el.scrollTop = el.scrollHeight; return el.scrollTop; });
  assert.ok(sideScrolled > 0, '左の一覧がスクロールできる');
  assert.equal(await scrollTop(page, '#appMain'), scrolled);
  ok('左の一覧だけをスクロールでき、中央の位置は変わらない');

  await page.locator('.app-sidebar [data-view="campaigns"]').click();
  await page.locator('#campaignsBody').waitFor({ state: 'attached' });
  assert.equal(await scrollTop(page, '#appMain'), 0);
  ok('別の画面に移ると、中央は先頭に戻る');

  await page.locator('#helpToggle').click();
  await page.locator('#contextHelp').waitFor();
  const [mainRight, helpLeft] = await page.evaluate(() => [
    document.querySelector('#appMain').getBoundingClientRect().right,
    document.querySelector('#contextHelp').getBoundingClientRect().left,
  ]);
  assert.ok(mainRight <= helpLeft + 1, `HELP が中央に重ならない (main.right=${mainRight}, help.left=${helpLeft})`);
  assert.equal(await page.locator('#helpToggle').getAttribute('aria-expanded'), 'true');
  const helpScrolled = await page.locator('#contextHelp').evaluate((el) => { el.scrollTop = el.scrollHeight; return el.scrollTop; });
  assert.ok(helpScrolled > 0, 'HELP がスクロールできる');
  assert.equal(await scrollTop(page, '#appMain'), 0);
  assert.equal(await top(page, '.app-navbar'), navTop);
  ok('幅 1440px では HELP は右の列に並び、HELP だけでスクロールする');
  await page.locator('#helpClose').click();
  await page.locator('#contextHelp').waitFor({ state: 'hidden', timeout: 2000 });
  // キーボードだけで一覧から画面を移れて、タブの表題が画面の名前になる
  await page.locator('.app-sidebar [data-view="groups"]').focus();
  await page.keyboard.press('Enter');
  await page.locator('[data-panel="groups"]:not(.d-none)').waitFor();
  assert.equal(await page.locator('.app-sidebar [data-view="groups"]').getAttribute('aria-current'), 'page');
  assert.match(await page.title(), /^グループ \| TET v2$/);
  ok('一覧の項目に Tab で届き、Enter で画面を移れ、タブの表題が画面の名前になる');

  // 文字を 200% にしても上のバーの中身がはみ出さない
  await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
  const overflow = await page.evaluate(() => [...document.querySelectorAll('.app-navbar *')]
    .filter((el) => el.offsetParent && el.getBoundingClientRect().right > window.innerWidth + 1).length);
  assert.equal(overflow, 0);
  await page.evaluate(() => { document.documentElement.style.fontSize = ''; });
  ok('文字を 200% にしても、上のバーの中身が画面の外へはみ出さない');
  assert.deepEqual(page.errors, []);

  // モバイル: 引き出し式の一覧と、横スクロールがないこと
  const mobile = await login(375, 812);
  assert.equal(await mobile.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
  assert.equal(await mobile.locator('#sidebar').isVisible(), false);
  await mobile.locator('#sidebarToggle').click();
  await mobile.locator('#sidebar').waitFor();
  assert.equal(await mobile.locator('#sidebarToggle').getAttribute('aria-expanded'), 'true');
  await mobile.locator('#sidebarBackdrop').click({ position: { x: 360, y: 400 } });
  await mobile.locator('#sidebar').waitFor({ state: 'hidden' });
  assert.equal(await mobile.locator('#sidebarToggle').getAttribute('aria-expanded'), 'false');
  ok('幅 375px では横スクロールがなく、一覧は開いて背景を押すと閉じる');
  await mobile.locator('#sidebarToggle').click();
  await mobile.locator('.app-sidebar [data-view="eduDeliveries"]').click();
  await mobile.locator('#sidebar').waitFor({ state: 'hidden' });
  ok('幅 375px で一覧から画面を選ぶと、一覧が閉じる');
  await mobile.locator('#sidebarToggle').click();
  assert.equal(await mobile.locator('#sidebarToggle').getAttribute('aria-label'), 'メニューを閉じる');
  await mobile.keyboard.press('Escape');
  await mobile.locator('#sidebar').waitFor({ state: 'hidden' });
  assert.equal(await mobile.locator('#sidebarToggle').getAttribute('aria-label'), 'メニューを開く');
  ok('幅 375px で一覧は Escape で閉じ、ボタンの名前が開閉に合わせて変わる');
  assert.equal(await mobile.locator('#helpToggle').getAttribute('aria-label'), 'HELP');
  const small = await mobile.evaluate(() => [...document.querySelectorAll('#appMain .btn-sm')]
    .filter((el) => el.offsetParent).map((el) => el.getBoundingClientRect())
    .filter((r) => r.height < 44 || r.width < 44).length);
  assert.equal(small, 0);
  ok('幅 375px で HELP ボタンに名前があり、表の操作ボタンは 44px 四方以上');
  await mobile.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
  assert.equal(await mobile.evaluate(() => [...document.querySelectorAll('.app-navbar *')]
    .filter((el) => el.offsetParent && el.getBoundingClientRect().right > window.innerWidth + 1).length), 0);
  ok('幅 375px で文字を 200% にしても、上のバーの中身がはみ出さない');
  assert.deepEqual(mobile.errors, []);

  console.log(`\n${passed} passed`);
} finally {
  await browser.close();
}

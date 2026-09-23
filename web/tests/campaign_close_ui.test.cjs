const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const test = require('node:test');
const { chromium } = require('playwright');

const webRoot = path.resolve(__dirname, '..');

test('システム管理者だけが確定レポートをクローズでき、画面で消去済みを示す', async () => {
  let role = 'tenant_admin';
  let closed = false;
  let closeCalls = 0;
  const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    const json = (data) => {
      response.setHeader('Content-Type', 'application/json; charset=utf-8');
      response.end(JSON.stringify({ success: true, ...data }));
    };
    if (url.pathname === '/api/auth.php') {
      json({ user: { id: 1, email: 'test@example.test', role, tenant_id: 1 }, csrf: 'synthetic' });
      return;
    }
    if (url.pathname === '/api/tenants.php') { json({ tenants: [{ id: 1, name: 'テスト組織' }] }); return; }
    if (url.pathname === '/api/report.php') {
      const action = url.searchParams.get('action');
      if (action === 'campaigns') {
        json({ campaigns: [{ id: 11, name: '合成訓練', status: 'done', closed_at: closed ? '2026-09-23 10:00:00' : null,
          target_count: 1, sent_count: 1, sent_rate: 100, click_count: 0, click_rate: 0, auth_count: 0 }] });
        return;
      }
      if (action === 'detail') {
        json({ is_committed: true, is_closed: closed, committed_at: '2026-09-23 09:00:00',
          closed_at: closed ? '2026-09-23 10:00:00' : null, summary: { count: 1 },
          by_company: [], by_position: [], by_content: [], timeline: [] });
        return;
      }
      if (action === 'close' && request.method === 'POST') {
        closeCalls++;
        closed = true;
        json({ is_closed: true, closed_at: '2026-09-23 10:00:00' });
        return;
      }
      json({}); return;
    }
    if (url.pathname.startsWith('/api/')) { json({}); return; }
    const requested = path.resolve(webRoot, `.${url.pathname === '/' ? '/index.html' : url.pathname}`);
    if (!requested.startsWith(`${webRoot}${path.sep}`)) { response.writeHead(403).end(); return; }
    const mime = requested.endsWith('.js') ? 'application/javascript' : requested.endsWith('.css') ? 'text/css' : 'text/html';
    try { response.setHeader('Content-Type', mime); response.end(fs.readFileSync(requested)); }
    catch { response.writeHead(404).end(); }
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  let browser;
  try {
    browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
    const openReport = async () => {
      const page = await browser.newPage();
      await page.goto(`http://127.0.0.1:${server.address().port}/`);
      await page.locator('.app-sidebar [data-view="reports"]').click();
      await page.locator('#reportsBody tr').first().click();
      await page.locator('#reportCommitBadge .badge').first().waitFor();
      return page;
    };
    const tenantPage = await openReport();
    assert.equal(await tenantPage.locator('#reportCloseBtn').isVisible(), false);
    await tenantPage.close();

    role = 'superadmin';
    const adminPage = await openReport();
    assert.equal(await adminPage.locator('#reportCloseBtn').isVisible(), true);
    await adminPage.setViewportSize({ width: 360, height: 780 });
    assert.equal(await adminPage.locator('#reportCloseBtn').isVisible(), true);
    adminPage.once('dialog', (dialog) => dialog.accept());
    await adminPage.locator('#reportCloseBtn').click();
    await adminPage.getByText('クローズ済み', { exact: false }).first().waitFor();
    assert.equal(closeCalls, 1);
    assert.equal(await adminPage.locator('#reportCloseBtn').isVisible(), false);
    assert.equal(await adminPage.locator('#reportUncommitBtn').isVisible(), false);
    assert.equal(await adminPage.locator('#reportStartDate').isDisabled(), true);
    await adminPage.close();
  } finally {
    await browser?.close();
    await new Promise((resolve) => server.close(resolve));
  }
});

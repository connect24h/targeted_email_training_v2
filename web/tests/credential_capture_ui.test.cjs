const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const test = require('node:test');
const { chromium } = require('playwright');

const webRoot = path.resolve(__dirname, '..');
const syntheticValue = 'SYNTHETIC_UI_ONLY';

test('入力本文はシステム管理者だけが明示操作で一時表示できる', async () => {
  let role = 'superadmin';
  let revealCalls = 0;
  const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    const json = (data) => {
      response.setHeader('Content-Type', 'application/json; charset=utf-8');
      response.end(JSON.stringify({ success: true, ...data }));
    };
    if (url.pathname === '/api/auth.php') {
      json({ user: { id: 1, email: 'test@example.test', role, tenant_id: role === 'superadmin' ? null : 1 }, csrf: 'synthetic' });
      return;
    }
    if (url.pathname === '/api/tenants.php') { json({ tenants: [{ id: 1, name: 'テスト組織' }, { id: 2, name: '別組織' }] }); return; }
    if (url.pathname === '/api/campaigns.php') { json({ campaigns: [{ id: 11, name: '承認済み訓練', status: 'running' }] }); return; }
    if (url.pathname === '/api/report.php' && url.searchParams.get('action') === 'campaigns') {
      json({ campaigns: [{ id: 11, name: '承認済み訓練' }] }); return;
    }
    if (url.pathname === '/api/credential_captures.php') {
      if (url.searchParams.get('action') === 'reveal') {
        revealCalls++;
        json({ fields: { email: 'person@example.test', password: syntheticValue } });
      } else json({ captures: [{ id: 3, tracking_id: '1234567890', auth_type: 'box', created_at: '2026-09-23 10:00:00' }] });
      return;
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
    const page = await browser.newPage();
    await page.goto(`http://127.0.0.1:${server.address().port}/`);
    await page.locator('.app-sidebar [data-view="logs"]').click();
    await page.locator('[data-log="credential_captures"]').click();
    await page.locator('#logsCampaignFilter').selectOption('11');
    await page.locator('#logsBody [data-capture-id="3"]').waitFor();
    assert.doesNotMatch(await page.locator('#logsBody').innerText(), /SYNTHETIC_UI_ONLY/);
    assert.equal(await page.locator('#logsXlsxBtn').isVisible(), false);
    await page.locator('#logsBody [data-capture-id="3"]').click();
    await page.locator('#credentialRevealDialog[open]').waitFor();
    assert.match(await page.locator('#credentialRevealText').innerText(), /SYNTHETIC_UI_ONLY/);
    assert.equal(revealCalls, 1);
    await page.locator('#credentialRevealDialog button').click();
    await page.locator('#credentialRevealDialog[open]').waitFor({ state: 'hidden' });
    assert.equal(await page.locator('#credentialRevealText').innerText(), '');
    await page.setViewportSize({ width: 360, height: 780 });
    await page.locator('#logsBody [data-capture-id="3"]').click();
    await page.locator('#credentialRevealDialog[open]').waitFor();
    assert.ok(await page.locator('#credentialRevealDialog').evaluate((dialog) => dialog.getBoundingClientRect().width <= 360));
    await page.keyboard.press('Escape');
    await page.locator('#credentialRevealDialog[open]').waitFor({ state: 'hidden' });
    assert.equal(await page.locator('#credentialRevealText').innerText(), '');
    await page.locator('#logsBody [data-capture-id="3"]').click();
    await page.locator('#credentialRevealDialog[open]').waitFor();
    await page.locator('#tenantSwitcher').selectOption('2');
    assert.equal(await page.locator('#credentialRevealDialog').evaluate((dialog) => dialog.open), false);
    assert.equal(await page.locator('#credentialRevealText').innerText(), '');
    await page.close();

    role = 'tenant_admin';
    const tenantPage = await browser.newPage();
    await tenantPage.goto(`http://127.0.0.1:${server.address().port}/`);
    await tenantPage.locator('.app-sidebar [data-view="logs"]').click();
    assert.equal(await tenantPage.locator('[data-log="credential_captures"]').isVisible(), false);
  } finally {
    await browser?.close();
    await new Promise((resolve) => server.close(resolve));
  }
});

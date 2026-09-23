const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const test = require('node:test');
const { chromium } = require('playwright');

const webRoot = path.resolve(__dirname, '..');
const campaigns = [
  { id: 1, name: '下書き訓練', status: 'draft', is_test: 0, target_count: 12, start_at: '2026-10-02 09:00:00', end_at: '2026-10-03 18:00:00' },
  { id: 2, name: '停止中訓練', status: 'paused', is_test: 0, target_count: 8, start_at: '2026-09-20 09:00:00' },
  { id: 3, name: 'TEST下書き', status: 'draft', is_test: 1, target_count: 4, start_at: '2026-10-03 09:00:00' },
];

test('運用ホーム、画面URL、文脈HELPをブラウザで操作できる', async () => {
  let preflightReady = true;
  let launchAttempts = 0;
  const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    const json = (data) => {
      response.setHeader('Content-Type', 'application/json; charset=utf-8');
      response.end(JSON.stringify({ success: true, ...data }));
    };
    if (url.pathname === '/api/auth.php') {
      json({ user: { id: 1, email: 'operator@example.test', role: 'operator', tenant_id: 1 }, csrf: 'synthetic' });
      return;
    }
    if (url.pathname === '/api/campaigns.php') {
      if (url.searchParams.get('action') === 'get') {
        json({ campaign: campaigns[0], contents: [{ content_no: 1, subject_template_id: 1, body_template_id: 2, phish_template_id: 3, link_mode: 'link' }], target_ids: [1] });
      } else json({ campaigns });
      return;
    }
    if (url.pathname === '/api/campaign_launch.php' && url.searchParams.get('action') === 'preflight') {
      json({ preflight: { can_launch: preflightReady, revision: 'a'.repeat(64), blockers: preflightReady ? [] : ['停止した対象者が含まれます'], warnings: [],
        contents: [{ content_no: 1, subject_name: 'snapshot件名', body_name: 'snapshot本文', link_mode: 'link' }],
        summary: { campaign_name: '下書き訓練', is_test: false, target_count: 12, send_count: 12, content_count: 1,
          start_at: '2026-10-02 09:00:00', end_at: '2026-10-03 18:00:00', test_distribution: [] } } });
      return;
    }
    if (url.pathname === '/api/campaign_launch.php' && url.searchParams.get('action') === 'launch') {
      launchAttempts++;
      preflightReady = false;
      response.writeHead(409, { 'Content-Type': 'application/json; charset=utf-8' });
      response.end(JSON.stringify({ success: false, error: '確認後に対象者が変更されました' }));
      return;
    }
    if (url.pathname === '/api/templates.php') {
      json({ templates: [
        { id: 1, kind: 'subject', name: '件名例', scenario_key: 'first' },
        { id: 2, kind: 'body', name: '本文例', scenario_key: 'first' },
        { id: 3, kind: 'phish_login', name: '到達画面例' },
        { id: 4, kind: 'subject', name: '別の件名', scenario_key: 'second' },
        { id: 5, kind: 'body', name: '別の本文', scenario_key: 'second' },
      ] });
      return;
    }
    if (url.pathname === '/api/send_control.php') { json({ statuses: [], data: [], active_count: 0 }); return; }
    if (url.pathname.startsWith('/api/')) { json({}); return; }

    const requested = path.resolve(webRoot, `.${url.pathname === '/' ? '/index.html' : url.pathname}`);
    if (!requested.startsWith(`${webRoot}${path.sep}`)) { response.writeHead(403).end(); return; }
    const mime = requested.endsWith('.js') ? 'application/javascript' : requested.endsWith('.css') ? 'text/css' : requested.endsWith('.html') ? 'text/html' : 'application/octet-stream';
    try {
      response.setHeader('Content-Type', mime);
      response.end(fs.readFileSync(requested));
    } catch { response.writeHead(404).end(); }
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  let browser;
  try {
    browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(`http://127.0.0.1:${server.address().port}/`);
    await page.locator('#dashWorkItems').getByText('下書き訓練').waitFor();
    assert.equal(await page.locator('#dashWorkItems').getByText('TEST下書き').count(), 0);
    assert.match(await page.locator('#dashWorkItems').innerText(), /停止中訓練/);

    await page.locator('#helpToggle').click();
    await page.locator('#helpSearch').fill('全件転送');
    await page.locator('[data-help-list]').getByText('テスト送信と通数').waitFor();
    await page.keyboard.press('Escape');
    assert.equal(await page.locator('#contextHelp').isVisible(), false);

    await page.locator('.app-sidebar [data-view="campaigns"]').click();
    await page.waitForURL('**/#campaigns');
    await page.goBack();
    await page.waitForURL('**/#dashboard');
    assert.equal(await page.locator('[data-panel="dashboard"]').isVisible(), true);

    await page.locator('.app-sidebar [data-view="campaigns"]').click();
    await page.locator('#campaignsBody button[onclick="launchCampaign(1)"]').click();
    await page.waitForURL('**/#campaignWorkspace/1');
    await page.locator('#campaignWorkspaceRoot').getByText('送信マニフェスト').waitFor();
    await page.locator('#campaignWorkspaceRoot').getByText('snapshot件名').waitFor();
    assert.match(await page.locator('#campaignWorkspaceRoot').innerText(), /12通/);
    assert.equal(await page.locator('[data-campaign-action="launch"]').isEnabled(), true);
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('[data-campaign-action="launch"]').click();
    await page.locator('#campaignWorkspaceRoot').getByText('停止した対象者が含まれます').waitFor();
    assert.equal(launchAttempts, 1);
    assert.equal(await page.locator('[data-campaign-action="launch"]').isEnabled(), false);
    preflightReady = false;
    await page.locator('[data-campaign-action="refresh"]').click();
    await page.locator('#campaignWorkspaceRoot').getByText('停止した対象者が含まれます').waitFor();
    assert.equal(await page.locator('[data-campaign-action="launch"]').isEnabled(), false);
    await page.locator('[data-campaign-action="edit"]').click();
    await page.locator('#campaignForm .campaign-editor-nav').waitFor();
    assert.equal(await page.locator('#campaignForm [data-campaign-step]:visible').count(), 1);
    await page.locator('#campaignForm [data-editor-step="targets"]').click();
    assert.equal(await page.locator('#campaignForm [data-campaign-step="targets"]').isVisible(), true);
    await page.locator('#campaignForm [data-editor-mode]').click();
    assert.equal(await page.locator('#campaignForm [data-campaign-step]:visible').count(), 6);
    assert.deepEqual(await page.locator('#campaignForm [data-campaign-step]').evaluateAll((sections) =>
      sections.map((section) => getComputedStyle(section).gridColumnStart)), Array(6).fill('2'));
    await page.locator('#appModalSave').click();
    const visibleEditorStep = await page.locator('#campaignForm [data-campaign-step]:visible').getAttribute('data-campaign-step');
    assert.equal(visibleEditorStep, 'delivery');
    assert.equal(await page.locator('#appModal').isVisible(), true);
    assert.equal(await page.locator('#campaignForm [name="from_address"]').evaluate((input) => input === document.activeElement), true);
    await page.setViewportSize({ width: 360, height: 780 });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    assert.ok(overflow <= 1, `360px表示で横スクロールしないこと (overflow=${overflow})`);
    const modalOverflow = await page.locator('#campaignForm').evaluate((form) => form.scrollWidth - form.clientWidth);
    assert.ok(modalOverflow <= 1, `編集フォームが横スクロールしないこと (overflow=${modalOverflow})`);
    assert.deepEqual(await page.locator('#campaignForm [data-campaign-step]').evaluateAll((sections) =>
      sections.map((section) => getComputedStyle(section).gridColumnStart)), Array(6).fill('1'));
    await page.locator('#appModal .btn-close').click();
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.locator('.app-sidebar [data-view="templates"]').click();
    await page.locator('#templatesBody').getByText('件名例').waitFor();
    await page.locator('#tplSearch').fill('本文例');
    assert.match(await page.locator('#templatesBody').innerText(), /件名例/);
    assert.doesNotMatch(await page.locator('#templatesBody').innerText(), /別の件名/);
    assert.deepEqual(errors, []);
  } finally {
    await browser?.close();
    await new Promise((resolve) => server.close(resolve));
  }
});

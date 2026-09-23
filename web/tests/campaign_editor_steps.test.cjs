const assert = require('node:assert/strict');
const path = require('node:path');
const test = require('node:test');
const { chromium } = require('playwright');

const scriptPath = path.resolve(__dirname, '../assets/campaign-editor-steps.js');

test('キャンペーン編集の6段階と一覧表示を切り替えられる', async () => {
  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
  try {
    const page = await browser.newPage();
    await page.setContent(`<form id="campaignForm">
      <section data-campaign-step="basic"><input name="name" value="訓練"></section>
      <section data-campaign-step="targets"><input name="target" value="1"></section>
      <section data-campaign-step="scenario"><input name="scenario" value="A"></section>
      <section data-campaign-step="schedule"><input name="start" value="future"></section>
      <section data-campaign-step="delivery"><input name="sender" value="sender@example.test"></section>
      <section data-campaign-step="review"><p>保存後に配信前確認へ</p></section>
    </form>`);
    await page.addScriptTag({ path: scriptPath });
    await page.evaluate(() => window.createCampaignEditorSteps(document.querySelector('#campaignForm')));
    assert.equal(await page.locator('[data-campaign-step]:visible').count(), 1);
    assert.equal(await page.locator('[data-campaign-step="basic"]').isVisible(), true);
    await page.getByRole('button', { name: /次へ.*対象者/ }).click();
    assert.equal(await page.locator('[data-campaign-step="targets"]').isVisible(), true);
    await page.getByRole('button', { name: '前へ' }).click();
    assert.equal(await page.locator('[data-campaign-step="basic"]').isVisible(), true);

    await page.getByRole('button', { name: /3 シナリオ/ }).click();
    assert.equal(await page.locator('[data-campaign-step="scenario"]').isVisible(), true);
    await page.getByRole('button', { name: 'すべて表示' }).click();
    assert.equal(await page.locator('[data-campaign-step]:visible').count(), 6);
    assert.equal(await page.locator('.campaign-editor-controls').isVisible(), false);
    await page.getByRole('button', { name: '手順表示' }).click();
    assert.equal(await page.locator('[data-campaign-step]:visible').count(), 1);
    assert.equal(await page.locator('.campaign-editor-controls').isVisible(), true);
  } finally {
    await browser.close();
  }
});

test('保存時に不正な入力があれば該当段階を表示する', async () => {
  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
  try {
    const page = await browser.newPage();
    await page.setContent(`<form id="campaignForm">
      <section data-campaign-step="basic"><input name="name" value="訓練"></section>
      <section data-campaign-step="targets"><input name="target" value="1"></section>
      <section data-campaign-step="scenario"><input name="scenario" value="A"></section>
      <section data-campaign-step="schedule"><input name="start" required></section>
      <section data-campaign-step="delivery"><input name="sender" value="sender@example.test"></section>
      <section data-campaign-step="review"><p>確認</p></section>
    </form>`);
    await page.addScriptTag({ path: scriptPath });
    const result = await page.evaluate(() => {
      const editor = window.createCampaignEditorSteps(document.querySelector('#campaignForm'));
      return editor.validateBeforeSave();
    });
    assert.equal(result, false);
    assert.equal(await page.locator('[data-campaign-step="schedule"]').isVisible(), true);
    assert.equal(await page.locator('input[name="start"]').evaluate((input) => input === document.activeElement), true);
  } finally {
    await browser.close();
  }
});

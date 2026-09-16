import assert from 'node:assert/strict';
import { stat } from 'node:fs/promises';
import { chromium } from 'playwright';

const baseUrl = process.env.TET2_E2E_BASE_URL;
const email = process.env.TET2_E2E_EMAIL;
const password = process.env.TET2_E2E_PASSWORD;
const fixtureDir = process.env.TET2_E2E_FIXTURE_DIR;

for (const [name, value] of Object.entries({ baseUrl, email, password, fixtureDir })) {
  if (!value) throw new Error(`${name} is required`);
}

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ acceptDownloads: true, viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
const educationPanel = page.locator('[data-panel="eduQuestions"]');
const pageErrors = [];
page.on('pageerror', (error) => pageErrors.push(error.message));

async function openEducationBank() {
  await page.goto(baseUrl, { waitUntil: 'networkidle' });
  await page.locator('#appView:not(.d-none)').waitFor();
  await page.evaluate(() => document.querySelector('[data-view="eduQuestions"]')?.click());
  await educationPanel.getByRole('button', { name: /PowerPoint読込/ }).waitFor();
}

try {
  await page.goto(baseUrl, { waitUntil: 'networkidle' });
  await page.locator('#loginEmail').fill(email);
  await page.locator('#loginPassword').fill(password);
  await page.locator('#loginBtn').click();
  await page.locator('#appView:not(.d-none)').waitFor();

  await page.evaluate(() => document.querySelector('[data-view="eduQuestions"]')?.click());
  await educationPanel.getByRole('button', { name: /PowerPoint読込/ }).waitFor();
  await page.getByRole('link', { name: /全カテゴリ/ }).waitFor();
  await page.getByText('既存の確認問題', { exact: true }).waitFor();

  await educationPanel.getByRole('button', { name: /回答付き一覧/ }).click();
  await page.getByText('確認テスト 全設問・回答付き一覧', { exact: true }).waitFor();
  await page.getByText('既存の解説', { exact: false }).waitFor();
  assert.equal(await page.locator('.modal.show .badge.bg-success').first().textContent(), '正解');
  await page.locator('.modal.show .btn-close').click();

  const templateDownload = page.waitForEvent('download');
  await educationPanel.getByRole('button', { name: /Excelテンプレート/ }).click();
  const template = await templateDownload;
  const templatePath = `${fixtureDir}/downloaded-template.xlsx`;
  await template.saveAs(templatePath);
  assert.ok((await stat(templatePath)).size > 0);

  await openEducationBank();
  const exportDownload = page.waitForEvent('download');
  await educationPanel.getByRole('button', { name: /Excel出力/ }).click();
  const exported = await exportDownload;
  const exportPath = `${fixtureDir}/downloaded-questions.xlsx`;
  await exported.saveAs(exportPath);
  assert.ok((await stat(exportPath)).size > 0);

  await openEducationBank();
  await educationPanel.getByRole('button', { name: /Excel読込/ }).click();
  await page.locator('#eduXlsxFile').setInputFiles(`${fixtureDir}/questions.xlsx`);
  await page.locator('.modal.show #appModalSave').click();
  await page.getByText('1問を追加しました', { exact: true }).waitFor();
  await page.getByText('Excel追加問題', { exact: true }).waitFor();

  await educationPanel.getByRole('button', { name: /PowerPoint読込/ }).click();
  await page.locator('#eduPptxFile').setInputFiles(`${fixtureDir}/material.pptx`);
  await page.locator('.modal.show #appModalSave').click();
  await page.locator('#eduMaterialForm').waitFor();
  assert.equal(await page.locator('#eduMaterialForm [name="title"]').inputValue(), 'material');
  assert.equal(await page.locator('#eduMaterialForm [name="slide_title"]').inputValue(), 'E2E教材タイトル');
  assert.equal(await page.locator('#eduMaterialForm [name="slide_body"]').inputValue(), 'E2E教材本文');
  await page.locator('.modal.show #appModalSave').click();
  await page.getByText('教材を作成しました', { exact: true }).waitFor();

  const materialRow = page.locator('#eduMaterialsBody tr').filter({ hasText: 'material' });
  await materialRow.waitFor();
  await materialRow.getByRole('button', { name: /教材を試行/ }).click();
  await page.getByText('E2E教材タイトル', { exact: true }).waitFor();
  await page.getByText('E2E教材本文', { exact: true }).waitFor();

  assert.deepEqual(pageErrors, []);
  console.log('PASS: 教材バンク Office入出力 E2E');
} finally {
  await context.close();
  await browser.close();
}

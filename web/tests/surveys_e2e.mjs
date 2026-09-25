// アンケート(U7)のブラウザ E2E。合成 DB を指したローカルサーバーに対して実行する。
//   TET2_E2E_BASE_URL=http://localhost:8099/ TET2_E2E_EMAIL=... TET2_E2E_PASSWORD=... TET2_E2E_FIXTURE_DIR=... node surveys_e2e.mjs
// 流れ: ログイン → 雛形から作成 → 配信 → 回答用 URL の CSV → 回答画面で回答 → 結果。
// あわせて、題名の HTML が実行されないこと、メール送信が無効ならボタンが押せないことを確かめる。
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
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
const pageErrors = [];
page.on('pageerror', (error) => pageErrors.push(error.message));
page.on('dialog', (dialog) => dialog.accept());
const panel = page.locator('[data-panel="surveys"]');

async function openSurveys() {
  await page.evaluate(() => document.querySelector('[data-view="surveys"]')?.click());
  await panel.locator('#svBody tr').first().waitFor();
}

try {
  await page.goto(baseUrl, { waitUntil: 'networkidle' });
  await page.locator('#loginEmail').fill(email);
  await page.locator('#loginPassword').fill(password);
  await page.locator('#loginBtn').click();
  await page.locator('#appView:not(.d-none)').waitFor();
  await openSurveys();
  await panel.getByText('メール送信は無効です', { exact: false }).waitFor();
  console.log('PASS: SE-1 アンケート画面を開き、送信が無効であることを表示する');

  // 題名に HTML を入れても実行されない。
  await panel.getByRole('button', { name: /新規作成/ }).click();
  await page.locator('#svTitle').fill('<img src=x onerror="window.__xss=1">題名');
  await page.locator('#svAddQuestion').click();
  await page.locator('[data-q-field="title"][data-q-index="0"]').fill('<b>太字</b>の設問');
  await page.locator('.modal.show #appModalSave').click();
  await page.getByText('保存しました', { exact: true }).waitFor();
  await panel.locator('#svBody').getByText('<img src=x onerror="window.__xss=1">題名', { exact: true }).waitFor();
  assert.equal(await page.evaluate(() => window.__xss), undefined);
  assert.equal(await panel.locator('#svBody img').count(), 0);
  console.log('PASS: SE-2 題名の HTML は文字として表示し、実行しない');

  // 雛形から作成して配信する。
  await panel.locator('#svTemplateSelect').selectOption('after_training');
  await panel.getByRole('button', { name: /雛形から作成/ }).click();
  const row = panel.locator('#svBody tr').filter({ hasText: '標的型メール訓練の振り返りアンケート' });
  await row.waitFor();
  await row.getByRole('button', { name: '配信' }).click();
  await page.locator('#svDeliveryTitle').fill('E2E 第1回');
  await page.locator('.modal.show').getByText('全職員', { exact: false }).click();
  await page.locator('.modal.show #appModalSave').click();
  await page.getByText('2名に配信を作成しました', { exact: true }).waitFor();
  const deliveryRow = panel.locator('#svDeliveryBody tr').filter({ hasText: 'E2E 第1回' });
  await deliveryRow.waitFor();
  assert.equal(await deliveryRow.getByRole('button', { name: '案内メール' }).isDisabled(), true);
  assert.equal(await row.getByRole('button', { name: '設問を見る' }).count(), 1);
  console.log('PASS: SE-3 雛形から作成して配信し、配信後は設問を編集できない');

  // 回答用 URL の CSV を取得する。
  const download = page.waitForEvent('download');
  await deliveryRow.getByRole('link', { name: /回答用 URL/ }).click();
  const csvPath = `${fixtureDir}/urls.csv`;
  await (await download).saveAs(csvPath);
  let csv = await readFile(csvPath, 'utf8');
  if (csv.charCodeAt(0) === 0xfeff) csv = csv.slice(1); // Excel 向けの BOM を外す
  const urls = csv.split('\n').slice(1).filter(Boolean).map((line) => line.split(',').pop().replace(/"/g, '').trim());
  assert.equal(urls.length, 2);
  assert.match(urls[0], /\/survey\.php\?token=[0-9a-f]{32}$/);
  console.log('PASS: SE-4 未回答者2名の回答用 URL を CSV で取得する');

  // 回答画面: 表示条件と必須の確認。
  const answerPage = await context.newPage();
  answerPage.on('pageerror', (error) => pageErrors.push(error.message));
  await answerPage.goto(urls[0], { waitUntil: 'networkidle' });
  await answerPage.getByText('標的型メール訓練の振り返りアンケート', { exact: true }).waitFor();
  const conditional = answerPage.locator('#q3');
  assert.equal(await conditional.isHidden(), true);
  await answerPage.getByLabel('リンクや添付を開き、報告しなかった').check();
  assert.equal(await conditional.isVisible(), true);
  await answerPage.getByLabel('開かずに報告した').check();
  assert.equal(await conditional.isHidden(), true);
  await answerPage.getByRole('button', { name: /回答を送信する/ }).click();
  await answerPage.getByText('必須の設問に回答してください。', { exact: true }).waitFor();
  await answerPage.getByLabel('知っていた').check();
  await answerPage.getByLabel('開かずに報告する').check();
  await answerPage.locator('#q5_text').fill('<script>alert(1)</script> 役に立った');
  await answerPage.getByRole('button', { name: /回答を送信する/ }).click();
  await answerPage.getByText('回答を受け付けました', { exact: true }).waitFor();
  await answerPage.goto(urls[0], { waitUntil: 'networkidle' });
  await answerPage.getByText('このアンケートは回答済みです', { exact: true }).waitFor();
  console.log('PASS: SE-5 表示条件、必須の確認、送信、二重回答の拒否');

  // 結果: 回答率と自由記述(文字として表示)。
  await openSurveys();
  await panel.locator('#svDeliveryBody tr').filter({ hasText: 'E2E 第1回' }).getByRole('button', { name: '結果' }).click();
  const modal = page.locator('.modal.show');
  await modal.getByText('50%', { exact: true }).waitFor();
  await modal.getByText('<script>alert(1)</script> 役に立った', { exact: true }).waitFor();
  await modal.getByText('総務部', { exact: true }).waitFor();
  console.log('PASS: SE-6 結果に回答率、部署別、自由記述を表示する');

  assert.deepEqual(pageErrors, []);
  console.log('PASS: アンケート E2E');
} finally {
  await context.close();
  await browser.close();
}

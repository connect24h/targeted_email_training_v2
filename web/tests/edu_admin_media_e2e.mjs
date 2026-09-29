// 管理画面のブラウザ E2E: 教材の PDF 読込(分割アップロード)、ページ画像の試行、設問の Excel と画像の ZIP の読込、
// 設問の画像と選択肢ごとの解説の表示、配信の答え合わせの時機。08 設計書の G16〜G18。
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:8765 TET2_E2E_EMAIL=... TET2_E2E_PASSWORD=...
//       TET2_E2E_PDF=<教材の PDF> TET2_E2E_XLSX=<設問の Excel> TET2_E2E_ZIP=<画像の ZIP> [TET2_E2E_SHOTS=<画面を撮る先>]
//       node edu_admin_media_e2e.mjs
import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

const env = (k) => process.env[k] || '';
const baseUrl = env('TET2_E2E_BASE_URL');
const shots = env('TET2_E2E_SHOTS');
for (const k of ['TET2_E2E_BASE_URL', 'TET2_E2E_EMAIL', 'TET2_E2E_PASSWORD', 'TET2_E2E_PDF', 'TET2_E2E_XLSX', 'TET2_E2E_ZIP']) {
  if (!env(k)) throw new Error(`${k} is required`);
}
if (shots) await mkdir(shots, { recursive: true });
let passed = 0;
const ok = (m) => { passed += 1; console.log(`PASS: ${m}`); };
const shot = async (page, name) => { if (shots) await page.screenshot({ path: `${shots}/${name}.png` }); };

const browser = await chromium.launch({ headless: true });
const page = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('response', (r) => { if (/action=(page_image|image)/.test(r.url()) && r.status() !== 200) errors.push(`${r.status()} ${r.url()}`); });
const openBank = async () => {
  await page.evaluate(() => document.querySelector('[data-view="eduQuestions"]')?.click());
  await page.locator('#eduBankTab-materials').click();
  await page.locator('#eduPdfImportBtn').waitFor();
};
const save = async () => { await page.locator('#appModalSave').click(); };

try {
  await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle' });
  await page.locator('#loginEmail').fill(env('TET2_E2E_EMAIL'));
  await page.locator('#loginPassword').fill(env('TET2_E2E_PASSWORD'));
  await page.locator('#loginBtn').click();
  await page.locator('#appView:not(.d-none)').waitFor();
  await openBank();
  ok('教材バンクに「PDF読込」がある');

  // --- PDF の教材を読み込む(10MB を超える PDF は分割して送る) ---
  await page.locator('#eduPdfImportBtn').click();
  await page.locator('#eduPdfFile').setInputFiles(env('TET2_E2E_PDF'));
  await page.locator('#eduPdfTitle').fill('インシデントの発見と初動報告（E2E）');
  await shot(page, 'a01-pdf-dialog');
  await save();
  await page.getByText('インシデントの発見と初動報告（E2E）').first().waitFor({ timeout: 180000 });
  await page.getByText(/PDF \d+ページ/).first().waitFor();
  ok('PDF を教材として読み込み、一覧に「PDF nページ」と出る');
  await shot(page, 'a02-material-list');

  // 教材の試行でページ画像が出る
  const row = page.locator('tr', { hasText: 'インシデントの発見と初動報告（E2E）' }).first();
  // 連打しても試行は1つだけ開き、矢印キー1回で1ページだけ進む
  await row.getByRole('button', { name: /教材を試行/ }).click();
  await row.getByRole('button', { name: /教材を試行/ }).click({ force: true }).catch(() => {});
  await page.waitForFunction(() => {
    const img = document.querySelector('.modal.show .edu-page-image');
    return img && img.complete && img.naturalWidth > 0;
  }, null, { timeout: 30000 });
  ok('教材の試行でページ画像を表示する');
  await page.keyboard.press('ArrowRight');
  await page.waitForTimeout(300);
  assert.match(await page.locator('#eduMaterialPreviewProgress').innerText(), /^2 \/ /);
  ok('試行を連打しても、矢印キー1回で1ページだけ進む');
  await shot(page, 'a03-material-preview');
  await page.keyboard.press('Escape');
  await page.waitForTimeout(500);

  // PDF の差し替え(表紙を足した改訂版などを、同じ教材のまま入れ替える)
  await row.getByRole('button', { name: /PDF差し替え/ }).click();
  await page.locator('#eduPdfReplaceFile').setInputFiles(env('TET2_E2E_PDF'));
  await shot(page, 'a03b-replace-dialog');
  await save();
  await page.getByText(/ページに差し替えました/).first().waitFor({ timeout: 180000 });
  await row.getByRole('button', { name: /教材を試行/ }).click();
  await page.waitForFunction(() => {
    const img = document.querySelector('.modal.show .edu-page-image');
    return img && img.complete && img.naturalWidth > 0 && /[?&]v=\d+/.test(img.src);
  }, null, { timeout: 30000 });
  ok('PDF を差し替えると、試行で版つきの新しいページ画像を表示する');
  await page.keyboard.press('Escape');
  await page.waitForTimeout(500);

  // --- 設問の Excel と画像の ZIP を読み込む ---
  const cats = ['aw-mail', 'aw-phishing', 'aw-auth', 'aw-malware', 'aw-bec', 'aw-genai', 'aw-data', 'aw-report', 'aw-scam', 'aw-remote'];
  for (const slug of cats) {
    // 既にあるカテゴリは作らない(準備の DB に作ってある場合がある)
    await page.evaluate((s) => api('api/edu_categories.php', { method: 'POST', query: { action: 'create' }, body: { name: s, slug: s } })
      .catch((e) => { if (!/既に存在/.test(e.message)) throw e; }), slug);
  }
  await openBank();
  await page.evaluate(() => importEduQuestionsXlsx());
  await page.locator('#eduXlsxFile').setInputFiles(env('TET2_E2E_XLSX'));
  await page.locator('#eduXlsxImagesZip').setInputFiles(env('TET2_E2E_ZIP'));
  await shot(page, 'a04-xlsx-dialog');
  await save();
  await page.waitForFunction(() => !document.querySelector('#appModal.show'), null, { timeout: 120000 });
  ok('設問の Excel と画像の ZIP を読み込む');

  // 設問の試行で画像と選択肢ごとの解説が出る
  await openBank();
  await page.locator('#eduBankTab-questions').click();
  await page.getByRole('link', { name: /全カテゴリ/ }).click().catch(() => {});
  const qRow = page.locator('tr', { hasText: '不審なメールのリンクを開いたが' }).first();
  await qRow.getByRole('button', { name: /試行/ }).click();
  await page.waitForFunction(() => {
    const img = document.querySelector('#eduQuestionPreviewImage');
    return img && !img.classList.contains('d-none') && img.complete && img.naturalWidth > 0;
  }, null, { timeout: 30000 });
  ok('設問の試行で設問の画像を表示する');
  await page.locator('#eduQuestionPreviewOptions button').first().click();
  await page.locator('#eduQuestionPreviewCheck').click();
  await page.locator('#eduQuestionPreviewFeedback').waitFor();
  const previewText = await page.locator('#appModal').innerText();
  assert.ok(/早いほど|翌朝までの間/.test(previewText), '試行の答え合わせで選択肢ごとの解説が出る');
  ok('設問の試行の答え合わせで選択肢ごとの解説を表示する');
  await shot(page, 'a05-question-preview');
  await page.keyboard.press('Escape');

  // --- 配信の作成に答え合わせの時機がある ---
  await page.evaluate(() => document.querySelector('[data-view="eduDeliveries"]')?.click());
  await page.getByRole('button', { name: /新規配信/ }).first().click();
  await page.locator('#eduFeedbackMode').waitFor();
  const options = await page.locator('#eduFeedbackMode option').allInnerTexts();
  assert.ok(options.some((t) => t.includes('1問ごと')) && options.some((t) => t.includes('提出後')));
  ok('配信の作成で答え合わせの時機を選べる');
  await shot(page, 'a06-delivery-form');

  assert.deepEqual(errors, []);
  ok('ページのエラーや画像の読み込みの失敗がない');
  console.log(`ALL ${passed} CHECKS PASSED`);
} finally {
  await browser.close();
}

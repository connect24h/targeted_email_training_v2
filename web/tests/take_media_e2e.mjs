// 受講画面(take.php)のブラウザ E2E: PDF のページ画像の教材、設問の画像、1問ごとの答え合わせ、振り返り、成績。
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:8765 TET2_E2E_TOKENS='{"immediate":"...","after_submit":"..."}'
//       TET2_E2E_PAGES=24 TET2_E2E_SHOTS=<画面を撮る先> node take_media_e2e.mjs
import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

const baseUrl = process.env.TET2_E2E_BASE_URL;
const tokens = JSON.parse(process.env.TET2_E2E_TOKENS || '{}');
const pageCount = Number(process.env.TET2_E2E_PAGES || '0');
const shots = process.env.TET2_E2E_SHOTS || '';
for (const [name, value] of Object.entries({ baseUrl, immediate: tokens.immediate, afterSubmit: tokens.after_submit, pageCount })) {
  if (!value) throw new Error(`${name} is required`);
}
if (shots) await mkdir(shots, { recursive: true });

const browser = await chromium.launch({ headless: true });
let passed = 0;
const ok = (message) => { passed += 1; console.log(`PASS: ${message}`); };

async function run(viewport, label, fn) {
  const context = await browser.newContext({ viewport });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('response', (r) => { if (r.url().includes('_image') && r.status() !== 200) errors.push(`${r.status()} ${r.url()}`); });
  try {
    await fn(page);
    assert.deepEqual(errors, [], `${label}: ページのエラーや画像の読み込みの失敗がない`);
    ok(`${label}: ページのエラーや画像の読み込みの失敗がない`);
  } finally {
    await context.close();
  }
}
const shot = async (page, name) => { if (shots) await page.screenshot({ path: `${shots}/${name}.png`, fullPage: false }); };
const imagesLoaded = (page, selector) => page.waitForFunction(
  (sel) => [...document.querySelectorAll(sel)].length > 0 && [...document.querySelectorAll(sel)].every((i) => i.complete && i.naturalWidth > 0),
  selector,
);

try {
  // --- 広い画面: 見開きで最後までめくり、1問ごとに答え合わせ ---
  await run({ width: 1440, height: 900 }, 'PC', async (page) => {
    await page.goto(`${baseUrl}/take.php?token=${tokens.immediate}`);
    await page.locator('#landingView:not(.d-none)').waitFor();
    assert.match(await page.locator('#landingMeta').innerText(), new RegExp(`${pageCount} ページ`));
    ok('PC: 開始の画面に教材のページ数と問題数を出す');
    await shot(page, '01-landing');
    await page.locator('#startBtn').click();
    await page.locator('#pagesView:not(.d-none)').waitFor();
    await imagesLoaded(page, '#pageBox img');
    assert.equal(await page.locator('#pageBox img').count(), 1);
    ok('PC: 1ページ目(表紙)は単独で表示する');
    assert.ok((await page.locator('#pageBox img').first().getAttribute('alt')).length > 0);
    ok('PC: ページ画像に代替テキストがある');
    await shot(page, '02-viewer-spread');
    assert.equal(await page.locator('#toQuiz').isDisabled(), true);
    ok('PC: 最後まで見るまで確認テストへ進めない');
    await page.locator('#zoomIn').click();
    await imagesLoaded(page, '#pageBox img');
    await page.locator('#zoomOut').click();
    await page.locator('#textToggle').click();
    assert.ok((await page.locator('#pageText').innerText()).includes('ページ'));
    ok('PC: 拡大と縮小、文字で読むが動く');
    await page.locator('#textToggle').click();
    await page.keyboard.press('ArrowRight');
    await imagesLoaded(page, '#pageBox img');
    assert.equal(await page.locator('#pageBox img').count(), 2);
    assert.match(await page.locator('#pageLabel').innerText(), /^2-3 \//);
    ok('PC: 2ページ目からは見開きで表示する');
    const spreads = 1 + Math.ceil((pageCount - 1) / 2);
    for (let i = 2; i < spreads; i += 1) {
      await page.keyboard.press('ArrowRight');
    }
    await imagesLoaded(page, '#pageBox img');
    assert.match(await page.locator('#pageLabel').innerText(), new RegExp(`/ ${pageCount}$`));
    assert.equal(await page.locator('#toQuiz').isDisabled(), false);
    ok('PC: キーボードで最後までめくると確認テストへ進める');
    await shot(page, '03-viewer-last');
    await page.locator('#toQuiz').click();

    await page.locator('#quizView:not(.d-none)').waitFor();
    await imagesLoaded(page, '#qImage');
    ok('PC: 設問の画像を表示する');
    assert.equal(await page.locator('#qCheck').isDisabled(), true);
    await page.locator('#qOptions .opt').nth(0).click();   // 1問目は誤りの選択肢
    await shot(page, '04-question');
    await page.locator('#qCheck').click();
    await page.locator('#qFeedback:not(.d-none)').waitFor();
    assert.match(await page.locator('#qVerdict').innerText(), /不正解/);
    assert.equal(await page.locator('#qOptions .opt.is-correct').count(), 1);
    assert.equal(await page.locator('#qOptions .opt.is-wrong').count(), 1);
    assert.ok((await page.locator('#qOptions').innerText()).includes('早いほど、窓口が被害を小さくできる。'));
    ok('PC: 答え合わせで不正解、正解の選択肢、選択肢ごとの解説を示す');
    await shot(page, '05-feedback-wrong');
    await page.locator('#qNext').click();
    await page.locator('#qOptions .opt').nth(0).click();   // 2問目は正解
    await page.locator('#qCheck').click();
    await page.locator('#qFeedback:not(.d-none)').waitFor();
    assert.match(await page.locator('#qVerdict').innerText(), /正解/);
    ok('PC: 正解の答え合わせを示す');
    await shot(page, '06-feedback-correct');
    await page.locator('#qSubmit').click();
    await page.locator('#resultView:not(.d-none)').waitFor();
    await page.waitForFunction(() => window.scrollY === 0);
    ok('PC: 結果の画面は先頭(成績)から見える');
    assert.match(await page.locator('#rPct').innerText(), /50%/);
    assert.match(await page.locator('#rBadge').innerText(), /不合格/);
    assert.equal(await page.locator('#reviewList .review-card').count(), 2);
    ok('PC: 成績と不合格と振り返りを示す');
    await shot(page, '07-result');
    // 「もう一度受講する」では教材に戻り、最後まで見るまで確認テストへ進めない
    await page.locator('#rRetry').click();
    await page.locator('#pagesView:not(.d-none)').waitFor();
    assert.equal(await page.locator('#toQuiz').isDisabled(), true);
    ok('PC: 再受講では教材を最後まで見るまで確認テストへ進めない');
    // 拡大中は矢印キーでページが変わらない(スワイプと同じく画面の移動に使う)
    const before = await page.locator('#pageLabel').innerText();
    await page.locator('#zoomIn').click();
    await page.keyboard.press('ArrowRight');
    assert.equal(await page.locator('#pageLabel').innerText(), before);
    await page.locator('#zoomOut').click();
    await page.keyboard.press('ArrowRight');
    assert.notEqual(await page.locator('#pageLabel').innerText(), before);
    ok('PC: 拡大中は矢印キーでページをめくらず、拡大を戻すとめくれる');
  });

  // --- スマートフォンの幅: 1ページずつ表示、スワイプの代わりに次へのボタン、提出後にまとめて答え合わせ ---
  await run({ width: 390, height: 844 }, 'スマホ', async (page) => {
    await page.goto(`${baseUrl}/take.php?token=${tokens.after_submit}`);
    await page.locator('#startBtn').click();
    await imagesLoaded(page, '#pageBox img');
    assert.equal(await page.locator('#pageBox img').count(), 1);
    ok('スマホ: 狭い画面では1ページずつ表示する');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    assert.ok(overflow <= 1, `横にはみ出していない(${overflow}px)`);
    ok('スマホ: 画面が横にはみ出さない');
    await shot(page, '08-mobile-viewer');
    // 画面の幅が変わっても同じページを表示する(1ページ表示の5ページ目 → 見開きなら5ページを含む組 4-5)
    for (let i = 1; i < 5; i += 1) {
      await page.locator('#pageNext').click();
    }
    assert.match(await page.locator('#pageLabel').innerText(), /^5 \//);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.waitForFunction(() => /^4-5 \//.test(document.getElementById('pageLabel').textContent));
    await page.setViewportSize({ width: 390, height: 844 });
    await page.waitForFunction(() => /^5 \//.test(document.getElementById('pageLabel').textContent));
    ok('スマホ: 画面の幅が変わっても同じページを表示する');
    for (let i = 5; i < pageCount; i += 1) {
      await page.locator('#pageNext').click();
    }
    await page.locator('#toQuiz').click();
    await page.locator('#quizView:not(.d-none)').waitFor();
    assert.equal(await page.locator('#qCheck').isVisible(), false);
    ok('スマホ: 提出後にまとめて答え合わせの配信では1問ごとの答え合わせを出さない');
    await page.locator('#qOptions .opt').nth(3).click();
    await page.locator('#qNext').click();
    await page.locator('#qOptions .opt').nth(0).click();
    await shot(page, '09-mobile-question');
    await page.locator('#qSubmit').click();
    await page.locator('#resultView:not(.d-none)').waitFor();
    assert.match(await page.locator('#rPct').innerText(), /100%/);
    assert.match(await page.locator('#rBadge').innerText(), /合格/);
    ok('スマホ: 全問正解で合格を示す');
    await shot(page, '10-mobile-result');
    await page.locator('#rLesson').click();
    await page.locator('#pagesView:not(.d-none)').waitFor();
    assert.equal(await page.locator('#toQuiz').isDisabled(), false);
    ok('スマホ: 受講後は教材を見直せる(見直しでは確認テストへすぐ戻れる)');
  });
  console.log(`ALL ${passed} CHECKS PASSED`);
} finally {
  await browser.close();
}

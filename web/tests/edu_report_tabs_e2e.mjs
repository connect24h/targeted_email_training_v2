// 教材バンクのタブと教育レポートのタブのブラウザ E2E(計画 tet2-edu-bank-tabs-and-report)。
// 合成 DB に向けたローカルのサーバーで動かす(本番に向けない)。
// 準備: php fixtures/edu_report_e2e_db.php <db> <password>
//       ドキュメントルートに web への symlink tet2 を置き、TET2_DB_PATH=<db> php -S 127.0.0.1:<port> -t <docroot> で起動する
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:<port>/tet2 TET2_E2E_EMAIL=e2e-admin@example.test TET2_E2E_PASSWORD=...
//       [TET2_E2E_SHOTS=<画面を撮る先>] node edu_report_tabs_e2e.mjs
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

let chromium;
try { ({ chromium } = await import('playwright')); } catch { ({ chromium } = await import('/root/node_modules/playwright/index.mjs')); }

const env = (k) => process.env[k] || '';
for (const k of ['TET2_E2E_BASE_URL', 'TET2_E2E_EMAIL', 'TET2_E2E_PASSWORD']) {
  if (!env(k)) throw new Error(`${k} is required`);
}
const SHOTS = env('TET2_E2E_SHOTS');
if (SHOTS) fs.mkdirSync(SHOTS, { recursive: true });
let passed = 0;
const ok = (m) => { passed += 1; console.log(`PASS: ${m}`); };
// タブの切り替えは fade(150ms)なので、動きが終わってから撮る
const shot = async (page, name) => {
  if (!SHOTS) return;
  await page.waitForTimeout(400);
  await page.screenshot({ path: path.join(SHOTS, `${name}.png`) });
};
const BOM = Buffer.from([0xef, 0xbb, 0xbf]);

const browser = await chromium.launch({ headless: true });
const login = async (width, height) => {
  const context = await browser.newContext({ viewport: { width, height }, acceptDownloads: true });
  const page = await context.newPage();
  page.errors = [];
  page.on('pageerror', (e) => page.errors.push(e.message));
  await page.goto(`${env('TET2_E2E_BASE_URL')}/`, { waitUntil: 'networkidle' });
  await page.locator('#loginEmail').fill(env('TET2_E2E_EMAIL'));
  await page.locator('#loginPassword').fill(env('TET2_E2E_PASSWORD'));
  await page.locator('#loginBtn').click();
  await page.locator('#appView:not(.d-none)').waitFor();
  return page;
};
// 左の一覧は狭い幅では隠れているので、リンクを直接押す
const go = (page, view) => page.evaluate((v) => document.querySelector(`.app-sidebar [data-view="${v}"]`).click(), view);
const rows = (page, sel) => page.locator(`${sel} tr:not(.table-state-row)`);
const waitRows = async (page, sel, n) => {
  await page.waitForFunction(([s, count]) => {
    const tb = document.querySelector(s);
    return tb && !tb.querySelector('.table-state-row') && tb.querySelectorAll('tr').length === count;
  }, [sel, n], { timeout: 10000 });
};
const download = async (page, selector) => {
  const [file] = await Promise.all([page.waitForEvent('download'), page.locator(selector).click()]);
  return { name: file.suggestedFilename(), bytes: fs.readFileSync(await file.path()) };
};
const noHorizontalOverflow = (page) => page.evaluate(() => {
  const main = document.querySelector('#appMain');
  return document.documentElement.scrollWidth <= window.innerWidth && main.scrollWidth <= main.clientWidth;
});

try {
  const page = await login(1440, 1000);

  // --- 教材バンク: タブ、形式の絞り込み、題名の検索、使っている配信の数 ---
  await go(page, 'eduQuestions');
  await waitRows(page, '#eduMaterialsBody', 3);
  assert.equal(await page.locator('#eduBankTab-materials').getAttribute('aria-selected'), 'true');
  assert.equal(await page.locator('#eduQuestionsBody').isVisible(), false, '設問の一覧は別のタブ');
  const bookRow = rows(page, '#eduMaterialsBody').filter({ hasText: 'フィッシングの見分け方（本）' });
  assert.match(await bookRow.innerText(), /本の版 PDF 3ページ\s+2/);
  ok('教材のタブが先に開き、形式(本の版)と使っている配信の数(2)が出る');
  await shot(page, 'bank-materials-desktop');

  await page.locator('#eduMaterialKind').selectOption('slide');
  await waitRows(page, '#eduMaterialsBody', 1);
  assert.match(await rows(page, '#eduMaterialsBody').innerText(), /従業員向け[\s\S]*スライド版 PDF 2ページ/);
  await page.locator('#eduMaterialKind').selectOption('text');
  await waitRows(page, '#eduMaterialsBody', 1);
  assert.match(await rows(page, '#eduMaterialsBody').innerText(), /パスワードの基本/);
  assert.match(await page.locator('#eduMaterialCount').innerText(), /3本中 1本/);
  ok('形式で絞り込める(スライド版、文字)');
  await page.locator('#eduMaterialKind').selectOption('');
  await page.locator('#eduMaterialSearch').fill('見分け方');
  await waitRows(page, '#eduMaterialsBody', 2);
  await page.locator('#eduMaterialSearch').fill('存在しない題名');
  await page.getByText('条件に合う教材がありません').waitFor();
  await page.locator('#eduMaterialSearch').fill('');
  await waitRows(page, '#eduMaterialsBody', 3);
  ok('題名で検索でき、合わない時は案内が出る');

  await page.locator('#eduBankTab-questions').click();
  await page.locator('#eduQuestionsBody').waitFor();
  await waitRows(page, '#eduQuestionsBody', 2);
  assert.equal(await page.locator('#eduMaterialsBody').isVisible(), false);
  assert.equal(await page.getByRole('button', { name: /新規設問/ }).isVisible(), true);
  ok('「確認テストの設問」のタブに設問の一覧と操作が出る');

  // --- 教育レポート: 4つのタブ ---
  await go(page, 'eduReport');
  await page.locator('#eduReportKpi .kpi-card').first().waitFor();
  assert.equal(await page.locator('#eduRepTab-overview').getAttribute('aria-selected'), 'true');
  ok('教育レポートは概要のタブから開く');

  await page.locator('#eduRepTab-deliveries').click();
  await waitRows(page, '#eduRepDeliveriesBody', 3);
  const d1Row = rows(page, '#eduRepDeliveriesBody').filter({ hasText: 'E2E 情報セキュリティ基礎' });
  const d1Text = await d1Row.innerText();
  assert.match(d1Text, /8\s+4\s+4\s+50%\s+37\.5%/, '対象8、完了4、未完了4、合格率50%、期限内合格率37.5%');
  assert.match(await rows(page, '#eduRepDeliveriesBody').filter({ hasText: 'アウェアネス' }).innerText(), /—\s+—/);
  ok('配信ごとのタブに、対象、完了、未完了、合格率、期限内合格率が出る');

  await page.locator('#eduRepTab-people').click();
  await waitRows(page, '#eduRepLearnersBody', 8);
  await page.locator('#eduRepLearnerSearch').fill('総務');
  await waitRows(page, '#eduRepLearnersBody', 2);
  await rows(page, '#eduRepLearnersBody').filter({ hasText: '総務 花子' }).getByRole('button', { name: /見る/ }).click();
  await waitRows(page, '#eduRepPersonBody', 2);
  assert.match(await page.locator('#eduRepPersonTitle').innerText(), /総務 花子（総務部）/);
  ok('受講者ごとのタブで検索し、1人の配信ごとの状況を見られる');

  await page.locator('#eduRepTab-cross').click();
  await waitRows(page, '#eduRepCrossBody', 1);
  assert.match(await page.locator('#eduRepCrossCampaign').innerText(), /E2E 訓練キャンペーン/);
  assert.match(await page.locator('#eduRepCrossBody').innerText(), /^2人\s+2人\s+100%\s+1人\s+50%/);
  ok('訓練と教育のタブで、訓練の失敗者の教育の割当と完了を見られる');

  // --- 配信の詳細: 受講者ごと(未完了だけ、検索、CSV)、部署ごと(CSV)、設問ごと ---
  await page.locator('#eduRepTab-deliveries').click();
  await waitRows(page, '#eduRepDeliveriesBody', 3);
  await d1Row.getByRole('button', { name: /詳細/ }).click();
  await waitRows(page, '#eduRepPeopleBody', 8);
  assert.equal(await page.locator('#eduRepDeliveryList').isVisible(), false);
  assert.match(await page.locator('#eduRepDetailTitle').innerText(), /E2E 情報セキュリティ基礎/);
  assert.match(await page.locator('#eduRepDetailKpi').innerText(), /4 \/ 8/);
  const sales1 = await rows(page, '#eduRepPeopleBody').filter({ hasText: '営業 一郎' }).innerText();
  assert.match(sales1, /完了[\s\S]*営業部\s+90%\s+合格\s+2/, '受講回数は2回');
  assert.match(await rows(page, '#eduRepPeopleBody').filter({ hasText: '営業 二郎' }).innerText(), /合格 期限後/);
  ok('配信の詳細に、状態、部署、点数、合否、受講回数が出る(期限後の合格は注記)');

  await page.getByRole('button', { name: '未完了だけ' }).click();
  await waitRows(page, '#eduRepPeopleBody', 4);
  assert.equal(await page.getByRole('button', { name: '未完了だけ' }).getAttribute('aria-pressed'), 'true');
  await page.locator('#eduRepPeopleSearch').fill('営業');
  await waitRows(page, '#eduRepPeopleBody', 1);
  assert.match(await rows(page, '#eduRepPeopleBody').innerText(), /営業 三郎/);
  ok('未完了だけに絞り、氏名で検索できる');

  const people = await download(page, '#eduRepPeopleCsv');
  assert.match(people.name, /^edu_delivery_\d+_people_\d{8}\.csv$/);
  assert.ok(people.bytes.subarray(0, 3).equals(BOM), 'CSV は UTF-8 の BOM で始まる');
  const peopleLines = people.bytes.subarray(3).toString('utf8').split('\r\n').filter(Boolean);
  assert.equal(peopleLines[0], '状態,氏名,メール,部署,点数,合否,受講回数,期限,完了日時');
  assert.equal(peopleLines.length, 2, '絞り込み(未完了、営業)が CSV にも効く');
  assert.equal(peopleLines[1], '未受講,"営業 三郎",sales3@example.test,営業部,,,0,"2026-09-15 23:59:59",');
  ok('受講者ごとの CSV を、今の絞り込みのままダウンロードできる');

  await page.locator('#eduRepSub-depts').click();
  await waitRows(page, '#eduRepDeptsBody', 4);
  assert.match(await rows(page, '#eduRepDeptsBody').filter({ hasText: '営業部' }).innerText(), /営業部\s+3\s+2\s+1\s+2\s+66\.7%\s+33\.3%/);
  const depts = await download(page, '#eduRepDeptsCsv');
  assert.ok(depts.bytes.subarray(0, 3).equals(BOM));
  assert.equal(depts.bytes.subarray(3).toString('utf8').split('\r\n')[0], '部署,対象,完了,未完了,合格,合格率(%),期限内合格率(%)');
  ok('部署ごとのタブに集計が出て、CSV をダウンロードできる');
  await shot(page, 'report-detail-depts-desktop');

  await page.locator('#eduRepSub-questions').click();
  await waitRows(page, '#eduRepQuestionsBody', 2);
  assert.match(await page.locator('#eduRepQuestionsNote').innerText(), /回答者 5人/);
  ok('設問ごとのタブに設問ごとの正答率が出る');

  await page.locator('#eduRepSub-people').click();
  await shot(page, 'report-detail-people-desktop');
  await page.locator('#eduRepBackBtn').click();
  await waitRows(page, '#eduRepDeliveriesBody', 3);
  assert.equal(await page.locator('#eduRepDeliveryDetail').isVisible(), false);
  ok('「配信の一覧に戻る」で一覧に戻る');

  // --- 教育配信の一覧の「レポート」は、教育レポートの配信の詳細を開く ---
  await go(page, 'eduDeliveries');
  const deliveryRow = page.locator('#eduDeliveriesBody tr').filter({ hasText: 'E2E 期限なしの配信' });
  await deliveryRow.getByRole('button', { name: /レポート/ }).click();
  await page.waitForFunction(() => window.location.hash === '#eduReport');
  await waitRows(page, '#eduRepPeopleBody', 2);
  assert.match(await page.locator('#eduRepDetailTitle').innerText(), /E2E 期限なしの配信/);
  assert.equal(await page.locator('#eduRepTab-deliveries').getAttribute('aria-selected'), 'true');
  assert.equal(await page.locator('#appModal.show').count(), 0, 'ダイアログは開かない');
  assert.match(await rows(page, '#eduRepPeopleBody').filter({ hasText: '営業 一郎' }).innerText(), /合格\s+1\s+なし/);
  ok('教育配信の一覧の「レポート」で、配信ごとのタブの詳細が開く(期限なしは「なし」)');
  // 概要のタブを開いていた時も、配信ごとのタブに切り替えて詳細を開く
  await page.locator('#eduRepTab-overview').click();
  await page.locator('#eduRepPane-overview.show').waitFor();
  await go(page, 'eduDeliveries');
  await page.locator('#eduDeliveriesBody tr').filter({ hasText: 'E2E 情報セキュリティ基礎' }).getByRole('button', { name: /レポート/ }).click();
  await page.locator('#eduRepPane-deliveries.show').waitFor();
  await waitRows(page, '#eduRepPeopleBody', 8);
  assert.match(await page.locator('#eduRepDetailTitle').innerText(), /E2E 情報セキュリティ基礎/);
  ok('概要のタブを開いていても、「レポート」で配信の詳細に切り替わる');
  assert.deepEqual(page.errors, []);
  ok('パソコンの幅でスクリプトのエラーがない');

  // --- スマホの幅: 横にはみ出さない ---
  const phone = await login(390, 844);
  await go(phone, 'eduQuestions');
  await waitRows(phone, '#eduMaterialsBody', 3);
  assert.equal(await noHorizontalOverflow(phone), true, '教材バンクが横にはみ出さない');
  await shot(phone, 'bank-materials-mobile');
  await go(phone, 'eduReport');
  await phone.locator('#eduReportKpi .kpi-card').first().waitFor();
  await phone.locator('#eduRepTab-deliveries').click();
  await waitRows(phone, '#eduRepDeliveriesBody', 3);
  assert.equal(await noHorizontalOverflow(phone), true, '配信ごとの一覧が横にはみ出さない');
  await rows(phone, '#eduRepDeliveriesBody').filter({ hasText: 'E2E 情報セキュリティ基礎' }).getByRole('button', { name: /詳細/ }).click();
  await waitRows(phone, '#eduRepPeopleBody', 8);
  assert.equal(await noHorizontalOverflow(phone), true, '配信の詳細が横にはみ出さない');
  const tableScroll = await phone.evaluate(() => {
    const wrap = document.querySelector('#eduRepPeopleBody').closest('.table-responsive');
    return wrap.scrollWidth > wrap.clientWidth;
  });
  assert.equal(tableScroll, true, '表は表の中だけで横にスクロールする');
  await shot(phone, 'report-detail-people-mobile');
  for (const tab of ['people', 'cross']) {
    await phone.locator(`#eduRepTab-${tab}`).click();
    await phone.locator(`#eduRepPane-${tab}.show`).waitFor();
    assert.equal(await noHorizontalOverflow(phone), true, `${tab} のタブが横にはみ出さない`);
  }
  assert.deepEqual(phone.errors, []);
  ok('スマホの幅で横にはみ出さず、スクリプトのエラーもない');

  console.log(`\n${passed} passed`);
} finally {
  await browser.close();
}

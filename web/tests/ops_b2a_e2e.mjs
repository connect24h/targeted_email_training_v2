// 段B2 の運用(B2-1〜B2-4)のブラウザ E2E。合成 DB に向けたローカルのサーバーで動かす(本番に向けない)。
//   - 対象者の一覧の従業員番号とメモ、検索、編集(重複は断る)、CSV の取込(従業員番号で先に照合)と出力
//   - 教育レポートのアウェアネスの成績のタブ(配信で絞る、CSV)
//   - 解答の1問1行の CSV(提出日の期間でテナント全体、配信1件)
//   - 自動の投入のタブ(自動の配信だけに出る、実行の一覧と当てはまった人)
// 準備: php fixtures/ops_b2a_e2e_db.php <db> <password>
//       ドキュメントルートに web への symlink tet2 を置き、TET2_DB_PATH=<db> php -S 127.0.0.1:<port> -t <docroot> で起動する
// 実行: TET2_E2E_BASE_URL=http://127.0.0.1:<port>/tet2 TET2_E2E_PASSWORD=... [TET2_E2E_SHOTS=<画面を撮る先>] node ops_b2a_e2e.mjs
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

let chromium;
try { ({ chromium } = await import('playwright')); } catch { ({ chromium } = await import('/root/node_modules/playwright/index.mjs')); }

const env = (k) => process.env[k] || '';
for (const k of ['TET2_E2E_BASE_URL', 'TET2_E2E_PASSWORD']) {
  if (!env(k)) throw new Error(`${k} is required`);
}
const SHOTS = env('TET2_E2E_SHOTS');
if (SHOTS) fs.mkdirSync(SHOTS, { recursive: true });
let passed = 0;
const ok = (m) => { passed += 1; console.log(`PASS: ${m}`); };
const shot = async (page, name) => {
  if (!SHOTS) return;
  await page.waitForTimeout(400);
  await page.screenshot({ path: path.join(SHOTS, `${name}.png`) });
};
const BOM = Buffer.from([0xef, 0xbb, 0xbf]);

const browser = await chromium.launch({ headless: true });
const login = async (email, width = 1440, height = 1000) => {
  const context = await browser.newContext({ viewport: { width, height }, acceptDownloads: true });
  const page = await context.newPage();
  page.errors = [];
  page.on('pageerror', (e) => page.errors.push(e.message));
  await page.goto(`${env('TET2_E2E_BASE_URL')}/`, { waitUntil: 'networkidle' });
  await page.locator('#loginEmail').fill(email);
  await page.locator('#loginPassword').fill(env('TET2_E2E_PASSWORD'));
  await page.locator('#loginBtn').click();
  await page.locator('#appView:not(.d-none)').waitFor();
  return page;
};
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
const csvLines = (bytes) => bytes.subarray(3).toString('utf8').split('\r\n').filter((l) => l !== '');
const targetRow = (page, email) => rows(page, '#targetsBody').filter({ hasText: email });
const noHorizontalOverflow = (page) => page.evaluate(() => {
  const main = document.querySelector('#appMain');
  return document.documentElement.scrollWidth <= window.innerWidth && main.scrollWidth <= main.clientWidth;
});

try {
  const page = await login('e2e-admin@example.test');

  // --- B2-1 対象者の従業員番号とメモ ---
  await go(page, 'users');
  await targetRow(page, 'e2e-sales1@example.test').waitFor();
  const sales = await targetRow(page, 'e2e-sales1@example.test').innerText();
  assert.match(sales, /E2E-001/);
  assert.match(sales, /東京の窓口/);
  ok('対象者の一覧に従業員番号とメモが出る');
  await shot(page, 'targets-list');

  await page.locator('#targetSearch').fill('E2E-002');
  await waitRows(page, '#targetsBody', 1);
  assert.match(await rows(page, '#targetsBody').innerText(), /e2e-ga1@example\.test/);
  await page.locator('#targetSearch').fill('夜勤');
  await waitRows(page, '#targetsBody', 1);
  await page.locator('#targetSearch').fill('該当なしの語');
  await page.waitForFunction(() => /条件に合う対象者がいません/.test(document.querySelector('#targetsBody').innerText));
  await page.locator('#targetSearch').fill('');
  await targetRow(page, 'e2e-nonum@example.test').waitFor();
  ok('一覧を従業員番号とメモで検索できる(合わなければその旨を出す)');

  await targetRow(page, 'e2e-nonum@example.test').locator('button:has(.bi-pencil)').click();
  await page.locator('#appModal.show #targetEmployeeNo').waitFor();
  await page.locator('#targetEmployeeNo').fill('E2E-001');
  await page.locator('#appModalSave').click();
  await page.waitForFunction(() => document.body.innerText.includes('従業員番号は既に使用されています'));
  ok('ほかの人の従業員番号にすると保存を断り、理由を出す');
  await page.locator('#targetEmployeeNo').fill('E2E-009');
  await page.locator('#targetMemo').fill('E2E で書いたメモ');
  await page.locator('#appModalSave').click();
  await page.locator('#appModal.show').waitFor({ state: 'detached' }).catch(() => {});
  await page.waitForFunction(() => /E2E-009[\s\S]*E2E で書いたメモ|E2E で書いたメモ/.test(document.querySelector('#targetsBody').innerText));
  assert.match(await targetRow(page, 'e2e-nonum@example.test').innerText(), /E2E-009/);
  ok('編集で従業員番号とメモを入れられる');

  await page.locator('#importCsvBtn').click();
  await page.locator('#appModal.show #csvText').waitFor();
  assert.match(await page.locator('#appModalBody').innerText(), /従業員番号で先に/);
  await page.locator('#csvText').fill('メールアドレス,氏名,従業員番号,メモ\ne2e-sales1-new@example.test,営業 一郎,E2E-001,\n');
  await page.locator('#appModalSave').click();
  await page.waitForFunction(() => /メールアドレスの変更 1/.test(document.querySelector('#toast').innerText));
  await targetRow(page, 'e2e-sales1-new@example.test').waitFor();
  const moved = await targetRow(page, 'e2e-sales1-new@example.test').innerText();
  assert.match(moved, /E2E-001/);
  assert.match(moved, /東京の窓口/, 'メモの列が空の行は今のメモを残す');
  assert.equal(await targetRow(page, 'e2e-sales1@example.test').count(), 0);
  ok('CSV の取込は従業員番号で先に照合し、アドレスが変わった人を同じ人として更新する');

  const exported = await download(page, '#exportCsvBtn');
  assert.ok(exported.bytes.subarray(0, 3).equals(BOM));
  const exportLines = exported.bytes.subarray(3).toString('utf8').split(/\r?\n/).filter((l) => l !== '');
  assert.equal(exportLines[0], 'メールアドレス,氏名,会社名,部署,役職,役職カテゴリ,従業員番号,メモ');
  assert.ok(exportLines.some((l) => l.startsWith('e2e-nonum@example.test,') && l.includes('E2E-009') && l.includes('E2E で書いたメモ')));
  ok('対象者の CSV の出力に従業員番号とメモが入る');

  // --- B2-2 アウェアネスの成績 ---
  await go(page, 'eduReport');
  await page.locator('#eduReportKpi .kpi-card').first().waitFor();
  await page.locator('#eduRepTab-awareness').click();
  await waitRows(page, '#eduRepAwBody', 3);
  const salesAw = await rows(page, '#eduRepAwBody').filter({ hasText: '営業 一郎' }).innerText();
  assert.match(salesAw, /2 \/ 2\s+4\s+3\s+1\s+0\s+75%/, '受講 2/2、設問4、正解3、不正解1、未回答0、正答率75%');
  assert.match(await rows(page, '#eduRepAwBody').filter({ hasText: '総務 花子' }).innerText(), /1 \/ 2\s+4\s+1\s+1\s+2\s+50%/);
  assert.equal(await rows(page, '#eduRepAwBody').filter({ hasText: 'テスト 用' }).count(), 0, 'テスト用の人は出ない');
  assert.match(await page.locator('#eduRepAwNote').innerText(), /受講者 3人/);
  ok('アウェアネスの成績のタブに、受講者ごとの正解、不正解、未回答が出る(テスト用は数えない)');
  await shot(page, 'awareness-tab');

  await page.locator('#eduRepAwDelivery').selectOption({ label: 'E2E アウェアネス 9月（2026-09-01 09:00）' });
  await waitRows(page, '#eduRepAwBody', 2);
  assert.match(await rows(page, '#eduRepAwBody').filter({ hasText: '総務 花子' }).innerText(), /0 \/ 1\s+2\s+0\s+0\s+2\s+—/);
  const awCsv = await download(page, '#eduRepAwCsv');
  assert.ok(awCsv.bytes.subarray(0, 3).equals(BOM));
  const awLines = csvLines(awCsv.bytes);
  assert.equal(awLines[0], '氏名,メール,従業員番号,部署,配信,受講完了,設問,正解,不正解,未回答,正答率(%)');
  assert.equal(awLines.length, 3, '絞った配信の2人');
  ok('配信で絞ると表と CSV が同じ条件になる(CSV は BOM と CRLF)');

  // --- B2-3 解答の1問1行の CSV ---
  await page.locator('#eduRepTab-deliveries').click();
  await waitRows(page, '#eduRepDeliveriesBody', 3);
  await page.locator('#eduRepAnswersCsvBtn').click();
  await page.waitForFunction(() => /提出日の期間/.test(document.querySelector('#toast').innerText));
  await page.locator('#eduRepAnswersFrom').fill('2026-09-01');
  await page.locator('#eduRepAnswersTo').fill('2026-10-31');
  const all = await download(page, '#eduRepAnswersCsvBtn');
  const allLines = csvLines(all.bytes);
  assert.equal(allLines[0], '配信日,配信,種類,氏名,メール,従業員番号,部署,カテゴリ,設問,解答,正誤,解答日時');
  assert.equal(allLines.length, 1 + 6, '実対象者の提出した解答6問(テスト用は出ない)');
  assert.ok(allLines.some((l) => l.includes('不審な添付を受け取ったら') && l.includes(',開く,不正解,')));
  assert.match(all.name, /^edu_answers_20260901_20261031_/);
  ok('提出日の期間でテナント全体の解答を1問1行で出せる(期間がなければ理由を出す)');

  await rows(page, '#eduRepDeliveriesBody').filter({ hasText: 'E2E アウェアネス 10月' }).getByRole('button', { name: /詳細/ }).click();
  await page.locator('#eduRepSub-questions').click();
  await waitRows(page, '#eduRepQuestionsBody', 2);
  assert.equal(await page.locator('#eduRepSubItem-auto').isVisible(), false, '手動の配信には自動の投入のタブを出さない');
  const one = await download(page, '#eduRepAnswersDeliveryCsv');
  assert.equal(csvLines(one.bytes).length, 1 + 4);
  ok('配信の詳細の設問ごとから、その配信の解答の CSV を出せる');

  // --- B2-4 自動の投入の履歴 ---
  await page.locator('#eduRepBackBtn').click();
  await waitRows(page, '#eduRepDeliveriesBody', 3);
  await rows(page, '#eduRepDeliveriesBody').filter({ hasText: 'E2E 訓練の後の小問' }).getByRole('button', { name: /詳細/ }).click();
  await page.locator('#eduRepSubItem-auto:not(.d-none)').waitFor();
  await page.locator('#eduRepSub-auto').click();
  await waitRows(page, '#eduRepAutoRunsBody', 2);
  const runText = await rows(page, '#eduRepAutoRunsBody').first().innerText();
  assert.match(runText, /2026-09-06 09:05[\s\S]*訓練の失敗\s+1人\s+0人\s+正常/, '新しい順(2回目が先)');
  await waitRows(page, '#eduRepAutoLearnersBody', 1);
  assert.match(await page.locator('#eduRepAutoLearnersBody').innerText(), /番号なし 健[\s\S]*E2E-009[\s\S]*2026-09-06 09:00/);
  ok('自動の配信の詳細に、実行の一覧と当てはまって入った人(入った日時)が出る');
  await shot(page, 'auto-runs');

  // 手動の配信に移ると、自動の投入のタブは隠れて受講者ごとに戻る
  await page.locator('#eduRepBackBtn').click();
  await waitRows(page, '#eduRepDeliveriesBody', 3);
  await rows(page, '#eduRepDeliveriesBody').filter({ hasText: 'E2E アウェアネス 9月' }).getByRole('button', { name: /詳細/ }).click();
  await page.waitForFunction(() => document.querySelector('#eduRepSubItem-auto').classList.contains('d-none'));
  await page.waitForFunction(() => document.querySelector('#eduRepSub-people').getAttribute('aria-selected') === 'true');
  ok('手動の配信に移ると、自動の投入のタブを隠して受講者ごとのタブに戻る');
  assert.deepEqual(page.errors, []);

  // --- 閲覧者: 読めるが編集はできない。狭い幅で横にはみ出さない ---
  const viewer = await login('e2e-viewer@example.test', 390, 844);
  await go(viewer, 'users');
  await targetRow(viewer, 'e2e-ga1@example.test').waitFor();
  assert.equal(await viewer.locator('#newTargetBtn').isVisible(), false);
  assert.equal(await viewer.locator('#importCsvBtn').isVisible(), false);
  assert.equal(await targetRow(viewer, 'e2e-ga1@example.test').locator('button:has(.bi-pencil)').count(), 0);
  ok('閲覧者は一覧を読めるが、新規、取込、編集のボタンは出ない');
  await go(viewer, 'eduReport');
  await viewer.locator('#eduReportKpi .kpi-card').first().waitFor();
  await viewer.locator('#eduRepTab-awareness').click();
  await waitRows(viewer, '#eduRepAwBody', 3);
  assert.equal(await noHorizontalOverflow(viewer), true);
  ok('閲覧者もアウェアネスの成績を読め、スマートフォンの幅で横にはみ出さない');
  await shot(viewer, 'awareness-phone');
  assert.deepEqual(viewer.errors, []);
} finally {
  await browser.close();
}
console.log(`ops_b2a_e2e: ${passed} passed`);

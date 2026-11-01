const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('scenario list numbers every visible row and preserves unpaired templates', () => {
  const script = fs.readFileSync(`${__dirname}/../app.js`, 'utf8');
  const start = script.indexOf('function renderTemplatesScenario(');
  const end = script.indexOf('function renderTemplatesPhish(', start);
  const nodes = { '#templatesHead': {}, '#templatesBody': {} };
  const context = vm.createContext({
    $: (selector) => nodes[selector],
    esc: (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('"', '&quot;').replaceAll("'", '&#39;'),
    KIND_LABELS: { subject: '件名', body: '本文' },
    emptyRow: () => '<tr><td>なし</td></tr>',
    tplSelectedScenarioKey: null,
  });
  vm.runInContext(script.slice(start, end), context);
  context.renderTemplatesScenario([
    { id: 1, kind: 'subject', name: '片側件名', scenario_key: 'missing', content: '片側の文面' },
    { id: 2, kind: 'subject', name: 'ペアシナリオ', scenario_key: 'pair', content: 'ペア件名の文面' },
    { id: 3, kind: 'body', name: 'ペアシナリオ', scenario_key: 'pair', content: '<p>ペア本文の中身</p>' },
    { id: 4, kind: 'body', name: '単独本文' },
    { id: 5, kind: 'subject', name: '追加件名', scenario_key: 'pair' },
  ]);
  const html = nodes['#templatesBody'].innerHTML;
  assert.deepEqual([...html.matchAll(/<td>(\d+)<\/td>/g)].map((match) => Number(match[1])), [1, 2, 3, 4]);
  for (const name of ['片側件名', 'ペアシナリオ', '単独本文', '追加件名']) {
    assert.ok(html.includes(name), `${name} is visible`);
  }
  // (2026-11-01) 件名列に件名の文面を出す。本文の中身は一覧に出さず、右のプレビューで見せる
  assert.ok(html.includes('ペア件名の文面'), '件名列に件名の文面が出る');
  assert.ok(!html.includes('ペア本文の中身'), '本文の中身は一覧に出ない');
  assert.equal(html.split('ペアシナリオ').length - 1, 1, 'シナリオ名は1行に1回だけ出る（本文列にシナリオ名を重ねない）');
  const head = nodes['#templatesHead'].innerHTML;
  assert.ok(head.includes('シナリオ名') && head.includes('件名') && !head.includes('<th>本文</th>'));
  context.renderTemplatesScenario([]);
  assert.ok(nodes['#templatesBody'].innerHTML.includes('なし'));
  const hostileKey = "x');alert(document.domain);//";
  context.renderTemplatesScenario([
    { id: 6, kind: 'subject', name: '安全な件名', scenario_key: hostileKey, content: '<img src=x onerror=alert(1)>' },
    { id: 7, kind: 'body', name: '安全な本文', scenario_key: hostileKey },
  ]);
  const hostileHtml = nodes['#templatesBody'].innerHTML;
  assert.ok(!hostileHtml.includes(hostileKey));
  assert.ok(!hostileHtml.includes('<img'), '件名の文面はエスケープする');
  assert.ok(hostileHtml.includes('selectScenario(this.dataset.scenarioKey)'));
  // 選んだ行は強調する
  context.tplSelectedScenarioKey = 'pair';
  context.renderTemplatesScenario([
    { id: 2, kind: 'subject', name: 'ペアシナリオ', scenario_key: 'pair', content: '件名' },
    { id: 3, kind: 'body', name: 'ペアシナリオ', scenario_key: 'pair', content: '本文' },
    { id: 8, kind: 'subject', name: '別', scenario_key: 'other', content: '件名' },
    { id: 9, kind: 'body', name: '別', scenario_key: 'other', content: '本文' },
  ]);
  const rows = nodes['#templatesBody'].innerHTML.split('</tr>');
  assert.ok(rows[0].includes('table-active') && rows[0].includes('aria-selected="true"'), '選んだ行に table-active');
  assert.ok(!rows[1].includes('table-active'), 'ほかの行は強調しない');
});

test('scenario preview pane shows the subject text and body, with edit controls only for editors', () => {
  const script = fs.readFileSync(`${__dirname}/../app.js`, 'utf8');
  const pick = (from, to) => script.slice(script.indexOf(from), script.indexOf(to, script.indexOf(from)));
  const context = vm.createContext({
    esc: (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#39;'),
    TPL_PLACEHOLDERS: [{ ph: '#$2$#', label: '姓', sample: '山田' }],
  });
  vm.runInContext(pick('function tplApplyPlaceholders(', '// 編集できない人に見せる概要'), context);
  vm.runInContext(pick('function tplDescriptionView(', '// 単一テンプレート'), context);
  vm.runInContext(pick('function findScenarioPair(', '// プレビュー欄の HTML'), context);
  vm.runInContext(pick('function scenarioPreviewHtml(', '// 選んだシナリオのプレビュー'), context);
  const templates = [
    { id: 1, kind: 'subject', name: '年末調整', scenario_key: 'sc1', content: '#$2$#様 書類のお願い', is_preset: 0 },
    { id: 2, kind: 'body', name: '年末調整', scenario_key: 'sc1', content: '<p>本文 #$2$#</p>', format: 'html', description: '概要<b>', is_preset: 0 },
    { id: 3, kind: 'subject', name: '片側', scenario_key: 'sc2', content: 'x' },
  ];
  const pair = context.findScenarioPair(templates, 'sc1');
  assert.equal(pair.subject.id, 1);
  assert.equal(pair.body.id, 2);
  assert.equal(context.findScenarioPair(templates, 'sc2'), null, '対が欠けたら null');
  assert.equal(context.findScenarioPair(templates, null), null);

  const html = context.scenarioPreviewHtml(pair.subject, pair.body, true);
  assert.ok(html.includes('年末調整'), 'シナリオ名');
  assert.ok(html.includes('山田様 書類のお願い'), '件名は差込をサンプル値に置き換えて出す');
  assert.ok(html.includes('<iframe sandbox srcdoc="&lt;p&gt;本文 山田&lt;/p&gt;"'), 'HTML 本文は sandbox iframe で出す');
  assert.ok(html.includes('概要&lt;b&gt;'), '概要はエスケープする');
  assert.ok(html.includes('id="tplPaneEdit"') && html.includes('id="tplPaneDelete"'), '編集できる人には修正と削除');
  const readonly = context.scenarioPreviewHtml(pair.subject, pair.body, false);
  assert.ok(!readonly.includes('tplPaneEdit') && !readonly.includes('tplPaneDelete'), '編集できない人にはボタンを出さない');
  const text = context.scenarioPreviewHtml(pair.subject, { ...pair.body, format: 'text', content: '<script>x</script>' }, false);
  assert.ok(text.includes('<pre') && text.includes('&lt;script&gt;') && !text.includes('<script>'), 'テキスト本文は pre でエスケープして出す');
});

test('scenario search keeps the subject and body paired when either name matches', () => {
  const script = fs.readFileSync(`${__dirname}/../app.js`, 'utf8');
  const start = script.indexOf('function templatesMatchingSearch(');
  const end = script.indexOf('async function renderTemplates(', start);
  const context = vm.createContext({});
  vm.runInContext(script.slice(start, end), context);
  const templates = [
    { id: 1, kind: 'subject', name: '採用通知', scenario_key: 'recruit' },
    { id: 2, kind: 'body', name: '採用本文', scenario_key: 'recruit' },
    { id: 3, kind: 'subject', name: '経費精算', scenario_key: 'expense' },
    { id: 4, kind: 'body', name: '経費本文', scenario_key: 'expense' },
    { id: 5, kind: 'phish_login', name: 'Microsoft 365認証' },
  ];
  assert.deepEqual(Array.from(context.templatesMatchingSearch(templates, '  採用本文  '), (t) => t.id), [1, 2]);
  assert.deepEqual(Array.from(context.templatesMatchingSearch(templates, '採用通知'), (t) => t.id), [1, 2]);
  assert.deepEqual(Array.from(context.templatesMatchingSearch(templates, 'microsoft'), (t) => t.id), [5]);
  assert.equal(context.templatesMatchingSearch(templates, '存在しない').length, 0);
  assert.equal(templates.length, 5, '原リストを変更しない');
});

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
    esc: (value) => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('"', '&quot;').replaceAll("'", '&#39;'),
    KIND_LABELS: { subject: '件名', body: '本文' },
    emptyRow: () => '<tr><td>なし</td></tr>',
  });
  vm.runInContext(script.slice(start, end), context);
  context.renderTemplatesScenario([
    { id: 1, kind: 'subject', name: '片側件名', scenario_key: 'missing' },
    { id: 2, kind: 'subject', name: 'ペア件名', scenario_key: 'pair' },
    { id: 3, kind: 'body', name: 'ペア本文', scenario_key: 'pair' },
    { id: 4, kind: 'body', name: '単独本文' },
    { id: 5, kind: 'subject', name: '追加件名', scenario_key: 'pair' },
  ]);
  const html = nodes['#templatesBody'].innerHTML;
  assert.deepEqual([...html.matchAll(/<td>(\d+)<\/td>/g)].map((match) => Number(match[1])), [1, 2, 3, 4]);
  for (const name of ['片側件名', 'ペア件名', 'ペア本文', '単独本文', '追加件名']) {
    assert.ok(html.includes(name), `${name} is visible`);
  }
  context.renderTemplatesScenario([]);
  assert.ok(nodes['#templatesBody'].innerHTML.includes('なし'));
  const hostileKey = "x');alert(document.domain);//";
  context.renderTemplatesScenario([
    { id: 6, kind: 'subject', name: '安全な件名', scenario_key: hostileKey },
    { id: 7, kind: 'body', name: '安全な本文', scenario_key: hostileKey },
  ]);
  assert.ok(!nodes['#templatesBody'].innerHTML.includes(hostileKey));
  assert.ok(nodes['#templatesBody'].innerHTML.includes('scenarioViewer(this.dataset.scenarioKey)'));
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

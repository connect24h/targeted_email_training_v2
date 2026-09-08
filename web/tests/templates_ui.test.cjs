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

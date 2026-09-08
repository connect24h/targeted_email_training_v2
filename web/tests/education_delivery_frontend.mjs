import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { test } from 'node:test';

const app = readFileSync(new URL('../app.js', import.meta.url), 'utf8');
const education = app.slice(app.indexOf('const EDU_DTYPE'), app.indexOf('function eduDeliveryForm'));
const deletion = app.slice(app.indexOf('async function deleteEduDelivery('), app.indexOf('async function launchEduDelivery('));
function harness({ role = 'operator', confirmed = true, failure = false, completed = 0, started_count = 0 } = {}) {
  const calls = [], notices = [], prompts = [];
  const body = { innerHTML: '' };
  const context = vm.createContext({
    State: { user: { role } }, Cache: {},
    roleAtLeast: (value) => value !== 'viewer',
    $: () => body, esc: (value) => String(value).replaceAll('<', '&lt;'), emptyRow: () => '',
    cacheRows: (key, rows) => { context.Cache[key] = Object.fromEntries(rows.map(row => [row.id, row])); },
    confirm: (message) => { prompts.push(message); return confirmed; },
    toast: (...args) => notices.push(args),
    api: async (path, options) => {
      calls.push({ path, options });
      if (options.method === 'POST') {
        if (failure) throw new Error('受講が開始された配信は削除できません');
        return { success: true };
      }
      return { deliveries: [{ id: 12, title: '<教材>', status: 'running', completed, started_count, assigned: 3 }] };
    },
  });
  vm.runInContext(education + '\n' + deletion, context);
  return { context, calls, notices, prompts, body };
}
test('operator can delete an unstarted delivery after a named confirmation and refresh', async () => {
  const h = harness();
  await h.context.renderEduDeliveries();
  assert.match(h.body.innerHTML, /deleteEduDelivery\(12\)/);
  await h.context.deleteEduDelivery(12);
  assert.match(h.prompts[0], /<教材>/);
  assert.match(h.prompts[0], /受講/);
  assert.equal(h.calls[1].path, 'api/edu_deliveries.php');
  assert.equal(h.calls[1].options.query.action, 'delete');
  assert.equal(h.calls[1].options.body.id, 12);
  assert.equal(h.calls.length, 3);
});
test('cancel and viewer perform no deletion', async () => {
  for (const options of [{ confirmed: false }, { role: 'viewer' }]) {
    const h = harness(options);
    await h.context.renderEduDeliveries();
    await h.context.deleteEduDelivery(12);
    assert.equal(h.calls.length, 1);
    if (options.role) assert.doesNotMatch(h.body.innerHTML, /deleteEduDelivery/);
  }
});
test('started delivery API rejection is shown and no success refresh occurs', async () => {
  const h = harness({ failure: true });
  await h.context.renderEduDeliveries();
  await h.context.deleteEduDelivery(12);
  assert.equal(h.calls.length, 2);
  assert.equal(h.notices[0][1], 'err');
  assert.match(h.notices[0][0], /受講が開始/);
});
test('completed delivery explains retention and cannot be deleted', async () => {
  const h = harness({ completed: 1 });
  await h.context.renderEduDeliveries();
  assert.match(h.body.innerHTML, /受講履歴/);
  assert.doesNotMatch(h.body.innerHTML, /deleteEduDelivery\(12\)/);
  await h.context.deleteEduDelivery(12);
  assert.equal(h.calls.length, 1);
});

test('material bank keeps viewer read-only while operator can edit tenant material', async () => {
  const source = app.slice(app.indexOf('async function renderEduMaterials()'), app.indexOf('let eduMaterialPreviewIndex'));
  for (const role of ['viewer', 'operator']) {
    const body = { innerHTML: '' };
    const context = vm.createContext({
      State: { user: { role } }, Cache: {}, $: () => body,
      roleAtLeast: (value) => value !== 'viewer', esc: String, emptyRow: () => '',
      api: async () => ({ materials: [{ id: 1, title: '教材', is_shared: 0, slide_count: 2 }] }),
    });
    vm.runInContext(source, context);
    await context.renderEduMaterials();
    assert.match(body.innerHTML, /教材を試行/);
    assert.match(body.innerHTML, /<tr><td>1<\/td><td>教材/);
    assert.equal(body.innerHTML.includes('editEduMaterial(1)'), role === 'operator');
    context.api = async () => ({ materials: [] });
    context.emptyRow = (columns) => `<td colspan="${columns}">なし</td>`;
    await context.renderEduMaterials();
    assert.match(body.innerHTML, /colspan="5"/);
  }
});

test('education screens explain retention and material header matches five columns', () => {
  const index = readFileSync(new URL('../index.html', import.meta.url), 'utf8');
  assert.match(index, /<th>#<\/th><th>教材名<\/th><th>説明<\/th><th>スライド<\/th><th>操作<\/th>/);
  assert.match(index, /キャンペーンを削除しても、教育配信と受講履歴は保持され/);
  assert.match(index, /削除できるのは受講開始前の配信です/);
});

test('started delivery is retained without offering deletion', async () => {
  const h = harness({ started_count: 1 });
  await h.context.renderEduDeliveries();
  assert.match(h.body.innerHTML, /受講履歴/);
  assert.doesNotMatch(h.body.innerHTML, /deleteEduDelivery\(12\)/);
  await h.context.deleteEduDelivery(12);
  assert.equal(h.calls.length, 1);
});

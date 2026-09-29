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
    assert.match(body.innerHTML, /colspan="6"/);
  }
});

test('material bank shows the kind and how many deliveries use each material', async () => {
  const source = app.slice(app.indexOf('async function renderEduMaterials()'), app.indexOf('let eduMaterialPreviewIndex'));
  const body = { innerHTML: '' };
  const context = vm.createContext({
    State: { user: { role: 'viewer' } }, Cache: {}, $: () => body,
    roleAtLeast: () => false, esc: String, emptyRow: () => '',
    api: async () => ({ materials: [
      { id: 1, title: '本', is_shared: 0, format: 'page_images', page_count: 25, kind: 'book', delivery_count: 3 },
      { id: 2, title: '要点', is_shared: 0, format: 'page_images', page_count: 13, kind: 'slide', delivery_count: 0 },
    ] }),
  });
  vm.runInContext(source, context);
  await context.renderEduMaterials();
  assert.match(body.innerHTML, /本の版 PDF 25ページ<\/td>\s*<td>3<\/td>/);
  assert.match(body.innerHTML, /スライド版 PDF 13ページ<\/td>\s*<td>0<\/td>/);
});

test('education screens explain retention and material header matches six columns', () => {
  const index = readFileSync(new URL('../index.html', import.meta.url), 'utf8');
  assert.match(index, /<th>#<\/th><th>教材名<\/th><th>説明<\/th><th>スライド<\/th><th[^>]*>使っている配信<\/th><th>操作<\/th>/);
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

// ---- 教育の配信の機能(予約、毎月、役職、訓練の結果、新入社員、案内メール) ----
const payloadSource = app.slice(app.indexOf('function eduCheckedValues('), app.indexOf('async function newEduDelivery('));
// 受講の設定(選択肢の並べ替えなど)は作成と編集の画面で共通の関数から読む。
const optionSource = app.slice(app.indexOf('const EDU_DELIVERY_OPTIONS = ['), app.indexOf('/** 毎月の配信(系列)'));
function fakeForm(values = {}) {
  const defaults = {
    title: '配信', delivery_type: 'awareness_quiz', feedback_mode: 'immediate', target_type: 'all',
    question_count: '3', pass_score: '80', material_id: '', auto_enroll: false, send_invites: false,
    scheduled_at: '', deadline: '', phish_campaign_id: '', new_target_days: '30', repeat_monthly: false,
    day_of_month: '1', time_of_day: '09:00', deadline_days: '14', end_date: '',
    allow_retake_after_pass: false,
    category_ids: [], target_ids: [], target_positions: [], risk_results: [],
    // 受講の設定(data-edu-option のチェックボックス)。値は新しい配信の画面の既定。
    options: { shuffle_options: true, lock_material_during_test: false, allow_after_deadline: false, retake_from_test: false },
  };
  const { options: optionValues = {}, ...rest } = values;
  const v = { ...defaults, ...rest };
  const options = { ...defaults.options, ...optionValues };
  delete v.options;
  const form = {
    querySelectorAll: (selector) => {
      if (selector === '[data-edu-option]') {
        return Object.entries(options).map(([key, checked]) => ({ dataset: { eduOption: key }, checked }));
      }
      const name = /name="([^"]+)"/.exec(selector)[1];
      return (v[name] || []).map((value) => ({ value }));
    },
  };
  for (const [key, value] of Object.entries(v)) {
    if (Array.isArray(value)) form[key] = { selectedOptions: value.map((x) => ({ value: String(x) })) };
    else if (typeof value === 'boolean') form[key] = { checked: value };
    else form[key] = { value };
  }
  return form;
}
function payloadContext() {
  const context = vm.createContext({ POSITION_CATEGORIES: ['役員', '管理職', '一般従業員'] });
  vm.runInContext(optionSource, context);
  vm.runInContext(payloadSource, context);
  return context;
}

test('案内メールは既定で送らず、選んだときだけ send_invites を立てる', () => {
  const c = payloadContext();
  assert.equal(c.eduDeliveryPayload(fakeForm()).send_invites, false);
  assert.equal(c.eduDeliveryPayload(fakeForm({ send_invites: true })).send_invites, true);
  // 合格後の再受講と受講の設定も、画面の値のまま送る
  const body = c.eduDeliveryPayload(fakeForm());
  assert.equal(body.allow_retake_after_pass, false);
  assert.equal(body.shuffle_options, true);
  assert.equal(body.lock_material_during_test, false);
  assert.equal(body.allow_after_deadline, false);
  assert.equal(body.retake_from_test, false);
  const custom = c.eduDeliveryPayload(fakeForm({ allow_retake_after_pass: true, options: { shuffle_options: false, allow_after_deadline: true } }));
  assert.equal(custom.allow_retake_after_pass, true);
  assert.equal(custom.shuffle_options, false);
  assert.equal(custom.allow_after_deadline, true);
});

test('予約の日時と締切を送り、毎月くり返すときは系列の作成を使う', () => {
  const c = payloadContext();
  const once = c.eduDeliveryPayload(fakeForm({ scheduled_at: '2030-04-01T09:00', deadline: '2030-04-15' }));
  assert.equal(once.scheduled_at, '2030-04-01T09:00');
  assert.equal(once.deadline, '2030-04-15');
  assert.equal(c.eduDeliveryAction(fakeForm()), 'create');
  const monthly = fakeForm({ repeat_monthly: true, scheduled_at: '2030-04-01T09:00', day_of_month: '10', time_of_day: '08:30', deadline_days: '7', end_date: '2031-03-31' });
  assert.equal(c.eduDeliveryAction(monthly), 'series_create');
  const body = c.eduDeliveryPayload(monthly);
  assert.equal(body.day_of_month, 10);
  assert.equal(body.time_of_day, '08:30');
  assert.equal(body.deadline_days, 7);
  assert.equal(body.end_date, '2031-03-31');
  assert.equal(body.scheduled_at, undefined);
  assert.throws(() => c.eduDeliveryPayload(fakeForm({ repeat_monthly: true, day_of_month: '29' })), /1〜28/);
});

test('役職区分と新入社員の対象を API の形にする', () => {
  const c = payloadContext();
  const position = c.eduDeliveryPayload(fakeForm({ target_type: 'position', target_positions: ['役員', '管理職'] }));
  assert.equal(position.target_type, 'position');
  assert.deepEqual([...position.target_positions], ['役員', '管理職']);
  assert.throws(() => c.eduDeliveryPayload(fakeForm({ target_type: 'position' })), /役職区分/);
  const newcomer = c.eduDeliveryPayload(fakeForm({ target_type: 'new_target', new_target_days: '45' }));
  assert.equal(newcomer.target_type, 'all');
  assert.equal(newcomer.triggered_by, 'new_target');
  assert.equal(newcomer.new_target_days, 45);
});

test('訓練の結果で選ぶときは、キャンペーンと区分を送る', () => {
  const c = payloadContext();
  const body = c.eduDeliveryPayload(fakeForm({ target_type: 'risk', phish_campaign_id: '7', risk_results: ['reported', 'not_opened'] }));
  assert.equal(body.phish_campaign_id, 7);
  assert.deepEqual([...body.risk_results], ['reported', 'not_opened']);
  assert.equal(body.triggered_by, undefined);
  assert.throws(() => c.eduDeliveryPayload(fakeForm({ target_type: 'risk', risk_results: ['opened'] })), /キャンペーン/);
  assert.throws(() => c.eduDeliveryPayload(fakeForm({ target_type: 'risk', phish_campaign_id: '7' })), /区分/);
  const auto = c.eduDeliveryPayload(fakeForm({ target_type: 'risk', auto_enroll: true, risk_results: ['opened'] }));
  assert.equal(auto.triggered_by, 'phishing_failure');
  assert.equal(auto.risk_results, undefined);
});

test('予約中の配信は予約の日時を出し、今すぐ開始と編集ができる', async () => {
  const body = { innerHTML: '', querySelectorAll: () => [] };
  const context = vm.createContext({
    State: { user: { role: 'operator' } }, Cache: {},
    roleAtLeast: () => true, $: () => body, esc: String, emptyRow: () => '', cacheRows: () => {},
    api: async () => ({ deliveries: [{ id: 5, title: '月例', status: 'scheduled', scheduled_at: '2030-04-01 09:00:00', series_id: 2, completed: 0, started_count: 0, assigned: 0 }] }),
  });
  vm.runInContext(education, context);
  await context.renderEduDeliveries();
  assert.match(body.innerHTML, /予約/);
  assert.match(body.innerHTML, /2030-04-01 09:00/);
  assert.match(body.innerHTML, /launchEduDelivery\(5\)/);
  assert.match(body.innerHTML, /data-edu-delivery-edit="5"/);
  assert.match(body.innerHTML, /毎月/);
});

test('毎月の配信の一覧を出し、有効な系列だけ停止できる', async () => {
  const source = app.slice(app.indexOf('async function renderEduSeries('), app.indexOf('function eduDeliveryForm('));
  const body = { innerHTML: '' };
  const calls = [];
  const context = vm.createContext({
    State: { user: { role: 'operator' } }, roleAtLeast: () => true, $: () => body, esc: String, emptyRow: () => '<tr></tr>',
    confirm: () => true, toast: () => {},
    api: async (path, options) => {
      calls.push(options);
      return { series: [
        { id: 3, title: '月例', day_of_month: 1, time_of_day: '09:00', deadline_days: 14, next_run_at: '2026-11-01 09:00:00', end_date: null, is_active: 1, delivery_count: 2 },
        { id: 4, title: '停止済み', day_of_month: 5, time_of_day: '10:00', deadline_days: 7, next_run_at: '2026-11-05 10:00:00', end_date: null, is_active: 0, delivery_count: 1 },
      ] };
    },
  });
  vm.runInContext(source, context);
  await context.renderEduSeries();
  assert.match(body.innerHTML, /毎月1日 09:00/);
  assert.match(body.innerHTML, /stopEduSeries\(3\)/);
  assert.doesNotMatch(body.innerHTML, /stopEduSeries\(4\)/);
  await context.stopEduSeries(3);
  assert.equal(calls[1].query.action, 'series_stop');
  assert.equal(calls[1].body.id, 3);
});

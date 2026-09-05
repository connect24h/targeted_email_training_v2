import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../assets/risk-dashboard.js', import.meta.url), 'utf8');
const sandbox = { globalThis: {}, window: undefined };
vm.runInNewContext(source, sandbox, { filename: 'risk-dashboard.js' });
const create = sandbox.globalThis.createRiskDashboard;
assert.equal(typeof create, 'function');

const deferred = () => {
  let resolve;
  let reject;
  const promise = new Promise((done, fail) => { resolve = done; reject = fail; });
  return { promise, resolve, reject };
};
let context = { userKey: '1:a@example.test', tenantId: 1 };
let view = 'riskDashboard';
const listeners = {};
const opened = [];
sandbox.globalThis.open = (...args) => opened.push(args);
sandbox.URLSearchParams = URLSearchParams;
const root = { innerHTML: 'OLD TENANT ROW', querySelector: (selector) => ({
  addEventListener: (event, handler) => { listeners[`${selector}:${event}`] = handler; },
}) };
const calls = [];
const api = (path, options) => {
  const item = deferred();
  calls.push({ path, options, ...item });
  return item.promise;
};
const dashboard = create({ api, root, getContext: () => context, getView: () => view });

const first = dashboard.render();
assert.doesNotMatch(root.innerHTML, /OLD TENANT ROW/, 'loading immediately clears prior tenant rows');
context = { userKey: '1:b@example.test', tenantId: 2 };
dashboard.invalidate();
assert.equal(root.innerHTML, '', 'invalidation clears prior tenant DOM immediately');
calls[0].resolve({ computed_date: '2026-09-01', bands: { high: 1, medium: 0, low: 0 }, total: 1,
  limit: 100, individuals: [{ name: 'Tenant A secret', score: 99, band: 'high', detail: {} }] });
calls[1].resolve({ computed_date: '2026-09-01', companies: [] });
calls[2].resolve({ computed_date: '2026-09-01', rows: [] });
await first;
assert.doesNotMatch(root.innerHTML, /Tenant A secret/, 'stale tenant response is ignored');

const second = dashboard.render();
calls[3].resolve({ computed_date: '2026-09-02', bands: { high: 1, medium: 0, low: 0 }, total: 1,
  limit: 100, individuals: [{ name: '<img src=x onerror=alert(1)>', company: '<script>x</script>',
    position_category: '一般従業員', score: 80, band: 'high', phish_component: 10,
    edu_component: 2, report_credit: 1, detail: { click: 1 } }] });
calls[4].resolve({ computed_date: '2026-09-02', companies: [{ company: '<b>危険社</b>', count: 1,
  avg_score: 80, high_count: 1, medium_count: 0, low_count: 0 }] });
calls[5].resolve({ computed_date: '2026-09-02', rows: [{ name: '推奨対象', company: '<b>会社</b>', score: 80, band: 'high', last_failure: { type: 'auth', occurred_at: '2026-08-20 10:00:00' }, recommended_categories: [{ name: '<教材>', slug: 'phishing' }], edu: { assigned_count: 1, completed_count: 0, overdue_count: 1 }, reason: '<理由>失敗後の教育未受講' }] });
await second;
assert.match(root.innerHTML, /&lt;img src=x onerror=alert\(1\)&gt;/, 'person text is escaped');
assert.match(root.innerHTML, /&lt;b&gt;危険社&lt;\/b&gt;/, 'company text is escaped');
assert.doesNotMatch(root.innerHTML, /<img src=x|<b>危険社<\/b>/, 'untrusted HTML is never emitted raw');

assert.match(root.innerHTML, /再訓練推奨/, 'recommendation section is rendered');
const recommendationsBody = root.innerHTML.match(/<tbody id="riskRecommendationsBody">([\s\S]*?)<\/tbody>/)[1];
assert.equal((recommendationsBody.match(/<tr>/g) || []).length, 1, 'recommendation row count');
assert.match(recommendationsBody, /badge[^>]*>&lt;教材&gt;/, 'category badge is escaped');
assert.match(recommendationsBody, /&lt;理由&gt;失敗後の教育未受講/, 'reason is escaped and displayed');
assert.match(recommendationsBody, /割当 1・完了 0・期限超過 1/, 'education counts displayed');
assert.equal(calls[5].options.query.limit, 20, 'recommendations request top 20');
listeners['#riskRecommendationsCsv:click']();
const csvUrl = new URL(opened[0][0], 'https://example.test/');
assert.equal(csvUrl.searchParams.get('action'), 'risk_recommendations');
assert.equal(csvUrl.searchParams.get('tenant_id'), '2', 'CSV retains selected tenant');
assert.equal(csvUrl.searchParams.get('limit'), '500');
assert.equal(csvUrl.searchParams.get('format'), 'csv');

const third = dashboard.render();
view = 'dashboard';
dashboard.invalidate();
calls[6].resolve({ computed_date: '2026-09-03', bands: { high: 0, medium: 0, low: 1 }, total: 1,
  limit: 100, individuals: [{ name: 'After logout', score: 10, band: 'low', detail: {} }] });
calls[7].resolve({ computed_date: '2026-09-03', companies: [] });
calls[8].resolve({ computed_date: '2026-09-03', rows: [] });
await third;
assert.doesNotMatch(root.innerHTML, /After logout/, 'response after view/logout invalidation is ignored');

view = 'riskDashboard';
const fourth = dashboard.render();
calls[9].resolve({ computed_date: '2026-09-04', bands: { high: 0, medium: 0, low: 0 }, total: 0,
  limit: 100, individuals: [] });
calls[10].resolve({ computed_date: '2026-09-05', companies: [] });
calls[11].resolve({ computed_date: '2026-09-04', rows: [] });
await fourth;
assert.match(root.innerHTML, /スナップショットの更新中です/, 'mixed snapshot dates show retryable error instead of mixed data');
assert.match(root.innerHTML, /riskRetryBtn/, 'snapshot mismatch offers retry');

const fifth = dashboard.render();
calls[12].resolve({ computed_date: null, bands: { high: 0, medium: 0, low: 0 }, total: 0, limit: 100, individuals: [] });
calls[13].resolve({ computed_date: null, companies: [] });
calls[14].resolve({ computed_date: null, rows: [] });
await fifth;
assert.match(root.innerHTML, /riskEmpty/, 'no-snapshot state is explicit');
assert.match(root.innerHTML, /riskRetryBtn/, 'no-snapshot state offers retry');

const sixth = dashboard.render();
calls[15].reject(new Error('network failed'));
calls[16].resolve({ computed_date: null, companies: [] });
calls[17].resolve({ computed_date: null, rows: [] });
await sixth;
assert.match(root.innerHTML, /riskError/, 'request failure has explicit error state');
assert.match(root.innerHTML, /network failed/, 'safe error message is shown');
assert.match(root.innerHTML, /riskRetryBtn/, 'request failure offers retry');

dashboard.setBand('high');
const slowHigh = dashboard.render();
dashboard.setBand('low');
const fastLow = dashboard.render();
assert.equal(calls[18].options.query.band, 'high', 'first filtered request uses high band');
assert.equal(calls[21].options.query.band, 'low', 'second filtered request uses low band');
calls[21].resolve({ computed_date: '2026-09-06', bands: { high: 1, medium: 0, low: 1 }, total: 1,
  limit: 100, individuals: [{ name: 'Current low', score: 20, band: 'low', detail: {} }] });
calls[22].resolve({ computed_date: '2026-09-06', companies: [] });
calls[23].resolve({ computed_date: '2026-09-06', rows: [{ name: '推奨low', score: 20, band: 'low', reason: '失敗記録なし' }] });
await fastLow;
calls[18].resolve({ computed_date: '2026-09-06', bands: { high: 1, medium: 0, low: 1 }, total: 1,
  limit: 100, individuals: [{ name: 'Stale high', score: 90, band: 'high', detail: {} }] });
calls[19].resolve({ computed_date: '2026-09-06', companies: [] });
calls[20].resolve({ computed_date: '2026-09-06', rows: [{ name: '古い推奨high', score: 20, band: 'low', reason: '失敗記録なし' }] });
await slowHigh;
assert.match(root.innerHTML, /Current low/, 'latest same-tenant filtered request wins');
assert.doesNotMatch(root.innerHTML, /Stale high/, 'slower prior filter response is ignored');

assert.equal(calls[20].options.query.band, 'high', 'recommendation high filter');
assert.equal(calls[23].options.query.band, 'low', 'recommendation low filter');
assert.match(root.innerHTML, /推奨low/, 'filtered recommendation is rendered');
assert.doesNotMatch(root.innerHTML, /古い推奨high/, 'stale recommendation is ignored');
listeners['#riskRecommendationsCsv:click']();
assert.equal(new URL(opened[1][0], 'https://example.test/').searchParams.get('band'), 'low', 'CSV keeps band');
const mixedRecommendation = dashboard.render();
calls[24].resolve({ computed_date: '2026-09-06', individuals: [], bands: {} });
calls[25].resolve({ computed_date: '2026-09-06', companies: [] });
calls[26].resolve({ computed_date: '2026-09-07', rows: [] });
await mixedRecommendation;
assert.match(root.innerHTML, /スナップショットの更新中です/, 'recommendation date mismatch offers retry');
console.log('ALL TESTS PASSED');

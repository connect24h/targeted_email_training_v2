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
const root = { innerHTML: 'OLD TENANT ROW' };
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
await first;
assert.doesNotMatch(root.innerHTML, /Tenant A secret/, 'stale tenant response is ignored');

const second = dashboard.render();
calls[2].resolve({ computed_date: '2026-09-02', bands: { high: 1, medium: 0, low: 0 }, total: 1,
  limit: 100, individuals: [{ name: '<img src=x onerror=alert(1)>', company: '<script>x</script>',
    position_category: '一般従業員', score: 80, band: 'high', phish_component: 10,
    edu_component: 2, report_credit: 1, detail: { click: 1 } }] });
calls[3].resolve({ computed_date: '2026-09-02', companies: [{ company: '<b>危険社</b>', count: 1,
  avg_score: 80, high_count: 1, medium_count: 0, low_count: 0 }] });
await second;
assert.match(root.innerHTML, /&lt;img src=x onerror=alert\(1\)&gt;/, 'person text is escaped');
assert.match(root.innerHTML, /&lt;b&gt;危険社&lt;\/b&gt;/, 'company text is escaped');
assert.doesNotMatch(root.innerHTML, /<img src=x|<b>危険社<\/b>/, 'untrusted HTML is never emitted raw');

const third = dashboard.render();
view = 'dashboard';
dashboard.invalidate();
calls[4].resolve({ computed_date: '2026-09-03', bands: { high: 0, medium: 0, low: 1 }, total: 1,
  limit: 100, individuals: [{ name: 'After logout', score: 10, band: 'low', detail: {} }] });
calls[5].resolve({ computed_date: '2026-09-03', companies: [] });
await third;
assert.doesNotMatch(root.innerHTML, /After logout/, 'response after view/logout invalidation is ignored');

view = 'riskDashboard';
const fourth = dashboard.render();
calls[6].resolve({ computed_date: '2026-09-04', bands: { high: 0, medium: 0, low: 0 }, total: 0,
  limit: 100, individuals: [] });
calls[7].resolve({ computed_date: '2026-09-05', companies: [] });
await fourth;
assert.match(root.innerHTML, /スナップショットの更新中です/, 'mixed snapshot dates show retryable error instead of mixed data');
assert.match(root.innerHTML, /riskRetryBtn/, 'snapshot mismatch offers retry');

const fifth = dashboard.render();
calls[8].resolve({ computed_date: null, bands: { high: 0, medium: 0, low: 0 }, total: 0, limit: 100, individuals: [] });
calls[9].resolve({ computed_date: null, companies: [] });
await fifth;
assert.match(root.innerHTML, /riskEmpty/, 'no-snapshot state is explicit');
assert.match(root.innerHTML, /riskRetryBtn/, 'no-snapshot state offers retry');

const sixth = dashboard.render();
calls[10].reject(new Error('network failed'));
calls[11].resolve({ computed_date: null, companies: [] });
await sixth;
assert.match(root.innerHTML, /riskError/, 'request failure has explicit error state');
assert.match(root.innerHTML, /network failed/, 'safe error message is shown');
assert.match(root.innerHTML, /riskRetryBtn/, 'request failure offers retry');

dashboard.setBand('high');
const slowHigh = dashboard.render();
dashboard.setBand('low');
const fastLow = dashboard.render();
assert.equal(calls[12].options.query.band, 'high', 'first filtered request uses high band');
assert.equal(calls[14].options.query.band, 'low', 'second filtered request uses low band');
calls[14].resolve({ computed_date: '2026-09-06', bands: { high: 1, medium: 0, low: 1 }, total: 1,
  limit: 100, individuals: [{ name: 'Current low', score: 20, band: 'low', detail: {} }] });
calls[15].resolve({ computed_date: '2026-09-06', companies: [] });
await fastLow;
calls[12].resolve({ computed_date: '2026-09-06', bands: { high: 1, medium: 0, low: 1 }, total: 1,
  limit: 100, individuals: [{ name: 'Stale high', score: 90, band: 'high', detail: {} }] });
calls[13].resolve({ computed_date: '2026-09-06', companies: [] });
await slowHigh;
assert.match(root.innerHTML, /Current low/, 'latest same-tenant filtered request wins');
assert.doesNotMatch(root.innerHTML, /Stale high/, 'slower prior filter response is ignored');

console.log('ALL TESTS PASSED');

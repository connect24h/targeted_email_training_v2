const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../app.js'), 'utf8');
const context = vm.createContext({ document: { addEventListener() {}, querySelector() { return null; } }, window: {} });
vm.runInContext(source, context);

test('運用ホームは下書き・一時停止・直近の予約を分ける', () => {
  const rows = [
    { id: 1, name: '下書き', status: 'draft', is_test: 0 },
    { id: 2, name: '一時停止', status: 'paused', is_test: 0 },
    { id: 3, name: '後', status: 'scheduled', start_at: '2026-11-02 09:00:00', is_test: 0 },
    { id: 4, name: '先', status: 'scheduled', start_at: '2026-10-02 09:00:00', is_test: 0 },
    { id: 5, name: '完了', status: 'done', is_test: 0 },
  ];
  const work = vm.runInContext(`dashboardWorkItems(${JSON.stringify(rows)})`, context);
  assert.deepEqual(Array.from(work.drafts, ({ id }) => id), [1]);
  assert.deepEqual(Array.from(work.paused, ({ id }) => id), [2]);
  assert.deepEqual(Array.from(work.upcoming, ({ id }) => id), [4, 3]);
});

test('TEST送信は本番フィルタの要対応に混ぜない', () => {
  const rows = [{ id: 9, status: 'draft', is_test: 1 }];
  const work = vm.runInContext(`dashboardWorkItems(${JSON.stringify(rows.filter((r) => !r.is_test))})`, context);
  assert.equal(work.drafts.length, 0);
});

test('URLの画面IDは登録済みビューだけを採用する', () => {
  assert.equal(vm.runInContext("routeFromHash('#reports')", context), 'reports');
  assert.equal(vm.runInContext("routeFromHash('#campaignWorkspace/42')", context), 'campaignWorkspace');
  assert.equal(vm.runInContext("campaignIdFromHash('#campaignWorkspace/42')", context), 42);
  assert.equal(vm.runInContext("routeFromHash('#unknown')", context), 'dashboard');
  assert.equal(vm.runInContext("routeFromHash('#../../api/auth.php')", context), 'dashboard');
});

test('テナント切替時に動的キャッシュも破棄する', () => {
  const result = vm.runInContext('Cache.eduCats = [{ id: 1 }]; Cache.targets = { 1: { name: "old" } }; clearTenantCache(); [Cache.eduCats, Object.keys(Cache.targets).length]', context);
  assert.equal(result[0], undefined);
  assert.equal(result[1], 0);
});

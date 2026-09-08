import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const calls = [];
const confirmations = [];
let confirmed = false;
const context = vm.createContext({
  VIEWS: {},
  document: { addEventListener() {} },
  confirm(message) { confirmations.push(message); return confirmed; },
  async api(path, options) { calls.push({ path, options }); return {}; },
  toast() {},
});
vm.runInContext(readFileSync(new URL('../assets/campaign-automations.js', import.meta.url), 'utf8'), context);
vm.runInContext('renderCampaignAutomations = async () => {};', context);
assert.match(context.automationActions({ id: 42, status: 'active' }, true), /data-automation-action="delete"/);
assert.doesNotMatch(context.automationActions({ id: 42, status: 'active' }, false), /data-automation-action="delete"/);
await context.deleteAutomation(42);
assert.equal(calls.length, 0, '確認取消では削除APIを呼ばない');
assert.match(confirmations[0], /生成済みキャンペーン.*送信ログ.*残ります/);
confirmed = true;
await context.handleAutomationAction({
  target: { closest() { return { dataset: { id: '42', automationAction: 'delete' } }; } },
});
assert.equal(calls.length, 1);
assert.equal(calls[0].options.method, 'POST');
assert.equal(calls[0].options.query.action, 'delete');
assert.equal(calls[0].options.body.id, 42);
process.stdout.write('PASS: 定期キャンペーン削除UI（権限表示・取消・確認・API dispatch）\n');

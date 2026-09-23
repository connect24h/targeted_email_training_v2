const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../assets/context-help.js'), 'utf8');
const context = vm.createContext({ window: {} });
vm.runInContext(source, context);
const articles = Array.from(context.window.TET2_HELP_ARTICLES);

test('8つの基礎HELPが検索・画面参照できる', () => {
  assert.equal(articles.length, 8);
  assert.equal(new Set(articles.map((a) => a.id)).size, 8);
  for (const article of articles) {
    assert.ok(article.title && article.summary && article.body);
    assert.ok(Array.isArray(article.views) && article.views.length);
    assert.match(article.reviewed, /^\d{4}-\d{2}-\d{2}$/);
  }
});

test('テスト送信のHELPは全件転送と空宛先拒否を明記する', () => {
  const article = articles.find((a) => a.id === 'test-delivery');
  assert.ok(article);
  assert.match(article.body, /全件転送/);
  assert.match(article.body, /空|未指定/);
  assert.match(article.body, /少量プレビュー/);
});

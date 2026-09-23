const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const canary = 'CANARY_SECRET_20260923';

for (const name of ['master2.html', 'master3.html', 'master4.html', 'master5.html']) {
  for (const approved of [false, true]) {
  test(`${name} ${approved ? 'approved capture' : 'unapproved action-only'}`, () => {
    // create_beacon_files.py のプレースホルダー置換後の個別HTMLを合成する。
    const html = fs.readFileSync(path.join(__dirname, '../../bin', name), 'utf8')
      .replaceAll('#$5$#', 'TRACK00001').replaceAll('#$6$#', 'canary@example.test')
      .replaceAll('#$C$#', approved ? '1' : '0');
    assert.doesNotMatch(html, /<input\b[^>]*\bname=["'](?:email|password)["']/i,
      'JavaScript無効時のフォーム標準送信でも入力値を送らない');
    const script = html.match(/<script type="text\/javascript">([\s\S]*?)<\/script>/);
    assert.ok(script, 'login script must exist');

    let onSubmit;
    let sent;
    const document = {
      getElementById(id) {
        if (id === 'loginForm') return { addEventListener(event, handler) {
          assert.equal(event, 'submit');
          onSubmit = handler;
        } };
        if (id === 'email') return { value: 'canary@example.test' };
        if (id === 'password') return { value: canary };
        throw new Error(`unexpected element ${id}`);
      },
      open() {}, write() {}, close() {},
    };
    vm.runInNewContext(script[1], {
      document,
      FormData,
      fetch(url, options) {
        assert.equal(url, approved ? '/tet2/api/credential_capture.php' : '/training_log.php');
        sent = options.body;
        return Promise.resolve({ ok: true, text: () => Promise.resolve('ok') });
      },
      console: { error(error) { throw error; } },
    }, { timeout: 1000 });
    assert.equal(typeof onSubmit, 'function');
    onSubmit({ preventDefault() {} });
    assert.ok(sent instanceof FormData);
    assert.equal(sent.get('random_value'), 'TRACK00001');
    assert.ok(sent.get('auth_type'));
    if (!approved) {
      assert.equal(sent.get('email'), null);
      assert.equal(sent.get('password'), null);
      assert.equal(sent.get('login_id'), null);
      assert.equal([...sent.values()].includes(canary), false);
    } else if (name === 'master4.html') {
      assert.equal(sent.get('login_id'), canary);
      assert.equal(sent.get('password'), null);
    } else if (name === 'master5.html') {
      assert.equal(sent.get('email'), 'canary@example.test');
      assert.equal(sent.get('password'), null);
    } else {
      assert.equal(sent.get('email'), 'canary@example.test');
      assert.equal(sent.get('password'), canary);
    }
  });
  }

  test(`${name} falls back without sending entered values when capture fails`, async () => {
    const html = fs.readFileSync(path.join(__dirname, '../../bin', name), 'utf8')
      .replaceAll('#$5$#', 'TRACK00001').replaceAll('#$6$#', 'canary@example.test')
      .replaceAll('#$C$#', '1');
    const script = html.match(/<script type="text\/javascript">([\s\S]*?)<\/script>/);
    assert.ok(script);
    let onSubmit;
    const requests = [];
    let done;
    const finished = new Promise((resolve) => { done = resolve; });
    const document = {
      getElementById(id) {
        if (id === 'loginForm') return { addEventListener(_event, handler) { onSubmit = handler; } };
        if (id === 'email') return { value: 'canary@example.test' };
        if (id === 'password') return { value: canary };
        throw new Error(`unexpected element ${id}`);
      },
      open() {}, write() {}, close() { done(); },
    };
    vm.runInNewContext(script[1], {
      document, FormData,
      fetch(url, options) {
        requests.push({ url, body: options.body });
        return Promise.resolve({ ok: requests.length > 1, text: () => Promise.resolve('reveal') });
      },
      console: { error(error) { throw error; } },
    }, { timeout: 1000 });
    onSubmit({ preventDefault() {} });
    await finished;
    assert.equal(requests.length, 2);
    assert.equal(requests[1].url, '/training_log.php');
    assert.deepEqual([...requests[1].body.keys()], ['random_value', 'auth_type']);
    assert.equal([...requests[1].body.values()].includes(canary), false);
  });
}

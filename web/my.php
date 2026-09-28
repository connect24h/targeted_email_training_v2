<?php declare(strict_types=1);
/**
 * 受講者のマイページ(受講者のサイト sat.cojp.online で公開する。L2〜L5、L7)。
 * ログイン、ホーム(期限つきの ToDo)、自分の成績(答え合わせ、受け直し)、自分のアンケート、パスワードの変更。
 * データはすべて api/my.php から取る(このページはセッションもデータも持たない土台だけ)。
 * 受講と回答は、既存の take.php と survey.php をトークンのリンクで開く。
 */
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>マイページ | セキュリティ教育</title>
  <link href="assets/vendor/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root { --ink:#19324A; --action:#146C78; --action-l:#E1F0F1; --canvas:#F5F7FA; --text:#243746;
            --ok:#1E7B4E; --ok-l:#E6F4EC; --ng:#B42318; --ng-l:#FCEBE9; --line:#D7DEE5; --muted:#5B6B7A; }
    body { background: var(--canvas); color: var(--text); }
    .my-head { background: var(--ink); color: #fff; }
    .my-head .title { font-weight: 700; font-size: 1rem; }
    .wrap { max-width: 880px; margin: 0 auto; padding: 1rem; }
    .card-t { background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(25,50,74,.10); }
    .btn-action { background: var(--action); border-color: var(--action); color: #fff; }
    .btn-action:hover, .btn-action:focus { background: #0f5761; border-color: #0f5761; color: #fff; }
    .btn-action:disabled { background: #9DBFC3; border-color: #9DBFC3; }
    .nav-my .nav-link { color: var(--text); }
    .nav-my .nav-link.active { color: var(--action); font-weight: 700; border-bottom-color: #fff; }
    .todo { display: flex; gap: .75rem; align-items: center; padding: .85rem 1rem; border-bottom: 1px solid var(--line); }
    .todo:last-child { border-bottom: 0; }
    .todo .meta { color: var(--muted); font-size: .85rem; }
    .todo .soon { color: var(--ng); font-weight: 700; }
    .pill { display: inline-block; font-size: .75rem; border-radius: 1rem; padding: .1rem .6rem; border: 1px solid var(--line); background: #fff; }
    .pill.ok { color: var(--ok); border-color: var(--ok); background: var(--ok-l); }
    .pill.ng { color: var(--ng); border-color: var(--ng); background: var(--ng-l); }
    .pill.act { color: var(--action); border-color: var(--action); background: var(--action-l); }
    .score { font-size: 1.6rem; font-weight: 800; font-variant-numeric: tabular-nums; }
    .kv { display: grid; grid-template-columns: max-content 1fr; gap: .15rem .9rem; font-size: .9rem; }
    .kv dt { color: var(--muted); font-weight: 400; } .kv dd { margin: 0; }
    .opt { border: 1.5px solid var(--line); border-radius: .5rem; padding: .45rem .7rem; margin-bottom: .35rem; background: #fff; }
    .opt.is-correct { border-color: var(--ok); background: var(--ok-l); }
    .opt.is-wrong { border-color: var(--ng); background: var(--ng-l); }
    .opt .exp { display: block; font-size: .85rem; color: var(--muted); margin-top: .2rem; }
    .point { background: var(--action-l); border-radius: .5rem; padding: .6rem .9rem; white-space: pre-wrap; line-height: 1.8; font-size: .9rem; }
    .review-q { border-left: 4px solid var(--line); padding-left: .8rem; margin-bottom: 1rem; }
    .review-q.ok { border-left-color: var(--ok); } .review-q.ng { border-left-color: var(--ng); }
    .trend-bar { height: .7rem; background: var(--line); border-radius: .35rem; overflow: hidden; min-width: 80px; }
    .trend-bar > div { height: 100%; background: var(--action); }
    details > summary { cursor: pointer; color: var(--action); }
    :focus-visible { outline: 2px solid var(--action); outline-offset: 2px; }
  </style>
</head>
<body>
<header class="my-head">
  <div class="wrap py-2 d-flex align-items-center gap-2">
    <i class="bi bi-shield-check fs-5" aria-hidden="true"></i>
    <div class="title">セキュリティ教育 マイページ</div>
    <div class="ms-auto d-flex align-items-center gap-2 d-none" id="headUser">
      <span class="small" id="headName"></span>
      <button class="btn btn-sm btn-outline-light" id="logoutBtn"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> ログアウト</button>
    </div>
  </div>
</header>

<main>
  <div class="wrap" id="loadingView" role="status"><div class="card-t p-4 mt-3 text-center"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> 読み込んでいます</div></div>

  <!-- ログイン -->
  <div class="wrap d-none" id="loginView">
    <form class="card-t p-4 mt-3 mx-auto" style="max-width:440px" id="loginForm" novalidate>
      <h1 class="h5 mb-3">ログイン</h1>
      <div id="loginNotice" class="alert alert-warning py-2 d-none" role="alert"></div>
      <div class="mb-3"><label class="form-label" for="loginEmail">メールアドレス</label>
        <input class="form-control" id="loginEmail" type="email" autocomplete="username" required></div>
      <div class="mb-3"><label class="form-label" for="loginPassword">パスワード</label>
        <input class="form-control" id="loginPassword" type="password" autocomplete="current-password" required></div>
      <div id="loginError" class="alert alert-danger py-2 d-none" role="alert"></div>
      <button class="btn btn-action w-100" type="submit" id="loginBtn">ログイン</button>
      <div class="text-center mt-3"><a href="#" id="toForgot">パスワードを忘れた場合</a></div>
      <p class="small text-muted mt-3 mb-0">初めての方は、招待のメールのリンクからパスワードを設定してください。</p>
    </form>
  </div>

  <!-- パスワードを忘れた場合 -->
  <div class="wrap d-none" id="forgotView">
    <form class="card-t p-4 mt-3 mx-auto" style="max-width:440px" id="forgotForm" novalidate>
      <h1 class="h5 mb-3">パスワードの再設定</h1>
      <p class="small text-muted">ご登録のメールアドレスを入れてください。パスワード再設定のリンクをメールで送ります。</p>
      <div class="mb-3"><label class="form-label" for="forgotEmail">メールアドレス</label>
        <input class="form-control" id="forgotEmail" type="email" autocomplete="username" required></div>
      <div id="forgotMsg" class="alert py-2 d-none" role="status"></div>
      <button class="btn btn-action w-100" type="submit" id="forgotBtn">再設定のメールを送る</button>
      <div class="text-center mt-3"><a href="#" id="backToLogin">ログインに戻る</a></div>
    </form>
  </div>

  <!-- ログイン後 -->
  <div class="wrap d-none" id="appView">
    <ul class="nav nav-tabs nav-my mt-2" role="tablist">
      <li class="nav-item" role="presentation"><button class="nav-link active" data-tab="home" id="tabHome" role="tab" aria-selected="true">ホーム</button></li>
      <li class="nav-item" role="presentation"><button class="nav-link" data-tab="grades" id="tabGrades" role="tab" aria-selected="false">成績</button></li>
      <li class="nav-item" role="presentation"><button class="nav-link" data-tab="surveys" id="tabSurveys" role="tab" aria-selected="false">アンケート</button></li>
      <li class="nav-item" role="presentation"><button class="nav-link" data-tab="password" id="tabPassword" role="tab" aria-selected="false">パスワードの変更</button></li>
    </ul>
    <div id="appError" class="alert alert-danger py-2 mt-3 d-none" role="alert"></div>

    <section class="mt-3" data-panel="home" aria-labelledby="tabHome">
      <h2 class="h6 text-muted mb-2">やること（期限の近い順）</h2>
      <div class="card-t" id="todoList"></div>
    </section>

    <section class="mt-3 d-none" data-panel="grades" aria-labelledby="tabGrades">
      <div class="card-t p-3 mb-3 d-none" id="trendCard">
        <h2 class="h6 mb-2">アウェアネス（小問）の正答率の推移</h2>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
          <thead><tr><th>提出日時</th><th>配信</th><th>正答率</th></tr></thead><tbody id="trendBody"></tbody>
        </table></div>
      </div>
      <div id="gradeList"></div>
    </section>

    <section class="mt-3 d-none" data-panel="surveys" aria-labelledby="tabSurveys">
      <h2 class="h6 text-muted mb-2">未回答</h2>
      <div class="card-t mb-3" id="surveyOpen"></div>
      <h2 class="h6 text-muted mb-2">回答の履歴</h2>
      <div class="card-t" id="surveyHistory"></div>
    </section>

    <section class="mt-3 d-none" data-panel="password" aria-labelledby="tabPassword">
      <form class="card-t p-4" style="max-width:480px" id="pwForm" novalidate>
        <div class="mb-3"><label class="form-label" for="pwCurrent">今のパスワード</label>
          <input class="form-control" id="pwCurrent" type="password" autocomplete="current-password" required></div>
        <div class="mb-3"><label class="form-label" for="pwNew">新しいパスワード</label>
          <input class="form-control" id="pwNew" type="password" autocomplete="new-password" required aria-describedby="pwPolicy">
          <div class="form-text" id="pwPolicy"></div></div>
        <div class="mb-3"><label class="form-label" for="pwConfirm">確認のため、もう一度</label>
          <input class="form-control" id="pwConfirm" type="password" autocomplete="new-password" required></div>
        <div id="pwMsg" class="alert py-2 d-none" role="status"></div>
        <button class="btn btn-action" type="submit" id="pwBtn">パスワードを変更する</button>
        <p class="small text-muted mt-3 mb-0 d-none" id="pwShared">このアカウントは管理画面と共通です。変更すると管理画面のパスワードも変わります。</p>
      </form>
    </section>
  </div>
</main>

<script>
(function () {
  'use strict';
  const API = 'api/my.php';
  const $ = (id) => document.getElementById(id);
  let csrf = '';

  function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function dt(v) { return v ? String(v).slice(0, 16) : ''; }

  async function call(action, { method = 'GET', body } = {}) {
    const opt = { method, credentials: 'same-origin', cache: 'no-store', headers: {} };
    if (method !== 'GET') {
      opt.headers['Content-Type'] = 'application/json';
      if (csrf) opt.headers['X-CSRF-Token'] = csrf;
      opt.body = JSON.stringify(body || {});
    }
    let res;
    try { res = await fetch(API + '?action=' + action, opt); }
    catch (_) { throw Object.assign(new Error('通信に失敗しました。時間をおいてもう一度お試しください。'), { status: 0 }); }
    let data;
    try { data = await res.json(); } catch (_) { data = { success: false, error: 'サーバーの応答を読めませんでした。' }; }
    if (!res.ok || !data.success) throw Object.assign(new Error(data.error || 'エラーが発生しました。'), { status: res.status });
    return data;
  }

  function show(view) {
    ['loadingView', 'loginView', 'forgotView', 'appView'].forEach((v) => $(v).classList.toggle('d-none', v !== view));
  }
  function alertBox(el, kind, text) {
    el.className = 'alert py-2 alert-' + kind;
    el.textContent = text;
  }
  function onAuthLost(err) {
    if (err.status === 401) { csrf = ''; $('headUser').classList.add('d-none'); showLogin(err.message); return true; }
    return false;
  }

  function showLogin(notice) {
    const n = $('loginNotice');
    n.classList.toggle('d-none', !notice);
    n.textContent = notice || '';
    show('loginView');
    $('loginEmail').focus();
  }

  function enterApp(user, policy) {
    $('headName').textContent = user.name || user.email;
    $('headUser').classList.remove('d-none');
    $('pwPolicy').textContent = policy || '';
    $('pwShared').classList.toggle('d-none', !user.is_admin_account);
    show('appView');
    openTab('home');
  }

  // ---- タブ ----
  const loaders = { home: loadHome, grades: loadGrades, surveys: loadSurveys, password: async () => {} };
  function openTab(name) {
    document.querySelectorAll('[data-tab]').forEach((b) => {
      const on = b.dataset.tab === name;
      b.classList.toggle('active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    document.querySelectorAll('[data-panel]').forEach((p) => p.classList.toggle('d-none', p.dataset.panel !== name));
    $('appError').classList.add('d-none');
    loaders[name]().catch((err) => {
      if (onAuthLost(err)) return;
      $('appError').textContent = err.message;
      $('appError').classList.remove('d-none');
    });
  }
  document.querySelectorAll('[data-tab]').forEach((b) => b.addEventListener('click', () => openTab(b.dataset.tab)));

  function loading(el) { el.innerHTML = '<div class="p-3 text-muted small" role="status"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> 読み込んでいます</div>'; }
  function empty(text) { return `<div class="p-3 text-muted small">${esc(text)}</div>`; }

  // ---- ホーム(L3) ----
  const EDU_STATUS = { assigned: '未受講', started: '受講中', completed: '完了' };
  function deadlineText(d) {
    if (!d) return '期限なし';
    const left = (new Date(String(d).replace(' ', 'T')) - new Date()) / 86400000;
    return `期限 ${dt(d)}` + (left >= 0 && left < 3 ? '（まもなく）' : '');
  }
  async function loadHome() {
    const box = $('todoList');
    loading(box);
    const data = await call('home');
    if (!data.todos.length) { box.innerHTML = empty('今やることはありません。'); return; }
    box.innerHTML = data.todos.map((t) => {
      const isEdu = t.kind === 'edu';
      const label = isEdu ? (t.status === 'started' ? '続ける' : '受講する') : '回答する';
      const soon = t.deadline && (new Date(String(t.deadline).replace(' ', 'T')) - new Date()) < 3 * 86400000;
      return `<div class="todo" data-kind="${esc(t.kind)}">
        <div class="flex-grow-1">
          <div><span class="pill ${isEdu ? 'act' : ''}">${isEdu ? (t.delivery_type === 'awareness_quiz' ? 'アウェアネス' : '教育') : 'アンケート'}</span>
            <span class="ms-1 small text-muted">${isEdu ? esc(EDU_STATUS[t.status] || t.status) : '未回答'}</span></div>
          <div class="fw-bold mt-1">${esc(t.title)}</div>
          <div class="meta ${soon ? 'soon' : ''}">${esc(deadlineText(t.deadline))}</div>
        </div>
        <a class="btn btn-action btn-sm text-nowrap" href="${esc(t.url)}">${label}</a>
      </div>`;
    }).join('');
  }

  // ---- 成績(L4、L5) ----
  function passPill(g) {
    if (g.passed === true) return '<span class="pill ok">合格</span>';
    if (g.passed === false) return '<span class="pill ng">不合格</span>';
    return '';
  }
  function statusPill(g) {
    if (g.retaking) return '<span class="pill act">受け直し中</span>';
    const cls = g.status === 'completed' ? 'ok' : (g.status === 'started' ? 'act' : '');
    return `<span class="pill ${cls}">${esc(EDU_STATUS[g.status] || g.status)}</span>`;
  }
  function reviewHtml(review) {
    return review.map((q, i) => {
      const opts = (q.options || []).map((o, j) => {
        const mine = q.your_answer.includes(j);
        const right = q.correct_answer.includes(j);
        const cls = right ? 'is-correct' : (mine ? 'is-wrong' : '');
        const tags = [mine ? 'あなたの解答' : '', right ? '正解' : ''].filter(Boolean).join('・');
        const exp = q.option_explanations && q.option_explanations[j] ? `<span class="exp">${esc(q.option_explanations[j])}</span>` : '';
        return `<div class="opt ${cls}">${esc(o)}${tags ? ` <span class="small fw-bold">（${tags}）</span>` : ''}${exp}</div>`;
      }).join('');
      return `<div class="review-q ${q.is_correct ? 'ok' : 'ng'}">
        <div class="fw-bold mb-1">問${i + 1} <span class="${q.is_correct ? 'text-success' : 'text-danger'}">${q.is_correct ? '正解' : '不正解'}</span></div>
        <div class="mb-2">${esc(q.title)}</div>${opts}
        ${q.explanation ? `<div class="point mt-2">${esc(q.explanation)}</div>` : ''}
      </div>`;
    }).join('');
  }
  async function loadGrades() {
    const box = $('gradeList');
    loading(box);
    const data = await call('grades');
    const trend = data.awareness_trend || [];
    $('trendCard').classList.toggle('d-none', !trend.length);
    $('trendBody').innerHTML = trend.map((p) => `<tr><td class="text-nowrap small">${esc(dt(p.completed_at))}</td>
      <td class="small">${esc(p.title)}${p.attempt_no > 1 ? `（${p.attempt_no}回目）` : ''}</td>
      <td style="min-width:140px"><div class="d-flex align-items-center gap-2"><div class="trend-bar flex-grow-1" role="img" aria-label="正答率 ${p.percentage}%"><div style="width:${Math.max(0, Math.min(100, p.percentage))}%"></div></div><span class="small">${p.percentage}%</span></div></td></tr>`).join('');
    if (!data.deliveries.length) { box.innerHTML = `<div class="card-t">${empty('まだ教育の配信はありません。')}</div>`; return; }
    box.innerHTML = data.deliveries.map((g) => {
      const typeLabel = g.delivery_type === 'awareness_quiz' ? 'アウェアネス' : '教育';
      const actions = [];
      if (g.take_url) actions.push(`<a class="btn btn-action btn-sm" href="${esc(g.take_url)}">${g.retaking ? '受け直しを続ける' : (g.status === 'started' ? '続ける' : '受講する')}</a>`);
      if (g.can_retake) actions.push(`<button class="btn btn-outline-secondary btn-sm" data-retake="${g.delivery_id}"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> もう一度受講する</button>`);
      const blocked = g.retake_block_reason ? `<div class="small text-muted mt-1">${esc(g.retake_block_reason)}</div>` : '';
      const attempts = g.attempts.filter((a) => a.completed_at);
      const attemptRows = attempts.map((a) => `<tr><td>${a.attempt_no}回目${a.is_retake ? '（受け直し）' : ''}</td><td>${esc(dt(a.started_at))}</td><td>${esc(dt(a.completed_at))}</td>
        <td>${a.percentage ?? ''}${a.percentage !== null ? '%' : ''}</td><td>${a.passed === true ? '合格' : (a.passed === false ? '不合格' : '')}</td></tr>`).join('');
      const review = g.review ? `<details class="mt-2"><summary>問題ごとの解答と解説（最新の回）</summary><div class="mt-2">${reviewHtml(g.review)}</div></details>`
        : (g.review_hidden ? '<div class="small text-muted mt-2">この配信は、答え合わせを表示しない設定です。</div>' : '');
      return `<article class="card-t p-3 mb-3" data-delivery="${g.delivery_id}">
        <div class="d-flex flex-wrap align-items-start gap-2">
          <div class="flex-grow-1">
            <div><span class="pill">${typeLabel}</span> ${statusPill(g)} ${passPill(g)}</div>
            <h3 class="h6 fw-bold mt-2 mb-1">${esc(g.title)}</h3>
          </div>
          <div class="text-end"><div class="score">${g.score !== null ? esc(g.score) + '<span class="fs-6">%</span>' : '—'}</div>
            ${g.pass_score !== null ? `<div class="small text-muted">合格点 ${g.pass_score}%</div>` : ''}</div>
        </div>
        <dl class="kv mt-2 mb-0">
          <dt>受講の開始</dt><dd>${esc(dt(g.started_at)) || '—'}</dd>
          <dt>完了</dt><dd>${esc(dt(g.completed_at)) || '—'}</dd>
          <dt>受講回数</dt><dd class="attempt-count">${g.attempt_count}回</dd>
          <dt>期限</dt><dd>${g.deadline ? esc(dt(g.deadline)) + (g.expired ? '（期限切れ）' : '') : '期限なし'}</dd>
        </dl>
        ${actions.length ? `<div class="d-flex gap-2 flex-wrap mt-2">${actions.join('')}</div>` : ''}${blocked}
        ${attempts.length > 1 ? `<details class="mt-2"><summary>回ごとの結果（${attempts.length}回）</summary>
          <div class="table-responsive"><table class="table table-sm mt-2 mb-0"><thead><tr><th>回</th><th>開始</th><th>提出</th><th>点数</th><th>合否</th></tr></thead><tbody>${attemptRows}</tbody></table></div></details>` : ''}
        ${review}
      </article>`;
    }).join('');
    box.querySelectorAll('[data-retake]').forEach((b) => b.addEventListener('click', () => retake(b, Number(b.dataset.retake))));
  }
  async function retake(btn, deliveryId) {
    if (!confirm('この教育をもう一度受講しますか？\n前の回の結果は残ります。提出すると、新しい回の結果が最新の成績になります。')) return;
    btn.disabled = true;
    try {
      const data = await call('retake', { method: 'POST', body: { delivery_id: deliveryId } });
      window.location.href = data.url;
    } catch (err) {
      btn.disabled = false;
      if (onAuthLost(err)) return;
      $('appError').textContent = err.message;
      $('appError').classList.remove('d-none');
    }
  }

  // ---- アンケート(L7) ----
  async function loadSurveys() {
    loading($('surveyOpen'));
    $('surveyHistory').innerHTML = '';
    const data = await call('surveys');
    $('surveyOpen').innerHTML = data.open.length ? data.open.map((s) => `<div class="todo">
        <div class="flex-grow-1"><div class="fw-bold">${esc(s.title)}</div><div class="meta">${esc(deadlineText(s.deadline))}</div></div>
        <a class="btn btn-action btn-sm text-nowrap" href="${esc(s.url)}">回答する</a></div>`).join('') : empty('未回答のアンケートはありません。');
    $('surveyHistory').innerHTML = data.history.length ? data.history.map((h) => {
      const answers = h.is_anonymous
        ? '<div class="small text-muted">匿名のアンケートのため、回答の内容は表示しません。</div>'
        : (h.answers && h.answers.length ? `<details><summary>自分の回答</summary><dl class="kv mt-2">${h.answers.map((a) => `<dt>${esc(a.question)}</dt><dd>${esc(a.answer) || '（無回答）'}</dd>`).join('')}</dl></details>` : '');
      return `<div class="todo d-block" data-history><div class="fw-bold">${esc(h.title)} ${h.is_anonymous ? '<span class="pill">匿名</span>' : ''}</div>
        <div class="meta">回答日時 ${esc(h.is_anonymous ? String(h.answered_at).slice(0, 10) : dt(h.answered_at))}</div>${answers}</div>`;
    }).join('') : empty('まだ回答したアンケートはありません。');
  }

  // ---- ログイン、ログアウト、パスワード ----
  $('loginForm').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const err = $('loginError');
    err.classList.add('d-none');
    $('loginBtn').disabled = true;
    try {
      const data = await call('login', { method: 'POST', body: { email: $('loginEmail').value.trim(), password: $('loginPassword').value } });
      csrf = data.csrf;
      $('loginPassword').value = '';
      $('loginNotice').classList.add('d-none');
      enterApp(data.user, data.password_policy);
    } catch (e) {
      err.textContent = e.message;
      err.classList.remove('d-none');
    } finally { $('loginBtn').disabled = false; }
  });
  $('logoutBtn').addEventListener('click', async () => {
    try { await call('logout', { method: 'POST' }); } catch (_) { /* セッションが切れていてもログインへ戻す */ }
    csrf = '';
    $('headUser').classList.add('d-none');
    showLogin('ログアウトしました。');
  });
  $('toForgot').addEventListener('click', (ev) => { ev.preventDefault(); $('forgotMsg').classList.add('d-none'); show('forgotView'); $('forgotEmail').focus(); });
  $('backToLogin').addEventListener('click', (ev) => { ev.preventDefault(); showLogin(''); });
  $('forgotForm').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    $('forgotBtn').disabled = true;
    try {
      const data = await call('forgot', { method: 'POST', body: { email: $('forgotEmail').value.trim() } });
      alertBox($('forgotMsg'), 'success', data.message);
    } catch (e) { alertBox($('forgotMsg'), 'danger', e.message); }
    finally { $('forgotBtn').disabled = false; }
  });
  $('pwForm').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const msg = $('pwMsg');
    if ($('pwNew').value !== $('pwConfirm').value) { alertBox(msg, 'danger', '確認のパスワードが一致しません。'); return; }
    $('pwBtn').disabled = true;
    try {
      const data = await call('change_password', { method: 'POST', body: { current_password: $('pwCurrent').value, new_password: $('pwNew').value } });
      csrf = data.csrf || csrf;
      ['pwCurrent', 'pwNew', 'pwConfirm'].forEach((id) => { $(id).value = ''; });
      alertBox(msg, 'success', 'パスワードを変更しました。ほかの端末のログインは切れます。');
    } catch (e) {
      if (!onAuthLost(e)) alertBox(msg, 'danger', e.message);
    } finally { $('pwBtn').disabled = false; }
  });

  // ---- 起動 ----
  call('me').then((data) => {
    if (!data.user) { showLogin(data.notice || ''); return; }
    csrf = data.csrf;
    enterApp(data.user, data.password_policy);
  }).catch((e) => showLogin(e.message));
})();
</script>
</body>
</html>

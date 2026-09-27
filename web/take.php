<?php declare(strict_types=1);
/**
 * 受講者向け 公開ページ(認証不要・トークン方式)。
 * /tet2/take.php?token=<32桁hex>
 *
 * 流れ: 開始 → 教材(PDF のページ画像のビューアか、文字のスライド) → 確認テスト → 結果と振り返り。
 * 確認テストは配信の feedback_mode で2通り:
 *   - immediate   : 1問ごとに「答え合わせ」して正誤と選択肢ごとの解説を見てから次へ(08 設計書の G18)
 *   - after_submit: 全問に答えてから提出し、結果でまとめて解説を見る(従来の形)
 * 画像(教材のページ、設問の画像)は edu_take.php がトークンを確かめてから返す。
 * ロジックは edu_take.php API を叩く。ここでは token を JS に渡す土台だけを描画する。
 */
$token = isset($_GET['token']) && is_string($_GET['token']) ? trim($_GET['token']) : '';
$tokenValid = (bool) preg_match('/^[0-9a-f]{32}$/', $token);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>セキュリティ教育</title>
  <link href="assets/vendor/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root { --ink:#19324A; --action:#146C78; --action-l:#E1F0F1; --canvas:#F5F7FA; --text:#243746;
            --ok:#1E7B4E; --ok-l:#E6F4EC; --ng:#B42318; --ng-l:#FCEBE9; --line:#D7DEE5; }
    body { background: var(--canvas); color: var(--text); }
    .take-head { background: var(--ink); color: #fff; }
    .take-head .title { font-weight: 700; font-size: 1rem; line-height: 1.4; }
    .wrap { max-width: 880px; margin: 0 auto; padding: 1rem; }
    .wrap-wide { max-width: 1320px; margin: 0 auto; padding: .75rem 1rem 1rem; }
    .card-t { background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(25,50,74,.10); }
    .btn-action { background: var(--action); border-color: var(--action); color: #fff; }
    .btn-action:hover, .btn-action:focus { background: #0f5761; border-color: #0f5761; color: #fff; }
    .btn-action:disabled { background: #9DBFC3; border-color: #9DBFC3; }
    .bar { height: 6px; background: var(--line); border-radius: 3px; overflow: hidden; }
    .bar > div { height: 100%; background: var(--action); transition: width .25s; }
    /* 教材のビューア */
    .viewer-stage { background: #E9EDF2; border-radius: .75rem; overflow: auto; position: relative;
                    height: calc(100vh - 210px); min-height: 320px; display: flex; align-items: center; justify-content: center; }
    .viewer-stage:fullscreen { border-radius: 0; height: 100vh; background: #2B3440; }
    .viewer-pages { display: flex; gap: 6px; align-items: center; justify-content: center; transform-origin: top center; }
    .viewer-pages img { display: block; max-height: calc(100vh - 230px); max-width: 100%; background: #fff;
                        box-shadow: 0 2px 10px rgba(0,0,0,.18); }
    .viewer-stage:fullscreen .viewer-pages img { max-height: 96vh; }
    .viewer-pages.two img { max-width: 49%; }
    .viewer-pages.zoomed img { max-height: none; max-width: none; }
    .viewer-bar { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
    .viewer-bar .page-label { min-width: 5.5rem; text-align: center; font-variant-numeric: tabular-nums; }
    .page-text { white-space: pre-wrap; font-size: .9rem; line-height: 1.8; max-height: 40vh; overflow: auto; }
    .lesson-body { white-space: pre-wrap; line-height: 1.9; }
    /* 設問 */
    .q-no { color: var(--action); font-weight: 700; }
    .q-title { font-weight: 700; font-size: 1.05rem; line-height: 1.7; }
    .q-image { max-width: 100%; max-height: 360px; border-radius: .5rem; border: 1px solid var(--line); background: #fff; }
    .opt { display: flex; gap: .75rem; align-items: flex-start; width: 100%; text-align: left; white-space: normal;
           border: 1.5px solid var(--line); border-radius: .6rem; background: #fff; padding: .75rem .9rem; color: var(--text); }
    .opt:hover:not(:disabled):not(.static) { border-color: var(--action); }
    .opt.selected { border-color: var(--action); background: var(--action-l); }
    .opt .num { flex: 0 0 1.8rem; height: 1.8rem; border-radius: 50%; border: 1.5px solid var(--line);
                display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: .85rem; }
    .opt.selected .num { background: var(--action); border-color: var(--action); color: #fff; }
    .opt.is-correct { border-color: var(--ok); background: var(--ok-l); }
    .opt.is-wrong { border-color: var(--ng); background: var(--ng-l); }
    .opt .exp { display: block; font-size: .85rem; color: #4A5A6A; margin-top: .35rem; }
    .verdict { font-size: 1.6rem; font-weight: 800; }
    .verdict.ok { color: var(--ok); } .verdict.ng { color: var(--ng); }
    .point { background: var(--action-l); border-radius: .5rem; padding: .75rem 1rem; white-space: pre-wrap; line-height: 1.8; }
    .score-ring { width: 140px; height: 140px; border-radius: 50%; margin: 0 auto; display: flex; align-items: center; justify-content: center;
                  background: conic-gradient(var(--action) calc(var(--pct) * 1%), var(--line) 0); }
    .score-ring > div { width: 112px; height: 112px; border-radius: 50%; background: #fff; display: flex; flex-direction: column;
                        align-items: center; justify-content: center; }
    .review-card { border-left: 4px solid var(--line); }
    .review-card.ok { border-left-color: var(--ok); } .review-card.ng { border-left-color: var(--ng); }
    @media (max-width: 575px) { .viewer-stage { height: calc(100vh - 240px); } .viewer-pages img { max-height: calc(100vh - 260px); } }
  </style>
</head>
<body>
<header class="take-head">
  <div class="wrap py-2 d-flex align-items-center gap-2">
    <i class="bi bi-shield-check fs-5"></i>
    <div class="title" id="headTitle">セキュリティ教育</div>
  </div>
</header>

<?php if (!$tokenValid): ?>
  <div class="wrap"><div class="card-t p-4 mt-4 text-center">
    <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size:2.5rem"></i>
    <h5 class="mt-3">受講リンクが正しくありません</h5>
    <p class="text-muted mb-0">案内に記載されたリンクからもう一度お開きください。</p>
  </div></div>
<?php else: ?>

  <!-- 開始 -->
  <div class="wrap d-none" id="landingView">
    <div class="card-t p-4 mt-3">
      <h4 class="mb-3" id="landingTitle"></h4>
      <ul class="list-unstyled mb-3" id="landingMeta"></ul>
      <p class="small text-muted mb-3" id="landingNote"></p>
      <button class="btn btn-action w-100 py-2" id="startBtn"><i class="bi bi-play-fill"></i> <span id="startLabel">はじめる</span></button>
    </div>
  </div>

  <!-- 教材(ページ画像) -->
  <div class="wrap-wide d-none" id="pagesView">
    <div class="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap">
      <span class="badge text-bg-light border"><i class="bi bi-book"></i> 教材</span>
      <button class="btn btn-sm btn-outline-secondary" id="textToggle"><i class="bi bi-card-text"></i> 文字で読む</button>
    </div>
    <div class="viewer-stage" id="stage" tabindex="0" aria-label="教材のページ">
      <div class="viewer-pages" id="pageBox"></div>
    </div>
    <div class="card-t p-3 mt-2 d-none page-text" id="pageText"></div>
    <div class="bar mt-2"><div id="pageBar" style="width:0%"></div></div>
    <div class="viewer-bar mt-2">
      <button class="btn btn-outline-secondary" id="pagePrev" aria-label="前のページ"><i class="bi bi-chevron-left"></i></button>
      <span class="page-label" id="pageLabel"></span>
      <button class="btn btn-outline-secondary" id="pageNext" aria-label="次のページ"><i class="bi bi-chevron-right"></i></button>
      <div class="ms-auto d-flex gap-2">
        <button class="btn btn-outline-secondary" id="zoomOut" aria-label="縮小"><i class="bi bi-zoom-out"></i></button>
        <button class="btn btn-outline-secondary" id="zoomIn" aria-label="拡大"><i class="bi bi-zoom-in"></i></button>
        <button class="btn btn-outline-secondary" id="fullBtn" aria-label="全画面"><i class="bi bi-arrows-fullscreen"></i></button>
        <button class="btn btn-action" id="toQuiz" disabled>確認テストへ <i class="bi bi-arrow-right"></i></button>
      </div>
    </div>
  </div>

  <!-- 教材(文字のスライド。従来の教材) -->
  <div class="wrap d-none" id="lessonView">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <span class="small text-muted" id="lessonProgress"></span>
      <span class="badge text-bg-light border"><i class="bi bi-book"></i> 教材</span>
    </div>
    <div class="bar mb-3"><div id="lessonBar" style="width:0%"></div></div>
    <div class="card-t p-4"><h4 id="lessonTitle"></h4><div class="lesson-body mt-3" id="lessonBody"></div></div>
    <div class="d-flex justify-content-between mt-3">
      <button class="btn btn-outline-secondary" id="lessonPrev"><i class="bi bi-chevron-left"></i> 前へ</button>
      <button class="btn btn-action" id="lessonNext">次へ <i class="bi bi-chevron-right"></i></button>
    </div>
  </div>

  <!-- 確認テスト -->
  <div class="wrap d-none" id="quizView">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <span class="small text-muted" id="qProgress"></span>
      <button class="btn btn-sm btn-link text-decoration-none d-none" id="backToLesson"><i class="bi bi-book"></i> 教材を見直す</button>
    </div>
    <div class="bar mb-3"><div id="qBar" style="width:0%"></div></div>
    <div class="card-t p-4">
      <div class="d-none mb-3" id="qFeedback">
        <div class="verdict" id="qVerdict"></div>
      </div>
      <div class="q-no mb-1" id="qNo"></div>
      <div class="q-title mb-3" id="qTitle"></div>
      <div class="mb-3 text-center d-none" id="qImageBox"><img class="q-image" id="qImage" alt=""></div>
      <div class="small text-muted mb-2" id="qHint"></div>
      <div class="d-grid gap-2" id="qOptions"></div>
      <div class="point mt-3 d-none" id="qPoint"></div>
    </div>
    <div class="d-flex justify-content-between mt-3 gap-2">
      <button class="btn btn-outline-secondary" id="qPrev"><i class="bi bi-chevron-left"></i> 前へ</button>
      <div class="d-flex gap-2">
        <button class="btn btn-action px-4" id="qCheck" disabled><span class="spinner-border spinner-border-sm d-none" id="qCheckSpin"></span> 答え合わせ</button>
        <button class="btn btn-action px-4 d-none" id="qNext">次へ <i class="bi bi-chevron-right"></i></button>
        <button class="btn btn-action px-4 d-none" id="qSubmit"><span class="spinner-border spinner-border-sm d-none" id="qSubmitSpin"></span> 結果を見る</button>
      </div>
    </div>
  </div>

  <!-- 結果と振り返り -->
  <div class="wrap d-none" id="resultView">
    <div class="card-t p-4 text-center mb-3">
      <div class="score-ring" id="scoreRing" style="--pct:0"><div><div class="fs-2 fw-bold" id="rPct"></div><div class="small text-muted">正答率</div></div></div>
      <div class="mt-3" id="rBadge"></div>
      <div class="text-muted mt-1" id="rCount"></div>
      <div class="d-flex gap-2 justify-content-center mt-3 flex-wrap">
        <button class="btn btn-outline-secondary d-none" id="rLesson"><i class="bi bi-book"></i> 教材を見直す</button>
        <button class="btn btn-action d-none" id="rRetry"><i class="bi bi-arrow-repeat"></i> もう一度受講する</button>
      </div>
    </div>
    <h6 class="fw-bold mb-2"><i class="bi bi-list-check"></i> 振り返り</h6>
    <div id="reviewList"></div>
  </div>

  <!-- エラー -->
  <div class="wrap d-none" id="errorView"><div class="card-t p-4 mt-4 text-center">
    <i class="bi bi-exclamation-circle-fill text-danger" style="font-size:2.5rem"></i>
    <h5 class="mt-3" id="errorMsg">エラーが発生しました</h5>
  </div></div>

<?php endif; ?>

<script src="assets/vendor/bootstrap.bundle.min.js"></script>
<?php if ($tokenValid): ?>
<script>
(function () {
  const TOKEN = <?= json_encode($token) ?>;
  const API = 'api/edu_take.php';
  const VIEWS = ['landingView', 'pagesView', 'lessonView', 'quizView', 'resultView', 'errorView'];
  const $ = (id) => document.getElementById(id);
  const show = (id) => $(id).classList.remove('d-none');
  const hide = (id) => $(id).classList.add('d-none');
  const only = (id) => { VIEWS.forEach(hide); show(id); window.scrollTo(0, 0); };
  const esc = (s) => String(s == null ? '' : s).replaceAll('&', '&amp;').replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#39;');
  const pageUrl = (no) => `${API}?action=page_image&token=${encodeURIComponent(TOKEN)}&page=${no}&v=${encodeURIComponent((material && material.rev) || '')}`;
  const qImageUrl = (id) => `${API}?action=question_image&token=${encodeURIComponent(TOKEN)}&question_id=${id}`;

  let delivery = {};
  let material = null;
  let questions = [];
  const answers = {};    // question_id -> [index]
  const feedbacks = {};  // question_id -> 答え合わせの結果(immediate)
  let cur = 0;
  let spread = 0;        // 教材のビューアで表示中の組(見開きなら2ページ)
  let firstPage = 1;     // 表示中の先頭ページ。画面の幅で見開きと1ページが切り替わっても同じページを表示する
  let zoom = 1;
  let reachedEnd = false;
  let lessonCur = 0;

  function fail(msg) { $('errorMsg').textContent = msg || 'エラーが発生しました'; only('errorView'); }

  async function api(action, body) {
    const opt = body ? { method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ token: TOKEN }, body)) } : {};
    const url = body ? `${API}?action=${action}` : `${API}?action=${action}&token=${encodeURIComponent(TOKEN)}`;
    const r = await fetch(url, opt);
    const j = await r.json();
    if (!j.success) throw new Error(j.error || 'エラーが発生しました');
    return j;
  }

  // ---------------------------------------------------------------- 開始
  async function start() {
    try {
      const j = await api('start');
      delivery = j.delivery;
      material = j.material;
      questions = j.questions;
      (j.answered || []).forEach((a) => { feedbacks[a.question_id] = a.feedback; answers[a.question_id] = a.feedback.your_answer; });
      $('headTitle').textContent = delivery.title || 'セキュリティ教育';
      $('landingTitle').textContent = delivery.title || 'セキュリティ教育';
      const meta = [];
      if (material) {
        const n = material.format === 'page_images' ? material.pages.length : material.slides.length;
        meta.push(`<li class="mb-1"><i class="bi bi-book text-secondary"></i> 教材「${esc(material.title)}」 ${n} ページ</li>`);
      }
      meta.push(`<li class="mb-1"><i class="bi bi-patch-question text-secondary"></i> 確認テスト ${questions.length} 問`
        + (delivery.pass_score && delivery.delivery_type === 'elearning' ? `（合格は ${delivery.pass_score}% 以上）` : '') + '</li>');
      $('landingMeta').innerHTML = meta.join('');
      $('landingNote').textContent = delivery.feedback_mode === 'immediate'
        ? '1問ずつ「答え合わせ」をして、正解と解説を確かめながら進みます。'
        : 'すべての問題に答えてから「結果を見る」を押すと、正解と解説がまとめて表示されます。';
      const answeredCount = Object.keys(feedbacks).length;
      $('startLabel').textContent = answeredCount ? `続きから（${answeredCount} 問 回答済み）` : 'はじめる';
      only('landingView');
    } catch (e) { fail(e.message || '通信に失敗しました'); }
  }

  function begin() {
    if (Object.keys(feedbacks).length) { openQuiz(firstUnanswered()); return; }
    if (material && material.format === 'page_images' && material.pages.length) { openPages(); return; }
    if (material && material.slides && material.slides.length) { lessonCur = 0; only('lessonView'); renderLesson(); return; }
    openQuiz(0);
  }

  // ---------------------------------------------------------------- 教材のビューア(ページ画像)
  function isTwoUp() {
    const p = material.pages[0];
    return window.innerWidth >= 992 && p && p.height > p.width;   // 縦長のページは広い画面で見開き表示
  }
  function groups() {
    const n = material.pages.length;
    const out = [];
    if (!isTwoUp()) { for (let i = 1; i <= n; i++) out.push([i]); return out; }
    // 印刷された本と同じく、1ページ目(表紙)は単独で見せ、2ページ目から左右の見開きに組む
    out.push([1]);
    for (let i = 2; i <= n; i += 2) out.push(i + 1 <= n ? [i, i + 1] : [i]);
    return out;
  }
  function openPages() { spread = 0; firstPage = 1; zoom = 1; only('pagesView'); renderPages(); $('stage').focus(); }
  function renderPages() {
    const gs = groups();
    const at = gs.findIndex((grp) => grp.includes(firstPage));
    spread = at === -1 ? Math.min(spread, gs.length - 1) : at;
    const g = gs[spread];
    if (!g.includes(firstPage)) firstPage = g[0];   // 見開きに組み直しても、見ていたページを覚えておく(1ページ表示へ戻すとそのページを出す)
    const box = $('pageBox');
    box.className = 'viewer-pages' + (g.length === 2 ? ' two' : '') + (zoom > 1 ? ' zoomed' : '');
    box.innerHTML = '';
    g.forEach((no) => {
      const page = material.pages[no - 1];
      const img = document.createElement('img');
      img.src = pageUrl(no);
      img.alt = page.page_text ? page.page_text.slice(0, 300) : `${no} ページ`;
      if (zoom > 1) { img.style.width = `${(g.length === 2 ? 48 : 90) * zoom}vw`; }
      box.appendChild(img);
    });
    // 次の組を先に読み込む
    (gs[spread + 1] || []).forEach((no) => { const pre = new Image(); pre.src = pageUrl(no); });
    const total = material.pages.length;
    const last = g[g.length - 1];
    $('pageLabel').textContent = g.length === 2 ? `${g[0]}-${g[1]} / ${total}` : `${g[0]} / ${total}`;
    $('pageBar').style.width = `${Math.round(last / total * 100)}%`;
    $('pagePrev').disabled = spread === 0;
    $('pageNext').disabled = spread === gs.length - 1;
    if (last === total) reachedEnd = true;
    $('toQuiz').disabled = !reachedEnd || questions.length === 0;
    $('pageText').textContent = g.map((no) => `【${no} ページ】\n${material.pages[no - 1].page_text || '（文字のないページ）'}`).join('\n\n');
  }
  function movePage(d) {
    const gs = groups();
    const next = spread + d;
    if (next < 0 || next >= gs.length) return;
    spread = next; firstPage = gs[next][0]; zoom = 1; renderPages();
    $('stage').scrollTo(0, 0);
  }

  // ---------------------------------------------------------------- 教材(文字のスライド)
  function renderLesson() {
    const slides = material.slides;
    const slide = slides[lessonCur];
    $('lessonProgress').textContent = `${lessonCur + 1} / ${slides.length}`;
    $('lessonBar').style.width = `${Math.round((lessonCur + 1) / slides.length * 100)}%`;
    $('lessonTitle').textContent = slide.title;
    $('lessonBody').textContent = slide.body;
    $('lessonPrev').disabled = lessonCur === 0;
    $('lessonNext').innerHTML = lessonCur === slides.length - 1
      ? '確認テストへ <i class="bi bi-arrow-right"></i>' : '次へ <i class="bi bi-chevron-right"></i>';
  }

  // ---------------------------------------------------------------- 確認テスト
  const immediate = () => delivery.feedback_mode === 'immediate';
  const firstUnanswered = () => { const i = questions.findIndex((q) => !feedbacks[q.id]); return i === -1 ? questions.length - 1 : i; };

  function openQuiz(index) {
    if (!questions.length) { fail('出題する設問がありません'); return; }
    cur = index; only('quizView');
    $('backToLesson').classList.toggle('d-none', !material);
    renderQuestion();
  }

  function renderQuestion() {
    const q = questions[cur];
    const total = questions.length;
    const fb = immediate() ? feedbacks[q.id] : null;
    $('qProgress').textContent = `${cur + 1} / ${total} 問`;
    $('qBar').style.width = `${Math.round((cur + (fb ? 1 : 0)) / total * 100)}%`;
    $('qNo').textContent = `問題 ${cur + 1}`;
    $('qTitle').textContent = q.title;
    if (q.has_image) { $('qImage').src = qImageUrl(q.id); $('qImage').alt = '問題の場面の画像'; show('qImageBox'); }
    else { hide('qImageBox'); $('qImage').removeAttribute('src'); }
    const multi = q.question_type === 'multiple_choice';
    $('qHint').textContent = fb ? '' : (multi ? '当てはまるものをすべて選んでください。' : '1つ選んでください。');

    const box = $('qOptions');
    box.innerHTML = '';
    const selected = answers[q.id] || [];
    q.options.forEach((text, i) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'opt';
      b.innerHTML = `<span class="num">${i + 1}</span><span class="flex-grow-1"><span class="txt"></span></span>`;
      b.querySelector('.txt').textContent = text;
      if (fb) {
        b.disabled = true;
        const correct = fb.correct_answer.includes(i);
        const chosen = fb.your_answer.includes(i);
        if (correct) b.classList.add('is-correct');
        else if (chosen) b.classList.add('is-wrong');
        const label = correct ? '正解' : (chosen ? 'あなたの回答' : '');
        const exp = fb.option_explanations ? fb.option_explanations[i] : '';
        if (label || exp) {
          const e = document.createElement('span');
          e.className = 'exp';
          e.innerHTML = (label ? `<strong>${label}${chosen && correct ? '（あなたの回答）' : ''}</strong> ` : '') + esc(exp || '');
          b.querySelector('.flex-grow-1').appendChild(e);
        }
      } else {
        if (selected.includes(i)) b.classList.add('selected');
        b.addEventListener('click', () => {
          answers[q.id] = multi ? (selected.includes(i) ? selected.filter((x) => x !== i) : [...selected, i]) : [i];
          renderQuestion();
        });
      }
      box.appendChild(b);
    });

    // 答え合わせの結果
    if (fb) {
      $('qVerdict').className = 'verdict ' + (fb.is_correct ? 'ok' : 'ng');
      $('qVerdict').innerHTML = fb.is_correct ? '<i class="bi bi-check-circle-fill"></i> 正解' : '<i class="bi bi-x-circle-fill"></i> 不正解';
      $('qPoint').innerHTML = fb.explanation ? `<strong><i class="bi bi-lightbulb"></i> ポイント</strong>\n${esc(fb.explanation)}` : '';
      $('qPoint').classList.toggle('d-none', !fb.explanation);
      show('qFeedback');
    } else {
      hide('qFeedback');
      hide('qPoint');
    }

    const last = cur === total - 1;
    $('qPrev').disabled = cur === 0;
    if (immediate()) {
      $('qCheck').classList.toggle('d-none', !!fb);
      $('qCheck').disabled = !(answers[q.id] || []).length;
      $('qNext').classList.toggle('d-none', !fb || last);
      $('qSubmit').classList.toggle('d-none', !fb || !last);
    } else {
      $('qCheck').classList.add('d-none');
      $('qNext').classList.toggle('d-none', last);
      $('qSubmit').classList.toggle('d-none', !last);
    }
  }

  async function check() {
    const q = questions[cur];
    $('qCheckSpin').classList.remove('d-none'); $('qCheck').disabled = true;
    try {
      const j = await api('answer', { question_id: q.id, answer: answers[q.id] || [] });
      feedbacks[q.id] = j.feedback;
      answers[q.id] = j.feedback.your_answer;
      renderQuestion();
      $('quizView').scrollIntoView({ block: 'start' });   // 判定を画面の上に見せる
    } catch (e) { alert(e.message); $('qCheck').disabled = false; }
    finally { $('qCheckSpin').classList.add('d-none'); }
  }

  async function submit() {
    if (!immediate()) {
      const blank = questions.filter((q) => !(answers[q.id] || []).length).length;
      if (blank && !confirm(`未回答の問題が ${blank} 問あります。このまま結果を見ますか？`)) return;
    }
    $('qSubmitSpin').classList.remove('d-none'); $('qSubmit').disabled = true;
    try {
      const j = await api('submit', { answers: questions.map((q) => ({ question_id: q.id, answer: answers[q.id] || [] })) });
      renderResult(j);
    } catch (e) { fail(e.message || '送信に失敗しました'); }
    finally { $('qSubmitSpin').classList.add('d-none'); $('qSubmit').disabled = false; }
  }

  // ---------------------------------------------------------------- 結果と振り返り
  function renderResult(j) {
    const res = j.result;
    $('scoreRing').style.setProperty('--pct', res.percentage);
    $('rPct').textContent = `${res.percentage}%`;
    const correctCount = j.feedback.filter((f) => f.is_correct).length;
    $('rCount').textContent = `${j.feedback.length} 問中 ${correctCount} 問 正解`;
    $('rBadge').innerHTML = res.passed === true ? '<span class="badge text-bg-success fs-6"><i class="bi bi-check-circle"></i> 合格</span>'
      : res.passed === false ? `<span class="badge text-bg-danger fs-6"><i class="bi bi-x-circle"></i> 不合格${Number.isFinite(Number(res.pass_score)) ? `（合格は ${Number(res.pass_score)}% 以上）` : ''}</span>`
      : '<span class="badge text-bg-primary fs-6"><i class="bi bi-flag"></i> 受講完了</span>';
    $('rRetry').classList.toggle('d-none', res.passed !== false);
    $('rLesson').classList.toggle('d-none', !material);
    const list = $('reviewList');
    list.innerHTML = '';
    j.feedback.forEach((f, n) => {
      const card = document.createElement('div');
      card.className = `card-t review-card p-3 mb-2 ${f.is_correct ? 'ok' : 'ng'}`;
      const opts = f.options.map((o, i) => {
        const ok = f.correct_answer.includes(i);
        const you = f.your_answer.includes(i);
        const cls = ok ? 'is-correct' : (you ? 'is-wrong' : '');
        const tags = [ok ? '正解' : '', you ? 'あなたの回答' : ''].filter(Boolean).join('、');
        const exp = f.option_explanations ? f.option_explanations[i] : '';
        return `<div class="opt static ${cls} mb-1" style="cursor:default"><span class="num">${i + 1}</span><span class="flex-grow-1">${esc(o)}`
          + (tags || exp ? `<span class="exp">${tags ? `<strong>${tags}</strong> ` : ''}${esc(exp || '')}</span>` : '') + '</span></div>';
      }).join('');
      card.innerHTML = `<div class="d-flex justify-content-between align-items-center mb-2"><span class="q-no">問題 ${n + 1}</span>`
        + (f.is_correct ? '<span class="text-success fw-bold"><i class="bi bi-check-circle-fill"></i> 正解</span>'
          : '<span class="text-danger fw-bold"><i class="bi bi-x-circle-fill"></i> 不正解</span>') + '</div>'
        + `<div class="q-title mb-2">${esc(f.title)}</div>`
        + (f.has_image ? `<div class="mb-2 text-center"><img class="q-image" style="max-height:200px" src="${qImageUrl(f.id)}" alt="問題の場面の画像"></div>` : '')
        + opts + (f.explanation ? `<div class="point mt-2">${esc(f.explanation)}</div>` : '');
      list.appendChild(card);
    });
    only('resultView');
    requestAnimationFrame(() => window.scrollTo(0, 0));   // 振り返りの画像を組み立てた後に先頭へ戻す
  }

  // ---------------------------------------------------------------- 操作
  $('startBtn').addEventListener('click', begin);
  $('pagePrev').addEventListener('click', () => movePage(-1));
  $('pageNext').addEventListener('click', () => movePage(1));
  $('zoomIn').addEventListener('click', () => { zoom = Math.min(3, zoom + 0.5); renderPages(); });
  $('zoomOut').addEventListener('click', () => { zoom = Math.max(1, zoom - 0.5); renderPages(); });
  $('fullBtn').addEventListener('click', () => {
    if (document.fullscreenElement) document.exitFullscreen(); else $('stage').requestFullscreen?.();
  });
  $('textToggle').addEventListener('click', () => $('pageText').classList.toggle('d-none'));
  $('toQuiz').addEventListener('click', () => openQuiz(firstUnanswered()));
  document.addEventListener('keydown', (e) => {
    if ($('pagesView').classList.contains('d-none')) return;
    // 拡大中は、スワイプと同じく矢印キーも画面の移動(スクロール)に使い、ページはボタンでめくる
    if (zoom > 1) return;
    if (e.key === 'ArrowRight') { movePage(1); e.preventDefault(); }
    if (e.key === 'ArrowLeft') { movePage(-1); e.preventDefault(); }
  });
  let touchX = null;
  $('stage').addEventListener('touchstart', (e) => { if (zoom === 1) touchX = e.touches[0].clientX; }, { passive: true });
  $('stage').addEventListener('touchend', (e) => {
    if (touchX === null) return;
    const dx = e.changedTouches[0].clientX - touchX; touchX = null;
    if (Math.abs(dx) > 50) movePage(dx < 0 ? 1 : -1);
  });
  window.addEventListener('resize', () => { if (!$('pagesView').classList.contains('d-none')) renderPages(); });
  $('lessonPrev').addEventListener('click', () => { if (lessonCur > 0) { lessonCur--; renderLesson(); } });
  $('lessonNext').addEventListener('click', () => {
    if (lessonCur < material.slides.length - 1) { lessonCur++; renderLesson(); return; }
    openQuiz(firstUnanswered());
  });
  // review=true は「教材を見直す」(既に読んだ教材を行き来する)。再受講では false にし、最後まで見るまでテストへ進めない
  const toMaterial = (review) => {
    if (material && material.format === 'page_images') { reachedEnd = review; openPages(); }
    else if (material) { lessonCur = 0; only('lessonView'); renderLesson(); }
  };
  $('backToLesson').addEventListener('click', () => toMaterial(true));
  $('rLesson').addEventListener('click', () => toMaterial(true));
  $('qPrev').addEventListener('click', () => { if (cur > 0) { cur--; renderQuestion(); } });
  $('qNext').addEventListener('click', () => { if (cur < questions.length - 1) { cur++; renderQuestion(); } });
  $('qCheck').addEventListener('click', check);
  $('qSubmit').addEventListener('click', submit);
  $('rRetry').addEventListener('click', () => {
    Object.keys(answers).forEach((k) => delete answers[k]);
    Object.keys(feedbacks).forEach((k) => delete feedbacks[k]);
    reachedEnd = false; cur = 0;
    if (material) toMaterial(false); else openQuiz(0);
  });

  start();
})();
</script>
<?php endif; ?>
</body>
</html>

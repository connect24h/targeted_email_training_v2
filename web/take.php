<?php declare(strict_types=1);
/**
 * 受講者向け 公開ページ(認証不要・トークン方式)。
 * /tet2/take.php?token=<32桁hex>
 * ランディング → クイズ(進捗バー) → 送信 → 即時採点+解説。モバイル対応(Bootstrap)。
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
  <title>セキュリティ教育クイズ</title>
  <link href="assets/vendor/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background: #f4f6f9; }
    .quiz-wrap { max-width: 720px; margin: 0 auto; padding: 1rem; }
    .quiz-card { background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
    .opt-btn { text-align: left; white-space: normal; }
    .opt-btn.selected { border-color: #0d6efd; background: #e7f1ff; }
    .fb-correct { border-left: 4px solid #198754; }
    .fb-wrong { border-left: 4px solid #dc3545; }
    .lesson-body { white-space: pre-wrap; line-height: 1.9; }
  </style>
</head>
<body>
<div class="quiz-wrap">

  <?php if (!$tokenValid): ?>
    <div class="quiz-card p-4 mt-5 text-center">
      <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size:2.5rem"></i>
      <h5 class="mt-3">受講リンクが正しくありません</h5>
      <p class="text-muted mb-0">メールに記載されたリンクからもう一度お開きください。</p>
    </div>
  <?php else: ?>

  <!-- ランディング -->
  <div id="landingView" class="quiz-card p-4 mt-4 d-none">
    <div class="text-center mb-3">
      <i class="bi bi-mortarboard-fill text-primary" style="font-size:2.5rem"></i>
      <h4 class="mt-2 mb-1" id="landingTitle">セキュリティ教育クイズ</h4>
      <div class="text-muted small" id="landingMeta"></div>
    </div>
    <p class="small text-muted">全問回答すると、その場で結果と解説が表示されます。所要時間の目安は数分です。</p>
    <button class="btn btn-primary w-100" id="startBtn">
      <span class="spinner-border spinner-border-sm d-none" id="startSpin"></span>
      はじめる
    </button>
  </div>

  <!-- スライド教材 -->
  <div id="lessonView" class="d-none mt-4">
    <div class="mb-2 d-flex justify-content-between align-items-center">
      <span class="small text-muted" id="lessonProgress"></span>
      <span class="badge bg-primary">教材</span>
    </div>
    <div class="progress mb-3" style="height:6px"><div class="progress-bar" id="lessonProgressBar" style="width:0%"></div></div>
    <div class="quiz-card p-4">
      <h4 id="lessonTitle"></h4>
      <div class="lesson-body mt-3" id="lessonBody"></div>
    </div>
    <div class="d-flex justify-content-between mt-3">
      <button class="btn btn-outline-secondary" id="lessonPrevBtn"><i class="bi bi-chevron-left"></i> 前へ</button>
      <button class="btn btn-primary" id="lessonNextBtn">次へ <i class="bi bi-chevron-right"></i></button>
    </div>
  </div>

  <!-- クイズ -->
  <div id="quizView" class="d-none mt-4">
    <div class="mb-2 d-flex justify-content-between align-items-center">
      <span class="small text-muted" id="progressLabel"></span>
      <span class="small text-muted" id="answeredLabel"></span>
    </div>
    <div class="progress mb-3" style="height:6px">
      <div class="progress-bar" id="progressBar" style="width:0%"></div>
    </div>
    <div class="quiz-card p-4">
      <div class="fw-bold mb-3" id="qTitle"></div>
      <div id="qOptions" class="d-grid gap-2"></div>
    </div>
    <div class="d-flex justify-content-between mt-3">
      <button class="btn btn-outline-secondary" id="prevBtn"><i class="bi bi-chevron-left"></i> 前へ</button>
      <button class="btn btn-primary" id="nextBtn">次へ <i class="bi bi-chevron-right"></i></button>
      <button class="btn btn-success d-none" id="submitBtn">
        <span class="spinner-border spinner-border-sm d-none" id="submitSpin"></span>
        採点する
      </button>
    </div>
  </div>

  <!-- 結果 -->
  <div id="resultView" class="d-none mt-4">
    <div class="quiz-card p-4 text-center mb-3">
      <div id="resultBadge"></div>
      <h3 class="mt-2 mb-1"><span id="resultPct"></span>%</h3>
      <div class="text-muted" id="resultScore"></div>
    </div>
    <div id="feedbackList"></div>
    <button class="btn btn-primary w-100 mt-3 d-none" id="retryBtn"><i class="bi bi-arrow-repeat"></i> もう一度受講</button>
  </div>

  <!-- エラー -->
  <div id="errorView" class="quiz-card p-4 mt-5 text-center d-none">
    <i class="bi bi-exclamation-circle-fill text-danger" style="font-size:2.5rem"></i>
    <h5 class="mt-3" id="errorMsg">エラーが発生しました</h5>
  </div>

  <?php endif; ?>
</div>

<script src="assets/vendor/bootstrap.bundle.min.js"></script>
<?php if ($tokenValid): ?>
<script>
(function () {
  const TOKEN = <?= json_encode($token) ?>;
  const API = 'api/edu_take.php';
  let questions = [];
  let materialSlides = [];
  let answers = {};   // question_id -> [index,...]
  let cur = 0;
  let lessonCur = 0;

  const $ = (id) => document.getElementById(id);
  const show = (id) => $(id).classList.remove('d-none');
  const hide = (id) => $(id).classList.add('d-none');

  function fail(msg) {
    ['landingView','lessonView','quizView','resultView'].forEach(hide);
    $('errorMsg').textContent = msg || 'エラーが発生しました';
    show('errorView');
  }

  async function start() {
    try {
      const r = await fetch(`${API}?action=start&token=${encodeURIComponent(TOKEN)}`);
      const j = await r.json();
      if (!j.success) return fail(j.error);
      $('landingTitle').textContent = j.delivery.title || 'セキュリティ教育クイズ';
      $('landingMeta').textContent = `全 ${j.delivery.question_count} 問`;
      questions = j.questions;
      materialSlides = j.material?.slides || [];
      show('landingView');
    } catch (e) { fail('通信に失敗しました'); }
  }

  function beginCourse() {
    hide('landingView');
    if (materialSlides.length) {
      lessonCur = 0; show('lessonView'); renderLesson(); return;
    }
    show('quizView'); renderQuestion();
  }

  function renderLesson() {
    const slide = materialSlides[lessonCur];
    $('lessonProgress').textContent = `${lessonCur + 1} / ${materialSlides.length}`;
    $('lessonProgressBar').style.width = `${Math.round((lessonCur + 1) / materialSlides.length * 100)}%`;
    $('lessonTitle').textContent = slide.title;
    $('lessonBody').textContent = slide.body;
    $('lessonPrevBtn').disabled = lessonCur === 0;
    $('lessonNextBtn').innerHTML = lessonCur === materialSlides.length - 1
      ? '確認テストへ <i class="bi bi-patch-question"></i>' : '次へ <i class="bi bi-chevron-right"></i>';
  }

  function renderQuestion() {
    const q = questions[cur];
    const total = questions.length;
    $('progressLabel').textContent = `第 ${cur + 1} / ${total} 問`;
    const answeredCount = Object.keys(answers).filter(k => (answers[k] || []).length > 0).length;
    $('answeredLabel').textContent = `回答済み ${answeredCount} / ${total}`;
    $('progressBar').style.width = `${Math.round((cur + 1) / total * 100)}%`;
    $('qTitle').textContent = q.title;

    const multi = q.question_type === 'multiple_choice';
    const box = $('qOptions');
    box.innerHTML = '';
    q.options.forEach((opt, i) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn-outline-secondary opt-btn';
      btn.textContent = opt;
      const sel = (answers[q.id] || []).includes(i);
      if (sel) btn.classList.add('selected');
      btn.onclick = () => toggle(q.id, i, multi);
      box.appendChild(btn);
    });

    $('prevBtn').disabled = cur === 0;
    const last = cur === total - 1;
    $('nextBtn').classList.toggle('d-none', last);
    $('submitBtn').classList.toggle('d-none', !last);
  }

  function toggle(qid, idx, multi) {
    let arr = answers[qid] || [];
    if (multi) {
      arr = arr.includes(idx) ? arr.filter(x => x !== idx) : [...arr, idx];
    } else {
      arr = [idx];
    }
    answers[qid] = arr;
    renderQuestion();
  }

  async function submit() {
    $('submitSpin').classList.remove('d-none');
    $('submitBtn').disabled = true;
    const payload = {
      token: TOKEN,
      answers: questions.map(q => ({ question_id: q.id, answer: answers[q.id] || [] })),
    };
    try {
      const r = await fetch(`${API}?action=submit`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const j = await r.json();
      if (!j.success) return fail(j.error);
      renderResult(j);
    } catch (e) { fail('採点の送信に失敗しました'); }
  }

  function renderResult(j) {
    hide('quizView');
    const res = j.result;
    $('resultPct').textContent = res.percentage;
    $('resultScore').textContent = `${res.total_score} / ${res.max_score} 点`;
    const badge = $('resultBadge');
    if (res.passed === true) {
      badge.innerHTML = '<span class="badge bg-success fs-6"><i class="bi bi-check-circle"></i> 合格</span>';
    } else if (res.passed === false) {
      badge.innerHTML = '<span class="badge bg-danger fs-6"><i class="bi bi-x-circle"></i> 不合格</span>';
    } else {
      badge.innerHTML = '<span class="badge bg-primary fs-6"><i class="bi bi-flag"></i> 受講完了</span>';
    }
    $('retryBtn').classList.toggle('d-none', res.passed !== false);
    const list = $('feedbackList');
    list.innerHTML = '';
    j.feedback.forEach((f, n) => {
      const card = document.createElement('div');
      card.className = `quiz-card p-3 mb-2 ${f.is_correct ? 'fb-correct' : 'fb-wrong'}`;
      const mark = f.is_correct
        ? '<span class="text-success"><i class="bi bi-check-circle-fill"></i> 正解</span>'
        : '<span class="text-danger"><i class="bi bi-x-circle-fill"></i> 不正解</span>';
      const opts = f.options.map((o, i) => {
        const you = f.your_answer.includes(i);
        const ok = f.correct_answer.includes(i);
        let cls = '';
        if (ok) cls = 'text-success fw-bold';
        else if (you) cls = 'text-danger';
        const tags = [];
        if (ok) tags.push('正解');
        if (you) tags.push('あなたの回答');
        const tag = tags.length ? ` <small class="text-muted">(${tags.join(' / ')})</small>` : '';
        return `<div class="${cls}">・${escapeHtml(o)}${tag}</div>`;
      }).join('');
      card.innerHTML = `
        <div class="d-flex justify-content-between"><span class="fw-bold">第 ${n + 1} 問</span>${mark}</div>
        <div class="mt-2 mb-2">${escapeHtml(f.title)}</div>
        <div class="small mb-2">${opts}</div>
        <div class="small text-muted border-top pt-2"><i class="bi bi-info-circle"></i> ${escapeHtml(f.explanation || '')}</div>`;
      list.appendChild(card);
    });
    show('resultView');
    window.scrollTo(0, 0);
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;').replaceAll("'", '&#39;');
  }

  $('startBtn').onclick = beginCourse;
  $('lessonPrevBtn').onclick = () => { if (lessonCur > 0) { lessonCur--; renderLesson(); } };
  $('lessonNextBtn').onclick = () => {
    if (lessonCur < materialSlides.length - 1) { lessonCur++; renderLesson(); return; }
    hide('lessonView'); show('quizView'); renderQuestion();
  };
  $('prevBtn').onclick = () => { if (cur > 0) { cur--; renderQuestion(); } };
  $('nextBtn').onclick = () => { if (cur < questions.length - 1) { cur++; renderQuestion(); } };
  $('submitBtn').onclick = submit;
  $('retryBtn').onclick = () => {
    answers = {}; cur = 0; hide('resultView'); $('submitBtn').disabled = false; $('submitSpin').classList.add('d-none');
    if (materialSlides.length) { lessonCur = 0; show('lessonView'); renderLesson(); }
    else { show('quizView'); renderQuestion(); }
  };

  start();
})();
</script>
<?php endif; ?>
</body>
</html>

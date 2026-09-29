<?php declare(strict_types=1);
/**
 * アンケート回答者向け 公開ページ(認証不要・トークン方式)。
 * /tet2/survey.php?token=<32桁hex>
 * 設問の表示、表示条件による出し分け、必須の確認、送信、完了表示。ロジックは survey_take.php API を叩く。
 * 設問や選択肢は textContent で描画し、HTML として解釈しない。
 */
$token = isset($_GET['token']) && is_string($_GET['token']) ? trim($_GET['token']) : '';
$tokenValid = (bool) preg_match('/^[0-9a-f]{32}$/', $token);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>アンケート</title>
  <link href="assets/vendor/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background: #f4f6f9; }
    .sv-wrap { max-width: 720px; margin: 0 auto; padding: 1rem; }
    .sv-card { background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
    .sv-question { border-top: 1px solid #eef1f5; }
    .sv-question.is-invalid { background: #fff5f5; }
    .sv-section { font-weight: 700; color: #19324A; margin-top: 1.25rem; }
    .sv-desc { white-space: pre-wrap; }
  </style>
</head>
<body>
<div class="sv-wrap">

  <?php if (!$tokenValid): ?>
    <div class="sv-card p-4 mt-5 text-center">
      <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size:2.5rem"></i>
      <h5 class="mt-3">回答用のリンクが正しくありません</h5>
      <p class="text-muted mb-0">メールなどで案内されたリンクから、もう一度お開きください。</p>
    </div>
  <?php else: ?>

  <div id="loadingView" class="sv-card p-4 mt-5 text-center" role="status">
    <span class="spinner-border spinner-border-sm"></span> 読み込んでいます
  </div>

  <form id="surveyView" class="d-none mt-4" novalidate>
    <div class="sv-card p-4 mb-3">
      <h4 id="svTitle" class="mb-2"></h4>
      <div id="svMeta" class="small text-muted mb-2"></div>
      <p id="svDesc" class="sv-desc mb-0"></p>
    </div>
    <div class="sv-card px-4 pb-2" id="svQuestions"></div>
    <div id="svError" class="alert alert-danger mt-3 d-none" role="alert"></div>
    <button type="submit" class="btn btn-primary w-100 mt-3" id="svSubmit">
      <span class="spinner-border spinner-border-sm d-none" id="svSpin"></span>
      回答を送信する
    </button>
  </form>

  <div id="doneView" class="sv-card p-4 mt-5 text-center d-none" role="status">
    <i class="bi bi-check-circle-fill text-success" style="font-size:2.5rem"></i>
    <h5 class="mt-3">回答を受け付けました</h5>
    <p class="text-muted mb-0">ご協力ありがとうございました。このページは閉じてかまいません。</p>
  </div>

  <div id="errorView" class="sv-card p-4 mt-5 text-center d-none" role="alert">
    <i class="bi bi-exclamation-circle-fill text-danger" style="font-size:2.5rem"></i>
    <h5 class="mt-3" id="errorMsg">エラーが発生しました</h5>
  </div>

  <?php endif; ?>
</div>

<?php if ($tokenValid): ?>
<script>
(function () {
  'use strict';
  const TOKEN = <?= json_encode($token) ?>;
  const API = 'api/survey_take.php';
  const $ = (id) => document.getElementById(id);
  let questions = [];

  function el(tag, attrs, text) {
    const node = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([k, v]) => node.setAttribute(k, v));
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function fail(message) {
    ['loadingView', 'surveyView'].forEach((id) => $(id).classList.add('d-none'));
    $('errorMsg').textContent = message || 'エラーが発生しました';
    $('errorView').classList.remove('d-none');
  }

  // 現在の回答(設問 index => 選んだ選択肢 index の配列、または文字列)
  function currentValue(q, index) {
    if (q.question_type === 'text') {
      return $('q' + index + '_text').value;
    }
    const checked = Array.from(document.querySelectorAll('input[name="q' + index + '"]:checked'));
    return checked.map((c) => Number(c.value));
  }

  function isVisible(index) {
    const cond = questions[index].show_if;
    if (!cond) return true;
    if (!isVisible(cond.question_index)) return false;
    return currentValue(questions[cond.question_index], cond.question_index).includes(cond.option);
  }

  function refreshVisibility() {
    questions.forEach((q, i) => $('q' + i).classList.toggle('d-none', !isVisible(i)));
  }

  // 「その他（自由記述）」: 選択肢の最後に足し(値は選択肢の数)、選んだ時だけ記述の欄を出す
  const OTHER_LABEL = 'その他（自由記述）';
  function renderOther(wrap, q, i) {
    const oi = q.options.length;
    const row = el('div', { class: 'form-check' });
    const input = el('input', {
      class: 'form-check-input', id: 'q' + i + '_' + oi, name: 'q' + i, value: String(oi),
      type: q.question_type === 'single' ? 'radio' : 'checkbox',
    });
    row.appendChild(input);
    row.appendChild(el('label', { class: 'form-check-label', for: 'q' + i + '_' + oi }, OTHER_LABEL));
    wrap.appendChild(row);
    const text = el('input', { type: 'text', id: 'q' + i + '_other', class: 'form-control form-control-sm mt-1 d-none',
      maxlength: '500', 'aria-label': q.title + '（その他の内容）', placeholder: 'その他の内容' });
    wrap.appendChild(text);
    wrap.addEventListener('change', () => text.classList.toggle('d-none', !input.checked));
    input.addEventListener('change', refreshVisibility);
  }

  function otherChosen(q, index) {
    return Boolean(q.allow_other) && currentValue(q, index).includes(q.options.length);
  }

  function render(data) {
    questions = data.questions;
    $('svTitle').textContent = data.survey.title;
    const meta = [];
    if (data.survey.is_anonymous) meta.push('匿名のアンケートです。回答と回答者は結び付けて保存しません。');
    if (data.survey.deadline) meta.push('回答の締切: ' + String(data.survey.deadline).slice(0, 16));
    $('svMeta').textContent = meta.join(' ');
    $('svDesc').textContent = data.survey.description || '';

    const box = $('svQuestions');
    let lastSection = null;
    questions.forEach((q, i) => {
      if (q.section && q.section !== lastSection) {
        box.appendChild(el('div', { class: 'sv-section' }, q.section));
        lastSection = q.section;
      }
      const wrap = el('fieldset', { id: 'q' + i, class: 'sv-question py-3' });
      const legend = el('legend', { class: 'fs-6 fw-bold mb-2' }, (i + 1) + '. ' + q.title);
      if (q.is_required) legend.appendChild(el('span', { class: 'badge bg-danger ms-2' }, '必須'));
      if (q.question_type === 'multiple') legend.appendChild(el('span', { class: 'text-muted small ms-2' }, '（複数選択可）'));
      wrap.appendChild(legend);
      if (q.question_type === 'text') {
        const area = el('textarea', { id: 'q' + i + '_text', class: 'form-control', rows: '3', maxlength: '2000', 'aria-label': q.title });
        wrap.appendChild(area);
      } else {
        q.options.forEach((label, oi) => {
          const row = el('div', { class: 'form-check' });
          const input = el('input', {
            class: 'form-check-input', id: 'q' + i + '_' + oi, name: 'q' + i, value: String(oi),
            type: q.question_type === 'single' ? 'radio' : 'checkbox',
          });
          input.addEventListener('change', refreshVisibility);
          row.appendChild(input);
          row.appendChild(el('label', { class: 'form-check-label', for: 'q' + i + '_' + oi }, label));
          wrap.appendChild(row);
        });
        if (q.allow_other) renderOther(wrap, q, i);
      }
      box.appendChild(wrap);
    });
    refreshVisibility();
    $('loadingView').classList.add('d-none');
    $('surveyView').classList.remove('d-none');
  }

  async function start() {
    try {
      const res = await fetch(API + '?action=start&token=' + encodeURIComponent(TOKEN), { credentials: 'omit' });
      const data = await res.json();
      if (!data.success) return fail(data.error);
      render(data);
    } catch (e) {
      fail('読み込みに失敗しました。時間をおいて開き直してください。');
    }
  }

  async function submit(event) {
    event.preventDefault();
    const answers = {};
    const others = {};
    let firstInvalid = null;
    let otherMissing = false;
    questions.forEach((q, i) => {
      const node = $('q' + i);
      node.classList.remove('is-invalid');
      if (!isVisible(i)) return;
      const value = currentValue(q, i);
      const empty = q.question_type === 'text' ? value.trim() === '' : value.length === 0;
      if (q.is_required && empty) {
        node.classList.add('is-invalid');
        firstInvalid = firstInvalid || node;
        return;
      }
      if (!empty) answers[q.id] = q.question_type === 'single' ? value[0] : value;
      if (otherChosen(q, i)) {
        const text = $('q' + i + '_other').value.trim();
        if (text === '') {
          node.classList.add('is-invalid');
          firstInvalid = firstInvalid || node;
          otherMissing = true;
          return;
        }
        others[q.id] = text;
      }
    });
    if (firstInvalid) {
      $('svError').textContent = otherMissing ? '選んだ「その他」の内容を入力してください。' : '必須の設問に回答してください。';
      $('svError').classList.remove('d-none');
      firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    $('svError').classList.add('d-none');
    $('svSubmit').disabled = true;
    $('svSpin').classList.remove('d-none');
    try {
      const res = await fetch(API + '?action=submit', {
        method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token: TOKEN, answers: answers, others: others }),
      });
      const data = await res.json();
      if (!data.success) {
        $('svError').textContent = data.error || '送信に失敗しました。';
        $('svError').classList.remove('d-none');
        return;
      }
      $('surveyView').classList.add('d-none');
      $('doneView').classList.remove('d-none');
    } catch (e) {
      $('svError').textContent = '送信に失敗しました。通信状態を確かめて、もう一度送信してください。';
      $('svError').classList.remove('d-none');
    } finally {
      $('svSubmit').disabled = false;
      $('svSpin').classList.add('d-none');
    }
  }

  $('surveyView').addEventListener('submit', submit);
  start();
})();
</script>
<?php endif; ?>
</body>
</html>

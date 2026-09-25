/**
 * アンケート画面(U7)。作成と編集、配信(対象の固定と回答用 URL の出力)、結果の集計と出力。
 *
 * app.js 本体には手を入れず、VIEWS へ後付け登録する(positions.js、suspicious-mails.js と同じ方式)。
 * 利用者が入力した文字列(題名、設問、選択肢、自由記述の回答)は必ず esc() を通して描画する。
 * メール送信は API 側で TET2_SURVEY_MAIL_ENABLED=1 のときだけ動く。無効時はボタンを押せなくし、理由を表示する。
 */

const SV_STATUS_LABEL = { draft: '下書き', published: '配信済み', closed: '終了' };
const SV_STATUS_BADGE = { draft: 'secondary', published: 'primary', closed: 'light text-dark border' };
const SV_TYPE_LABEL = { single: '単一選択', multiple: '複数選択', text: '自由記述' };

const SvState = { surveys: [], deliveries: [], templates: [], mailEnabled: false, groups: [] };
// 設問エディタの作業用モデル。描画は常にこのモデルから行う。
const SvEditor = { questions: [] };

function svCanEdit() {
  return ['operator', 'tenant_admin', 'superadmin'].includes(State.user?.role);
}
function svDownloadUrl(action, deliveryId) {
  const query = new URLSearchParams({ action, delivery_id: String(deliveryId) });
  if (State.user?.role === 'superadmin' && State.activeTenantId) query.set('tenant_id', State.activeTenantId);
  return `api/surveys.php?${query.toString()}`;
}

/* ========== 一覧 ========== */

async function renderSurveys() {
  const [tpl, list, deliveries] = await Promise.all([
    api('api/surveys.php', { query: { action: 'templates' } }),
    api('api/surveys.php', { query: { action: 'list' } }),
    api('api/surveys.php', { query: { action: 'deliveries' } }),
  ]);
  SvState.templates = tpl.templates || [];
  SvState.mailEnabled = Boolean(tpl.mail_enabled);
  SvState.surveys = list.surveys || [];
  SvState.deliveries = deliveries.deliveries || [];

  $('#svTemplateSelect').innerHTML = SvState.templates
    .map((t) => `<option value="${esc(t.key)}">${esc(t.label)}（${Number(t.question_count)}問）</option>`).join('');
  $('#svMailNotice').innerHTML = SvState.mailEnabled
    ? 'メール送信は有効です。案内メールと締切前の催促を送れます。送信は実在の従業員に届きます。'
    : 'メール送信は無効です。配信を作ったら「回答用 URL（CSV）」を出力し、社内のメールで配布してください。';
  $('#svMailNotice').className = `alert small py-2 ${SvState.mailEnabled ? 'alert-warning' : 'alert-secondary'}`;

  const canEdit = svCanEdit();
  $('#svBody').innerHTML = SvState.surveys.length === 0 ? emptyRow(7) : SvState.surveys.map((s) => {
    const draft = s.status === 'draft';
    const actions = canEdit ? [
      `<button class="btn btn-outline-primary btn-sm" data-sv-action="${draft ? 'edit' : 'view'}" data-id="${Number(s.id)}">${draft ? '編集' : '設問を見る'}</button>`,
      s.status !== 'closed' && Number(s.question_count) > 0 ? `<button class="btn btn-primary btn-sm" data-sv-action="deliver" data-id="${Number(s.id)}">配信</button>` : '',
      `<button class="btn btn-outline-secondary btn-sm" data-sv-action="duplicate" data-id="${Number(s.id)}">複製</button>`,
      draft ? `<button class="btn btn-outline-danger btn-sm" data-sv-action="delete" data-id="${Number(s.id)}">削除</button>` : '',
    ].join(' ') : `<button class="btn btn-outline-primary btn-sm" data-sv-action="view" data-id="${Number(s.id)}">設問を見る</button>`;
    return `<tr>
      <td>${esc(s.title)}</td>
      <td><span class="badge bg-${SV_STATUS_BADGE[s.status] || 'secondary'}">${esc(SV_STATUS_LABEL[s.status] || s.status)}</span></td>
      <td>${Number(s.is_anonymous) === 1 ? '匿名' : '記名'}</td>
      <td class="text-end">${Number(s.question_count)}</td>
      <td class="text-end">${Number(s.delivery_count)}</td>
      <td class="small">${esc(fmtDate(s.updated_at))}</td>
      <td class="text-nowrap">${actions}</td></tr>`;
  }).join('');

  $('#svDeliveryBody').innerHTML = SvState.deliveries.length === 0 ? emptyRow(6) : SvState.deliveries.map((d) => {
    const open = d.status === 'open';
    const mailTitle = SvState.mailEnabled ? '' : ' title="メール送信は無効です"';
    const mailDisabled = SvState.mailEnabled ? '' : ' disabled';
    const actions = [
      `<button class="btn btn-outline-primary btn-sm" data-sv-action="results" data-id="${Number(d.id)}">結果</button>`,
      canEdit && open ? `<a class="btn btn-outline-secondary btn-sm" href="${svDownloadUrl('tokens_csv', Number(d.id))}">回答用 URL（CSV）</a>` : '',
      canEdit && open ? `<span${mailTitle}><button class="btn btn-outline-secondary btn-sm" data-sv-action="invite" data-id="${Number(d.id)}"${mailDisabled}>案内メール</button></span>` : '',
      canEdit && open && d.deadline ? `<span${mailTitle}><button class="btn btn-outline-secondary btn-sm" data-sv-action="remind" data-id="${Number(d.id)}"${mailDisabled}>催促</button></span>` : '',
      canEdit && open ? `<button class="btn btn-outline-danger btn-sm" data-sv-action="close" data-id="${Number(d.id)}">終了</button>` : '',
    ].join(' ');
    return `<tr>
      <td>${esc(d.title)}<div class="small text-muted">${esc(d.survey_title)}${Number(d.is_anonymous) === 1 ? '（匿名）' : ''}</div></td>
      <td>${open ? '<span class="badge bg-success">受付中</span>' : '<span class="badge bg-light text-dark border">終了</span>'}</td>
      <td class="small">${d.deadline ? esc(fmtDate(d.deadline)) : '期限なし'}</td>
      <td class="text-end">${Number(d.answered)} / ${Number(d.assigned)}</td>
      <td class="small">${d.invited_at ? esc(fmtDate(d.invited_at)) : '—'}</td>
      <td class="text-nowrap">${actions}</td></tr>`;
  }).join('');
}

/* ========== 設問エディタ ========== */

function svShowIfValue(q) {
  return q.show_if ? `${q.show_if.question_index}:${q.show_if.option}` : '';
}

function svRenderQuestions(readOnly) {
  const box = $('#svQuestionList');
  if (!box) return;
  box.innerHTML = SvEditor.questions.map((q, i) => {
    const dis = readOnly ? ' disabled' : '';
    const conditionOptions = ['<option value="">表示条件なし（常に表示）</option>'];
    SvEditor.questions.slice(0, i).forEach((prev, pi) => {
      if (prev.question_type === 'text') return;
      prev.options.forEach((label, oi) => {
        const value = `${pi}:${oi}`;
        conditionOptions.push(`<option value="${value}"${value === svShowIfValue(q) ? ' selected' : ''}>設問${pi + 1}で「${esc(label)}」を選んだとき</option>`);
      });
    });
    return `<div class="border rounded p-2 mb-2" data-q="${i}">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <strong>設問${i + 1}</strong>
        ${readOnly ? '' : `<div class="btn-group btn-group-sm">
          <button type="button" class="btn btn-outline-secondary" data-q-move="-1" data-q-index="${i}" aria-label="上へ"${i === 0 ? ' disabled' : ''}><i class="bi bi-arrow-up"></i></button>
          <button type="button" class="btn btn-outline-secondary" data-q-move="1" data-q-index="${i}" aria-label="下へ"${i === SvEditor.questions.length - 1 ? ' disabled' : ''}><i class="bi bi-arrow-down"></i></button>
          <button type="button" class="btn btn-outline-danger" data-q-remove="${i}" aria-label="削除"><i class="bi bi-trash"></i></button>
        </div>`}
      </div>
      <div class="row g-2">
        <div class="col-md-4"><label class="form-label small mb-0">見出し（任意）</label>
          <input class="form-control form-control-sm" data-q-field="section" data-q-index="${i}" value="${esc(q.section || '')}" maxlength="200"${dis}></div>
        <div class="col-md-4"><label class="form-label small mb-0">形式</label>
          <select class="form-select form-select-sm" data-q-field="question_type" data-q-index="${i}"${dis}>
            ${Object.entries(SV_TYPE_LABEL).map(([v, l]) => `<option value="${v}"${v === q.question_type ? ' selected' : ''}>${l}</option>`).join('')}
          </select></div>
        <div class="col-md-4 d-flex align-items-end"><div class="form-check">
          <input class="form-check-input" type="checkbox" id="svReq${i}" data-q-field="is_required" data-q-index="${i}"${q.is_required ? ' checked' : ''}${dis}>
          <label class="form-check-label small" for="svReq${i}">回答を必須にする</label></div></div>
        <div class="col-12"><label class="form-label small mb-0">設問文</label>
          <textarea class="form-control form-control-sm" rows="2" data-q-field="title" data-q-index="${i}" maxlength="500"${dis}>${esc(q.title)}</textarea></div>
        ${q.question_type === 'text' ? '' : `<div class="col-12"><label class="form-label small mb-0">選択肢（1行に1つ、2〜20個）</label>
          <textarea class="form-control form-control-sm" rows="3" data-q-field="options" data-q-index="${i}"${dis}>${esc(q.options.join('\n'))}</textarea></div>`}
        <div class="col-12"><label class="form-label small mb-0">表示条件</label>
          <select class="form-select form-select-sm" data-q-field="show_if" data-q-index="${i}"${dis}>${conditionOptions.join('')}</select></div>
      </div></div>`;
  }).join('') || '<div class="text-muted small">設問がありません。「設問を追加」で作成してください。</div>';
}

/** 並べ替えや削除、選択肢の変更で成り立たなくなった表示条件を外す。外した数を返す。 */
function svDropBrokenConditions() {
  let dropped = 0;
  SvEditor.questions.forEach((q, i) => {
    const c = q.show_if;
    if (!c) return;
    const src = SvEditor.questions[c.question_index];
    if (c.question_index >= i || !src || src.question_type === 'text' || c.option >= src.options.length) {
      q.show_if = null;
      dropped++;
    }
  });
  return dropped;
}

function svBindEditor() {
  const box = $('#svQuestionList');
  box.addEventListener('input', (e) => {
    const field = e.target.dataset.qField;
    if (!field) return;
    const q = SvEditor.questions[Number(e.target.dataset.qIndex)];
    if (field === 'section' || field === 'title') q[field] = e.target.value;
  });
  box.addEventListener('change', (e) => {
    const field = e.target.dataset.qField;
    if (!field) return;
    const q = SvEditor.questions[Number(e.target.dataset.qIndex)];
    if (field === 'is_required') q.is_required = e.target.checked;
    if (field === 'question_type') {
      q.question_type = e.target.value;
      if (q.question_type === 'text') q.options = [];
      else if (q.options.length < 2) q.options = ['選択肢1', '選択肢2'];
    }
    if (field === 'options') q.options = e.target.value.split('\n').map((s) => s.trim()).filter(Boolean);
    if (field === 'show_if') {
      const [qi, oi] = e.target.value.split(':');
      q.show_if = e.target.value === '' ? null : { question_index: Number(qi), option: Number(oi) };
    }
    if (['question_type', 'options'].includes(field)) {
      if (svDropBrokenConditions() > 0) toast('成り立たなくなった表示条件を外しました', 'info');
      svRenderQuestions(false);
    }
  });
  box.addEventListener('click', (e) => {
    const move = e.target.closest('[data-q-move]');
    const remove = e.target.closest('[data-q-remove]');
    if (move) {
      const i = Number(move.dataset.qIndex);
      const j = i + Number(move.dataset.qMove);
      [SvEditor.questions[i], SvEditor.questions[j]] = [SvEditor.questions[j], SvEditor.questions[i]];
      // 表示条件は設問の位置で参照しているため、入れ替えた2問への参照を付け替える。
      SvEditor.questions.forEach((q) => {
        if (!q.show_if) return;
        if (q.show_if.question_index === i) q.show_if.question_index = j;
        else if (q.show_if.question_index === j) q.show_if.question_index = i;
      });
    } else if (remove) {
      const i = Number(remove.dataset.qRemove);
      SvEditor.questions.splice(i, 1);
      SvEditor.questions.forEach((q) => {
        if (q.show_if && q.show_if.question_index > i) q.show_if.question_index -= 1;
        else if (q.show_if && q.show_if.question_index === i) q.show_if = null;
      });
    } else {
      return;
    }
    if (svDropBrokenConditions() > 0) toast('成り立たなくなった表示条件を外しました', 'info');
    svRenderQuestions(false);
  });
}

function svOpenEditor(survey, readOnly = false) {
  SvEditor.questions = (survey?.questions || []).map((q) => ({
    section: q.section || '', question_type: q.question_type, title: q.title,
    options: [...(q.options || [])], is_required: Boolean(q.is_required),
    show_if: q.show_if ? { ...q.show_if } : null,
  }));
  const dis = readOnly ? ' disabled' : '';
  const body = `
    ${readOnly ? '<div class="alert alert-secondary small py-2">配信済みのアンケートは編集できません。変更する場合は複製してください。</div>' : ''}
    <div class="mb-2"><label class="form-label">題名</label>
      <input class="form-control" id="svTitle" maxlength="500" value="${esc(survey?.title || '')}"${dis}></div>
    <div class="mb-2"><label class="form-label">説明（回答画面の冒頭に表示）</label>
      <textarea class="form-control" id="svDescription" rows="2" maxlength="5000"${dis}>${esc(survey?.description || '')}</textarea></div>
    <div class="form-check mb-3">
      <input class="form-check-input" type="checkbox" id="svAnonymous"${Number(survey?.is_anonymous) === 1 ? ' checked' : ''}${dis}>
      <label class="form-check-label" for="svAnonymous">匿名にする（回答と回答者を結び付けずに保存し、回答者別の出力をしない）</label>
    </div>
    <div id="svQuestionList"></div>
    ${readOnly ? '' : '<button type="button" class="btn btn-outline-primary btn-sm" id="svAddQuestion"><i class="bi bi-plus"></i> 設問を追加</button>'}`;
  const title = readOnly ? 'アンケートの設問' : (survey?.id ? 'アンケートを編集' : 'アンケートを作成');
  const save = async () => {
    const payload = {
      title: $('#svTitle').value, description: $('#svDescription').value,
      is_anonymous: $('#svAnonymous').checked, questions: SvEditor.questions,
    };
    if (survey?.id) await api('api/surveys.php', { method: 'POST', query: { action: 'update' }, body: { id: Number(survey.id), ...payload } });
    else await api('api/surveys.php', { method: 'POST', query: { action: 'create' }, body: payload });
    toast('保存しました', 'ok');
    renderSurveys().catch((e) => toast(e.message, 'err'));
  };
  if (readOnly) showInfoModal(title, body, { size: 'xl' });
  else showModal(title, body, save, { size: 'xl' });
  svRenderQuestions(readOnly);
  if (!readOnly) {
    svBindEditor();
    $('#svAddQuestion').addEventListener('click', () => {
      SvEditor.questions.push({ section: '', question_type: 'single', title: '', options: ['選択肢1', '選択肢2'], is_required: false, show_if: null });
      svRenderQuestions(false);
    });
  }
}

async function svEdit(id, readOnly) {
  const { survey } = await api('api/surveys.php', { query: { action: 'get', id } });
  svOpenEditor(survey, readOnly);
}

/* ========== 配信 ========== */

async function svDeliver(id) {
  const survey = SvState.surveys.find((s) => Number(s.id) === Number(id));
  const { groups } = await api('api/groups.php', { query: { action: 'list' } });
  const active = (groups || []).filter((g) => (g.status || 'active') === 'active');
  const body = `
    <div class="alert alert-info small py-2">配信を作ると対象者を確定し、回答用のリンクを発行します。${SvState.mailEnabled ? '案内メールは、配信一覧の「案内メール」で別に送ります。' : 'メールは送りません。回答用 URL の CSV を出力して配布してください。'}配信を作った後は設問を編集できません。</div>
    <div class="mb-2"><label class="form-label">配信名</label>
      <input class="form-control" id="svDeliveryTitle" maxlength="500" value="${esc((survey?.title || '') + ' ' + new Date().toISOString().slice(0, 10))}"></div>
    <div class="mb-2"><label class="form-label">回答の締切（任意）</label>
      <input type="datetime-local" class="form-control" id="svDeadline"></div>
    <div class="mb-1"><label class="form-label">配信先のグループ</label></div>
    <div class="border rounded p-2" style="max-height:40vh;overflow:auto">
      ${active.length === 0 ? '<div class="text-muted small">グループがありません</div>' : active.map((g) => `
        <div class="form-check"><input class="form-check-input" type="checkbox" value="${Number(g.id)}" id="svGroup${Number(g.id)}" data-sv-group>
        <label class="form-check-label" for="svGroup${Number(g.id)}">${esc(g.name)}${g.kind === 'all' ? '（全員）' : ''}${g.member_count != null ? `（${Number(g.member_count)}名）` : ''}</label></div>`).join('')}
    </div>`;
  showModal('アンケートを配信', body, async () => {
    const groupIds = $$('[data-sv-group]:checked').map((c) => Number(c.value));
    const deadline = $('#svDeadline').value ? $('#svDeadline').value.replace('T', ' ') + ':00' : null;
    const res = await api('api/surveys.php', { method: 'POST', query: { action: 'deliver' },
      body: { survey_id: Number(id), title: $('#svDeliveryTitle').value, deadline, group_ids: groupIds } });
    toast(`${Number(res.assigned)}名に配信を作成しました`, 'ok');
    renderSurveys().catch((e) => toast(e.message, 'err'));
  });
}

async function svPost(action, body, message) {
  const res = await api('api/surveys.php', { method: 'POST', query: { action }, body });
  toast(message(res), 'ok');
  await renderSurveys();
}

/* ========== 結果 ========== */

async function svResults(deliveryId) {
  const { results: r } = await api('api/surveys.php', { query: { action: 'results', delivery_id: deliveryId } });
  const questions = r.questions.map((q, i) => {
    let inner;
    if (q.question_type === 'text') {
      inner = q.texts.length === 0 ? '<div class="text-muted small">回答はありません</div>'
        : `<ul class="small mb-0">${q.texts.map((t) => `<li style="white-space:pre-wrap">${esc(t)}</li>`).join('')}</ul>`;
    } else {
      const total = Math.max(1, q.answered);
      inner = q.options.map((label, oi) => {
        const n = Number(q.counts[oi] || 0);
        const pct = Math.round((n / total) * 100);
        return `<div class="small d-flex justify-content-between"><span>${esc(label)}</span><span>${n}件（${pct}%）</span></div>
          <div class="progress mb-1" style="height:8px" role="img" aria-label="${esc(label)} ${pct}%"><div class="progress-bar" style="width:${pct}%"></div></div>`;
      }).join('');
    }
    return `<div class="mb-3"><div class="fw-bold small mb-1">${i + 1}. ${esc(q.title)}
      <span class="text-muted fw-normal">（${SV_TYPE_LABEL[q.question_type]}、回答 ${Number(q.answered)}件）</span></div>${inner}</div>`;
  }).join('');
  const depts = r.by_department.map((d) => `<tr><td>${esc(d.department)}</td>
    <td class="text-end">${Number(d.answered)} / ${Number(d.assigned)}</td><td class="text-end">${Number(d.rate)}%</td></tr>`).join('');
  const body = `
    <div class="d-flex gap-3 flex-wrap mb-3">
      <div><div class="small text-muted">回答率</div><div class="fs-4 fw-bold">${Number(r.rate)}%</div></div>
      <div><div class="small text-muted">回答 / 対象</div><div class="fs-4 fw-bold">${Number(r.answered)} / ${Number(r.assigned)}</div></div>
      <div class="ms-auto d-flex gap-2 align-items-start">
        <a class="btn btn-outline-secondary btn-sm" href="${svDownloadUrl('export_xlsx', deliveryId)}">Excel</a>
        <a class="btn btn-outline-secondary btn-sm" href="${svDownloadUrl('export_csv', deliveryId)}">CSV</a>
      </div>
    </div>
    <div class="small text-muted mb-2">テスト用の対象者は、母数と回答の両方から除いています。${r.delivery.is_anonymous ? '匿名のため、回答者別の出力はありません。' : ''}</div>
    <h6>部署ごとの回答率</h6>
    <table class="table table-sm small"><thead><tr><th>部署</th><th class="text-end">回答 / 対象</th><th class="text-end">回答率</th></tr></thead>
      <tbody>${depts || emptyRow(3)}</tbody></table>
    <h6>設問ごとの回答</h6>${questions}`;
  showInfoModal(`結果: ${r.delivery.title}`, body, { size: 'lg' });
}

/* ========== 登録 ========== */

VIEWS.surveys = renderSurveys;

document.addEventListener('DOMContentLoaded', () => {
  $('#svNewBtn')?.addEventListener('click', () => svOpenEditor(null));
  $('#svFromTemplateBtn')?.addEventListener('click', () => {
    const key = $('#svTemplateSelect').value;
    if (!key) return;
    svPost('create', { template: key }, () => '雛形から下書きを作成しました。内容を確認して配信してください').catch((e) => toast(e.message, 'err'));
  });
  $('#surveysPanel')?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-sv-action]');
    if (!btn || btn.disabled) return;
    const id = Number(btn.dataset.id);
    const run = (p) => p.catch((err) => toast(err.message, 'err'));
    switch (btn.dataset.svAction) {
      case 'edit': return run(svEdit(id, false));
      case 'view': return run(svEdit(id, true));
      case 'deliver': return run(svDeliver(id));
      case 'duplicate': return run(svPost('duplicate', { id }, () => '複製しました（下書き）'));
      case 'delete':
        if (!confirm('この下書きを削除しますか。')) return undefined;
        return run(svPost('delete', { id }, () => '削除しました'));
      case 'results': return run(svResults(id));
      case 'close':
        if (!confirm('この配信を終了しますか。終了すると回答を受け付けません。')) return undefined;
        return run(svPost('close', { delivery_id: id }, () => '配信を終了しました'));
      case 'invite':
        if (!confirm('まだ案内していない未回答者へ案内メールを送ります。実在の従業員に届きます。よろしいですか。')) return undefined;
        return run(svPost('send_invitations', { delivery_id: id }, (r) => `案内メール: 送信 ${Number(r.sent)} / 失敗 ${Number(r.failed)}`));
      case 'remind':
        if (!confirm('締切が2日以内で、まだ催促していない未回答者へ催促メールを送ります。よろしいですか。')) return undefined;
        return run(svPost('remind', { delivery_id: id }, (r) => `催促: 送信 ${Number(r.sent)} / 失敗 ${Number(r.failed)}`));
      default: return undefined;
    }
  });
});

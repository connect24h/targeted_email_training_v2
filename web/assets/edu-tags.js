/**
 * 分野のタグ(C1、G09)。教材バンクの「分野のタグ」のタブ(一覧、作成、編集、削除)、設問の編集のタグの選択、
 * 設問の一覧のタグでの絞り込み、教育レポートの「分野」のタブ(分野ごと、部署×分野、月の推移)。
 *
 * app.js の renderEduQuestions、eduQuestionForm、eduQFormBody、eduRepRenderTab から呼ばれる(読み込みは app.js の後)。
 * 共有のタグの変更はシステム管理者だけ(サーバーでも拒む)。利用者が入力した文字列(タグの名前、説明、部署)は必ず esc() を通す。
 */

const EduTagState = { tags: [] };

async function refreshEduTags() {
  const { tags } = await api('api/edu_tags.php', { query: { action: 'list' } });
  EduTagState.tags = tags || [];
  renderEduTagRows();
  renderEduTagFilter();
}
function eduTagById(id) { return EduTagState.tags.find((t) => Number(t.id) === Number(id)); }
function eduTagIsShared(t) { return Number(t.is_shared) === 1; }
function eduTagCanEdit(t) {
  return roleAtLeast(State.user?.role, 'operator') && (!eduTagIsShared(t) || State.user.role === 'superadmin');
}
function eduTagKindBadge(t) {
  return eduTagIsShared(t) ? '<span class="badge bg-info">共有</span>' : '<span class="badge bg-primary">自組織</span>';
}
function eduTagNameCell(t) {
  return t.parent_id !== null && t.parent_id !== undefined
    ? `<span class="text-muted ms-3 me-1" aria-hidden="true">└</span>${esc(t.name)}`
    : `<strong>${esc(t.name)}</strong>`;
}

/* ---- 教材バンクの「分野のタグ」のタブ ---- */
function renderEduTagRows() {
  const tb = $('#eduTagsBody');
  if (!tb) return;
  const tags = EduTagState.tags;
  if (!tags.length) {
    tb.innerHTML = eduNoteRow(5, 'まだ分野のタグがありません。「新規タグ」から作ってください');
    return;
  }
  const canCreate = roleAtLeast(State.user?.role, 'operator');
  tb.innerHTML = tags.map((t) => {
    const id = Number(t.id);
    const isParent = t.parent_id === null;
    const buttons = [];
    if (isParent && canCreate) buttons.push(`<button type="button" class="btn btn-sm btn-outline-primary" data-tag-action="child" data-tag-id="${id}" title="このタグの下に子のタグを作る"><i class="bi bi-diagram-2" aria-hidden="true"></i> 子のタグ</button>`);
    if (eduTagCanEdit(t)) {
      buttons.push(`<button type="button" class="btn btn-sm btn-outline-secondary" data-tag-action="edit" data-tag-id="${id}" aria-label="${esc(t.path)} を編集"><i class="bi bi-pencil" aria-hidden="true"></i> 編集</button>`);
      buttons.push(`<button type="button" class="btn btn-sm btn-outline-danger" data-tag-action="delete" data-tag-id="${id}" aria-label="${esc(t.path)} を削除"><i class="bi bi-trash" aria-hidden="true"></i> 削除</button>`);
    }
    return `<tr data-tag-row="${id}"><td>${eduTagNameCell(t)}</td><td class="small">${esc(t.description || '')}</td>
      <td>${eduTagKindBadge(t)}</td><td>${Number(t.question_count || 0)}</td>
      <td class="text-nowrap">${buttons.length ? buttons.join(' ') : '<span class="text-muted small">閲覧のみ</span>'}</td></tr>`;
  }).join('');
}

function eduTagForm(tag, parentId) {
  const self = tag.id ? Number(tag.id) : null;
  const hasChildren = self !== null && EduTagState.tags.some((t) => Number(t.parent_id) === self);
  const current = tag.id ? tag.parent_id : parentId;
  const parents = EduTagState.tags.filter((t) => t.parent_id === null && Number(t.id) !== self);
  const parentOpts = parents.map((t) => `<option value="${Number(t.id)}"${Number(current) === Number(t.id) ? ' selected' : ''}>${esc(t.name)}${eduTagIsShared(t) ? '（共有）' : ''}</option>`).join('');
  const sharedDefault = parentId ? eduTagIsShared(eduTagById(parentId) || {}) : !State.activeTenantId;
  const sharedBox = !tag.id && State.user?.role === 'superadmin'
    ? `<div class="form-check"><input class="form-check-input" type="checkbox" id="eduTagShared"${sharedDefault ? ' checked' : ''}>
        <label class="form-check-label" for="eduTagShared">共有のタグにする（すべての組織で使えます）</label></div>` : '';
  return `<form id="eduTagForm" novalidate>
    <div class="mb-2"><label class="form-label" for="eduTagName">名前</label>
      <input class="form-control" id="eduTagName" name="name" maxlength="100" value="${esc(tag.name || '')}" required></div>
    <div class="mb-2"><label class="form-label" for="eduTagParent">親のタグ</label>
      <select class="form-select" id="eduTagParent" name="parent_id"${hasChildren ? ' disabled aria-describedby="eduTagParentHelp"' : ''}>
        <option value="">なし（親のタグにする）</option>${parentOpts}</select>
      ${hasChildren ? '<div class="form-text" id="eduTagParentHelp">子のタグがあるので、ほかのタグの下には移せません。</div>' : ''}</div>
    <div class="mb-2"><label class="form-label" for="eduTagDesc">説明（任意）</label>
      <textarea class="form-control" id="eduTagDesc" name="description" rows="2" maxlength="500">${esc(tag.description || '')}</textarea></div>
    <div class="mb-2"><label class="form-label" for="eduTagSort">並び順（小さいほど上）</label>
      <input class="form-control" id="eduTagSort" name="sort_order" type="number" min="0" value="${Number(tag.sort_order || 0)}"></div>
    ${sharedBox}
  </form>`;
}
function openEduTagForm(tag = {}, parentId = null) {
  const title = tag.id ? '分野のタグを編集' : (parentId ? '子のタグを作る' : '新規タグ');
  showModal(title, eduTagForm(tag, parentId), async () => {
    const f = $('#eduTagForm');
    const body = {
      name: f.name.value.trim(),
      description: f.description.value.trim(),
      sort_order: Math.max(0, parseInt(f.sort_order.value, 10) || 0),
    };
    if (!f.parent_id.disabled) body.parent_id = f.parent_id.value ? Number(f.parent_id.value) : null;
    if (tag.id) {
      await api('api/edu_tags.php', { method: 'POST', query: { action: 'update' }, body: { ...body, id: Number(tag.id) } });
      toast('更新しました', 'ok');
    } else {
      if ($('#eduTagShared')?.checked) body.shared = true;
      await api('api/edu_tags.php', { method: 'POST', query: { action: 'create' }, body });
      toast('作成しました', 'ok');
    }
    renderEduQuestions();
  });
}
function confirmDeleteEduTag(tag) {
  showModal('分野のタグを削除', `<p class="mb-1">分野のタグ「${esc(tag.path)}」を削除しますか？</p>
    <p class="small text-muted mb-0">子のタグがあるタグと、設問に付いているタグは削除できません。</p>`, async () => {
    await api('api/edu_tags.php', { method: 'POST', query: { action: 'delete' }, body: { id: Number(tag.id) } });
    toast('削除しました', 'ok');
    renderEduQuestions();
  }, { saveLabel: '削除する', saveClass: 'btn-danger' });
}
function handleEduTagAction(event) {
  const btn = event.target.closest('[data-tag-action]');
  if (!btn) return;
  const tag = eduTagById(btn.dataset.tagId);
  if (!tag) return;
  if (btn.dataset.tagAction === 'child') openEduTagForm({}, Number(tag.id));
  if (btn.dataset.tagAction === 'edit') openEduTagForm(tag);
  if (btn.dataset.tagAction === 'delete') confirmDeleteEduTag(tag);
}

/* ---- 設問の一覧: タグでの絞り込みと、行のタグの表示 ---- */
function renderEduTagFilter() {
  const select = $('#eduQTagFilter');
  if (!select) return;
  const current = select.value;
  select.innerHTML = '<option value="">すべての分野</option>' + EduTagState.tags.map((t) =>
    `<option value="${Number(t.id)}">${t.parent_id !== null ? '　└ ' : ''}${esc(t.name)}</option>`).join('');
  // 消えたタグで絞っていたら、絞り込みを外す
  select.value = eduTagById(current) ? current : '';
}
function eduTagFilterId() { return Number($('#eduQTagFilter')?.value || 0); }
function eduTagBadges(tags) {
  if (!tags || !tags.length) return '';
  return `<div class="mt-1 edu-tag-badges">${tags.map((t) => `<span class="badge rounded-pill text-bg-light border me-1">${esc(t.path)}</span>`).join('')}</div>`;
}

/* ---- 設問の編集: タグを複数選ぶ(共有カテゴリの設問は共有のタグだけ) ---- */
function eduTagPickerOptions(selected, sharedOnly) {
  const chosen = new Set(selected.map(Number));
  const tags = EduTagState.tags.filter((t) => !sharedOnly || eduTagIsShared(t));
  if (!tags.length) return '<div class="small text-muted">付けられる分野のタグがありません（教材バンクの「分野のタグ」で作れます）</div>';
  return tags.map((t) => {
    const id = Number(t.id);
    return `<div class="form-check${t.parent_id !== null ? ' ms-4' : ''}"><input class="form-check-input" type="checkbox" name="tag_ids" value="${id}" id="eduQTag${id}"${chosen.has(id) ? ' checked' : ''}>
      <label class="form-check-label" for="eduQTag${id}">${esc(t.name)}${eduTagIsShared(t) ? ' <span class="badge bg-info">共有</span>' : ''}</label></div>`;
  }).join('');
}
function eduTagPickerHtml(selected, sharedOnly) {
  return `<div class="mb-2"><div class="form-label" id="eduQTagsLabel">分野のタグ（複数選べます）</div>
    <div id="eduQTagPicker" class="border rounded p-2 edu-tag-picker" role="group" aria-labelledby="eduQTagsLabel">${eduTagPickerOptions(selected, sharedOnly)}</div>
    ${sharedOnly ? '<div class="form-text">共有カテゴリの設問には、共有のタグだけを付けられます。</div>' : ''}</div>`;
}
function eduTagPickerSelected() { return $$('#eduQTagPicker input[name="tag_ids"]:checked').map((i) => Number(i.value)); }
function eduTagPickerRefresh(sharedOnly) {
  const box = $('#eduQTagPicker');
  if (box) box.innerHTML = eduTagPickerOptions(eduTagPickerSelected(), sharedOnly);
}

/* ---- 教育レポートの「分野」のタブ ---- */
function eduTagRateCell(rate, answered) {
  if (rate === null || rate === undefined) return '<td class="text-muted"><span class="visually-hidden">解答なし</span></td>';
  return `<td title="回答 ${Number(answered)} 件">${esc(String(rate))}%</td>`;
}
function eduRepTagCsvUrl() {
  const params = new URLSearchParams({ action: 'tag_matrix', format: 'csv' });
  if (State.user?.role === 'superadmin' && State.activeTenantId) params.set('tenant_id', State.activeTenantId);
  return `api/edu_report.php?${params}`;
}
function renderEduRepTags() {
  $('#eduRepTagMatrixCsv').href = eduRepTagCsvUrl();
  const load = api('api/edu_report.php', { query: { action: 'tags' } });
  const byTag = eduRepFill('#eduRepTagsBody', async () => {
    const { by_tag: rows } = await load;
    if (!(rows || []).length) return eduNoteRow(3, '分野のタグがまだありません。教材バンクの「分野のタグ」で作り、設問に付けてください');
    return rows.map((t) => `<tr${t.parent_id === null ? ' class="fw-semibold"' : ''}><td>${eduTagNameCell(t)} ${eduTagIsShared(t) ? '<span class="badge bg-info">共有</span>' : ''}</td>
      <td>${Number(t.answered)}</td>${eduTagRateCell(t.correct_rate, t.answered)}</tr>`).join('');
  });
  const matrix = eduRepFill('#eduRepTagMatrixBody', async () => {
    const { matrix: m } = await load;
    $('#eduRepTagMatrixHead').innerHTML = `<tr><th>部署</th>${m.tags.map((t) => `<th>${esc(t.name)}</th>`).join('')}</tr>`;
    if (!m.departments.length) return eduNoteRow(m.tags.length + 1, 'タグの付いた設問の解答がまだありません');
    return m.departments.map((d) => `<tr><th scope="row" class="fw-normal">${esc(d.department)}</th>${d.cells.map((c) => eduTagRateCell(c.correct_rate, c.answered)).join('')}</tr>`).join('');
  });
  const trend = eduRepFill('#eduRepTagTrendBody', async () => {
    const { trend: tr } = await load;
    $('#eduRepTagTrendHead').innerHTML = `<tr><th>分野</th>${tr.months.map((mo) => `<th class="text-nowrap">${esc(mo.slice(2).replace('-', '/'))}</th>`).join('')}</tr>`;
    if (!tr.series.length) return eduNoteRow(tr.months.length + 1, '直近12か月に、タグの付いた設問の解答がありません');
    return tr.series.map((s) => `<tr><th scope="row" class="fw-normal text-nowrap">${esc(s.name)}</th>${s.points.map((p) => eduTagRateCell(p.correct_rate, p.answered)).join('')}</tr>`).join('');
  });
  return Promise.all([byTag, matrix, trend]);
}

document.addEventListener('DOMContentLoaded', () => {
  $('#eduTagNewBtn')?.addEventListener('click', () => openEduTagForm());
  $('#eduTagsBody')?.addEventListener('click', handleEduTagAction);
  $('#eduQTagFilter')?.addEventListener('change', () => renderEduQuestions());
});

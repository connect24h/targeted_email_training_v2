'use strict';
/* 定期キャンペーン管理。生成物は常にdraftであり、送信予約は作成しない。 */

let campaignAutomationState = { rows: {}, campaigns: [], groups: [] };

function automationFrequencyLabel(value) {
  return value === 'quarterly' ? '四半期' : '毎月';
}

function automationStatusBadge(value) {
  return value === 'active'
    ? '<span class="badge bg-success">有効</span>'
    : '<span class="badge bg-secondary">停止中</span>';
}

function automationLastRun(row) {
  if (!row.last_run_status) return '最終実行: なし';
  if (row.last_run_status === 'generated') {
    return `最終実行: draft #${Number(row.last_generated_campaign_id)} 生成`;
  }
  if (row.last_run_status === 'failed') {
    return `最終実行: 失敗（${esc(row.last_run_error_code || 'unknown')}）`;
  }
  return '最終実行: 処理中';
}

function automationScheduleLabel(row) {
  const frequency = automationFrequencyLabel(row.frequency);
  const time = row.time_mode === 'random_window'
    ? `${row.send_window_start}〜${row.send_window_end}の間でランダム`
    : row.send_window_start;
  const assignment = row.assignment_mode === 'rotate'
    ? `個別rotation ${Number(row.completed_occurrences || 0)}/${Number(row.max_occurrences || 0)}回`
    : '通常配分';
  return `${frequency} ${Number(row.day_of_month)}日 ${esc(time)}（${Number(row.generation_lead_days)}日前に生成）<div class="small text-muted">${assignment}</div>`;
}

function automationActions(row, canOperate) {
  const id = Number(row.id);
  const statusAction = row.status === 'active' ? 'pause' : 'resume';
  const statusLabel = row.status === 'active' ? '停止' : '再開';
  const operatorButtons = canOperate ? `
    <button class="btn btn-sm btn-outline-secondary" data-automation-action="edit" data-id="${id}" title="編集"><i class="bi bi-pencil"></i></button>
    <button class="btn btn-sm btn-outline-secondary" data-automation-action="${statusAction}" data-id="${id}">${statusLabel}</button>
    <button class="btn btn-sm btn-primary" data-automation-action="generate" data-id="${id}"${row.status !== 'active' ? ' disabled' : ''}>draft生成</button>
    <button class="btn btn-sm btn-outline-danger" data-automation-action="delete" data-id="${id}">削除</button>` : '';
  return `<div class="d-flex flex-wrap gap-1">
    <button class="btn btn-sm btn-outline-info" data-automation-action="preview" data-id="${id}">確認</button>
    ${operatorButtons}
  </div>`;
}

function renderAutomationRows(automations) {
  const canOperate = roleAtLeast(State.user.role, 'operator');
  const body = $('#campaignAutomationsBody');
  body.innerHTML = automations.length ? automations.map((row) => `
    <tr>
      <td><strong>${esc(row.name)}</strong><div class="small text-muted">${automationLastRun(row)} / 履歴 ${Number(row.run_count || 0)}件</div></td>
      <td>${esc(row.source_campaign_name)}</td>
      <td>${automationScheduleLabel(row)}</td>
      <td>${esc(fmtDate(row.next_due_at))}</td>
      <td>${automationStatusBadge(row.status)}</td>
      <td>${automationActions(row, canOperate)}</td>
    </tr>`).join('') : emptyRow(6);
}

async function renderCampaignAutomations() {
  const [automationData, campaignData, groupData] = await Promise.all([
    api('api/campaign_automations.php', { query: { action: 'list' } }),
    api('api/campaigns.php', { query: { action: 'list' } }),
    api('api/groups.php', { query: { action: 'list' } }),
  ]);
  const automations = automationData.automations || [];
  campaignAutomationState = {
    rows: Object.fromEntries(automations.map((row) => [Number(row.id), row])),
    campaigns: campaignData.campaigns || [],
    groups: groupData.groups || [],
  };
  renderAutomationRows(automations);
}

function automationOptions(items, selectedIds) {
  const selected = new Set((selectedIds || []).map(Number));
  return items.map((item) => `<option value="${Number(item.id)}"${selected.has(Number(item.id)) ? ' selected' : ''}>${esc(item.name)}</option>`).join('');
}

function automationCampaignOptions(items, selectedId) {
  return items.map((item) => {
    const count = Number(item.content_count || 0);
    const label = `${item.name}（${count}コンテンツ / ${item.status}）`;
    return `<option value="${Number(item.id)}" data-content-count="${count}" data-status="${esc(item.status)}" data-delivery="${esc(item.content_delivery)}"${Number(item.id) === Number(selectedId) ? ' selected' : ''}>${esc(label)}</option>`;
  }).join('');
}

function automationForm(row = {}) {
  const fixed = (row.time_mode || 'fixed') === 'fixed';
  const frequency = row.frequency || 'monthly';
  const assignmentMode = row.assignment_mode || 'static';
  return `<form id="campaignAutomationForm">
    <div class="mb-2"><label class="form-label">ルール名</label><input class="form-control" name="name" maxlength="200" value="${esc(row.name)}" required></div>
    <div class="mb-2"><label class="form-label">元キャンペーン</label><select class="form-select" name="source_campaign_id" required><option value="">選択してください</option>${automationCampaignOptions(campaignAutomationState.campaigns, row.source_campaign_id)}</select></div>
    <div class="mb-2"><label class="form-label">対象グループ</label><select class="form-select" name="group_ids" multiple size="5" required>${automationOptions(campaignAutomationState.groups, row.group_ids)}</select><div class="form-text">Ctrl / Command キーで複数選択できます。</div></div>
    <div class="row g-2 mb-2">
      <div class="col-sm-8"><label class="form-label">従業員への割当</label><select class="form-select" name="assignment_mode"><option value="static"${assignmentMode === 'static' ? ' selected' : ''}>通常の均等割り</option><option value="rotate"${assignmentMode === 'rotate' ? ' selected' : ''}>個別ローテーション（前回と別のコンテンツ）</option></select><div class="form-text" id="automationAssignmentHint">ローテーションは完了済み・均等割り・2コンテンツ以上の元キャンペーンで利用できます。</div></div>
      <div class="col-sm-4"><label class="form-label">実施回数</label><div class="input-group"><input class="form-control" type="number" name="max_occurrences" min="1" max="120" value="${Number(row.max_occurrences || 6)}" required><span class="input-group-text">回</span></div></div>
    </div>
    <div class="row g-2 mb-2">
      <div class="col-sm-4"><label class="form-label">周期</label><select class="form-select" name="frequency"><option value="monthly"${frequency === 'monthly' ? ' selected' : ''}>毎月</option><option value="quarterly"${frequency === 'quarterly' ? ' selected' : ''}>四半期</option></select></div>
      <div class="col-sm-4"><label class="form-label">実施日</label><input class="form-control" type="number" name="day_of_month" min="1" max="28" value="${Number(row.day_of_month || 15)}" required></div>
      <div class="col-sm-4"><label class="form-label">draft生成</label><div class="input-group"><input class="form-control" type="number" name="generation_lead_days" min="0" max="28" value="${Number(row.generation_lead_days ?? 5)}" required><span class="input-group-text">日前</span></div></div>
    </div>
    <div class="row g-2 mb-2">
      <div class="col-sm-4"><label class="form-label">時刻指定</label><select class="form-select" name="time_mode"><option value="fixed"${fixed ? ' selected' : ''}>固定</option><option value="random_window"${fixed ? '' : ' selected'}>時間帯からランダム</option></select></div>
      <div class="col-sm-4"><label class="form-label">開始</label><input class="form-control" type="time" name="send_window_start" value="${esc(row.send_window_start || '09:00')}" required></div>
      <div class="col-sm-4" id="automationWindowEnd"><label class="form-label">終了</label><input class="form-control" type="time" name="send_window_end" value="${esc(row.send_window_end || '17:00')}"></div>
    </div>
    <div class="alert alert-warning py-2 small mb-0">この設定はレビュー用draftだけを生成し、自動送信や送信予約は行いません。</div>
  </form>`;
}

function selectedAutomationGroups(form) {
  return Array.from(form.group_ids.selectedOptions, (option) => Number(option.value));
}

function automationPayload(form, id = null) {
  const groupIds = selectedAutomationGroups(form);
  if (!form.reportValidity()) throw new Error('必須項目を確認してください');
  if (!groupIds.length) throw new Error('対象グループを1件以上選択してください');
  const payload = {
    name: form.name.value.trim(),
    source_campaign_id: Number(form.source_campaign_id.value),
    group_ids: groupIds,
    frequency: form.frequency.value,
    day_of_month: Number(form.day_of_month.value),
    generation_lead_days: Number(form.generation_lead_days.value),
    assignment_mode: form.assignment_mode.value,
    max_occurrences: Number(form.max_occurrences.value),
    time_mode: form.time_mode.value,
    send_window_start: form.send_window_start.value,
    send_window_end: form.time_mode.value === 'random_window' ? form.send_window_end.value : null,
  };
  return id === null ? payload : { ...payload, id };
}

function syncAutomationTimeMode() {
  const form = $('#campaignAutomationForm');
  const random = form.time_mode.value === 'random_window';
  $('#automationWindowEnd').classList.toggle('d-none', !random);
  form.send_window_end.required = random;
}

function syncAutomationAssignment() {
  const form = $('#campaignAutomationForm');
  const option = form.source_campaign_id.selectedOptions[0];
  const rotate = form.assignment_mode.value === 'rotate';
  const contentCount = Number(option?.dataset.contentCount || 0);
  const validSource = option?.dataset.status === 'done'
    && option?.dataset.delivery === 'distribute' && contentCount >= 2;
  form.source_campaign_id.setCustomValidity(rotate && !validSource
    ? '個別ローテーションには完了済み・均等割り・2コンテンツ以上のキャンペーンが必要です'
    : '');
  form.max_occurrences.max = String(rotate && contentCount > 0 ? Math.min(120, contentCount) : 120);
  if (rotate && contentCount > 0 && Number(form.max_occurrences.value) > contentCount) {
    form.max_occurrences.value = String(contentCount);
  }
  const hint = $('#automationAssignmentHint');
  hint.textContent = rotate && contentCount > 0
    ? `この元キャンペーンでは重複なしで最大${contentCount}回実施できます。`
    : 'ローテーションは完了済み・均等割り・2コンテンツ以上の元キャンペーンで利用できます。';
}

function openAutomationModal(id = null) {
  const row = id === null ? {} : campaignAutomationState.rows[id];
  if (id !== null && !row) return;
  showModal(id === null ? '定期キャンペーン作成' : '定期キャンペーン編集', automationForm(row), async () => {
    const action = id === null ? 'create' : 'update';
    await api('api/campaign_automations.php', {
      method: 'POST', query: { action }, body: automationPayload($('#campaignAutomationForm'), id),
    });
    toast(id === null ? '自動化ルールを作成しました' : '自動化ルールを更新しました', 'ok');
    await renderCampaignAutomations();
  });
  $('#campaignAutomationForm').time_mode.addEventListener('change', syncAutomationTimeMode);
  $('#campaignAutomationForm').assignment_mode.addEventListener('change', syncAutomationAssignment);
  $('#campaignAutomationForm').source_campaign_id.addEventListener('change', syncAutomationAssignment);
  syncAutomationTimeMode();
  syncAutomationAssignment();
}

async function previewAutomation(id) {
  const { preview } = await api('api/campaign_automations.php', { query: { action: 'preview', id } });
  const rule = campaignAutomationState.rows[id];
  showInfoModal('次回draftの確認', `
    <dl class="row mb-0">
      <dt class="col-5">ルール</dt><dd class="col-7">${esc(rule?.name)}</dd>
      <dt class="col-5">対象者</dt><dd class="col-7">${Number(preview.target_count || 0)}名</dd>
      <dt class="col-5">コンテンツ</dt><dd class="col-7">${Number(preview.content_count || 0)}件</dd>
      <dt class="col-5">進捗</dt><dd class="col-7">${Number(preview.completed_occurrences || 0)} / ${Number(rule?.max_occurrences || 0)}回</dd>
      <dt class="col-5">実施予定</dt><dd class="col-7">${esc(fmtDate(preview.send_window_start_at))}</dd>
      <dt class="col-5">draft生成予定</dt><dd class="col-7">${esc(fmtDate(preview.next_due_at))}</dd>
    </dl>
    <div class="alert alert-info py-2 small mt-3 mb-0">確認用draftのみを生成します。メールは送信されません。</div>`);
}

async function changeAutomationStatus(id, action) {
  const label = action === 'pause' ? '停止' : '再開';
  if (!confirm(`この自動化ルールを${label}しますか？`)) return;
  await api('api/campaign_automations.php', { method: 'POST', query: { action }, body: { id } });
  toast(`${label}しました`, 'ok');
  await renderCampaignAutomations();
}

async function deleteAutomation(id) {
  if (!confirm('この定期キャンペーンのルール・対象グループとの関連・draft生成履歴を削除します。今後の自動生成は停止します。生成済みキャンペーンとその送信予約・送信ログ・訓練イベントは残ります。削除しますか？')) return;
  await api('api/campaign_automations.php', { method: 'POST', query: { action: 'delete' }, body: { id } });
  toast('定期キャンペーンのルールを削除しました', 'ok');
  await renderCampaignAutomations();
}

async function generateAutomationDraft(id) {
  if (!confirm('次回分のレビュー用draftを生成します。メールは送信されません。続けますか？')) return;
  const result = await api('api/campaign_automations.php', {
    method: 'POST', query: { action: 'generate_now' }, body: { id },
  });
  await renderCampaignAutomations();
  showInfoModal('draftを生成しました', `<p>キャンペーン #${Number(result.campaign.id)} をdraftとして生成しました。</p>
    <div class="alert alert-warning py-2 small">内容と対象者を確認するまで送信操作を行わないでください。</div>
    <button class="btn btn-outline-primary" id="openGeneratedCampaigns">キャンペーン一覧で確認</button>`);
  $('#openGeneratedCampaigns').addEventListener('click', () => { modalInstance.hide(); navigate('campaigns'); });
}

async function handleAutomationAction(event) {
  const button = event.target.closest('[data-automation-action]');
  if (!button) return;
  const id = Number(button.dataset.id);
  const action = button.dataset.automationAction;
  try {
    if (action === 'preview') await previewAutomation(id);
    if (action === 'edit') openAutomationModal(id);
    if (action === 'pause' || action === 'resume') await changeAutomationStatus(id, action);
    if (action === 'generate') await generateAutomationDraft(id);
    if (action === 'delete') await deleteAutomation(id);
  } catch (error) {
    toast(error.message, 'err');
  }
}

VIEWS.campaignAutomations = renderCampaignAutomations;

document.addEventListener('DOMContentLoaded', () => {
  $('#newAutomationBtn').addEventListener('click', () => openAutomationModal());
  $('#campaignAutomationsBody').addEventListener('click', handleAutomationAction);
});

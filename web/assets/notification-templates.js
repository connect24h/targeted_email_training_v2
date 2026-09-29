'use strict';
/* 通知の文面(C2、G35)。ユーザ管理の「通知の文面」タブ(組織管理者以上)。
 * 種類を選び、件名と本文を直し、見本の値で差し込んだプレビューを見て、保存・既定に戻す・本人へのテスト送信をする。
 * app.js の api / $ / esc / toast / State / roleAtLeast / VIEWS を使う。 */

const NotifyTemplates = { items: [], kind: null, dirty: false, previewTimer: null, previewSeq: 0, limits: null };

function ntItem(kind = NotifyTemplates.kind) {
  return NotifyTemplates.items.find((i) => i.kind === kind) || null;
}

function ntMessage(text, kind = 'info') {
  const el = $('#ntMessage');
  if (!el) return;
  el.className = `small mt-2 ${kind === 'err' ? 'text-danger' : kind === 'ok' ? 'text-success' : 'text-muted'}`;
  el.setAttribute('role', kind === 'err' ? 'alert' : 'status');
  el.textContent = text;
}

async function renderNotifyTemplates() {
  if (!State.user || !roleAtLeast(State.user.role, 'tenant_admin')) return;
  const box = $('#ntLoadError');
  box.classList.add('d-none');
  $('#ntEditor').classList.add('d-none');
  if (State.user.role === 'superadmin' && !State.activeTenantId && !State.user.tenant_id) {
    box.textContent = '上のテナントの切り替えで、文面を変えるテナントを選んでください。';
    box.classList.remove('d-none');
    return;
  }
  let data;
  try { data = await api('api/notification_templates.php', { query: { action: 'list' } }); }
  catch (e) {
    box.textContent = `通知の文面を読み込めませんでした（${e.message}）`;
    box.classList.remove('d-none');
    return;
  }
  NotifyTemplates.items = data.items;
  NotifyTemplates.limits = data.limits;
  if (!ntItem()) NotifyTemplates.kind = data.items[0]?.kind || null;
  $('#ntKind').innerHTML = data.items.map((i) => `<option value="${esc(i.kind)}">${esc(i.label)}${i.customized ? '（変更あり）' : ''}</option>`).join('');
  $('#ntTestLimit').textContent = `自分のアドレス（${State.user.email || 'ログイン中のアカウント'}）にだけ送ります。${data.limits.test_send_minutes}分に${data.limits.test_send}回まで。`;
  $('#ntEditor').classList.remove('d-none');
  ntFill();
}

/** 選んだ種類の文面を編集欄に入れる。 */
function ntFill() {
  const item = ntItem();
  if (!item) return;
  $('#ntKind').value = item.kind;
  $('#ntUsedBy').textContent = `送る場面: ${item.used_by}`;
  $('#ntState').innerHTML = item.customized
    ? `<span class="badge bg-primary">この組織の文面</span> <span class="text-muted">${esc(fmtDate(item.updated_at))}${item.updated_by ? ` ${esc(item.updated_by)}` : ''}</span>`
    : '<span class="badge bg-secondary">既定の文面</span>';
  $('#ntSubject').value = item.subject;
  $('#ntBody').value = item.body;
  $('#ntResetBtn').disabled = !item.customized;
  $('#ntVars').innerHTML = item.variables.map((v) => `<button type="button" class="btn btn-outline-secondary btn-sm me-1 mb-1" data-nt-var="${esc(v.name)}" title="${esc(v.label)}">{${esc(v.name)}}${item.required.includes(v.name) ? ' <span class="text-danger" aria-label="必須">*</span>' : ''}</button>`).join('');
  $('#ntVarHelp').innerHTML = item.variables.map((v) => `<li><code>{${esc(v.name)}}</code> ${esc(v.label)}</li>`).join('');
  NotifyTemplates.dirty = false;
  ntMessage('');
  ntPreview();
}

/** 見本の値で差し込んだ件名と本文(保存前の下書きのまま)。 */
async function ntPreview() {
  const item = ntItem();
  if (!item) return;
  const seq = ++NotifyTemplates.previewSeq;
  try {
    const r = await api('api/notification_templates.php', { method: 'POST', query: { action: 'preview' },
      body: { kind: item.kind, subject: $('#ntSubject').value, body: $('#ntBody').value } });
    if (seq !== NotifyTemplates.previewSeq) return;
    $('#ntPreviewSubject').textContent = r.subject;
    $('#ntPreviewBody').textContent = r.body;
    $('#ntPreviewError').classList.add('d-none');
  } catch (e) {
    if (seq !== NotifyTemplates.previewSeq) return;
    $('#ntPreviewError').textContent = e.message;
    $('#ntPreviewError').classList.remove('d-none');
  }
}

function ntSchedulePreview() {
  NotifyTemplates.dirty = true;
  clearTimeout(NotifyTemplates.previewTimer);
  NotifyTemplates.previewTimer = setTimeout(ntPreview, 500);
}

async function ntSave() {
  const item = ntItem();
  if (!item) return;
  const btn = $('#ntSaveBtn');
  btn.disabled = true;
  try {
    await api('api/notification_templates.php', { method: 'POST', query: { action: 'save' },
      body: { kind: item.kind, subject: $('#ntSubject').value, body: $('#ntBody').value } });
    toast('通知の文面を保存しました', 'ok');
    await renderNotifyTemplates();
    ntMessage('保存しました。次に送るメールからこの文面になります。', 'ok');
  } catch (e) { ntMessage(e.message, 'err'); }
  finally { btn.disabled = false; }
}

async function ntReset() {
  const item = ntItem();
  if (!item || !item.customized) return;
  if (!confirm(`「${item.label}」を既定の文面に戻しますか？\nこの組織で変えた件名と本文は消えます。`)) return;
  try {
    await api('api/notification_templates.php', { method: 'POST', query: { action: 'reset' }, body: { kind: item.kind } });
    toast('既定の文面に戻しました', 'ok');
    await renderNotifyTemplates();
    ntMessage('既定の文面に戻しました。', 'ok');
  } catch (e) { ntMessage(e.message, 'err'); }
}

async function ntTestSend() {
  const item = ntItem();
  if (!item) return;
  const btn = $('#ntTestSendBtn');
  btn.disabled = true;
  try {
    const r = await api('api/notification_templates.php', { method: 'POST', query: { action: 'test_send' },
      body: { kind: item.kind, subject: $('#ntSubject').value, body: $('#ntBody').value } });
    ntMessage(`${r.to} にテストのメールを送りました（件名の先頭に「[テスト送信]」が付きます。URL は見本で、開いても使えません）。`, 'ok');
  } catch (e) { ntMessage(e.message, 'err'); }
  finally { btn.disabled = false; }
}

/** 差し込みのボタン: 最後にフォーカスした欄(件名か本文)のカーソルの位置に入れる。 */
let ntLastField = 'ntBody';
function ntInsertVariable(name) {
  const field = $(`#${ntLastField}`) || $('#ntBody');
  const text = `{${name}}`;
  const start = field.selectionStart ?? field.value.length;
  const end = field.selectionEnd ?? field.value.length;
  field.value = field.value.slice(0, start) + text + field.value.slice(end);
  field.focus();
  field.setSelectionRange(start + text.length, start + text.length);
  ntSchedulePreview();
}

// ユーザ管理を読み直した時(テナントの切り替えを含む)、このタブを開いていれば読み直す
if (typeof VIEWS !== 'undefined' && VIEWS.users) {
  const renderUsersBase = VIEWS.users;
  VIEWS.users = async (...args) => {
    await renderUsersBase(...args);
    if ($('#notifyTemplatesPane')?.classList.contains('active')) await renderNotifyTemplates();
  };
}

document.addEventListener('DOMContentLoaded', () => {
  $('#notifyTemplatesTab')?.addEventListener('shown.bs.tab', renderNotifyTemplates);
  $('#ntKind')?.addEventListener('change', (e) => {
    if (NotifyTemplates.dirty && !confirm('保存していない変更があります。破棄して通知を切り替えますか？')) {
      e.target.value = NotifyTemplates.kind;
      return;
    }
    NotifyTemplates.kind = e.target.value;
    ntFill();
  });
  $('#ntSubject')?.addEventListener('input', ntSchedulePreview);
  $('#ntBody')?.addEventListener('input', ntSchedulePreview);
  $('#ntSubject')?.addEventListener('focus', () => { ntLastField = 'ntSubject'; });
  $('#ntBody')?.addEventListener('focus', () => { ntLastField = 'ntBody'; });
  $('#ntVars')?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-nt-var]');
    if (btn) ntInsertVariable(btn.dataset.ntVar);
  });
  $('#ntSaveBtn')?.addEventListener('click', ntSave);
  $('#ntResetBtn')?.addEventListener('click', ntReset);
  $('#ntTestSendBtn')?.addEventListener('click', ntTestSend);
});

/**
 * 不審メール画面。.eml のアップロード、解析結果の一覧・詳細、分類/状態/優先度の管理、VirusTotal 照会。
 *
 * app.js 本体には手を入れず、VIEWS へ後付け登録する(positions.js と同じ方式)。
 * リンク先 URL は <code> で表示するだけで、決して <a href> にしない(誤クリックで危険サイトへ飛ばさない)。
 */

/* ========== 定数 ========== */

const SM_CATEGORY_LABEL = { training: '訓練メール', safe: '安全', spam: '迷惑メール', threat: '脅威', undetermined: '未判定' };
const SM_CATEGORY_BADGE = { training: 'secondary', safe: 'success', spam: 'warning text-dark', threat: 'danger', undetermined: 'light text-dark border' };
const SM_STATUS_LABEL = { open: '未確認', in_progress: '確認中', resolved: '対応済' };
const SM_STATUS_BADGE = { open: 'primary', in_progress: 'info text-dark', resolved: 'secondary' };
const SM_PRIORITY_LABEL = { low: '低', normal: '中', high: '高' };
const SM_PRIORITY_BADGE = { low: 'light text-dark border', normal: 'light text-dark border', high: 'danger' };
const SM_SEVERITY_LABEL = { high: '高', medium: '中', low: '低', info: '情報' };
const SM_SEVERITY_BADGE = { high: 'danger', medium: 'warning text-dark', low: 'info text-dark', info: 'light text-dark border' };
const SM_SOURCE_LABEL = { upload: 'アップロード', maildir: '報告アドレス' };
// 2MB (API の SM_MAX_BASE64_LEN と同じ上限)
const SM_MAX_FILE_BYTES = 2 * 1024 * 1024;

const SmState = { offset: 0, limit: 50, total: 0, vtConfigured: false };
// 一覧・詳細のキャッシュ。HTML 属性に値を埋め込まず id で引く。
const SmCache = { rows: {}, detail: null };

function smBadge(map, labels, value) {
  const color = map[value] || 'light text-dark border';
  return `<span class="badge bg-${color}">${esc(labels[value] || value || '—')}</span>`;
}
function smOptions(labels, selected, withBlank = true) {
  const opts = withBlank ? ['<option value="">すべて</option>'] : [];
  for (const [value, label] of Object.entries(labels)) {
    opts.push(`<option value="${value}"${value === selected ? ' selected' : ''}>${esc(label)}</option>`);
  }
  return opts.join('');
}
function smCanEdit() {
  return ['operator', 'tenant_admin', 'superadmin'].includes(State.user?.role);
}
function smBytes(n) {
  if (n == null) return '—';
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  return `${(n / 1024 / 1024).toFixed(2)} MB`;
}

/* ========== 一覧 ========== */

async function renderSuspiciousMails() {
  SmState.offset = 0;
  await loadSuspiciousMails();
}

async function loadSuspiciousMails() {
  const query = {
    action: 'list',
    status: $('#smStatusFilter')?.value || '',
    category: $('#smCategoryFilter')?.value || '',
    priority: $('#smPriorityFilter')?.value || '',
    source: $('#smSourceFilter')?.value || '',
    q: $('#smKeyword')?.value.trim() || '',
    limit: SmState.limit,
    offset: SmState.offset,
  };
  const data = await api('api/suspicious_mails.php', { query });
  SmState.total = data.total;
  SmState.vtConfigured = !!data.virustotal_configured;
  SmCache.rows = {};
  for (const r of data.rows) SmCache.rows[r.id] = r;

  const counts = data.status_counts || {};
  $('#smStatusCounts').innerHTML = Object.entries(SM_STATUS_LABEL).map(([k, label]) =>
    `<span class="badge bg-${SM_STATUS_BADGE[k]} me-1">${esc(label)} ${counts[k] || 0}</span>`).join('');

  const body = $('#smBody');
  if (data.rows.length === 0) {
    body.innerHTML = emptyRow(10);
  } else {
    body.innerHTML = data.rows.map((r) => {
      const rowClass = r.category === 'threat' || (r.category === 'undetermined' && r.suggested_category === 'threat') ? 'table-danger'
        : (r.category === 'training' ? 'table-secondary' : '');
      return `<tr class="${rowClass}" data-id="${r.id}" style="cursor:pointer">
        <td class="text-nowrap small">${esc(fmtDate(r.received_at))}</td>
        <td class="small">${esc(SM_SOURCE_LABEL[r.source] || r.source)}</td>
        <td class="small text-break">${r.from_name ? `<div>${esc(r.from_name)}</div>` : ''}<div class="text-muted">${esc(r.from_email || '—')}</div></td>
        <td class="text-break">${esc(r.subject || '(件名なし)')}</td>
        <td class="small text-break">${esc(r.reporter_email || '—')}</td>
        <td>${smBadge(SM_CATEGORY_BADGE, SM_CATEGORY_LABEL, r.suggested_category)}</td>
        <td>${smBadge(SM_CATEGORY_BADGE, SM_CATEGORY_LABEL, r.category)}</td>
        <td>${smBadge(SM_STATUS_BADGE, SM_STATUS_LABEL, r.status)}</td>
        <td>${smBadge(SM_PRIORITY_BADGE, SM_PRIORITY_LABEL, r.priority)}</td>
        <td class="text-end">${esc(String(r.score))}</td>
      </tr>`;
    }).join('');
  }
  const from = data.total === 0 ? 0 : SmState.offset + 1;
  const to = Math.min(SmState.offset + SmState.limit, data.total);
  $('#smInfo').textContent = `${from}–${to} / ${data.total} 件`;
  $('#smPrevBtn').disabled = SmState.offset === 0;
  $('#smNextBtn').disabled = SmState.offset + SmState.limit >= data.total;
}

/* ========== CSV 出力 ========== */

// いまの絞り込みの条件で一覧を CSV に出す。superadmin がテナントを選んでいる時はそのテナントだけ(api() と同じ扱い)。
async function smExportCsv() {
  const qs = new URLSearchParams({ action: 'export_csv' });
  const filters = { status: '#smStatusFilter', category: '#smCategoryFilter', priority: '#smPriorityFilter', source: '#smSourceFilter' };
  for (const [key, sel] of Object.entries(filters)) {
    const v = $(sel)?.value || '';
    if (v) qs.set(key, v);
  }
  const q = $('#smKeyword')?.value.trim() || '';
  if (q) qs.set('q', q);
  if (State.user && State.user.role === 'superadmin' && State.activeTenantId) qs.set('tenant_id', State.activeTenantId);
  const btn = $('#smExportBtn');
  if (btn) btn.disabled = true;
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), 60000);
  try {
    const res = await fetch(`api/suspicious_mails.php?${qs}`, { credentials: 'same-origin', signal: ctrl.signal });
    if (!res.ok) { toast(`CSV の出力に失敗しました（HTTP ${res.status}）`, 'err'); return; }
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = `suspicious_mails_${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
    if (res.headers.get('X-Truncated') === '1') toast(`件数が多いため、新しい順に上限の件数だけを出力しました（全 ${res.headers.get('X-Total-Count')} 件）`, 'info');
  } catch (e) {
    toast(e.name === 'AbortError' ? '通信がタイムアウトしました' : 'CSV の出力に失敗しました', 'err');
  } finally {
    clearTimeout(timer);
    if (btn) btn.disabled = false;
  }
}

/* ========== アップロード ========== */

function smPickFile() {
  $('#smFileInput').value = '';
  $('#smFileInput').click();
}

async function smUploadFile(file) {
  if (!file) return;
  if (!/\.eml$/i.test(file.name)) { toast('.eml ファイルを選んでください', 'err'); return; }
  if (file.size > SM_MAX_FILE_BYTES) { toast('メールファイルは2MB以内にしてください', 'err'); return; }
  const base64 = await new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result).replace(/^data:[^,]*,/, ''));
    reader.onerror = () => reject(new Error('ファイルを読み取れません'));
    reader.readAsDataURL(file);
  });
  const reporter = $('#smReporterEmail')?.value.trim() || '';
  const btn = $('#smUploadBtn');
  btn.disabled = true;
  try {
    const body = { filename: file.name, file_base64: base64 };
    if (reporter) body.reporter_email = reporter;
    const res = await api('api/suspicious_mails.php', { method: 'POST', query: { action: 'upload' }, body, timeout: 60000 });
    toast(`解析しました: ${SM_CATEGORY_LABEL[res.suggested_category] || res.suggested_category}（${res.score} 点）`, 'ok');
    await loadSuspiciousMails();
    await openSuspiciousMail(res.id);
  } catch (e) {
    toast(e.message, 'err');
  } finally {
    btn.disabled = false;
  }
}

/* ========== 詳細 ========== */

async function openSuspiciousMail(id) {
  const data = await api('api/suspicious_mails.php', { query: { action: 'get', id } });
  const mail = data.mail;
  SmCache.detail = mail;
  SmState.vtConfigured = !!mail.virustotal_configured;
  const target = mail.analysis.messages[smTargetIndex(mail.analysis.messages)] || null;

  const editable = smCanEdit();
  const dis = editable ? '' : ' disabled';
  const html = `
    <ul class="nav nav-tabs mb-3" role="tablist" id="smDetailTabs">
      <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#smTabSummary">概要</a></li>
      <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#smTabHeaders">ヘッダー</a></li>
      <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#smTabUrls">URL <span class="badge bg-light text-dark border">${target ? target.urls.length : 0}</span></a></li>
      <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#smTabAttachments">添付 <span class="badge bg-light text-dark border">${target ? target.attachments.length : 0}</span></a></li>
      <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#smTabBody">本文</a></li>
      <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#smTabRaw">生データ</a></li>
      <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#smTabHistory">履歴 <span class="badge bg-light text-dark border">${mail.history.length}</span></a></li>
    </ul>
    <div class="tab-content">
      <div class="tab-pane fade show active" id="smTabSummary">${smRenderSummary(mail, target, dis)}</div>
      <div class="tab-pane fade" id="smTabHeaders">${smRenderHeaders(mail, target)}</div>
      <div class="tab-pane fade" id="smTabUrls">${smRenderUrls(mail, target)}</div>
      <div class="tab-pane fade" id="smTabAttachments">${smRenderAttachments(mail, target)}</div>
      <div class="tab-pane fade" id="smTabBody">${smRenderBody(target)}</div>
      <div class="tab-pane fade" id="smTabRaw">${smRenderRaw(mail.analysis.messages)}</div>
      <div class="tab-pane fade" id="smTabHistory">${smRenderHistory(mail.history)}</div>
    </div>`;
  const title = `不審メール #${mail.id}: ${mail.subject || '(件名なし)'}`;
  if (editable) {
    showModal(title, html, () => smSaveDetail(mail.id), { size: 'xl' });
  } else {
    showInfoModal(title, html, { size: 'xl' });
  }
  $('#smReanalyzeBtn')?.addEventListener('click', () => smReanalyze(mail.id));
  $('#smReputationBtn')?.addEventListener('click', () => smReputation(mail.id));
}

function smTargetIndex(messages) {
  let index = 0;
  messages.forEach((m, i) => { if (m.depth >= messages[index].depth) index = i; });
  return index;
}

function smRenderSummary(mail, target, dis) {
  const findings = mail.findings.length === 0
    ? '<div class="text-muted small">所見はありません。</div>'
    : `<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th style="width:70px">重要度</th><th style="width:60px">点</th><th>所見</th></tr></thead><tbody>${
        mail.findings.map((f) => `<tr>
          <td>${smBadge(SM_SEVERITY_BADGE, SM_SEVERITY_LABEL, f.severity)}</td>
          <td class="text-end">${esc(String(f.score))}</td>
          <td>${esc(f.message)}${smEvidence(f.evidence)}</td>
        </tr>`).join('')}</tbody></table></div>`;
  const vtInfo = SmState.vtConfigured
    ? (mail.reputation_checked_at ? `最終照会 ${esc(fmtDate(mail.reputation_checked_at))}` : '未照会')
    : 'API キー未設定（secrets.ini）';
  return `
    <div class="row g-3">
      <div class="col-lg-7">
        <dl class="row small mb-2">
          <dt class="col-sm-3">受付</dt><dd class="col-sm-9">${esc(fmtDate(mail.received_at))} / ${esc(SM_SOURCE_LABEL[mail.source] || mail.source)}${mail.uploaded_by ? `（${esc(mail.uploaded_by)}）` : ''}</dd>
          <dt class="col-sm-3">報告者</dt><dd class="col-sm-9">${esc(mail.reporter_email || '—')}</dd>
          <dt class="col-sm-3">送信者</dt><dd class="col-sm-9">${mail.from_name ? esc(mail.from_name) + ' ' : ''}<code>${esc(mail.from_email || '—')}</code></dd>
          <dt class="col-sm-3">件名</dt><dd class="col-sm-9">${esc(mail.subject || '(件名なし)')}</dd>
          <dt class="col-sm-3">推奨分類</dt><dd class="col-sm-9">${smBadge(SM_CATEGORY_BADGE, SM_CATEGORY_LABEL, mail.suggested_category)} <span class="text-muted">スコア ${esc(String(mail.score))}</span>${mail.is_training ? ` <span class="text-muted">追跡 ID ${esc(mail.tracking_id || '')}</span>` : ''}</dd>
          <dt class="col-sm-3">サイズ</dt><dd class="col-sm-9">${smBytes(mail.raw_bytes)} / SHA256 <code class="small">${esc(mail.sha256)}</code></dd>
        </dl>
        <h6 class="mt-3">所見</h6>
        ${findings}
        <div class="d-flex gap-2 mt-3 flex-wrap align-items-center">
          <button type="button" class="btn btn-sm btn-outline-secondary" id="smReanalyzeBtn"${dis}><i class="bi bi-arrow-repeat"></i> 再解析</button>
          <button type="button" class="btn btn-sm btn-outline-primary" id="smReputationBtn"${dis}${SmState.vtConfigured ? '' : ' disabled'}><i class="bi bi-shield-check"></i> VirusTotal 照会（${esc(String(mail.reputation_targets))} 件）</button>
          <span class="small text-muted">${vtInfo}</span>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card"><div class="card-body">
          <h6 class="card-title">判定と対応</h6>
          <div class="mb-2"><label class="form-label small mb-0">分類</label><select class="form-select form-select-sm" id="smEditCategory"${dis}>${smOptions(SM_CATEGORY_LABEL, mail.category, false)}</select></div>
          <div class="mb-2"><label class="form-label small mb-0">確認状況</label><select class="form-select form-select-sm" id="smEditStatus"${dis}>${smOptions(SM_STATUS_LABEL, mail.status, false)}</select></div>
          <div class="mb-2"><label class="form-label small mb-0">優先度</label><select class="form-select form-select-sm" id="smEditPriority"${dis}>${smOptions(SM_PRIORITY_LABEL, mail.priority, false)}</select></div>
          <div class="mb-2"><label class="form-label small mb-0">担当</label><input type="text" class="form-control form-control-sm" id="smEditAssigned" value="${esc(mail.assigned_to || '')}" maxlength="200"${dis}></div>
          <div class="mb-0"><label class="form-label small mb-0">メモ</label><textarea class="form-control form-control-sm" id="smEditNote" rows="5" maxlength="4000"${dis}>${esc(mail.note || '')}</textarea></div>
        </div></div>
      </div>
    </div>`;
}

function smEvidence(evidence) {
  if (!evidence || typeof evidence !== 'object') return '';
  const parts = [];
  for (const [k, v] of Object.entries(evidence)) {
    if (v == null || v === '' || (Array.isArray(v) && v.length === 0)) continue;
    const text = Array.isArray(v) ? v.join(', ') : (typeof v === 'object' ? JSON.stringify(v) : String(v));
    parts.push(`<span class="text-muted">${esc(k)}:</span> <code>${esc(text.length > 200 ? text.slice(0, 200) + '…' : text)}</code>`);
  }
  return parts.length ? `<div class="small mt-1">${parts.join(' ')}</div>` : '';
}

function smRenderHeaders(mail, target) {
  if (!target) return '<div class="text-muted">ヘッダー情報がありません。</div>';
  const key = [
    ['From', target.from_email ? `${target.from_name ? esc(target.from_name) + ' ' : ''}<code>${esc(target.from_email)}</code>` : '—'],
    ['Return-Path', target.return_path ? `<code>${esc(target.return_path)}</code>` : '—'],
    ['Reply-To', target.reply_to ? `<code>${esc(target.reply_to)}</code>` : '—'],
    ['To', esc(target.to || '—')],
    ['Date', esc(target.date || '—')],
    ['Message-ID', `<code>${esc(target.message_id || '—')}</code>`],
    ['Authentication-Results', target.auth_results.length ? target.auth_results.map((v) => `<code class="d-block text-break">${esc(v)}</code>`).join('') : '<span class="text-muted">なし</span>'],
    ['Received-SPF', target.received_spf.length ? target.received_spf.map((v) => `<code class="d-block text-break">${esc(v)}</code>`).join('') : '<span class="text-muted">なし</span>'],
  ];
  const highlight = new Set(mail.findings.map((f) => f.code));
  const danger = (name) => (name === 'Return-Path' && highlight.has('return_path_mismatch'))
    || (name === 'Reply-To' && highlight.has('reply_to_mismatch'))
    || (name === 'Authentication-Results' && (highlight.has('auth_fail') || highlight.has('auth_weak')))
    || (name === 'From' && (highlight.has('display_name_email') || highlight.has('display_name_lookalike')));
  const received = target.received.length
    ? `<ol class="small mb-0">${[...target.received].reverse().map((v) => `<li><code class="text-break">${esc(v)}</code></li>`).join('')}</ol>`
    : '<span class="text-muted">なし</span>';
  return `
    <div class="table-responsive"><table class="table table-sm"><tbody>
      ${key.map(([name, value]) => `<tr class="${danger(name) ? 'table-danger' : ''}"><th style="width:180px">${esc(name)}</th><td class="text-break">${value}</td></tr>`).join('')}
    </tbody></table></div>
    <h6 class="mt-3">配送経路（Received、古い順）</h6>${received}`;
}

function smRenderUrls(mail, target) {
  if (!target || target.urls.length === 0) return '<div class="text-muted">本文にリンクはありません。</div>';
  const rep = (mail.reputation && mail.reputation.url) || {};
  const flagged = {};
  for (const f of mail.findings) {
    if (f.code.startsWith('url_') && f.evidence?.url) (flagged[f.evidence.url] ||= []).push(f);
    if (f.code.startsWith('vt_url') && f.evidence?.url) (flagged[f.evidence.url] ||= []).push(f);
  }
  return `<div class="alert alert-secondary small py-2">リンクはクリックできません。調査は隔離された環境で行ってください。</div>
    <div class="table-responsive"><table class="table table-sm"><thead><tr><th>リンク先</th><th style="width:200px">表示文字列</th><th style="width:260px">所見 / VirusTotal</th></tr></thead><tbody>
    ${target.urls.map((u) => {
      const fs = flagged[u.unwrapped] || [];
      const r = rep[u.unwrapped];
      const vt = r ? (r.found ? `<div class="small">VT: 悪性 ${r.malicious} / 疑わしい ${r.suspicious} / 無害 ${r.harmless}</div>` : '<div class="small text-muted">VT: 登録なし</div>') : '';
      const bad = fs.some((f) => f.severity === 'high');
      return `<tr class="${bad ? 'table-danger' : ''}">
        <td class="text-break"><code>${esc(u.unwrapped)}</code>${u.raw !== u.unwrapped ? `<div class="small text-muted text-break">元: <code>${esc(u.raw)}</code></div>` : ''}<div class="small text-muted">${u.location === 'href' ? 'HTML リンク' : '本文テキスト'}</div></td>
        <td class="text-break small">${u.display ? esc(u.display) : '<span class="text-muted">—</span>'}</td>
        <td>${fs.map((f) => `<div>${smBadge(SM_SEVERITY_BADGE, SM_SEVERITY_LABEL, f.severity)} <span class="small">${esc(f.message)}</span></div>`).join('')}${vt}</td>
      </tr>`;
    }).join('')}</tbody></table></div>`;
}

function smRenderAttachments(mail, target) {
  if (!target || target.attachments.length === 0) return '<div class="text-muted">添付ファイルはありません。</div>';
  const rep = (mail.reputation && mail.reputation.file) || {};
  const flagged = {};
  for (const f of mail.findings) {
    if ((f.code.startsWith('attachment_') || f.code.startsWith('vt_file')) && f.evidence?.sha256) (flagged[f.evidence.sha256] ||= []).push(f);
  }
  return `<div class="table-responsive"><table class="table table-sm"><thead><tr><th>ファイル名</th><th>種類</th><th>サイズ</th><th>SHA256</th><th style="width:260px">所見 / VirusTotal</th></tr></thead><tbody>
    ${target.attachments.map((a) => {
      const fs = flagged[a.sha256] || [];
      const r = rep[a.sha256];
      const vt = r ? (r.found ? `<div class="small">VT: 悪性 ${r.malicious} / 疑わしい ${r.suspicious} / 無害 ${r.harmless}</div>` : '<div class="small text-muted">VT: 登録なし</div>') : '';
      const bad = fs.some((f) => f.severity === 'high');
      const entries = a.zip_entries && a.zip_entries.length ? `<div class="small text-muted">zip 内: ${esc(a.zip_entries.slice(0, 10).join(', '))}${a.zip_entries.length > 10 ? ' …' : ''}</div>` : '';
      return `<tr class="${bad ? 'table-danger' : ''}">
        <td class="text-break"><code>${esc(a.filename)}</code>${entries}</td>
        <td class="small">${esc(a.content_type)}</td>
        <td class="small text-nowrap">${smBytes(a.size)}</td>
        <td><code class="small text-break">${esc(a.sha256)}</code></td>
        <td>${fs.map((f) => `<div>${smBadge(SM_SEVERITY_BADGE, SM_SEVERITY_LABEL, f.severity)} <span class="small">${esc(f.message)}</span></div>`).join('')}${vt}</td>
      </tr>`;
    }).join('')}</tbody></table></div>`;
}

function smRenderBody(target) {
  if (!target) return '<div class="text-muted">本文がありません。</div>';
  const text = target.text || target.html_text || '';
  const other = target.text && target.html_text ? `<h6 class="mt-3">HTML 版（テキスト化）</h6><pre class="border rounded p-2 bg-light small" style="white-space:pre-wrap;max-height:40vh;overflow:auto">${esc(target.html_text)}</pre>` : '';
  return `<pre class="border rounded p-2 bg-light small" style="white-space:pre-wrap;max-height:50vh;overflow:auto">${esc(text || '(本文なし)')}</pre>${other}`;
}

function smRenderRaw(messages) {
  return messages.map((m) => {
    const lines = [];
    for (const [name, values] of Object.entries(m.headers || {})) {
      for (const v of values) lines.push(`${name}: ${v}`);
    }
    return `<h6 class="mt-2">${m.depth === 0 ? '外側メッセージ' : `内側メッセージ（深さ ${m.depth}）`}</h6>
      <pre class="border rounded p-2 bg-light small" style="white-space:pre-wrap;max-height:40vh;overflow:auto">${esc(lines.join('\n'))}</pre>`;
  }).join('');
}

function smRenderHistory(history) {
  if (history.length === 0) return '<div class="text-muted">変更履歴はありません。</div>';
  const label = (field, v) => {
    if (v == null || v === '') return '—';
    if (field === 'category') return SM_CATEGORY_LABEL[v] || v;
    if (field === 'status') return SM_STATUS_LABEL[v] || v;
    if (field === 'priority') return SM_PRIORITY_LABEL[v] || v;
    return v;
  };
  const fieldLabel = { category: '分類', status: '確認状況', priority: '優先度', assigned_to: '担当', note: 'メモ' };
  return `<div class="table-responsive"><table class="table table-sm"><thead><tr><th>日時</th><th>操作者</th><th>項目</th><th>変更前</th><th>変更後</th></tr></thead><tbody>
    ${history.map((h) => `<tr><td class="text-nowrap small">${esc(fmtDate(h.created_at))}</td><td class="small">${esc(h.actor_email)}</td><td>${esc(fieldLabel[h.field] || h.field)}</td><td class="small text-break">${esc(label(h.field, h.old_value))}</td><td class="small text-break">${esc(label(h.field, h.new_value))}</td></tr>`).join('')}
  </tbody></table></div>`;
}

async function smSaveDetail(id) {
  const body = {
    id,
    category: $('#smEditCategory').value,
    status: $('#smEditStatus').value,
    priority: $('#smEditPriority').value,
    assigned_to: $('#smEditAssigned').value.trim() || null,
    note: $('#smEditNote').value.trim() || null,
  };
  const res = await api('api/suspicious_mails.php', { method: 'POST', query: { action: 'update' }, body });
  toast(res.changed.length ? '保存しました' : '変更はありません', 'ok');
  await loadSuspiciousMails();
}

async function smReanalyze(id) {
  const btn = $('#smReanalyzeBtn');
  btn.disabled = true;
  try {
    const res = await api('api/suspicious_mails.php', { method: 'POST', query: { action: 'reanalyze' }, body: { id } });
    toast(`再解析しました: ${SM_CATEGORY_LABEL[res.suggested_category] || res.suggested_category}（${res.score} 点）`, 'ok');
    await loadSuspiciousMails();
    await openSuspiciousMail(id);
  } catch (e) {
    toast(e.message, 'err');
    btn.disabled = false;
  }
}

async function smReputation(id) {
  const btn = $('#smReputationBtn');
  btn.disabled = true;
  try {
    const res = await api('api/suspicious_mails.php', { method: 'POST', query: { action: 'reputation' }, body: { id }, timeout: 60000 });
    if (res.unauthorized) toast('VirusTotal の API キーが無効です', 'err');
    else if (res.rate_limited) toast(`VirusTotal の呼び出し上限に達しました。${res.done} 件照会、残り ${res.remaining} 件は 1 分後に再度押してください`, 'err');
    else if (res.remaining > 0) toast(`${res.done} 件照会しました。残り ${res.remaining} 件は 1 分後に再度押してください`, 'info');
    else toast(`${res.done} 件照会しました`, 'ok');
    await loadSuspiciousMails();
    await openSuspiciousMail(id);
  } catch (e) {
    toast(e.message, 'err');
    btn.disabled = false;
  }
}

/* ========== 登録 ========== */

VIEWS.suspiciousMails = renderSuspiciousMails;

document.addEventListener('DOMContentLoaded', () => {
  $('#smUploadBtn')?.addEventListener('click', smPickFile);
  $('#smFileInput')?.addEventListener('change', (e) => smUploadFile(e.target.files[0]));
  $('#smApplyBtn')?.addEventListener('click', () => { SmState.offset = 0; loadSuspiciousMails().catch((e) => toast(e.message, 'err')); });
  $('#smExportBtn')?.addEventListener('click', () => { smExportCsv(); });
  $('#smKeyword')?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); $('#smApplyBtn').click(); } });
  ['#smStatusFilter', '#smCategoryFilter', '#smPriorityFilter', '#smSourceFilter'].forEach((sel) =>
    $(sel)?.addEventListener('change', () => $('#smApplyBtn').click()));
  $('#smPrevBtn')?.addEventListener('click', () => { SmState.offset = Math.max(0, SmState.offset - SmState.limit); loadSuspiciousMails().catch((e) => toast(e.message, 'err')); });
  $('#smNextBtn')?.addEventListener('click', () => { SmState.offset += SmState.limit; loadSuspiciousMails().catch((e) => toast(e.message, 'err')); });
  // 行クリックで詳細(再描画で要素が入れ替わるためイベント委譲)。
  $('#smBody')?.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    openSuspiciousMail(Number(tr.dataset.id)).catch((err) => toast(err.message, 'err'));
  });
});

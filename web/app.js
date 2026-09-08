'use strict';
/* TET v2 フロントエンド SPA。バックエンド API (api/*.php) と連携する。 */

const State = { user: null, csrf: null, tenants: [], activeTenantId: null, view: null };
/* 一覧取得結果を id→行 でキャッシュ。編集モーダルは属性埋め込みでなくここから引く（XSS/クオート破損回避） */
const Cache = { targets: {}, groups: {}, users: {}, tenants: {}, reports: {}, failuresCampaign: null };
function cacheRows(kind, rows) { Cache[kind] = {}; for (const r of rows) Cache[kind][r.id] = r; }

/* ========== API ラッパ ========== */
async function api(path, { method = 'GET', body = null, query = {}, timeout = 30000 } = {}) {
  const qs = new URLSearchParams();
  for (const [k, v] of Object.entries(query)) {
    if (v !== null && v !== undefined && v !== '') qs.set(k, v);
  }
  // superadmin がテナント切替中なら tenant_id を自動付与
  if (State.user && State.user.role === 'superadmin' && State.activeTenantId && !('tenant_id' in query)) {
    if (!path.startsWith('api/tenants') && !path.startsWith('api/auth')) qs.set('tenant_id', State.activeTenantId);
  }
  const url = qs.toString() ? `${path}?${qs}` : path;
  const headers = { 'Content-Type': 'application/json' };
  if (method !== 'GET' && State.csrf) headers['X-CSRF-Token'] = State.csrf;
  let payload = body;
  if (body && State.user && State.user.role === 'superadmin' && State.activeTenantId
      && !path.startsWith('api/tenants') && !('tenant_id' in body)) {
    payload = { ...body, tenant_id: State.activeTenantId };
  }
  const ctrl = new AbortController();
  // 既定30秒。launch など重い生成処理は呼び出し側で timeout を延ばす
  const timer = setTimeout(() => ctrl.abort(), timeout);
  let res;
  try {
    res = await fetch(url, { method, headers, body: payload ? JSON.stringify(payload) : null, credentials: 'same-origin', signal: ctrl.signal });
  } catch (e) {
    if (e.name === 'AbortError') throw new Error('通信がタイムアウトしました');
    throw new Error('通信に失敗しました');
  } finally {
    clearTimeout(timer);
  }
  let data;
  try { data = await res.json(); } catch { data = { success: false, error: 'レスポンス解析失敗' }; }
  if (res.status === 401 && State.user) { logout(true); throw new Error('セッション切れ'); }
  if (!res.ok || data.success === false) throw new Error(data.error || `HTTP ${res.status}`);
  return data;
}

/* ========== ユーティリティ ========== */
const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => Array.from(document.querySelectorAll(sel));
function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}
function toast(msg, kind = 'info') {
  const el = document.createElement('div');
  el.className = `app-toast ${kind}`;
  el.textContent = msg;
  $('#toast').appendChild(el);
  setTimeout(() => el.remove(), 3500);
}
function fmtDate(s) { return s ? String(s).replace('T', ' ').slice(0, 16) : '—'; }

/**
 * 完了まで消えない進行表示。toast は 3.5 秒で消えるため、
 * 生成のように「押してから結果が返るまで」を覆いたい処理はこちらを使う。
 * 返り値の close() を finally で必ず呼ぶ。
 */
function showProgress(msg) {
  const overlay = document.createElement('div');
  overlay.className = 'app-progress-overlay';
  const box = document.createElement('div');
  box.className = 'app-progress-box';
  const spinner = document.createElement('div');
  spinner.className = 'app-progress-spinner';
  const text = document.createElement('div');
  text.className = 'app-progress-text';
  text.textContent = msg;
  box.appendChild(spinner);
  box.appendChild(text);
  overlay.appendChild(box);
  document.body.appendChild(overlay);
  return { close() { overlay.remove(); } };
}

/* ========== 認証 ========== */
async function login(email, password) {
  const data = await api('api/auth.php', { method: 'POST', query: { action: 'login' }, body: { email, password } });
  State.user = data.user;
  State.csrf = data.csrf;
  await afterLogin();
}
async function logout(silent = false) {
  try { if (!silent) await api('api/auth.php', { method: 'POST', query: { action: 'logout' } }); } catch {}
  riskDashboard?.invalidate();
  State.user = null; State.csrf = null; State.activeTenantId = null;
  $('#appView').classList.add('d-none');
  $('#loginView').classList.remove('d-none');
  $('#loginPassword').value = '';
}
async function checkSession() {
  try {
    const data = await api('api/auth.php', { query: { action: 'me' } });
    if (data.user) { State.user = data.user; State.csrf = data.csrf; await afterLogin(); return; }
  } catch {}
  $('#loginView').classList.remove('d-none');
}
async function afterLogin() {
  $('#loginView').classList.add('d-none');
  $('#appView').classList.remove('d-none');
  $('#userLabel').textContent = `${State.user.email}（${roleLabel(State.user.role)}）`;
  // ロール別メニュー表示
  $$('[data-role]').forEach((el) => {
    el.classList.toggle('d-none', !roleAtLeast(State.user.role, el.dataset.role));
  });
  $$('[data-perm]').forEach((el) => {
    el.classList.toggle('d-none', !roleAtLeast(State.user.role, el.dataset.perm));
  });
  await setupTenantSwitcher();
  navigate('dashboard');
}
function roleLabel(r) {
  return { superadmin:'システム管理者', tenant_admin:'組織管理者', operator:'オペレータ', viewer:'閲覧者' }[r] || r;
}
const ROLE_RANK = { viewer:1, operator:2, tenant_admin:3, superadmin:4 };
function roleAtLeast(have, need) { return (ROLE_RANK[have] || 0) >= (ROLE_RANK[need] || 99); }
// 役職カテゴリの正規値(サーバの TET2_POSITION_CATEGORIES と一致させる)。
// 旧称「社員」は取込・API 側でエイリアス変換されるため、選択肢には出さない。
const POSITION_CATEGORIES = ['役員', '管理職', '一般従業員'];
// カテゴリごとのバッジ色。役職マスタ画面・集計画面と共通。
const POSITION_CATEGORY_BADGE = { '役員': 'danger', '管理職': 'warning', '一般従業員': 'secondary' };

async function setupTenantSwitcher() {
  const sw = $('#tenantSwitcher');
  if (State.user.role !== 'superadmin') {
    State.activeTenantId = State.user.tenant_id;
    sw.classList.add('d-none');
    return;
  }
  const data = await api('api/tenants.php', { query: { action: 'list' } });
  State.tenants = data.tenants || [];
  sw.innerHTML = State.tenants.map((t) => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
  if (State.tenants.length) {
    // 初期選択は「そのsuperadminの所属テナント(users.tenant_id)」を優先する。
    // 該当テナントが一覧にあればそれを、なければ一覧の先頭を既定にする。
    const preferred = State.tenants.find((t) => Number(t.id) === Number(State.user.tenant_id));
    State.activeTenantId = preferred ? Number(preferred.id) : Number(State.tenants[0].id);
    sw.value = State.activeTenantId;
    sw.classList.remove('d-none');
  }
  sw.onchange = () => {
    riskDashboard?.invalidate();
    State.activeTenantId = Number(sw.value);
    renderCurrentView();
  };
}

/* ========== ルーティング ========== */
let riskDashboard = null;
function renderRiskDashboard() {
  if (!riskDashboard) {
    riskDashboard = createRiskDashboard({
      api,
      root: $('#riskDashboardRoot'),
      getContext: () => ({
        tenantId: State.activeTenantId ?? State.user?.tenant_id ?? '',
        userKey: `${State.user?.id ?? ''}:${State.user?.email ?? ''}`,
      }),
      getView: () => State.view,
    });
  }
  return riskDashboard.render();
}
const VIEWS = {
  dashboard: renderDashboard,
  campaigns: renderCampaigns,
  reports: renderReports,
  riskDashboard: renderRiskDashboard,
  groups: renderGroups,
  templates: renderTemplates,
  eduDeliveries: renderEduDeliveries,
  eduQuestions: renderEduQuestions,
  eduReport: renderEduReport,
  masters: renderMasters,
  logs: renderLogs,
  users: renderUsers,
  tenants: renderTenants,
};
function navigate(view) {
  // ビュー切替時に一覧自動更新タイマーを止める(campaigns に戻れば renderCampaigns が再設定)。
  if (campaignsRefreshTimer) { clearTimeout(campaignsRefreshTimer); campaignsRefreshTimer = null; }
  if (State.view === 'riskDashboard' && view !== 'riskDashboard') riskDashboard?.invalidate();
  State.view = view;
  $$('.view-panel').forEach((p) => p.classList.toggle('d-none', p.dataset.panel !== view));
  $$('.app-sidebar .nav-link').forEach((a) => a.classList.toggle('active', a.dataset.view === view));
  $('#sidebar').classList.remove('open');
  renderCurrentView();
}
function renderCurrentView() { const fn = VIEWS[State.view]; if (fn) fn().catch((e) => toast(e.message, 'err')); }

/* ========== ダッシュボード ========== */
let dashChart = null;
// 本番/テスト フィルタ: prod=本番のみ(既定・日常の確認は本番数値) / test=テストのみ / all=全部。
let dashTestFilter = 'prod';
function setDashTestFilter(v) { dashTestFilter = v; renderDashboard(); }
async function renderDashboard() {
  const { campaigns: allCampaigns } = await api('api/campaigns.php', { query: { action: 'list' } });
  const filterBar = document.getElementById('dashFilterBar');
  if (filterBar) {
    const btn = (v, label) => `<button class="btn btn-sm ${dashTestFilter === v ? 'btn-primary' : 'btn-outline-secondary'}" onclick="setDashTestFilter('${v}')">${label}</button>`;
    filterBar.innerHTML = `<div class="btn-group btn-group-sm">${btn('prod', '本番のみ')}${btn('test', 'テストのみ')}${btn('all', '全部')}</div>`;
  }
  const campaigns = allCampaigns.filter((c) => {
    if (dashTestFilter === 'prod') return !Number(c.is_test);
    if (dashTestFilter === 'test') return Number(c.is_test);
    return true; // all
  });
  const total = campaigns.length;
  const running = campaigns.filter((c) => c.status === 'running' || c.status === 'scheduled').length;
  const done = campaigns.filter((c) => c.status === 'done').length;
  const targets = campaigns.reduce((s, c) => s + Number(c.target_count || 0), 0);
  const kpis = [
    { label: '総キャンペーン', value: total, icon: 'bi-envelope-paper' },
    { label: '実行中/予約', value: running, icon: 'bi-send', cls: running ? 'val-warning' : '' },
    { label: '完了', value: done, icon: 'bi-check-circle', cls: 'val-success' },
    { label: '対象者延べ', value: targets, icon: 'bi-people' },
  ];
  $('#kpiRow').innerHTML = kpis.map((k) => `
    <div class="col-6 col-lg-3">
      <div class="card kpi-card"><div class="card-body d-flex justify-content-between align-items-center">
        <div><div class="kpi-value ${k.cls || ''}">${k.value}</div><div class="kpi-label">${k.label}</div></div>
        <i class="bi ${k.icon} kpi-icon"></i>
      </div></div>
    </div>`).join('');

  const labels = campaigns.map((c) => c.name);
  const counts = campaigns.map((c) => Number(c.target_count || 0));
  if (dashChart) dashChart.destroy();
  const ctx = $('#dashChart');
  dashChart = new Chart(ctx, {
    type: 'bar',
    data: { labels, datasets: [{ label: '対象者数', data: counts, backgroundColor: '#2c6fbb' }] },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
  });
}

/* ========== キャンペーン ========== */
const STATUS_LABEL = { draft:'下書き', scheduled:'予約', running:'実行中', paused:'一時停止', done:'完了', cancelled:'中止' };
let campaignsRefreshTimer = null;
let campaignSortDesc = true;  // 並び順: true=降順(最新が上, 既定) / false=昇順(古い順が上)
function toggleCampaignSort() { campaignSortDesc = !campaignSortDesc; renderCampaigns(); }
// 本番/テスト フィルタ: all=全部(既定・運用一覧なのでまず全部見せる) / prod=本番のみ / test=テストのみ。
let campaignTestFilter = 'all';
function setCampaignTestFilter(v) { campaignTestFilter = v; renderCampaigns(); }
async function renderCampaigns() {
  renderSendControl();  // 送信制御パネル(ステータス+アラート)を描画・ポーリング開始
  const { campaigns } = await api('api/campaigns.php', { query: { action: 'list' } });
  // #列は表示上の通し番号(作成順=古い順に固定で1,2,3…)。並び順を変えても番号は変わらない。
  // 古い順(id昇順)でordinalを確定 → id→番号 のマップを作る。
  const campaignsAsc = campaigns.slice().sort((a, b) => a.id - b.id);
  const numById = {};
  campaignsAsc.forEach((c, i) => { numById[c.id] = i + 1; });
  // 本番/テストフィルタを適用(番号は全体基準で確定済みなので絞っても番号は不変)。
  const filtered = campaignsAsc.filter((c) => {
    if (campaignTestFilter === 'prod') return !Number(c.is_test);
    if (campaignTestFilter === 'test') return Number(c.is_test);
    return true; // all
  });
  // 表示順は選択された方向。降順(既定)は最新が上。番号は上記マップで固定。
  const shown = campaignSortDesc ? filtered.slice().reverse() : filtered;
  // フィルタUI(全部/本番のみ/テストのみ)をツールバーに描画(レポートと同じ btn-group)。
  const filterBar = document.getElementById('campaignFilterBar');
  if (filterBar) {
    const fbtn = (v, label) => `<button class="btn btn-sm ${campaignTestFilter === v ? 'btn-primary' : 'btn-outline-primary'}" onclick="setCampaignTestFilter('${v}')">${label}</button>`;
    filterBar.innerHTML = `<div class="btn-group btn-group-sm">${fbtn('all', '全部')}${fbtn('prod', '本番のみ')}${fbtn('test', 'テストのみ')}</div>`;
  }
  // 並び順トグルUI(ヘッダの#列に矢印ボタン)。
  const sortArrow = campaignSortDesc ? 'bi-sort-down' : 'bi-sort-up';
  const sortLabel = campaignSortDesc ? '最新が上(降順)' : '古い順が上(昇順)';
  const head = document.getElementById('campaignsHead');
  if (head) {
    head.innerHTML = `<tr><th><button class="btn btn-sm btn-link p-0 text-decoration-none" onclick="toggleCampaignSort()" title="${sortLabel}・クリックで切替">#<i class="bi ${sortArrow}"></i></button></th><th>名称</th><th>状態</th><th>対象</th><th>開始</th><th>操作</th></tr>`;
  }
  $('#campaignsBody').innerHTML = shown.length ? shown.map((c) => `
    <tr>
      <td>${numById[c.id]}</td>
      <td>${esc(c.name)}${Number(c.is_test) ? ' <span class="badge bg-info">TEST</span>' : ''}</td>
      <td><span class="badge st-${c.status}">${STATUS_LABEL[c.status] || c.status}</span></td>
      <td>${c.target_count}</td>
      <td class="small text-muted">${fmtDate(c.start_at)}</td>
      <td><div class="d-flex flex-wrap gap-1">
        ${roleAtLeast(State.user.role, 'operator') && c.status === 'draft'
          ? `<button class="btn btn-sm btn-outline-primary" onclick="editCampaign(${c.id})" title="修正（下書きを編集）"><i class="bi bi-pencil"></i></button>` : ''}
        ${roleAtLeast(State.user.role, 'operator') && c.status === 'draft'
          ? `<button class="btn btn-sm btn-outline-info" onclick="generateCampaign(${c.id})" title="生成確認"><i class="bi bi-file-earmark-check"></i></button>` : ''}
        ${roleAtLeast(State.user.role, 'operator') && (c.status === 'scheduled' || c.status === 'paused')
          ? `<button class="btn btn-sm btn-outline-warning" onclick="toDraftCampaign(${c.id})" title="下書きに戻す（送信予約を取り消して編集可能に）"><i class="bi bi-arrow-counterclockwise"></i></button>` : ''}
        ${['scheduled','running','paused','done'].includes(c.status)
          ? `<button class="btn btn-sm btn-outline-secondary" onclick="showCampaignData(${c.id})" title="データ確認"><i class="bi bi-table"></i></button>` : ''}
        ${roleAtLeast(State.user.role, 'operator') && c.status === 'draft'
          ? `<button class="btn btn-sm btn-success" onclick="launchCampaign(${c.id})"><i class="bi bi-send"></i></button>` : ''}
        ${['running','scheduled','paused'].includes(c.status)
          ? `<button class="btn btn-sm btn-outline-secondary" onclick="showCampaignProgress(${c.id})" title="送信進捗"><i class="bi bi-bar-chart-line"></i></button>` : ''}
        ${roleAtLeast(State.user.role, 'operator') && (c.status === 'running' || c.status === 'scheduled')
          ? `<button class="btn btn-sm btn-danger" onclick="stopCampaign(${c.id})" title="緊急停止"><i class="bi bi-stop-circle"></i></button>` : ''}
        ${roleAtLeast(State.user.role, 'operator') && c.status === 'paused'
          ? `<button class="btn btn-sm btn-success" onclick="resumeCampaign(${c.id})" title="停止点から再開"><i class="bi bi-play-circle"></i></button>` : ''}
        ${roleAtLeast(State.user.role, 'operator') && ['done','paused','cancelled'].includes(c.status)
          ? `<button class="btn btn-sm btn-outline-success" onclick="relaunchCampaign(${c.id})" title="複製して再送信（新しい下書きを作成）"><i class="bi bi-arrow-repeat"></i></button>` : ''}
        ${roleAtLeast(State.user.role, 'operator')
          ? `<button class="btn btn-sm btn-outline-primary" onclick="renameCampaign(${c.id})" title="名称変更（送信データには影響しません）"><i class="bi bi-input-cursor-text"></i></button>` : ''}
        ${roleAtLeast(State.user.role, 'operator')
          ? `<button class="btn btn-sm btn-outline-info" onclick="toggleTestCampaign(${c.id}, ${Number(c.is_test) ? 1 : 0})" title="${Number(c.is_test) ? '本番系へ切替（分類のみ・送信データには影響しません）' : 'テスト系へ切替（分類のみ・送信データには影響しません）'}"><i class="bi ${Number(c.is_test) ? 'bi-toggle-on' : 'bi-toggle-off'}"></i></button>` : ''}
        ${roleAtLeast(State.user.role, 'operator')
          ? `<button class="btn btn-sm btn-outline-primary" onclick="duplicateCampaign(${c.id})" title="複製（設定・対象者を引き継いで下書き作成）"><i class="bi bi-files"></i></button>` : ''}
        ${roleAtLeast(State.user.role, 'operator')
          ? `<button class="btn btn-sm btn-outline-danger" onclick="deleteCampaign(${c.id})" title="削除（90日間はデータ保持、その後自動削除）"><i class="bi bi-trash"></i></button>` : ''}
      </div></td>
    </tr>`).join('') : emptyRow(6);
  // 進行中(実行中/予約)のキャンペーンがあれば、送信完了→done 遷移を画面に反映するため
  // 15秒ごとに一覧を自動更新する。緊急停止ボタンが完了後も残る問題への対処。
  if (campaignsRefreshTimer) { clearTimeout(campaignsRefreshTimer); campaignsRefreshTimer = null; }
  const hasActive = campaigns.some((c) => c.status === 'running' || c.status === 'scheduled');
  if (hasActive && State.view === 'campaigns') {
    campaignsRefreshTimer = setTimeout(() => { if (State.view === 'campaigns') renderCampaigns(); }, 15000);
  }
}

/* ========== メール送信制御パネル（送信ステータス＋アラート） ========== */
let sendControlTimer = null;
let sendControlAlertsOpen = false;
const SEND_STATUS_LABEL = {
  idle: '待機', running: '送信中', paused: '一時停止',
  stopped: '停止', completed: '完了', error: 'エラー',
};
// アラート種別ごとの深刻度（色分け）。
const ALERT_SEVERITY = {
  MULTIPLE_DEFERRED: 'danger', AUTO_STOP: 'danger', SEND_FAILED: 'danger',
  SEND_EXCEPTION: 'danger', BOUNCED: 'danger',
  AUTO_PAUSE: 'warning', RATE_LIMITED: 'warning', GREYLISTED: 'warning',
  CONNECTION_REFUSED: 'warning', CONNECTION_TIMEOUT: 'warning',
  TEMPORARILY_REJECTED: 'warning', DEFERRED: 'warning',
};

async function renderSendControl() {
  const el = $('#sendControlPanel');
  if (!el) return;
  let statusData, alertsData;
  try {
    statusData = await api('api/send_control.php', { query: { action: 'status' } });
    alertsData = await api('api/send_control.php', { query: { action: 'alerts' } });
  } catch (e) {
    el.innerHTML = '';  // 取得失敗時はパネルを出さない（画面を壊さない）
    return;
  }
  const statuses = statusData.statuses || [];
  const alerts = alertsData.data || [];
  const activeCount = statusData.active_count || 0;

  // 実行中（running系）のキャンペーンを優先表示。無ければ直近の完了を1件見せる。
  const running = statuses.filter((s) => ['running', 'scheduled', 'paused'].includes(s.campaign_status));
  const shown = running.length ? running : statuses.slice(0, 1);

  const statusRows = shown.map((s) => {
    const rate = s.total > 0 ? Math.round((s.processed * 100) / s.total) : 0;
    const stLabel = SEND_STATUS_LABEL[s.status] || s.status;
    const stColor = s.status === 'running' ? 'primary'
      : s.status === 'completed' ? 'success'
      : (s.status === 'error' || s.status === 'stopped') ? 'danger'
      : s.status === 'paused' ? 'warning' : 'secondary';
    const stopBadge = s.is_stopped ? '<span class="badge bg-danger ms-1">停止フラグ</span>' : '';
    const stopBtn = (roleAtLeast(State.user.role, 'operator') && s.status === 'running')
      ? `<button class="btn btn-sm btn-danger ms-2" onclick="stopCampaign(${s.campaign_id})" title="緊急停止"><i class="bi bi-stop-circle"></i> 停止</button>` : '';
    return `
      <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
        <span class="badge bg-${stColor}">${stLabel}</span>
        <span class="fw-bold">${esc(s.campaign_name)}</span>
        <span class="small text-muted">${s.success}/${s.total} 送信（失敗 ${s.error}）</span>
        ${stopBadge}
        <div class="progress flex-grow-1" style="height:16px;min-width:120px;max-width:280px">
          <div class="progress-bar bg-${stColor}" style="width:${rate}%">${rate}%</div>
        </div>
        ${s.current_email ? `<span class="small text-muted">送信中: ${esc(s.current_email)}</span>` : ''}
        <span class="small text-muted">更新 ${esc(s.timestamp || '—')}</span>
        ${stopBtn}
      </div>`;
  }).join('');

  const dangerCount = alerts.filter((a) => ALERT_SEVERITY[a.type] === 'danger').length;
  const alertBadge = alerts.length
    ? `<button class="btn btn-sm btn-outline-${dangerCount ? 'danger' : 'warning'}" onclick="toggleSendAlerts()">
         <i class="bi bi-exclamation-triangle"></i> アラート ${alerts.length}
         <i class="bi bi-chevron-${sendControlAlertsOpen ? 'up' : 'down'}"></i>
       </button>`
    : '<span class="small text-success"><i class="bi bi-check-circle"></i> アラートなし</span>';

  const alertList = (sendControlAlertsOpen && alerts.length) ? `
    <div class="border rounded p-2 mt-2" style="max-height:240px;overflow-y:auto">
      ${roleAtLeast(State.user.role, 'operator')
        ? `<div class="text-end mb-1"><button class="btn btn-sm btn-outline-secondary" onclick="clearSendAlerts()"><i class="bi bi-trash"></i> アラートをクリア</button></div>` : ''}
      ${alerts.map((a) => {
        const sev = ALERT_SEVERITY[a.type] || 'secondary';
        return `<div class="small border-start border-3 border-${sev} ps-2 mb-1">
          <span class="badge bg-${sev}">${esc(a.type)}</span>
          <span>${esc(a.message)}</span>
          ${a.to_email ? `<span class="text-muted">- ${esc(a.to_email)}</span>` : ''}
          ${a.campaign_name ? `<span class="text-muted">[${esc(a.campaign_name)}]</span>` : ''}
          <span class="text-muted float-end">${esc(a.timestamp || '')}</span>
        </div>`;
      }).join('')}
    </div>` : '';

  el.innerHTML = `
    <div class="card">
      <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-bold"><i class="bi bi-broadcast me-1"></i>メール送信制御
          ${activeCount ? `<span class="badge bg-primary ms-1">実行中 ${activeCount}</span>` : ''}</span>
        ${alertBadge}
      </div>
      <div class="card-body py-2">
        ${statusRows || '<span class="small text-muted">送信中のキャンペーンはありません</span>'}
        ${alertList}
      </div>
    </div>`;

  // 送信中は3秒ポーリング。無ければ止める（idle/completed は手動更新）。
  if (sendControlTimer) { clearTimeout(sendControlTimer); sendControlTimer = null; }
  if (activeCount > 0 && State.view === 'campaigns') {
    sendControlTimer = setTimeout(() => { if (State.view === 'campaigns') renderSendControl(); }, 3000);
  }
}

function toggleSendAlerts() {
  sendControlAlertsOpen = !sendControlAlertsOpen;
  renderSendControl();
}

async function clearSendAlerts() {
  if (!confirm('アラート履歴をクリアします。よろしいですか？')) return;
  try {
    await api('api/send_control.php', { method: 'POST', query: { action: 'clear_alerts' }, body: {} });
    toast('アラートをクリアしました', 'ok');
    renderSendControl();
  } catch (e) { toast(e.message, 'err'); }
}

async function launchCampaign(id) {
  if (!confirm('このキャンペーンを開始します。よろしいですか？')) return;
  // 大人数のキャンペーンは送信データ生成に時間がかかる(1,876人で約40秒)。
  // 生成中と分かる表示を出し、api の既定30秒では切れるので timeout を延ばす。
  const overlay = showProgress('送信データを生成しています…（対象人数が多いと1分ほどかかります）');
  try {
    const r = await api('api/campaign_launch.php', {
      method: 'POST', query: { action: 'launch' }, body: { id }, timeout: 180000,
    });
    toast(`開始しました（${r.batches} バッチ予約）`, 'ok'); renderCampaigns();
  } catch (e) {
    toast(e.message, 'err');
  } finally {
    overlay.close();
  }
}
async function stopCampaign(id) {
  if (!confirm('⚠️ メール送信を緊急停止します。\n未実行分の送信を止め、送信済みは保持されます。よろしいですか？')) return;
  try { await api('api/campaign_launch.php', { method: 'POST', query: { action: 'stop' }, body: { id } });
    toast('緊急停止しました', 'ok'); renderCampaigns(); } catch (e) { toast(e.message, 'err'); }
}
async function resumeCampaign(id) {
  if (!confirm('停止した位置から送信を再開します。\n送信済みの宛先には再送しません。よろしいですか？')) return;
  try { const r = await api('api/campaign_launch.php', { method: 'POST', query: { action: 'resume' }, body: { id } });
    toast(r.batches > 0 ? `再開しました（残り ${r.batches} バッチ）` : '未送信の宛先はありませんでした', 'ok');
    renderCampaigns(); } catch (e) { toast(e.message, 'err'); }
}
let campaignProgressTimer = null;
async function showCampaignProgress(id) {
  const render = async () => {
    let p;
    try { p = await api('api/campaign_launch.php', { method: 'POST', query: { action: 'progress' }, body: { id } }); }
    catch (e) { return `<div class="text-danger">進捗の取得に失敗しました</div>`; }
    const stopBadge = p.is_stopped ? '<span class="badge bg-danger ms-2">停止フラグあり</span>' : '';
    const batchRows = Object.entries(p.batches || {}).map(([s, n]) => `<span class="badge bg-secondary me-1">${esc(s)}: ${n}</span>`).join('') || '<span class="text-muted">なし</span>';
    return `
      <div class="mb-2">状態: <span class="badge st-${p.campaign_status}">${STATUS_LABEL[p.campaign_status] || p.campaign_status}</span>${stopBadge}</div>
      <div class="progress mb-2" style="height:22px"><div class="progress-bar" style="width:${p.progress_rate}%">${p.progress_rate}%</div></div>
      <div class="mb-2 small">送信済 <b>${p.sent}</b> / 全 <b>${p.total}</b> 件（未送信 ${p.pending}）</div>
      <div class="mb-2 small">再開項番（次に送る koban）: <b>${p.resume_koban ?? '—（全件送信済み）'}</b></div>
      <div class="small text-muted">バッチ: ${batchRows}</div>`;
  };
  showInfoModal('送信進捗', await render());
  // モーダル表示中は3秒ごとに自動更新
  if (campaignProgressTimer) clearInterval(campaignProgressTimer);
  campaignProgressTimer = setInterval(async () => {
    const modal = document.getElementById('appModalBody');
    if (!modal || !document.querySelector('.modal.show')) { clearInterval(campaignProgressTimer); campaignProgressTimer = null; return; }
    modal.innerHTML = await render();
  }, 3000);
}
async function deleteCampaign(id) {
  if (!confirm('キャンペーンを削除します。\n\n削除後 90日間はデータを保持し、その後に自動で完全削除（ビーコン・追跡ファイル含む）されます。\n実行中の場合は送信も停止します。よろしいですか？')) return;
  try { await api('api/campaigns.php', { method: 'POST', query: { action: 'delete' }, body: { id } });
    toast('削除しました（90日間はデータ保持）', 'ok'); renderCampaigns(); } catch (e) { toast(e.message, 'err'); }
}
async function editCampaign(id) {
  // 下書きキャンペーンの編集モーダルを開く（既存値をプリフィル）。
  try {
    await openCampaignModal(id);
  } catch (e) { toast(e.message || '編集フォームを開けませんでした', 'err'); }
}
async function toDraftCampaign(id) {
  if (!confirm('このキャンペーンを下書きに戻します。\n\n送信予約（未実行分）を取り消し、編集できる状態にします。\n送信済みの宛先はそのまま残ります。よろしいですか？')) return;
  try {
    await api('api/campaign_launch.php', { method: 'POST', query: { action: 'to_draft' }, body: { id } });
    toast('下書きに戻しました', 'ok'); renderCampaigns();
  } catch (e) { toast(e.message, 'err'); }
}
async function renameCampaign(id) {
  // 現在の名前を初期値にする(一覧APIから取得。onclick属性に名前を埋め込むと
  // クォート混入で壊れるため、id だけ受け取り名前はここで引く)。
  let current = '';
  try {
    const { campaigns } = await api('api/campaigns.php', { query: { action: 'list' } });
    const c = (campaigns || []).find((x) => x.id === id);
    current = c ? c.name : '';
  } catch (e) { /* 取得失敗時は空で続行 */ }
  const name = prompt('新しいキャンペーン名を入力してください（送信データには影響しません）', current);
  if (name === null) return;                 // キャンセル
  if (name.trim() === '') { toast('名称を入力してください', 'err'); return; }
  if (name.trim() === current) return;       // 変更なし
  try {
    await api('api/campaigns.php', { method: 'POST', query: { action: 'rename' }, body: { id, name: name.trim() } });
    toast('名称を変更しました', 'ok'); renderCampaigns();
  } catch (e) { toast(e.message, 'err'); }
}
// 本番系/テスト系を後から切り替える(分類のみ。status に関わらず可能。送信データには影響しない)。
async function toggleTestCampaign(id, currentIsTest) {
  const toTest = Number(currentIsTest) ? 0 : 1;
  const label = toTest ? 'テスト系' : '本番系';
  if (!confirm(`このキャンペーンを「${label}」に切り替えます。\n分類のみの変更で、送信済みメールや追跡には影響しません。よろしいですか？`)) return;
  try {
    await api('api/campaigns.php', { method: 'POST', query: { action: 'set_test' }, body: { id, is_test: toTest } });
    toast(`${label}に切り替えました`, 'ok'); renderCampaigns();
  } catch (e) { toast(e.message, 'err'); }
}
async function duplicateCampaign(id) {
  if (!confirm('このキャンペーンを複製して新しい下書きを作成します。\n設定・対象者を引き継ぎます（送信履歴は引き継ぎません）。よろしいですか？')) return;
  try { const r = await api('api/campaigns.php', { method: 'POST', query: { action: 'duplicate' }, body: { id } });
    toast(`複製しました（下書き #${r.campaign.id}）`, 'ok'); renderCampaigns(); } catch (e) { toast(e.message, 'err'); }
}
async function relaunchCampaign(id) {
  if (!confirm('このキャンペーンを複製して再送信の下書きを作成します。\n同じ対象者に新しい tracking_id で再送できます。\n複製後、送信日時を設定して開始してください。よろしいですか？')) return;
  try { const r = await api('api/campaigns.php', { method: 'POST', query: { action: 'duplicate' }, body: { id } });
    toast(`再送信用の下書き #${r.campaign.id} を作成しました。日時を設定して開始してください`, 'ok'); renderCampaigns(); } catch (e) { toast(e.message, 'err'); }
}
async function generateCampaign(id) {
  toast('生成中…', 'info');
  try {
    const r = await api('api/campaign_generate.php', { method: 'POST', query: { action: 'generate' }, body: { id } });
    const rows = (r.files || []).map((f) =>
      `<tr><td><code>${esc(f.filename)}</code></td><td class="text-end">${f.rows}</td><td class="text-end text-muted">${f.bytes} B</td></tr>`).join('');
    const body = `<p class="small text-muted">${esc(r.note || '')}</p>
      <p class="small">出力先: <code>${esc(r.data_dir || '')}</code></p>
      <table class="table table-sm"><thead><tr><th>ファイル</th><th class="text-end">行数</th><th class="text-end">サイズ</th></tr></thead>
        <tbody>${rows || '<tr><td colspan="3" class="text-muted">生成物なし</td></tr>'}</tbody></table>`;
    showInfoModal('生成確認（送信はしていません）', body);
  } catch (e) { toast(e.message, 'err'); }
}
/* ========== マスタ管理（認証マスタ=共通 / 種明かし=テナント別） ========== */
const AUTH_MASTERS = [
  ['master.html', '認証フラグ0: 通常'],
  ['master2.html', '認証フラグ1: Box認証'],
  ['master3.html', '認証フラグ2: Microsoft365認証'],
  ['master4.html', '認証フラグ3: デジタルアーツ認証'],
  ['master5.html', '認証フラグ4: Microsoft 365（メールのみ）'],
];
async function renderMasters() {
  const canEditAuth = roleAtLeast(State.user.role, 'tenant_admin'); // 認証マスタは全テナント共通=tenant_admin以上
  const authCards = AUTH_MASTERS.map(([f, label]) => `
    <div class="col-md-6 mb-2"><div class="card"><div class="card-body py-2 d-flex justify-content-between align-items-center">
      <div><div class="fw-bold small">${esc(label)}</div><code class="small text-muted">${esc(f)}</code></div>
      <div class="text-nowrap">
        <button class="btn btn-sm btn-outline-secondary" onclick="viewMaster('auth','${f}')">表示</button>
        <button class="btn btn-sm btn-outline-secondary" onclick="downloadMaster('auth','${f}')" title="ダウンロード"><i class="bi bi-download"></i></button>
        ${canEditAuth ? `<button class="btn btn-sm btn-outline-primary" onclick="editMaster('auth','${f}')">修正</button>` : ''}
      </div>
    </div></div></div>`).join('');
  $('#mastersBody').innerHTML = `
    <div class="card mb-3"><div class="card-header py-2 small fw-bold">認証画面マスタ（全テナント共通${canEditAuth ? '' : '・閲覧のみ'}）</div>
      <div class="card-body"><div class="row">${authCards}</div>
        <p class="small text-muted mb-0">Box/Microsoft365/デジタルアーツ等の偽ログイン画面。全テナント共通で、変更はシステム管理者のみ。</p></div></div>
    <div class="card"><div class="card-header py-2 small fw-bold">種明かし画面（テナント別）</div>
      <div class="card-body">
        <p class="small text-muted">訓練の種明かし・啓発ページ。認証後やQRコードの遷移先。テナントごとに差し替えできます（自社の問い合わせ先・ロゴ入り等）。</p>
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-sm btn-outline-secondary" onclick="viewMaster('reveal','reveal.html')"><i class="bi bi-eye me-1"></i>現在の内容を表示</button>
          <button class="btn btn-sm btn-outline-info" onclick="viewMaster('auth','master.html')"><i class="bi bi-file-earmark-text me-1"></i>共通の種明かしをプレビュー</button>
          <button class="btn btn-sm btn-outline-secondary" onclick="downloadMaster('reveal','reveal.html')"><i class="bi bi-download me-1"></i>ダウンロード</button>
          <button class="btn btn-sm btn-primary" data-perm="operator" onclick="editMaster('reveal','reveal.html')"><i class="bi bi-pencil me-1"></i>修正</button>
        </div>
      </div></div>`;
}

/* ========== ログ管理 (P8) ========== */
// 各ログ種別の列定義: ラベルと行→セル値(esc済み or 数値)の写像。
const LOG_COLUMNS = {
  delivery: {
    headers: ['#', 'キャンペーン', '宛先', '結果', 'SMTP', '日時'],
    cells: (r) => [r.id, esc(r.campaign_name), esc(r.to_email), logResultBadge(r.result), esc(r.smtp_message), esc(r.occurred_at)],
  },
  events: {
    headers: ['#', 'キャンペーン', '追跡ID', '種別', '認証種', 'source', '日時'],
    cells: (r) => [r.id, esc(r.campaign_name), esc(r.tracking_id), logEventBadge(r.event_type), esc(r.auth_variant), esc(r.source), esc(r.occurred_at)],
  },
  schedule: {
    headers: ['#', 'キャンペーン', 'バッチ', '予定時刻', '項番', '間隔', '状態', 'PID', '試行'],
    cells: (r) => [r.id, esc(r.campaign_name), r.batch_no, esc(r.scheduled_at), `${r.koban_from ?? ''}–${r.koban_to ?? ''}`, `${r.interval_sec}s`, esc(r.status), esc(r.worker_pid), r.attempts],
  },
  replies: {
    headers: ['#', 'キャンペーン', '氏名', 'メール', '会社', '本文', '日時'],
    cells: (r) => [r.id, esc(r.campaign_name), esc(r.target_name), esc(r.email), esc(r.company), esc(r.raw), esc(r.occurred_at)],
  },
  audit: {
    headers: ['#', 'ユーザ', 'アクション', '詳細', 'IP', '日時'],
    cells: (r) => [r.id, esc(r.user_email), esc(r.action), esc(r.detail), esc(r.ip), esc(r.occurred_at)],
  },
  training_results: {
    headers: ['キャンペーン', '項番', '氏名', 'メール', '会社', '送信', '開封', 'クリック', '認証'],
    cells: (r) => {
      const mark = (v) => Number(v) ? '<span class="text-danger fw-bold">○</span>' : '<span class="text-muted">—</span>';
      return [esc(r.campaign_name), r.koban, esc(r.target_name || ''), esc(r.email), esc(r.company || ''),
        r.send_status === 'sent' ? '<span class="badge bg-success">済</span>' : esc(r.send_status),
        mark(r.opened), mark(r.clicked), mark(r.authed)];
    },
  },
  training_log_detail: {
    headers: ['日時', '乱数', '区分', '開封回数', 'タイプ', '送信先メール', '氏名', '会社名', '本務役職', '役職カテゴリ',
      '入力Email', 'Password/ID', 'IP', '国', '場所', 'ISP', '組織', 'AS', 'ホスト名'],
    cells: (r) => [
      esc(r.timestamp), esc(r.random),
      // 区分: 人間 or システム(サンドボックス/SWG等。exclude_system=0 の時だけ混在)。
      r.is_system ? '<span class="badge bg-secondary">装置</span>' : '<span class="badge bg-success">人間</span>',
      // 開封回数: 人間アクセスの「n/m回目」。システム行は対象外なので空。
      (!r.is_system && r.human_total > 1)
        ? `<span class="badge bg-info text-dark">${r.human_seq}/${r.human_total}回目</span>`
        : (!r.is_system && r.human_total === 1 ? '1回' : ''),
      logTypeBadge(r.type), esc(r.recipient_email), esc(r.fullname), esc(r.company), esc(r.position),
      esc(r.position_category), esc(r.email), esc(r.password), esc(r.ip), esc(r.country),
      esc(r.location), esc(r.isp), esc(r.org), esc(r.as), esc(r.hostname),
    ],
  },
  campaign_files: {
    headers: ['項番', '乱数(tracking_id)', '送信先メール', '氏名', '会社名', '送信状況',
      'リンクHTMLファイル名', 'リンクHTML URL', 'ビーコン画像ファイル名', 'ビーコン画像 URL'],
    cells: (r) => [
      esc(r.koban), esc(r.tracking_id), esc(r.recipient_email), esc(r.fullname), esc(r.company),
      esc(r.send_status),
      `<code class="small">${esc(r.link_file)}</code>`,
      `<a href="${esc(r.link_url)}" target="_blank" rel="noopener" class="small text-break">${esc(r.link_url)}</a>`,
      `<code class="small">${esc(r.beacon_file)}</code>`,
      `<a href="${esc(r.beacon_url)}" target="_blank" rel="noopener" class="small text-break">${esc(r.beacon_url)}</a>`,
    ],
  },
  webaccess: {
    headers: ['IP', 'Timestamp', 'Method', 'Path', 'Protocol', 'Status', 'Size', 'Referer', 'User-Agent',
      '国', '場所', 'ISP', '組織', 'AS', 'ホスト名'],
    cells: (r) => [
      esc(r.ip), esc(r.timestamp), esc(r.method),
      `<span class="text-break" style="max-width:280px;display:inline-block">${esc(r.path)}</span>`,
      esc(r.protocol), logStatusBadge(r.status), esc(r.size),
      `<span class="text-break small" style="max-width:180px;display:inline-block">${esc(r.referer)}</span>`,
      `<span class="text-break small" style="max-width:220px;display:inline-block">${esc(r.useragent)}</span>`,
      esc(r.country), esc(r.location), esc(r.isp), esc(r.org), esc(r.as), esc(r.hostname),
    ],
  },
  reply_maildir: {
    headers: ['受信日時', '送信元アカウント', '差出人', '件名', '添付', ''],
    // r は Maildir 由来。本文表示ボタンで別モーダルを開く。
    cells: (r) => [
      esc(r.date), esc(r.sender_account), esc(r.from || r.from_email), esc(r.subject),
      Number(r.has_attachment) ? '<i class="bi bi-paperclip" title="添付あり"></i>' : '',
      `<button class="btn btn-sm btn-outline-primary py-0" data-mailview="1" data-account="${esc(r.sender_account)}" data-file="${esc(r.filename)}"><i class="bi bi-envelope-open"></i> 本文</button>`,
    ],
  },
};
function logResultBadge(v) {
  const cls = v === 'sent' ? 'bg-success' : v === 'failed' ? 'bg-danger' : 'bg-secondary';
  return `<span class="badge ${cls}">${esc(v)}</span>`;
}
function logEventBadge(v) {
  const map = { open: 'bg-info text-dark', click: 'bg-warning text-dark', auth: 'bg-danger', reply: 'bg-primary' };
  return `<span class="badge ${map[v] || 'bg-secondary'}">${esc(v)}</span>`;
}
function logTypeBadge(v) {
  // link_click=リンククリック / box・ms365・da 等=認証入力
  const cls = v === 'link_click' ? 'bg-warning text-dark' : 'bg-danger';
  return `<span class="badge ${cls}">${esc(v)}</span>`;
}
function logStatusBadge(v) {
  const n = parseInt(v, 10);
  const cls = n >= 500 ? 'bg-danger' : n >= 400 ? 'bg-warning text-dark' : n >= 300 ? 'bg-info text-dark' : 'bg-success';
  return `<span class="badge ${cls}">${esc(v)}</span>`;
}
const logsState = { type: 'delivery', offset: 0, limit: 100 };
let reportMailRequest = 0;

// ログ管理テーブルの列見出しクリックソート(共通)。表示済みの tbody 行を並べ替える
// DOM ベース方式で、各タブの cells 実装に依存しない。列のセル値から数値/日時/文字を
// 自動判定して比較する。バッジ等のHTMLはテキスト内容(例「2/7回目」→数値2)で比較する。
function makeLogTableSortable(headSel, bodySel) {
  const head = $(headSel);
  const body = $(bodySel);
  if (!head || !body) return;
  const ths = [...head.querySelectorAll('th')];
  if (!ths.length) return;
  // ソート用の値を取り出す: 日時 > 数値 > 文字 の順で判定。
  const sortKey = (text) => {
    const t = (text || '').trim();
    // 日時 YYYY-MM-DD[ HH:MM[:SS]]
    if (/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/.test(t)) {
      const ms = Date.parse(t.replace(' ', 'T'));
      return { num: isNaN(ms) ? null : ms, str: t };
    }
    // 先頭に数値を含む(「2/7回目」「5059」「12回」等)→ 先頭数値で比較。
    const m = t.match(/-?\d[\d,]*\.?\d*/);
    if (m) return { num: parseFloat(m[0].replace(/,/g, '')), str: t };
    return { num: null, str: t };
  };
  ths.forEach((th, idx) => {
    th.style.cursor = 'pointer';
    th.title = 'クリックで並び替え';
    th.addEventListener('click', () => {
      const cur = th.dataset.sortDir === 'asc' ? 'desc' : 'asc';
      ths.forEach((o) => { delete o.dataset.sortDir; o.querySelector('.sort-caret')?.remove(); });
      th.dataset.sortDir = cur;
      const caret = document.createElement('span');
      caret.className = 'sort-caret text-muted small ms-1';
      caret.textContent = cur === 'asc' ? '▲' : '▼';
      th.appendChild(caret);
      const rows = [...body.querySelectorAll('tr')].filter((tr) => tr.querySelectorAll('td').length === ths.length);
      rows.sort((ra, rb) => {
        const a = sortKey(ra.children[idx]?.textContent);
        const b = sortKey(rb.children[idx]?.textContent);
        let cmp;
        if (a.num !== null && b.num !== null) cmp = a.num - b.num;
        else if (a.num !== null) cmp = -1; // 数値を空文字より前に
        else if (b.num !== null) cmp = 1;
        else cmp = a.str.localeCompare(b.str, 'ja');
        return cur === 'asc' ? cmp : -cmp;
      });
      rows.forEach((tr) => body.appendChild(tr));
    });
  });
}
async function renderLogs() {
  // キャンペーン絞込セレクトは画面を開くたびに毎回作り直す。
  // キャンペーンは随時追加されるためキャッシュしない(新規作成分がすぐ出るように)。
  // 選択中のキャンペーンは維持するが、テナントを切り替えた時だけは破棄する。
  const sel = $('#logsCampaignFilter');
  const tenantKey = String(State.activeTenantId ?? State.user?.tenant_id ?? '');
  if (sel) {
    const tenantChanged = sel.dataset.tenantKey !== undefined && sel.dataset.tenantKey !== tenantKey;
    const prev = tenantChanged ? '' : sel.value;
    try {
      const { campaigns } = await api('api/report.php', { query: { action: 'campaigns' } });
      // 先頭の「すべて」を残して以降を破棄してから詰め直す。
      while (sel.options.length > 1) sel.remove(1);
      for (const c of campaigns) {
        const o = document.createElement('option');
        o.value = c.id; o.textContent = `#${c.id} ${c.name}`;
        sel.appendChild(o);
      }
      // 選択中の値が新しい一覧にも存在すれば復元する。無ければ「すべて」に戻す。
      sel.value = prev && [...sel.options].some((o) => o.value === prev) ? prev : '';
      sel.dataset.tenantKey = tenantKey;
    } catch { /* 一覧取得失敗は絞込なしで続行 */ }
  }
  await loadLogs();
}
async function loadLogs() {
  const type = logsState.type;
  if (type === 'raw_mail' || type === 'raw_web') return loadRawLog(type);
  if (type === 'training_results') return loadTrainingResults();
  if (type === 'training_log_detail') return loadTrainingLogDetail();
  if (type === 'campaign_files') return loadCampaignFiles();
  if (type === 'webaccess') return loadWebAccessLog();
  if (type === 'reply_maildir') return loadReplyMaildir();
  if (type === 'report_mail') return loadReportMail();
  // 既存の DB ログ(ページングつきテーブル)
  const def = LOG_COLUMNS[type];
  $('#logsHead').innerHTML = `<tr>${def.headers.map((h) => `<th>${h}</th>`).join('')}</tr>`;
  $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">読込中…</td></tr>`;
  const query = { action: type, limit: logsState.limit, offset: logsState.offset };
  const cid = $('#logsCampaignFilter')?.value;
  if (cid) query.campaign_id = cid;
  let data;
  try { data = await api('api/logs.php', { query }); }
  catch (e) { $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-danger py-3">${esc(e.message)}</td></tr>`; return; }
  const rows = data.rows || [];
  $('#logsBody').innerHTML = rows.length
    ? rows.map((r) => `<tr>${def.cells(r).map((c) => `<td>${c ?? ''}</td>`).join('')}</tr>`).join('')
    : `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">ログはありません</td></tr>`;
  makeLogTableSortable('#logsHead', '#logsBody');
  const from = rows.length ? logsState.offset + 1 : 0;
  $('#logsInfo').textContent = `${from}–${logsState.offset + rows.length} 件目（${logsState.limit} 件/ページ）`;
  $('#logsPrevBtn').disabled = logsState.offset === 0;
  $('#logsNextBtn').disabled = rows.length < logsState.limit;
}
function reportMailCells(r) {
  const labels = { pending: '保留', confirmed: '確定', rejected: '却下' };
  const colors = { pending: 'warning text-dark', confirmed: 'success', rejected: 'secondary' };
  const canDecide = ['operator', 'tenant_admin', 'superadmin'].includes(State.user?.role);
  const actions = r.status === 'pending' && canDecide
    ? `<button class="btn btn-sm btn-outline-success" data-report-decision="confirm" data-id="${esc(r.id)}">確定</button>
       <button class="btn btn-sm btn-outline-secondary" data-report-decision="reject" data-id="${esc(r.id)}">却下</button>` : '';
  return [esc(r.received_at), `<span class="badge bg-${esc(colors[r.status] || 'secondary')}">${esc(labels[r.status] || r.status)}</span>`,
    esc(r.method), esc(r.reason), esc(r.tracking_id), esc(r.campaign_name),
    `${esc(r.target_name)}<br>${esc(r.target_email)}`, esc(r.from_email), esc(r.subject_head), actions];
}
async function loadReportMail() {
  const request = ++reportMailRequest;
  const query = { action: 'report_mail', status: $('#reportMailStatus').value, limit: logsState.limit, offset: logsState.offset };
  if (State.user?.role === 'superadmin' && $('#reportMailUnmatched').checked) query.include_unmatched = '1';
  const headers = ['受信日時', '状態', '方法', '理由', '追跡 ID', 'キャンペーン', '対象者', '差出人', '件名', '操作'];
  $('#logsHead').innerHTML = `<tr>${headers.map((h) => `<th>${esc(h)}</th>`).join('')}</tr>`;
  $('#logsBody').innerHTML = '<tr><td colspan="10">読込中…</td></tr>';
  const unmatched = $('#reportMailUnmatchedTable');
  unmatched.style.display = 'none'; unmatched.innerHTML = '';
  $('#logsPrevBtn').disabled = true; $('#logsNextBtn').disabled = true;
  $('#logsInfo').textContent = '';
  let data;
  try { data = await api('api/logs.php', { query }); }
  catch (e) {
    if (logsState.type === 'report_mail' && request === reportMailRequest) $('#logsBody').innerHTML = `<tr><td colspan="10" class="text-danger">${esc(e.message)}</td></tr>`;
    return;
  }
  if (logsState.type !== 'report_mail' || request !== reportMailRequest) return;
  $('#logsBody').innerHTML = data.rows.length
    ? data.rows.map((r) => `<tr>${reportMailCells(r).map((c) => `<td>${c}</td>`).join('')}</tr>`).join('')
    : '<tr><td colspan="10">報告メールはありません</td></tr>';
  $('#logsInfo').textContent = `${data.rows.length ? data.offset + 1 : 0}–${data.offset + data.rows.length} 件目 / ${data.total} 件`;
  $('#logsPrevBtn').disabled = data.offset === 0;
  $('#logsNextBtn').disabled = data.offset + data.rows.length >= data.total;
  if (State.user?.role === 'superadmin' && data.unmatched) {
    unmatched.style.display = '';
    unmatched.innerHTML = `<h6>未照合（${esc(data.unmatched.length)}件）</h6><table class="table table-sm"><thead><tr>${['受信日時', '差出人', '件名', 'parse_status', 'parse_error'].map((h) => `<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${data.unmatched.map((r) => `<tr>${['received_at', 'from_email', 'subject_head', 'parse_status', 'parse_error'].map((k) => `<td>${esc(r[k])}</td>`).join('')}</tr>`).join('') || '<tr><td colspan="5">未照合メールはありません</td></tr>'}</tbody></table>`;
  }
}
async function decideReportMail(button) {
  const decision = button.dataset.reportDecision;
  if (!['confirm', 'reject'].includes(decision) || !['operator', 'tenant_admin', 'superadmin'].includes(State.user?.role)) return;
  if (!confirm(decision === 'confirm' ? 'この報告メールを確定しますか？' : 'この報告メールを却下しますか？')) return;
  button.disabled = true;
  try {
    await api('api/logs.php', { method: 'POST', query: { action: `report_mail_${decision}` }, body: { id: Number(button.dataset.id) } });
    if (logsState.type === 'report_mail') await loadReportMail();
  } catch (e) { alert(e.message); }
  finally { button.disabled = false; }
}
// 生ログ(mail/access)を pre 表示。キーワードで絞込。最新5000行、ファイルサイズ表示。
async function loadRawLog(type) {
  const pre = $('#logsRaw');
  pre.textContent = '読込中…';
  const query = { action: type, limit: 5000 };
  const kw = $('#logsKeyword')?.value.trim();
  if (kw) query.q = kw;
  try {
    const d = await api('api/logs.php', { query });
    if (d.error) { pre.textContent = d.error; }
    const lines = d.lines || [];
    if (!d.error) pre.textContent = lines.length ? lines.join('\n') : '（該当する行がありません）';
    $('#logsInfo').textContent = `${lines.length} 行（末尾から最新最大5000行、${esc(d.source)}）`;
    // ファイルサイズ・更新日時をバッジ表示。
    const fi = d.file_info;
    if (fi) {
      $('#logsFileInfo').style.display = '';
      $('#logsFileInfo').textContent = `サイズ ${fi.size_human} / 更新 ${fi.modified}`;
    }
  } catch (e) { pre.textContent = e.message; }
}
// 生ログ全件ダウンロード(mail/access)。
function downloadRawLog(type) {
  const qs = new URLSearchParams({ action: type, download: '1' });
  window.open(`api/logs.php?${qs}`, '_blank');
}
// 訓練結果ログ(メール一覧・明細)。events(click/auth)をパースした21カラム。キャンペーン別フィルタ。
async function loadTrainingLogDetail() {
  const def = LOG_COLUMNS.training_log_detail;
  $('#logsHead').innerHTML = `<tr>${def.headers.map((h) => `<th class="text-nowrap">${h}</th>`).join('')}</tr>`;
  $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">読込中…</td></tr>`;
  const query = { action: 'training_log_detail' };
  const cid = $('#logsCampaignFilter')?.value; if (cid) query.campaign_id = cid;
  const tp = $('#logsTypeFilter')?.value; if (tp) query.type = tp;
  const sd = logDateVal('#logsStartDate'); if (sd) query.start_date = sd;
  const ed = logDateVal('#logsEndDate'); if (ed) query.end_date = ed;
  // 「システム開封を除外」チェック(既定ON)。外すとシステム(サンドボックス/SWG等)も表示。
  const inclSys = $('#logsInclSystem')?.checked;
  if (inclSys) query.exclude_system = '0';
  let data;
  try { data = await api('api/logs.php', { query }); }
  catch (e) { $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-danger py-3">${esc(e.message)}</td></tr>`; return; }
  // タイプフィルタの選択肢は毎回作り直す(新しい種別がすぐ出るように)。選択中の値は維持。
  const sel = $('#logsTypeFilter');
  if (sel && data.by_type) {
    const prev = sel.value;
    while (sel.options.length > 1) sel.remove(1);
    for (const t of Object.keys(data.by_type)) {
      const o = document.createElement('option'); o.value = t; o.textContent = t; sel.appendChild(o);
    }
    sel.value = prev && [...sel.options].some((o) => o.value === prev) ? prev : '';
  }
  const rows = data.rows || [];
  $('#logsBody').innerHTML = rows.length
    ? rows.map((r) => `<tr>${def.cells(r).map((c) => `<td class="text-nowrap">${c ?? ''}</td>`).join('')}</tr>`).join('')
    : `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">対象データがありません</td></tr>`;
  makeLogTableSortable('#logsHead', '#logsBody');
  const byType = data.by_type ? Object.entries(data.by_type).map(([k, v]) => `${k}:${v}`).join(' / ') : '';
  $('#logsInfo').textContent = `${data.count ?? rows.length} 件${byType ? '（' + byType + '）' : ''}`;
}
// 訓練結果ログ(明細)の GeoIP を後追い補完する。click 行の国/場所/ISP は ip_cache.json 頼みで、
// 未登録IPだと空欄になる。このボタンで表示中の条件に含まれる未解決IPを解決してから再表示する。
// サーバは1回200件まで解決し未解決の残数(geoip_remaining)を返すので、残数0まで繰り返す。
async function refreshTrainingLogGeoip() {
  const btn = document.getElementById('tldGeoipBtn');
  if (btn) { btn.disabled = true; btn.dataset.orig = btn.innerHTML; }
  const query = { action: 'training_log_geoip' };
  const cid = $('#logsCampaignFilter')?.value; if (cid) query.campaign_id = cid;
  const tp = $('#logsTypeFilter')?.value; if (tp) query.type = tp;
  const sd = logDateVal('#logsStartDate'); if (sd) query.start_date = sd;
  const ed = logDateVal('#logsEndDate'); if (ed) query.end_date = ed;
  // 補完対象は表示中の行に合わせる(システム除外チェックの状態も引き継ぐ)。
  const inclSys = $('#logsInclSystem')?.checked;
  if (inclSys) query.exclude_system = '0';
  const setLabel = (t) => { if (btn) btn.innerHTML = `<span class="spinner-border spinner-border-sm"></span> ${t}`; };
  try {
    // 残数が尽きるまで繰り返す。上限は暴走防止(200件×100回=2万件)。
    // 残数が2回続けて減らなければ(API失敗でUnknown固定等)打ち切る。
    let prevRemaining = Infinity, stall = 0;
    for (let i = 0; i < 100; i++) {
      const res = await api('api/logs.php', { query });
      const remaining = Number(res?.geoip_remaining ?? 0);
      if (remaining <= 0) { break; }
      setLabel(`GeoIP取得中… 残り${remaining}件`);
      if (remaining >= prevRemaining) { if (++stall >= 2) break; } else { stall = 0; }
      prevRemaining = remaining;
    }
    toast('GeoIP情報を更新しました（未知IPをすべて解決）', 'ok');
    await loadTrainingLogDetail();  // キャッシュが埋まった状態で再表示
  } catch (e) {
    toast(e.message || 'GeoIP更新に失敗しました', 'err');
  } finally {
    if (btn) { btn.disabled = false; if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig; }
  }
}
// 訓練結果ログ(明細)を CSV ダウンロード。
function downloadTrainingLogDetailCsv() {
  const qs = new URLSearchParams({ action: 'training_log_detail_csv' });
  const cid = $('#logsCampaignFilter')?.value; if (cid) qs.set('campaign_id', cid);
  const tp = $('#logsTypeFilter')?.value; if (tp) qs.set('type', tp);
  const sd = logDateVal('#logsStartDate'); if (sd) qs.set('start_date', sd);
  const ed = logDateVal('#logsEndDate'); if (ed) qs.set('end_date', ed);
  if (State.user && State.user.role === 'superadmin' && State.activeTenantId) qs.set('tenant_id', State.activeTenantId);
  window.open(`api/logs.php?${qs}`, '_blank');
}
// キャンペーン別 リンク/ビーコン ファイル一覧。対象者×(link HTML / beacon PNG)の完全URL。
// curl で外部から存在チェックする用途。キャンペーン未選択時は促す。
async function loadCampaignFiles() {
  const def = LOG_COLUMNS.campaign_files;
  $('#logsHead').innerHTML = `<tr>${def.headers.map((h) => `<th class="text-nowrap">${h}</th>`).join('')}</tr>`;
  const cid = $('#logsCampaignFilter')?.value;
  if (!cid) {
    $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">上部の「キャンペーン」を選択してください。</td></tr>`;
    $('#logsInfo').textContent = '';
    return;
  }
  $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">読込中…</td></tr>`;
  const query = { action: 'campaign_files', campaign_id: cid };
  let data;
  try { data = await api('api/logs.php', { query }); }
  catch (e) { $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-danger py-3">${esc(e.message)}</td></tr>`; return; }
  const rows = data.rows || [];
  $('#logsBody').innerHTML = rows.length
    ? rows.map((r) => `<tr>${def.cells(r).map((c) => `<td class="text-nowrap">${c ?? ''}</td>`).join('')}</tr>`).join('')
    : `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">対象者がいません（キャンペーン未生成の可能性）。</td></tr>`;
  makeLogTableSortable('#logsHead', '#logsBody');
  $('#logsInfo').textContent = `${data.count ?? rows.length} 名（base: ${esc(data.beacon_base || '')}）`;
}
// キャンペーン別 リンク/ビーコン ファイル一覧を CSV ダウンロード。
function downloadCampaignFilesCsv() {
  const cid = $('#logsCampaignFilter')?.value;
  if (!cid) { toast('キャンペーンを選択してください', 'err'); return; }
  const qs = new URLSearchParams({ action: 'campaign_files_csv', campaign_id: cid });
  if (State.user && State.user.role === 'superadmin' && State.activeTenantId) qs.set('tenant_id', State.activeTenantId);
  window.open(`api/logs.php?${qs}`, '_blank');
}
// WebアクセスLog(Apache access.log + GeoIP)。期間/Path/検索/ページング5000件。superadmin。
async function loadWebAccessLog() {
  const def = LOG_COLUMNS.webaccess;
  $('#logsHead').innerHTML = `<tr>${def.headers.map((h) => `<th class="text-nowrap">${h}</th>`).join('')}</tr>`;
  $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">読込中…（大量ログの解析に時間がかかることがあります）</td></tr>`;
  const query = { action: 'webaccess', page: logsState.webPage || 1, per_page: 5000 };
  const sd = logDateVal('#logsStartDate'); if (sd) query.start_date = sd;
  const ed = logDateVal('#logsEndDate'); if (ed) query.end_date = ed;
  const pf = $('#logsPathFilter')?.value.trim(); if (pf) query.path = pf;
  const kw = $('#logsKeyword')?.value.trim(); if (kw) query.search = kw;
  let data;
  try { data = await api('api/logs.php', { query }); }
  catch (e) { $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-danger py-3">${esc(e.message)}</td></tr>`; return; }
  const rows = data.logs || [];
  $('#logsBody').innerHTML = rows.length
    ? rows.map((r) => `<tr>${def.cells(r).map((c) => `<td class="text-nowrap">${c ?? ''}</td>`).join('')}</tr>`).join('')
    : `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">該当するアクセスログがありません</td></tr>`;
  makeLogTableSortable('#logsHead', '#logsBody');
  const pg = data.pagination || {};
  $('#logsInfo').textContent = `全 ${pg.total_count ?? rows.length} 件中 ${rows.length} 件表示（ページ ${pg.page ?? 1}/${pg.total_pages ?? 1}、最大5000件/ページ）`;
  // ページャ(webaccess専用)。
  logsState.webTotalPages = pg.total_pages || 1;
  $('#logsPrevBtn').disabled = (pg.page ?? 1) <= 1;
  $('#logsNextBtn').disabled = (pg.page ?? 1) >= (pg.total_pages || 1);
  // ログファイルサイズ表示。
  if (data.log_files && data.log_files.length) {
    const total = data.log_files.reduce((s, f) => s + (f.size || 0), 0);
    $('#logsFileInfo').style.display = '';
    $('#logsFileInfo').textContent = `ログ ${data.log_files.length} ファイル / 合計 ${formatBytesJs(total)}`;
  }
}
// GeoIP を後追い補完する（未知IPだけ外部APIで解決。一覧は即座に返すのでタイムアウトしない）。
// サーバは1回200件まで解決し未解決の残数(geoip_remaining)を返す。残数が0になるまで
// 自動で繰り返し叩き、その時点で表示中の全IPの国/ISPを埋め切る。
async function refreshWebAccessGeoip() {
  const btn = document.getElementById('webGeoipBtn');
  if (btn) { btn.disabled = true; btn.dataset.orig = btn.innerHTML; }
  const query = { action: 'webaccess_geoip', page: logsState.webPage || 1, per_page: 5000 };
  const sd = logDateVal('#logsStartDate'); if (sd) query.start_date = sd;
  const ed = logDateVal('#logsEndDate'); if (ed) query.end_date = ed;
  const pf = $('#logsPathFilter')?.value.trim(); if (pf) query.path = pf;
  const kw = $('#logsKeyword')?.value.trim(); if (kw) query.search = kw;
  const setLabel = (t) => { if (btn) btn.innerHTML = `<span class="spinner-border spinner-border-sm"></span> ${t}`; };
  try {
    // 残数が尽きるまで繰り返す。上限は暴走防止(200件×100回=2万件)。
    // 残数が2回続けて減らなければ(API失敗でUnknown固定等)打ち切る。
    let prevRemaining = Infinity, stall = 0;
    for (let i = 0; i < 100; i++) {
      const res = await api('api/logs.php', { query });
      const remaining = Number(res?.geoip_remaining ?? 0);
      if (remaining <= 0) { break; }
      setLabel(`GeoIP取得中… 残り${remaining}件`);
      if (remaining >= prevRemaining) { if (++stall >= 2) break; } else { stall = 0; }
      prevRemaining = remaining;
    }
    toast('GeoIP情報を更新しました（未知IPをすべて解決）', 'ok');
    await loadWebAccessLog();  // キャッシュが埋まった状態で再表示
  } catch (e) {
    toast(e.message || 'GeoIP更新に失敗しました', 'err');
  } finally {
    if (btn) { btn.disabled = false; if (btn.dataset.orig) btn.innerHTML = btn.dataset.orig; }
  }
}
// WebアクセスLog CSV(全件, GeoIP付き)ダウンロード。
function downloadWebAccessCsv() {
  const qs = new URLSearchParams({ action: 'webaccess_csv' });
  const sd = logDateVal('#logsStartDate'); if (sd) qs.set('start_date', sd);
  const ed = logDateVal('#logsEndDate'); if (ed) qs.set('end_date', ed);
  const pf = $('#logsPathFilter')?.value.trim(); if (pf) qs.set('path', pf);
  window.open(`api/logs.php?${qs}`, '_blank');
}
// datetime-local の値(YYYY-MM-DDTHH:MM)を 'YYYY-MM-DD HH:MM:SS' に変換。空なら ''。
function logDateVal(sel) {
  const v = $(sel)?.value;
  if (!v) return '';
  // datetime-local は秒がない場合があるので補完。
  return v.replace('T', ' ') + (v.length === 16 ? ':00' : '');
}
// バイト数を人間可読に(JS側)。
function formatBytesJs(size) {
  if (!size || size <= 0) return '0 B';
  const u = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.min(Math.floor(Math.log(size) / Math.log(1024)), u.length - 1);
  return `${(size / 1024 ** i).toFixed(2)} ${u[i]}`;
}
// 訓練結果ログ(対象者別 開封状況)。status フィルタ、全件表示。
async function loadTrainingResults() {
  const def = LOG_COLUMNS.training_results;
  $('#logsHead').innerHTML = `<tr>${def.headers.map((h) => `<th>${h}</th>`).join('')}</tr>`;
  $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">読込中…</td></tr>`;
  const query = { action: 'training_results' };
  const cid = $('#logsCampaignFilter')?.value; if (cid) query.campaign_id = cid;
  const st = $('#logsStatusFilter')?.value; if (st) query.status = st;
  let data;
  try { data = await api('api/logs.php', { query }); }
  catch (e) { $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-danger py-3">${esc(e.message)}</td></tr>`; return; }
  const rows = data.rows || [];
  $('#logsBody').innerHTML = rows.length
    ? rows.map((r) => `<tr>${def.cells(r).map((c) => `<td>${c ?? ''}</td>`).join('')}</tr>`).join('')
    : `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">対象データがありません</td></tr>`;
  makeLogTableSortable('#logsHead', '#logsBody');
  $('#logsInfo').textContent = `${rows.length} 件`;
}
// 訓練結果ログを CSV ダウンロード。
async function downloadTrainingResultsCsv() {
  const qs = new URLSearchParams({ action: 'training_results_csv' });
  const cid = $('#logsCampaignFilter')?.value; if (cid) qs.set('campaign_id', cid);
  if (State.user && State.user.role === 'superadmin' && State.activeTenantId) qs.set('tenant_id', State.activeTenantId);
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), 30000);
  let res;
  try { res = await fetch(`api/logs.php?${qs}`, { credentials: 'same-origin', signal: ctrl.signal }); }
  finally { clearTimeout(timer); }
  if (!res.ok) { toast(`出力に失敗しました（HTTP ${res.status}）`, 'err'); return; }
  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = `training_results_${new Date().toISOString().slice(0, 10)}.csv`;
  document.body.appendChild(a); a.click(); a.remove();
  URL.revokeObjectURL(url);
}
// 返信者(Maildir 実ファイル): 送信元アカウントの受信メール一覧。superadmin 限定。
async function loadReplyMaildir() {
  const def = LOG_COLUMNS.reply_maildir;
  $('#logsHead').innerHTML = `<tr>${def.headers.map((h) => `<th>${h}</th>`).join('')}</tr>`;
  $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">読込中…</td></tr>`;
  const query = { action: 'reply_maildir' };
  // キャンペーン選択時は、その送信元アドレス宛に届いた受信メールだけに絞る。
  const cid = $('#logsCampaignFilter')?.value; if (cid) query.campaign_id = cid;
  const sender = $('#logsSenderFilter')?.value; if (sender) query.sender = sender;
  const kw = $('#logsKeyword')?.value.trim(); if (kw) query.q = kw;
  const sd = logDateVal('#logsStartDate'); if (sd) query.start_date = sd;
  const ed = logDateVal('#logsEndDate'); if (ed) query.end_date = ed;
  let data;
  try { data = await api('api/logs.php', { query }); }
  catch (e) { $('#logsBody').innerHTML = `<tr><td colspan="${def.headers.length}" class="text-center text-danger py-3">${esc(e.message)}</td></tr>`; return; }
  // 送信元アカウントのセレクトは毎回作り直す(件数も最新化される)。選択中の値は維持。
  const sel = $('#logsSenderFilter');
  if (sel && data.accounts) {
    const prevSender = sel.value;
    while (sel.options.length > 1) sel.remove(1);
    for (const [key, email] of Object.entries(data.accounts)) {
      const n = (data.counts && data.counts[key]) || 0;
      const o = document.createElement('option');
      o.value = key; o.textContent = `${key} <${email}> (${n})`;
      sel.appendChild(o);
    }
    sel.value = prevSender && [...sel.options].some((o) => o.value === prevSender) ? prevSender : '';
  }
  const rows = data.rows || [];
  // 権限不足で読めないメールは黄色で目立たせる。以前は一覧から静かに消えており、
  // 届いている返信を見落とす原因になっていた。
  $('#logsBody').innerHTML = rows.length
    ? rows.map((r) => {
        const cls = r.unreadable ? ' class="table-warning"' : '';
        return `<tr${cls}>${def.cells(r).map((c) => `<td>${c ?? ''}</td>`).join('')}</tr>`;
      }).join('')
    : `<tr><td colspan="${def.headers.length}" class="text-center text-muted py-3">受信メールはありません</td></tr>`;
  makeLogTableSortable('#logsHead', '#logsBody');
  const unreadable = rows.filter((r) => r.unreadable).length;
  const info = $('#logsInfo');
  info.textContent = `${data.total ?? rows.length} 件（送信元アカウントの受信トレイを直接参照）`;
  if (unreadable) {
    const span = document.createElement('span');
    span.className = 'text-danger fw-bold ms-2';
    span.textContent = `／読み取り不可 ${unreadable} 件（サーバ側のファイル権限を要確認）`;
    info.appendChild(span);
  }
}
// 受信メール本文をモーダル表示(Maildir)。
async function viewMailBody(account, filename, meta) {
  $('#mailBodyTitle').textContent = '受信メール';
  $('#mailBodyMeta').innerHTML = meta || '';
  $('#mailBodyText').textContent = '読込中…';
  const modal = bootstrap.Modal.getOrCreateInstance($('#mailBodyModal'));
  modal.show();
  try {
    const d = await api('api/logs.php', { query: { action: 'reply_maildir_view', account, filename } });
    $('#mailBodyText').textContent = d.body || '（本文が空です）';
  } catch (e) {
    $('#mailBodyText').textContent = e.message;
  }
}
// 返信者(受信メール)一覧を CSV ダウンロード。
function downloadReplyMaildirCsv() {
  const qs = new URLSearchParams({ action: 'reply_maildir_csv' });
  const cid = $('#logsCampaignFilter')?.value; if (cid) qs.set('campaign_id', cid);
  const sender = $('#logsSenderFilter')?.value; if (sender) qs.set('sender', sender);
  const kw = $('#logsKeyword')?.value.trim(); if (kw) qs.set('q', kw);
  const sd = logDateVal('#logsStartDate'); if (sd) qs.set('start_date', sd);
  const ed = logDateVal('#logsEndDate'); if (ed) qs.set('end_date', ed);
  window.open(`api/logs.php?${qs}`, '_blank');
}
function switchLogTab(type) {
  reportMailRequest++;
  logsState.type = type;
  logsState.offset = 0;
  logsState.webPage = 1;
  $$('#logsTabs .nav-link').forEach((a) => a.classList.toggle('active', a.dataset.log === type));
  const isRaw = (type === 'raw_mail' || type === 'raw_web');
  const isTr = (type === 'training_results');
  const isTld = (type === 'training_log_detail');
  const isWeb = (type === 'webaccess');
  const isMd = (type === 'reply_maildir');
  const isCf = (type === 'campaign_files');
  const isReport = (type === 'report_mail');
  // 追加フィルタ行を出すタブ(campaign_files は CSV ボタンを出すため含める)。
  const hasFilter = (isRaw || isTr || isTld || isWeb || isMd || isCf || isReport);
  // 期間(開始/終了)を使うタブ。
  const hasPeriod = (isTld || isWeb || isMd);
  // DB ページャ(offset)を使う既存タブ。
  const isDbPaged = (type === 'delivery' || type === 'events' || type === 'schedule' || type === 'replies' || type === 'audit' || isReport);

  const show = (sel, on) => { const el = $(sel); if (el) el.style.display = on ? '' : 'none'; };

  $('#logsRaw').classList.toggle('d-none', !isRaw);
  $('#logsTableWrap').classList.toggle('d-none', isRaw);
  // ページャは DB ページ系 と WebアクセスLog(独自ページ)で表示。
  $('#logsPager').style.display = (isDbPaged || isWeb) ? '' : 'none';
  $('#logsExtraFilter').style.display = hasFilter ? '' : 'none';

  show('#logsCampaignFilter', !isReport);
  show('#reportMailStatus', isReport);
  show('#reportMailUnmatchedWrap', isReport && State.user?.role === 'superadmin');
  show('#reportMailUnmatchedTable', false);
  show('#logsStatusFilter', isTr);
  show('#logsTypeFilter', isTld);
  show('#logsInclSystemWrap', isTld);
  show('#logsSenderFilter', isMd);
  show('#logsStartLabel', hasPeriod);
  show('#logsEndLabel', hasPeriod);
  show('#logsPathFilter', isWeb);
  show('#logsKeyword', isRaw || isMd || isWeb);
  show('#logsApplyBtn', hasFilter && !isReport);
  // CSV 出力: 訓練結果/訓練結果ログ明細/WebアクセスLog/返信者Maildir/リンク・ビーコンファイル一覧。
  show('#logsCsvBtn', isTr || isTld || isWeb || isMd || isCf);
  // Excel(XLSX)出力: 生ログ以外の全タブ。
  show('#logsXlsxBtn', !isRaw && !isReport);
  // GeoIP取得: WebアクセスLog のみ(一覧はキャッシュのみ表示、未知IPはこのボタンで後追い解決)。
  show('#webGeoipBtn', isWeb);
  show('#tldGeoipBtn', isTld);
  // 「更新」ボタンは campaign_files では上部キャンペーン選択で再読込するため隠す(専用フィルタなし)。
  if (isCf) show('#logsApplyBtn', false);
  // ログ全件DL: 生ログ(mail/web)のみ。
  show('#logsRawDlBtn', isRaw);
  // ファイルサイズバッジは raw/web のみ。切替時は一旦隠す。
  show('#logsFileInfo', false);

  loadLogs();
}

async function viewMaster(kind, file) {
  let r;
  try { r = await api('api/master_upload.php', { query: kind === 'auth' ? { action: 'get', kind, file } : { action: 'get', kind } }); }
  catch (e) { toast(e.message, 'err'); return; }
  const html = r.content || '';
  if (!html) { showInfoModal('マスタ表示', '<p class="text-muted">まだ内容がありません。</p>'); return; }
  // 種明かしでテナント別が未設定→共通(master.html)を表示している場合のバナー。
  const fallbackBanner = r.is_fallback
    ? '<div class="alert alert-info py-2 small mb-2"><i class="bi bi-info-circle me-1"></i>このテナント専用の種明かしは未設定です。<strong>共通の種明かし画面</strong>を表示しています（実際の訓練でもこれが使われます）。「修正」で自社用に差し替えられます。</div>'
    : '';
  // プレビューは iframe sandbox(全制限=JS無効)で安全にレンダリング。srcdoc は属性値なので esc()。
  const body = `
    ${fallbackBanner}
    <ul class="nav nav-pills mb-2" id="masterViewTabs">
      <li class="nav-item"><a class="nav-link active" href="#" data-mv="preview">プレビュー</a></li>
      <li class="nav-item"><a class="nav-link" href="#" data-mv="source">HTMLソース</a></li>
    </ul>
    <div id="mvPreview">
      <div class="small text-muted mb-1">実際の表示イメージ（スクリプトは無効化して安全に表示）。</div>
      <iframe sandbox srcdoc="${esc(html)}" style="width:100%;height:55vh;border:1px solid #dee2e6;border-radius:.375rem;background:#fff"></iframe>
    </div>
    <div id="mvSource" class="d-none">
      <pre class="border rounded p-2 bg-light" style="max-height:55vh;overflow:auto"><code>${esc(html)}</code></pre>
    </div>`;
  showInfoModal(`マスタ表示: ${esc(file)}`, body);
  // タブ切替
  const tabs = document.getElementById('masterViewTabs');
  if (tabs) tabs.addEventListener('click', (e) => {
    const a = e.target.closest('.nav-link'); if (!a) return;
    e.preventDefault();
    tabs.querySelectorAll('.nav-link').forEach((x) => x.classList.toggle('active', x === a));
    document.getElementById('mvPreview').classList.toggle('d-none', a.dataset.mv !== 'preview');
    document.getElementById('mvSource').classList.toggle('d-none', a.dataset.mv !== 'source');
  });
}
// マスタHTMLを「現在の内容を読み込んで編集→保存」する。プレビュー(iframe sandbox)付き。
async function editMaster(kind, file) {
  const label = kind === 'auth' ? `認証マスタ ${file}` : '種明かし画面';
  let cur = '', isFallback = false;
  try {
    const r = await api('api/master_upload.php', { query: kind === 'auth' ? { action: 'get', kind, file } : { action: 'get', kind } });
    cur = r.content || '';
    isFallback = !!r.is_fallback;
  } catch (e) { toast(e.message, 'err'); return; }
  // 注意バナー: 認証マスタは全テナント共通 / 種明かしは共通からコピー編集。
  const banner = kind === 'auth'
    ? '<div class="alert alert-warning py-2 small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>この認証画面は<strong>全テナント共通</strong>です。変更はすべてのテナントの訓練に反映されます。</div>'
    : (isFallback ? '<div class="alert alert-info py-2 small mb-2"><i class="bi bi-info-circle me-1"></i>共通の種明かしを読み込んでいます。保存すると<strong>このテナント専用</strong>の種明かしになります。</div>' : '');
  const body = `${banner}<p class="small text-muted">${esc(label)} を編集します（&lt;script&gt; やイベント属性は不可・1MB以内）。保存時に既存はバックアップされます。</p>
    <ul class="nav nav-pills mb-2" id="editMasterTabs">
      <li class="nav-item"><a class="nav-link active" href="#" data-em="edit">HTML編集</a></li>
      <li class="nav-item"><a class="nav-link" href="#" data-em="preview">プレビュー</a></li>
    </ul>
    <div id="emEdit"><textarea class="form-control" id="masterHtml" rows="14">${esc(cur)}</textarea></div>
    <div id="emPreview" class="d-none">
      <div class="small text-muted mb-1">現在の編集内容の表示イメージ（スクリプト無効）。プレビュータブに切り替えるたび最新化されます。</div>
      <iframe sandbox id="emPreviewFrame" style="width:100%;height:50vh;border:1px solid #dee2e6;border-radius:.375rem;background:#fff"></iframe>
    </div>`;
  showModal(`編集: ${label}`, body, async () => {
    const html = $('#masterHtml').value;
    if (!html.trim()) throw new Error('内容を入力してください');
    if (kind === 'auth' && !confirm('この認証画面は全テナント共通です。すべてのテナントの訓練に反映されます。保存しますか？')) return;
    const payload = kind === 'auth' ? { kind, file, html } : { kind, html };
    await api('api/master_upload.php', { method: 'POST', query: { action: 'upload' }, body: payload });
    toast('保存しました', 'ok');
  });
  // タブ切替(プレビューは編集中の最新内容を毎回反映)
  const tabs = document.getElementById('editMasterTabs');
  if (tabs) tabs.addEventListener('click', (e) => {
    const a = e.target.closest('.nav-link'); if (!a) return;
    e.preventDefault();
    tabs.querySelectorAll('.nav-link').forEach((x) => x.classList.toggle('active', x === a));
    const isPreview = a.dataset.em === 'preview';
    document.getElementById('emEdit').classList.toggle('d-none', isPreview);
    document.getElementById('emPreview').classList.toggle('d-none', !isPreview);
    if (isPreview) document.getElementById('emPreviewFrame').srcdoc = $('#masterHtml').value;
  });
}
// マスタHTMLをファイルとしてダウンロード。
async function downloadMaster(kind, file) {
  try {
    const r = await api('api/master_upload.php', { query: kind === 'auth' ? { action: 'get', kind, file } : { action: 'get', kind } });
    if (!r.exists) { toast('まだ内容がありません', 'err'); return; }
    const blob = new Blob([r.content || ''], { type: 'text/html;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = kind === 'auth' ? file : 'reveal.html';
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
  } catch (e) { toast(e.message, 'err'); }
}

let campaignDataCtx = null;
async function showCampaignData(id) {
  let r;
  try { r = await api('api/campaign_files.php', { query: { action: 'list', id } }); }
  catch (e) { toast(e.message, 'err'); return; }
  campaignDataCtx = { id, files: r.files || [] };
  const tabs = campaignDataCtx.files.map((f, i) =>
    `<button class="btn btn-sm ${i===0?'btn-primary':'btn-outline-secondary'} me-1 mb-1" ${f.exists?'':'disabled'}
       onclick="loadCampaignFile('${esc(f.file)}', this)">${esc(f.label)}${f.exists?'':'（無）'}</button>`).join('');
  const body = `<div class="mb-2">${tabs}</div><div id="campaignFileView" class="small text-muted">ファイルを選択してください</div>`;
  showInfoModal('データビューア', body);
  const first = campaignDataCtx.files.find((f) => f.exists);
  if (first) loadCampaignFile(first.file, null);
}
async function loadCampaignFile(file, btn) {
  if (btn) { document.querySelectorAll('#appModalBody .btn').forEach((b) => { b.classList.remove('btn-primary'); b.classList.add('btn-outline-secondary'); });
    btn.classList.remove('btn-outline-secondary'); btn.classList.add('btn-primary'); }
  const view = document.getElementById('campaignFileView');
  if (!view) return;
  view.innerHTML = '読み込み中…';
  let r;
  try { r = await api('api/campaign_files.php', { query: { action: 'content', id: campaignDataCtx.id, file } }); }
  catch (e) { view.innerHTML = `<span class="text-danger">${esc(e.message)}</span>`; return; }
  if (r.type === 'csv') {
    const headers = r.headers || [];
    const thead = `<thead><tr>${headers.map((h) => `<th>${esc(h)}</th>`).join('')}</tr></thead>`;
    const tbody = (r.data || []).map((row) => `<tr>${headers.map((h) => `<td>${esc(String(row[h] ?? ''))}</td>`).join('')}</tr>`).join('');
    view.innerHTML = `<div class="table-responsive" style="max-height:50vh;overflow:auto"><table class="table table-sm table-bordered">${thead}<tbody>${tbody || `<tr><td colspan="${headers.length||1}" class="text-muted">データなし</td></tr>`}</tbody></table></div>`;
  } else {
    view.innerHTML = `<pre class="border rounded p-2 bg-light" style="max-height:50vh;overflow:auto"><code>${esc(r.content || '')}</code></pre>`;
  }
}

async function openCampaignModal(campaignId = null) {
  const isEdit = campaignId !== null;
  const [tpls, tgts, grps, beacons, campaigns] = await Promise.all([
    api('api/templates.php', { query: { action: 'list' } }),
    api('api/targets.php', { query: { action: 'list' } }),
    api('api/groups.php', { query: { action: 'list' } }),
    api('api/campaigns.php', { query: { action: 'beacon_bases' } }),
    api('api/campaigns.php', { query: { action: 'list' } }),
  ]);
  // 編集時は既存キャンペーンの値を取得してプリフィルする。
  let editData = null;
  if (isEdit) {
    editData = await api('api/campaigns.php', { query: { action: 'get', id: campaignId } });
  }
  const beaconList = beacons.beacon_bases || ['http://85.131.251.224/'];
  const byKind = (k) => (tpls.templates || []).filter((t) => t.kind === k);
  const opt = (arr) => arr.map((t) => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
  // 偽ログイン専用: 認証種別(auth_flag)をラベルに出す。
  // QR型/リンク型で「認証画面を出すか(=auth_flag 1/2/3)、種明かし直行か(=0)」を選択者が区別できるように。
  const AUTH_LABEL = { 0: '認証なし・種明かし直行', 1: 'Box認証', 2: 'Microsoft365認証', 3: 'デジタルアーツ認証' };
  const optPhish = (arr) => arr.map((t) => {
    const af = Number(t.auth_flag) || 0;
    return `<option value="${t.id}">${esc(t.name)}（${AUTH_LABEL[af] || '認証なし'}）</option>`;
  }).join('');
  // プレビュー用: テンプレート id → {name, content, format}
  const tplById = {};
  for (const t of (tpls.templates || [])) tplById[t.id] = t;
  // シナリオ構築: scenario_key を持つ件名/本文をペアにまとめる(件名と本文の連動用)。
  const scenarioMap = {}; // key → {subject_id, body_id, label}
  for (const t of (tpls.templates || [])) {
    if (!t.scenario_key) continue;
    if (!scenarioMap[t.scenario_key]) scenarioMap[t.scenario_key] = { key: t.scenario_key };
    if (t.kind === 'subject') { scenarioMap[t.scenario_key].subject_id = t.id; scenarioMap[t.scenario_key].label = t.name; }
    if (t.kind === 'body') scenarioMap[t.scenario_key].body_id = t.id;
  }
  // 件名と本文が両方揃ったシナリオのみ選択肢にする。
  const scenarios = Object.values(scenarioMap).filter((s) => s.subject_id && s.body_id);
  const scenarioOptions = scenarios.map((s) => `<option value="${s.key}">${esc(s.label)}</option>`).join('');
  const importableCampaigns = (campaigns.campaigns || []).filter((campaign) =>
    Number(campaign.id) !== Number(campaignId) && Number(campaign.content_count || 0) > 0
  );
  const campaignImportOptions = importableCampaigns.map((campaign) =>
    `<option value="${Number(campaign.id)}">${esc(campaign.name)}（${Number(campaign.content_count)}件）</option>`
  ).join('');
  const body = `
    <form id="campaignForm">
      <div class="mb-2"><label class="form-label">キャンペーン名</label><input class="form-control" name="name" required></div>
      <div class="campaign-content-toolbar border rounded p-2 mb-2 bg-light">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <label class="form-label mb-0">コンテンツ <span class="badge bg-primary" id="contentCountLabel">0 / 100件</span></label>
          <div class="btn-group btn-group-sm">
            <button type="button" class="btn btn-outline-secondary" id="collapseAllContents">すべて折りたたむ</button>
            <button type="button" class="btn btn-outline-secondary" id="expandAllContents">すべて展開</button>
            <button type="button" class="btn btn-outline-primary" id="addContentBtn"><i class="bi bi-plus-lg"></i> 追加</button>
          </div>
        </div>
        <div class="row g-2 align-items-end">
          <div class="col-md-9"><label class="form-label small mb-1">過去キャンペーンから取り込む（複数選択可）</label>
            <select class="form-select form-select-sm" id="contentImportCampaigns" multiple size="3">${campaignImportOptions}</select></div>
          <div class="col-md-3 d-grid"><button type="button" class="btn btn-sm btn-outline-primary" id="importSelectedCampaigns">選択内容を追加</button></div>
        </div>
      </div>
      <div id="contentsList" class="mb-2"></div>
      <div class="row g-2">
        <div class="col-md-12 mb-2"><label class="form-label">送信元アドレス（キャンペーン既定・各コンテンツで上書き可）</label><input class="form-control" name="from_address" type="email" required></div>
      </div>
      <div class="row g-2">
        <div class="col-md-12 mb-2"><label class="form-label">ビーコンベースURL（キャンペーン既定・各コンテンツで上書き可。登録済みから選択、または直接入力）</label>
          <div class="input-group">
            <input class="form-control" name="beacon_base" id="beaconBaseInput" list="beaconBaseList" value="${esc(beaconList[0])}" autocomplete="off">
            <button type="button" class="btn btn-outline-secondary" id="beaconCheckBtn"><i class="bi bi-broadcast"></i> 疎通確認</button>
          </div>
          <datalist id="beaconBaseList">${beaconList.map((b) => `<option value="${esc(b)}"></option>`).join('')}</datalist>
          <div class="form-text" id="beaconCheckResult"></div>
        </div>
      </div>
      <div class="row g-2">
        <div class="col-md-4 mb-2"><label class="form-label">送信方式</label>
          <select class="form-select" name="send_mode"><option value="normal">通常</option><option value="split">分割</option><option value="slow">なだらか</option></select></div>
        <div class="col-md-4 mb-2"><label class="form-label">分割数</label><input class="form-control" name="split_count" type="number" min="1"></div>
        <div class="col-md-4 mb-2"><label class="form-label">分割間隔(分)</label>
          <select class="form-select" name="split_interval_min"><option value="">—</option><option>5</option><option>15</option><option>30</option><option>60</option></select></div>
      </div>
      <div class="row g-2">
        <div class="col-md-6 mb-2"><label class="form-label">開始日時</label><input class="form-control" name="start_at" type="datetime-local" required></div>
        <div class="col-md-6 mb-2"><label class="form-label">終了日時</label><input class="form-control" name="end_at" type="datetime-local" required></div>
      </div>
      <div class="row g-2">
        <div class="col-md-4 mb-2"><label class="form-label">営業開始</label><input class="form-control" name="business_start" placeholder="09:00"></div>
        <div class="col-md-4 mb-2"><label class="form-label">営業終了</label><input class="form-control" name="business_end" placeholder="18:00"></div>
        <div class="col-md-4 mb-2 d-flex align-items-end justify-content-between"><div class="form-check">
          <input class="form-check-input" type="checkbox" name="weekdays_only" checked id="cbWeek"><label class="form-check-label" for="cbWeek">平日限定</label></div>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="weekdayPreviewBtn"><i class="bi bi-calendar-week"></i> 対象日を確認</button></div>
      </div>
      <div class="mb-2 d-none" id="weekdayPreview"></div>
      <div class="mb-2"><label class="form-label">配信方式</label>
        <select class="form-select" name="content_delivery">
          <option value="distribute">均等割り（各対象者に1コンテンツを均等配分）</option>
          <option value="all">全員に全コンテンツ（テスト対象者に全パターン送付）</option>
        </select>
        <div class="form-text">「全員に全コンテンツ」は各対象者へ登録した全コンテンツを送ります。コンテンツごとに個別追跡されます。</div></div>
      <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_test" id="cbTest"><label class="form-check-label" for="cbTest">テスト送信（is_test）</label></div>
      <div class="mb-2" id="testRedirectWrap" style="display:none">
        <label class="form-label">テスト宛先（リダイレクト先メール・カンマ区切り）</label>
        <textarea class="form-control" name="test_redirect_emails" rows="2" placeholder="test1@example.com, test2@example.com"></textarea>
        <div class="form-text">テスト送信時、本番の宛先リスト分のメールを、ここに入力したアドレスへ<strong>均等分配</strong>して送ります（例: 1000名分を4アドレスに各250通）。差し込みデータ・追跡は本番のまま。空なら本番の宛先にそのまま送ります。</div>
      </div>
      <hr>
      <div class="mb-2"><label class="form-label">対象グループ</label>
        <select class="form-select" name="group_ids" multiple size="3">${(grps.groups||[]).map((g)=>`<option value="${g.id}">${esc(g.name)}（${Number(g.target_count || 0)}名）</option>`).join('')}</select>
        <div class="form-text">グループを変更すると、前回の個別対象者選択は解除されます。</div></div>
      <div class="mb-2"><label class="form-label">個別対象者（グループへ追加する場合）</label>
        <select class="form-select" name="target_ids" multiple size="4">${(tgts.targets||[]).map((t)=>`<option value="${t.id}">${esc(t.email)}（${esc(t.name||'')}）</option>`).join('')}</select></div>
      <div class="alert alert-info py-2 mb-0" id="campaignTargetSummary">送付予定人数を計算しています...</div>
    </form>`;
  // コンテンツ行のHTML（件名/本文/偽ログイン/配信形式/添付拡張子・zip/削除）
  const contentRow = (idx) => `
    <div class="border rounded mb-2 content-row" data-idx="${idx}">
      <div class="content-row-header d-flex justify-content-between align-items-center gap-2 p-2 bg-light">
        <div class="min-w-0"><span class="badge bg-secondary">コンテンツ ${idx + 1}</span>
          <span class="content-row-summary small text-muted ms-2"></span></div>
        <div class="btn-group btn-group-sm flex-shrink-0">
          <button type="button" class="btn btn-outline-secondary move-content-up" title="上へ"><i class="bi bi-arrow-up"></i></button>
          <button type="button" class="btn btn-outline-secondary move-content-down" title="下へ"><i class="bi bi-arrow-down"></i></button>
          <button type="button" class="btn btn-outline-secondary toggle-content" aria-expanded="true" title="折りたたむ"><i class="bi bi-chevron-up"></i></button>
          <button type="button" class="btn btn-outline-danger del-content" ${idx === 0 ? 'style="visibility:hidden"' : ''}><i class="bi bi-trash"></i></button>
        </div>
      </div>
      <div class="content-row-body p-2">
      <div class="row g-2">
        <div class="col-md-12"><label class="form-label small fw-bold">シナリオ（選ぶと件名・本文が連動します）</label>
          <select class="form-select form-select-sm c-scenario">
            <option value="">— 個別に選択 —</option>
            ${scenarioOptions}
          </select></div>
      </div>
      <div class="row g-2 mt-1">
        <div class="col-md-4"><label class="form-label small">件名</label><select class="form-select form-select-sm c-subject">${opt(byKind('subject'))}</select></div>
        <div class="col-md-4"><label class="form-label small">本文</label><select class="form-select form-select-sm c-body">${opt(byKind('body'))}</select></div>
        <div class="col-md-4"><label class="form-label small">偽ログイン（認証画面）</label><select class="form-select form-select-sm c-phish">${optPhish(byKind('phish_login'))}</select></div>
      </div>
      <div class="text-end mt-1">
        <button type="button" class="btn btn-sm btn-outline-info c-preview"><i class="bi bi-eye"></i> 内容プレビュー</button>
      </div>
      <div class="c-preview-area border rounded p-2 mt-1 bg-light d-none"></div>
      <div class="row g-2 mt-1">
        <div class="col-md-4"><label class="form-label small">配信形式</label>
          <select class="form-select form-select-sm c-linkmode"><option value="link">リンク</option><option value="attachment">添付</option><option value="form">フォーム</option><option value="qr">QRコード</option></select></div>
        <div class="col-md-4"><label class="form-label small c-ext-label text-muted">添付拡張子(添付/QR時)</label><input class="form-control form-control-sm c-ext" placeholder="html / docx / pdf / xlsx / pptx"></div>
        <div class="col-md-4 d-flex align-items-end"><div class="form-check">
          <input class="form-check-input c-zip" type="checkbox"><label class="form-check-label small c-zip-label text-muted">zip化</label></div></div>
      </div>
      <div class="row g-2 mt-1">
        <div class="col-md-8"><label class="form-label small c-prefix-label text-muted">添付ファイル名の接頭辞(添付/QR時・任意)</label>
          <input class="form-control form-control-sm c-prefix" maxlength="40" placeholder="例: 添付資料-  （末尾に管理番号と拡張子が自動で付きます）"></div>
      </div>
      <div class="form-text c-linkmode-hint"></div>
      <div class="form-check mt-1">
        <input class="form-check-input c-suppress-url" type="checkbox" id="sup-${idx}">
        <label class="form-check-label small c-suppress-label text-muted" for="sup-${idx}">本文にURLを差し込まない（添付/QR型向け：本文に半端なリンクを残さない）</label>
      </div>
      <div class="form-check mt-1">
        <input class="form-check-input c-suppress-email" type="checkbox" id="supmail-${idx}">
        <label class="form-check-label small text-muted" for="supmail-${idx}">認証画面にメールアドレスを事前入力しない（利用者に自分で入力させる）</label>
      </div>
      <div class="row g-2 mt-1">
        <div class="col-md-6"><label class="form-label small text-muted">送信元アドレス（任意・未指定ならキャンペーン既定）</label>
          <input class="form-control form-control-sm c-from" type="email" placeholder="このコンテンツ専用の送信元"></div>
        <div class="col-md-6"><label class="form-label small text-muted">ビーコンURL（任意・未指定ならキャンペーン既定）</label>
          <input class="form-control form-control-sm c-beacon" list="beaconBaseList" placeholder="このコンテンツ専用の追跡URL"></div>
      </div>
      </div>
    </div>`;
  showModal(isEdit ? 'キャンペーン編集' : '新規キャンペーン', body, async () => {
    const f = $('#campaignForm');
    // コンテンツ収集
    const contents = Array.from(document.querySelectorAll('#contentsList .content-row')).map((row) => {
      const c = {
        subject_template_id: Number(row.querySelector('.c-subject').value),
        body_template_id: Number(row.querySelector('.c-body').value),
        phish_template_id: Number(row.querySelector('.c-phish').value),
        link_mode: row.querySelector('.c-linkmode').value,
      };
      const ext = row.querySelector('.c-ext').value.trim();
      if (ext) c.attachment_ext = ext;
      const prefix = row.querySelector('.c-prefix').value.trim();
      if (prefix) c.attachment_filename = prefix;
      if (row.querySelector('.c-zip').checked) c.attachment_zip = 1;
      if (row.querySelector('.c-suppress-url').checked) c.suppress_body_url = 1;
      if (row.querySelector('.c-suppress-email').checked) c.suppress_prefill_email = 1;
      // コンテンツ別の送信元/ビーコンURL(任意)。空ならキャンペーン既定を使う。
      const cFrom = row.querySelector('.c-from').value.trim();
      if (cFrom) c.from_address = cFrom;
      const cBeacon = row.querySelector('.c-beacon').value.trim();
      if (cBeacon) c.beacon_base = cBeacon;
      return c;
    });
    if (!contents.length) throw new Error('コンテンツを1件以上追加してください');
    const payload = {
      name: f.name.value.trim(),
      contents,
      from_address: f.from_address.value.trim(),
      send_mode: f.send_mode.value,
      weekdays_only: f.weekdays_only.checked,
      is_test: f.is_test.checked,
      content_delivery: f.content_delivery ? f.content_delivery.value : 'distribute',
      test_redirect_emails: (f.test_redirect_emails && f.test_redirect_emails.value.trim()) || null,
      start_at: f.start_at.value.replace('T', ' '),
      end_at: f.end_at.value.replace('T', ' '),
      target_ids: multiVals(f.target_ids),
      group_ids: multiVals(f.group_ids),
    };
    if (f.split_count.value) payload.split_count = Number(f.split_count.value);
    if (f.split_interval_min.value) payload.split_interval_min = Number(f.split_interval_min.value);
    if (f.business_start.value.trim()) payload.business_start = f.business_start.value.trim();
    if (f.business_end.value.trim()) payload.business_end = f.business_end.value.trim();
    if (f.beacon_base && f.beacon_base.value.trim()) payload.beacon_base = f.beacon_base.value.trim();
    if (!payload.target_ids.length && !payload.group_ids.length) throw new Error('対象者かグループを選択してください');
    if (isEdit) {
      payload.id = campaignId;
      await api('api/campaigns.php', { method: 'POST', query: { action: 'update' }, body: payload });
      toast('更新しました', 'ok');
    } else {
      await api('api/campaigns.php', { method: 'POST', query: { action: 'create' }, body: payload });
      toast('作成しました', 'ok');
    }
    renderCampaigns();
  }, { size: 'xl' });
  // モーダル表示後: コンテンツリストを初期化（1行）+ 追加/削除ボタン配線
  let contentIdx = 0;
  const listEl = document.getElementById('contentsList');
  const syncContentSummary = (row) => {
    const scenario = row.querySelector('.c-scenario').selectedOptions[0]?.textContent || '個別指定';
    const mode = row.querySelector('.c-linkmode').selectedOptions[0]?.textContent || '';
    row.querySelector('.content-row-summary').textContent = `${scenario} / ${mode}`;
  };
  const setContentExpanded = (row, expanded) => {
    row.querySelector('.content-row-body').classList.toggle('d-none', !expanded);
    const button = row.querySelector('.toggle-content');
    button.setAttribute('aria-expanded', String(expanded));
    button.title = expanded ? '折りたたむ' : '展開する';
    button.querySelector('i').className = expanded ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
  };
  const renumber = () => {
    const rows = Array.from(listEl.querySelectorAll('.content-row'));
    rows.forEach((row, i) => {
      row.querySelector('.badge').textContent = `コンテンツ ${i + 1}`;
      const del = row.querySelector('.del-content');
      del.style.visibility = i === 0 && rows.length === 1 ? 'hidden' : 'visible';
      row.querySelector('.move-content-up').disabled = i === 0;
      row.querySelector('.move-content-down').disabled = i === rows.length - 1;
      syncContentSummary(row);
    });
    const countLabel = document.getElementById('contentCountLabel');
    if (countLabel) countLabel.textContent = `${rows.length} / 100件`;
  };
  // 配信形式に応じて添付拡張子/zipの有効・無効を切り替える(整合性ガード)。
  // link/form 型は本文中のリンクで追跡するため、添付拡張子・zipは意味を持たない→無効化。
  const syncAttachment = (row) => {
    const mode = row.querySelector('.c-linkmode').value;
    const needsAttachment = (mode === 'attachment' || mode === 'qr');
    const ext = row.querySelector('.c-ext');
    const zip = row.querySelector('.c-zip');
    const prefix = row.querySelector('.c-prefix');
    const extLabel = row.querySelector('.c-ext-label');
    const zipLabel = row.querySelector('.c-zip-label');
    const prefixLabel = row.querySelector('.c-prefix-label');
    const hint = row.querySelector('.c-linkmode-hint');
    const suppress = row.querySelector('.c-suppress-url');
    const suppressLabel = row.querySelector('.c-suppress-label');
    ext.disabled = !needsAttachment;
    zip.disabled = !needsAttachment;
    // 添付ファイル名の接頭辞も添付/QR型のみ有効(link/form型ではファイルを作らない)。
    prefix.disabled = !needsAttachment;
    // 本文URL抑制は添付/QR型のみ有効(リンク/フォーム型でURLを消すと追跡不能になるため)。
    suppress.disabled = !needsAttachment;
    extLabel.classList.toggle('text-muted', !needsAttachment);
    zipLabel.classList.toggle('text-muted', !needsAttachment);
    prefixLabel.classList.toggle('text-muted', !needsAttachment);
    suppressLabel.classList.toggle('text-muted', !needsAttachment);
    if (!needsAttachment) { ext.value = ''; zip.checked = false; suppress.checked = false; prefix.value = ''; }
    else { suppress.checked = true; } // 添付/QR型は既定で本文URLを抑制(半端なリンクを残さない)
    const hints = {
      link: 'リンク型：本文中のリンクをクリックすると追跡します（添付は使いません）。',
      form: 'フォーム型：偽ログインフォームへの入力を追跡します（添付は使いません）。',
      attachment: '添付型：指定拡張子のファイルを添付し、開封を追跡します。',
      qr: 'QRコード型：QR画像を添付し、スキャン（＝クリック）を追跡します。',
    };
    hint.textContent = hints[mode] || '';
  };
  // コンテンツの本文プレビュー(事故防止): 選択中の件名/本文/偽ログインの実内容を行内に展開表示。
  // 作成フォームを壊さないよう、モーダルではなく行内の c-preview-area にトグル表示する。
  const previewContent = (row) => {
    const area = row.querySelector('.c-preview-area');
    if (!area.classList.contains('d-none')) { area.classList.add('d-none'); area.innerHTML = ''; return; }
    const sid = row.querySelector('.c-subject').value;
    const bid = row.querySelector('.c-body').value;
    const pid = row.querySelector('.c-phish').value;
    const block = (label, t) => {
      if (!t) return `<div class="mb-2"><strong>${label}</strong> <span class="text-muted small">（未選択）</span></div>`;
      return `<div class="mb-2"><strong>${label}：</strong>${esc(t.name)}
        <pre class="border rounded p-2 mt-1 small mb-0" style="white-space:pre-wrap;max-height:180px;overflow:auto">${esc(t.content || '')}</pre></div>`;
    };
    area.innerHTML = `<div class="small text-muted mb-2">送信前の内容確認。プレースホルダ #$1$#〜 は対象者情報に置換されます。</div>
      ${block('件名', tplById[sid])}
      ${block('本文', tplById[bid])}
      ${block('偽ログイン画面', tplById[pid])}`;
    area.classList.remove('d-none');
  };
  const addRow = (prefill = null) => {
    if (listEl.querySelectorAll('.content-row').length >= 100) {
      toast('コンテンツは100件までです', 'err');
      return null;
    }
    const wrap = document.createElement('div');
    wrap.innerHTML = contentRow(contentIdx++);
    const row = wrap.firstElementChild;
    row.querySelector('.del-content').addEventListener('click', () => { row.remove(); renumber(); });
    row.querySelector('.toggle-content').addEventListener('click', () => {
      const expanded = row.querySelector('.toggle-content').getAttribute('aria-expanded') === 'true';
      setContentExpanded(row, !expanded);
    });
    row.querySelector('.move-content-up').addEventListener('click', () => {
      const previous = row.previousElementSibling;
      if (previous) listEl.insertBefore(row, previous);
      renumber();
    });
    row.querySelector('.move-content-down').addEventListener('click', () => {
      const next = row.nextElementSibling;
      if (next) listEl.insertBefore(next, row);
      renumber();
    });
    row.querySelector('.c-linkmode').addEventListener('change', () => {
      syncAttachment(row);
      syncContentSummary(row);
    });
    row.querySelector('.c-preview').addEventListener('click', () => previewContent(row));
    // シナリオ選択 → 件名・本文を連動セット。
    const scenSel = row.querySelector('.c-scenario');
    scenSel.addEventListener('change', () => {
      const s = scenarioMap[scenSel.value];
      if (s && s.subject_id && s.body_id) {
        row.querySelector('.c-subject').value = s.subject_id;
        row.querySelector('.c-body').value = s.body_id;
      }
      syncContentSummary(row);
    });
    // 件名/本文を手動変更したら、シナリオ選択を「個別」に戻す(連動が崩れたことを示す)。
    const clearScenario = () => { scenSel.value = ''; };
    row.querySelector('.c-subject').addEventListener('change', clearScenario);
    row.querySelector('.c-body').addEventListener('change', clearScenario);
    listEl.appendChild(row);
    if (prefill) {
      // 編集時: 既存コンテンツの値を復元。
      if (prefill.subject_template_id) row.querySelector('.c-subject').value = prefill.subject_template_id;
      if (prefill.body_template_id) row.querySelector('.c-body').value = prefill.body_template_id;
      if (prefill.phish_template_id) row.querySelector('.c-phish').value = prefill.phish_template_id;
      if (prefill.link_mode) row.querySelector('.c-linkmode').value = prefill.link_mode;
      row.querySelector('.c-scenario').value = '';  // 個別指定として復元
      syncAttachment(row);  // link_mode に応じて添付欄の有効/無効を先に整える
      if (prefill.attachment_ext) row.querySelector('.c-ext').value = prefill.attachment_ext;
      if (prefill.attachment_filename) row.querySelector('.c-prefix').value = prefill.attachment_filename;
      row.querySelector('.c-zip').checked = Number(prefill.attachment_zip) === 1;
      row.querySelector('.c-suppress-url').checked = Number(prefill.suppress_body_url) === 1;
      row.querySelector('.c-suppress-email').checked = Number(prefill.suppress_prefill_email) === 1;
      if (prefill.from_address) row.querySelector('.c-from').value = prefill.from_address;
      if (prefill.beacon_base) row.querySelector('.c-beacon').value = prefill.beacon_base;
    } else {
      syncAttachment(row); // 初期状態(link)で添付を無効化
      // 初期状態で最初のシナリオを選択して件名・本文を連動させておく(ちぐはぐ防止の既定)。
      if (scenarios.length) { scenSel.value = scenarios[0].key; scenSel.dispatchEvent(new Event('change')); }
    }
    row.dataset.pristine = prefill ? 'false' : 'true';
    row.addEventListener('input', () => { row.dataset.pristine = 'false'; });
    row.addEventListener('change', () => { row.dataset.pristine = 'false'; syncContentSummary(row); });
    setContentExpanded(row, true);
    renumber();
    return row;
  };
  const importSelectedCampaigns = async () => {
    const select = document.getElementById('contentImportCampaigns');
    const campaignIds = Array.from(select?.selectedOptions || []).map((option) => Number(option.value));
    if (!campaignIds.length) throw new Error('取り込むキャンペーンを選択してください');
    const importedCampaigns = await Promise.all(campaignIds.map((id) =>
      api('api/campaigns.php', { query: { action: 'get', id } })
    ));
    const importedContents = importedCampaigns.flatMap((campaign) => campaign.contents || []);
    const rows = Array.from(listEl.querySelectorAll('.content-row'));
    const replacePlaceholder = rows.length === 1 && rows[0].dataset.pristine === 'true';
    const retainedCount = replacePlaceholder ? 0 : rows.length;
    if (retainedCount + importedContents.length > 100) {
      throw new Error(`取り込み後のコンテンツ数が100件を超えます（${retainedCount + importedContents.length}件）`);
    }
    if (replacePlaceholder) rows[0].remove();
    listEl.querySelectorAll('.content-row').forEach((row) => setContentExpanded(row, false));
    let firstImported = null;
    importedContents.forEach((content) => {
      const row = addRow(content);
      if (row) setContentExpanded(row, false);
      if (firstImported === null) firstImported = row;
    });
    if (firstImported) setContentExpanded(firstImported, true);
    Array.from(select.options).forEach((option) => { option.selected = false; });
    renumber();
    toast(`${importedContents.length}件のコンテンツを取り込みました`, 'ok');
  };
  if (listEl) {
    const editContents = (isEdit && editData && editData.contents && editData.contents.length) ? editData.contents : null;
    if (editContents) {
      editContents.forEach((c, index) => {
        const row = addRow(c);
        if (row && index > 0) setContentExpanded(row, false);
      });  // 既存コンテンツを行として復元
    } else {
      addRow(); // 初期1行(新規)
    }
    const addBtn = document.getElementById('addContentBtn');
    if (addBtn) addBtn.addEventListener('click', () => {
      listEl.querySelectorAll('.content-row').forEach((row) => setContentExpanded(row, false));
      const row = addRow();
      if (row) setContentExpanded(row, true);
    });
    document.getElementById('collapseAllContents')?.addEventListener('click', () => {
      listEl.querySelectorAll('.content-row').forEach((row) => setContentExpanded(row, false));
    });
    document.getElementById('expandAllContents')?.addEventListener('click', () => {
      listEl.querySelectorAll('.content-row').forEach((row) => setContentExpanded(row, true));
    });
    document.getElementById('importSelectedCampaigns')?.addEventListener('click', async () => {
      try { await importSelectedCampaigns(); }
      catch (error) { toast(error.message, 'err'); }
    });
  }
  // 編集時: キャンペーン本体フィールドをプリフィル。
  if (isEdit && editData && editData.campaign) {
    const c = editData.campaign;
    const f = document.getElementById('campaignForm');
    const setVal = (name, val) => { if (f[name] !== undefined && val !== null && val !== undefined) f[name].value = val; };
    setVal('name', c.name);
    setVal('from_address', c.from_address);
    if (c.beacon_base) setVal('beacon_base', c.beacon_base);
    setVal('send_mode', c.send_mode || 'normal');
    if (c.split_count) setVal('split_count', c.split_count);
    if (c.split_interval_min) setVal('split_interval_min', c.split_interval_min);
    // datetime-local は "YYYY-MM-DDTHH:MM" 形式。DBは "YYYY-MM-DD HH:MM:SS"。
    if (c.start_at) f.start_at.value = String(c.start_at).replace(' ', 'T').slice(0, 16);
    if (c.end_at) f.end_at.value = String(c.end_at).replace(' ', 'T').slice(0, 16);
    if (c.business_start) setVal('business_start', c.business_start);
    if (c.business_end) setVal('business_end', c.business_end);
    f.weekdays_only.checked = Number(c.weekdays_only) === 1;
    f.is_test.checked = Number(c.is_test) === 1;
    if (f.content_delivery) f.content_delivery.value = c.content_delivery || 'distribute';
    if (f.test_redirect_emails && c.test_redirect_emails) f.test_redirect_emails.value = c.test_redirect_emails;
    toggleTestRedirect();  // is_test の状態に応じてテスト宛先欄の表示を更新
    // 対象者を選択状態にする。
    const tids = (editData.target_ids || []).map(String);
    if (f.target_ids && tids.length) {
      Array.from(f.target_ids.options).forEach((o) => { o.selected = tids.includes(o.value); });
    }
  }
  const campaignForm = document.getElementById('campaignForm');
  const campaignTargetSummary = document.getElementById('campaignTargetSummary');
  const allMembersGroupIds = new Set((grps.groups || [])
    .filter((group) => group.kind === 'all' || group.name === '全職員')
    .map((group) => Number(group.id)));
  let cachedGroupMemberIds = new Set();
  let targetSummaryRequest = 0;
  const clearIndividualTargets = () => {
    Array.from(campaignForm.target_ids.options).forEach((option) => { option.selected = false; });
  };
  const renderCampaignTargetSummary = () => {
    const individualIds = new Set(multiVals(campaignForm.target_ids));
    const combinedIds = new Set([...cachedGroupMemberIds, ...individualIds]);
    const addedIndividuals = [...individualIds].filter((id) => !cachedGroupMemberIds.has(id)).length;
    campaignTargetSummary.textContent = `送付予定: ${combinedIds.size}名（グループ ${cachedGroupMemberIds.size}名 + 個別追加 ${addedIndividuals}名）`;
  };
  const syncCampaignTargetSummary = async () => {
    const requestId = ++targetSummaryRequest;
    const groupIds = multiVals(campaignForm.group_ids);
    try {
      const responses = await Promise.all(groupIds.map((groupId) =>
        api('api/groups.php', { query: { action: 'members', group_id: groupId } })
      ));
      if (requestId !== targetSummaryRequest) return;
      cachedGroupMemberIds = new Set(responses.flatMap((response) =>
        (response.members || []).map((member) => Number(member.id))
      ));
      renderCampaignTargetSummary();
    } catch (error) {
      if (requestId !== targetSummaryRequest) return;
      campaignTargetSummary.textContent = `送付予定人数を取得できません（${error.message}）`;
    }
  };
  campaignForm.group_ids.addEventListener('change', () => {
    const selectedGroupIds = multiVals(campaignForm.group_ids);
    if (selectedGroupIds.some((groupId) => allMembersGroupIds.has(groupId))) clearIndividualTargets();
    syncCampaignTargetSummary();
  });
  campaignForm.target_ids.addEventListener('change', renderCampaignTargetSummary);
  syncCampaignTargetSummary();
  // P6: ビーコンベースURLの疎通確認ボタン
  const checkBtn = document.getElementById('beaconCheckBtn');
  if (checkBtn) checkBtn.addEventListener('click', checkBeaconUrl);
  // 対象日プレビュー(平日限定の可視化)
  const wpBtn = document.getElementById('weekdayPreviewBtn');
  if (wpBtn) wpBtn.addEventListener('click', showWeekdayPreview);
  // テスト送信チェックに応じてテスト宛先(リダイレクト先)欄の表示を切り替える。
  const cbTest = document.getElementById('cbTest');
  if (cbTest) cbTest.addEventListener('change', toggleTestRedirect);
  toggleTestRedirect();  // 初期表示
}
// is_test チェック時のみテスト宛先(リダイレクト先メール)入力欄を表示する。
function toggleTestRedirect() {
  const cb = document.getElementById('cbTest');
  const wrap = document.getElementById('testRedirectWrap');
  if (wrap) wrap.style.display = (cb && cb.checked) ? '' : 'none';
}
// 開始〜終了の期間の各日をカレンダー表示。平日限定ONなら土日を配信対象外として色分けする。
function showWeekdayPreview() {
  const area = document.getElementById('weekdayPreview');
  const f = document.getElementById('campaignForm');
  if (!area.classList.contains('d-none')) { area.classList.add('d-none'); area.innerHTML = ''; return; }
  const sv = f.start_at.value, ev = f.end_at.value;
  if (!sv || !ev) { area.classList.remove('d-none'); area.innerHTML = '<span class="text-warning small">開始日時・終了日時を先に入力してください。</span>'; return; }
  const start = new Date(sv), end = new Date(ev);
  if (isNaN(start) || isNaN(end) || end < start) { area.classList.remove('d-none'); area.innerHTML = '<span class="text-danger small">期間が不正です。</span>'; return; }
  const weekdaysOnly = f.weekdays_only.checked;
  const days = [];
  const cur = new Date(start.getFullYear(), start.getMonth(), start.getDate());
  const last = new Date(end.getFullYear(), end.getMonth(), end.getDate());
  let guard = 0;
  while (cur <= last && guard < 366) {
    days.push(new Date(cur));
    cur.setDate(cur.getDate() + 1);
    guard++;
  }
  const wd = ['日', '月', '火', '水', '木', '金', '土'];
  let sendable = 0;
  const chips = days.map((d) => {
    const dow = d.getDay();
    const isWeekend = (dow === 0 || dow === 6);
    const excluded = weekdaysOnly && isWeekend;
    if (!excluded) sendable++;
    const cls = excluded ? 'bg-light text-muted text-decoration-line-through' : (isWeekend ? 'bg-info-subtle' : 'bg-primary-subtle');
    return `<span class="badge ${cls} border me-1 mb-1" style="font-weight:normal">${d.getMonth() + 1}/${d.getDate()}(${wd[dow]})</span>`;
  }).join('');
  area.classList.remove('d-none');
  area.innerHTML = `<div class="small text-muted mb-1">配信対象日：${sendable} 日 / 全 ${days.length} 日${weekdaysOnly ? '（平日限定：土日は除外）' : '（全曜日）'}。取消線＝配信されない日。</div>
    <div class="border rounded p-2" style="max-height:120px;overflow:auto">${chips}</div>`;
}
async function checkBeaconUrl() {
  const input = document.getElementById('beaconBaseInput');
  const result = document.getElementById('beaconCheckResult');
  const btn = document.getElementById('beaconCheckBtn');
  const url = (input?.value || '').trim();
  if (!url) { result.innerHTML = '<span class="text-warning">URLを入力してください</span>'; return; }
  btn.disabled = true;
  result.innerHTML = '<span class="text-muted">確認中…</span>';
  try {
    const r = await api('api/url_check.php', { method: 'POST', body: { url } });
    if (r.reachable) {
      result.innerHTML = `<span class="text-success"><i class="bi bi-check-circle"></i> 到達可能（HTTP ${r.http_status}, ${r.response_ms}ms）</span>`;
    } else {
      result.innerHTML = `<span class="text-danger"><i class="bi bi-x-circle"></i> 到達できません（${esc(r.error || '応答なし')}）</span>`;
    }
  } catch (e) {
    result.innerHTML = `<span class="text-danger">${esc(e.message)}</span>`;
  } finally { btn.disabled = false; }
}
function multiVals(sel) { return Array.from(sel.selectedOptions).map((o) => Number(o.value)); }

/* ========== レポート ========== */
let reportChart = null;
let reportTimelineChart = null;
let reportSelectedId = null;
let reportCommitCampaignId = null;
function pct(v) { return `${(Number(v) || 0).toFixed(1)}%`; }
// 数と率の併記セル。率が null(分母0で未定義)のときは数だけを出す。
function countRate(count, rate) { const c = Number(count) || 0; return rate === null ? `${c}` : `${c} <span class="small text-muted">(${pct(rate)})</span>`; }
// 認証率 = 認証数 ÷ クリック数(2026-08-24 に定義を統一)。クリック0のときは null(未定義)。
// 従来の「分母=サイト表示数」だと表ごとに定義が揺れて誤読するため、全テーブルこの定義で表示する。
function authRateOf(auth, clicks) { const c = Number(clicks) || 0; return c > 0 ? (Number(auth) || 0) / c * 100 : null; }
function authTargetRateOf(auth, targets) { const t = Number(targets) || 0; return t > 0 ? (Number(auth) || 0) / t * 100 : null; }
function rateClass(v, warn, danger) { const n = Number(v) || 0; return n >= danger ? 'val-danger' : n >= warn ? 'val-warning' : 'val-success'; }
// 報告率は「高いほど良い」ので rateClass とは色の向きが逆になる。
// 失敗率と同じ関数を使い回すと、よく報告している部署が赤く出て判断を誤る。
function goodRateClass(v, ok, great) { const n = Number(v) || 0; return n >= great ? 'val-success' : n >= ok ? 'val-warning' : 'val-danger'; }
let reportTestFilter = 'prod';  // prod=本番のみ / test=テストのみ / all=全部
function setReportFilter(v) { reportTestFilter = v; renderReports(); }
async function renderReports() {
  // 本番統計にテスト送信が混ざらないよう、既定は本番のみ(prod)。フィルタで切替。
  const { campaigns } = await api('api/report.php', { query: { action: 'campaigns', test_filter: reportTestFilter } });
  Cache.reports = {}; for (const c of campaigns) Cache.reports[c.id] = c;
  // フィルタUI(本番/テスト/全部)を一覧上部に描画。
  const filterBar = document.getElementById('reportFilterBar');
  if (filterBar) {
    const btn = (v, label) => `<button class="btn btn-sm ${reportTestFilter === v ? 'btn-primary' : 'btn-outline-secondary'}" onclick="setReportFilter('${v}')">${label}</button>`;
    filterBar.innerHTML = `<div class="btn-group btn-group-sm">${btn('prod', '本番のみ')}${btn('test', 'テストのみ')}${btn('all', '全部')}</div>`;
  }
  // #列は作成順(古い順)で固定の通し番号。表示は降順(最新が上)。onclick は内部ID(c.id)を保持。
  const reportsAsc = campaigns.slice().sort((a, b) => a.id - b.id);
  const numById = {};
  reportsAsc.forEach((c, i) => { numById[c.id] = i + 1; });
  const shown = reportsAsc.slice().reverse();  // 最新が一番上
  $('#reportsBody').innerHTML = shown.length ? shown.map((c) => {
    const s = c.summary || c;
    const authTargetRate = authTargetRateOf(s.auth_count, s.target_count);
    return `<tr style="cursor:pointer" onclick="showReportDetail(${c.id})">
      <td>${numById[c.id]}</td><td>${esc(c.name)}${Number(c.is_test) ? ' <span class="badge bg-info">TEST</span>' : ''}</td><td>${s.target_count}</td>
      <td>${pct(s.sent_rate)}</td>
      <td class="${rateClass(s.click_rate,25,50)}">${countRate(s.click_count, s.click_rate)}</td>
      <td class="${rateClass(authRateOf(s.auth_count, s.click_count) ?? 0,5,20)}">${countRate(s.auth_count, authRateOf(s.auth_count, s.click_count))}</td>
      <td class="${authTargetRate === null ? '' : rateClass(authTargetRate,5,20)}">${authTargetRate === null ? '-' : pct(authTargetRate)}</td>
      <td><i class="bi bi-chevron-right"></i></td>
    </tr>`;
  }).join('') : emptyRow(8);
  if (reportSelectedId && Cache.reports[reportSelectedId]) showReportDetail(reportSelectedId);
  else { $('#reportDetail').classList.add('d-none'); reportSelectedId = null; }
}
async function showReportDetail(id) {
  reportSelectedId = id;
  const c = Cache.reports[id];
  if (!c) return;
  const s = c.summary || c;
  $('#reportDetail').classList.remove('d-none');
  $('#reportDetailTitle').textContent = `${c.name} — 反応内訳`;
  if (reportChart) reportChart.destroy();
  reportChart = new Chart($('#reportChart'), {
    type: 'bar',
    data: {
      // 「開封」ではなく「サイト表示」。ビーコン(kunren-beacon-*.png)は偽サイトのHTMLに
      // 埋め込まれており、訓練メール本文はプレーンテキスト(send_email.py の MIMEText(...,'plain'))で
      // 画像を含まないため、メールを開いただけでは計測されない。実体はクリック先ページの表示。
      // メール開封を本当に測るには HTML メール化が必要(2026-08-19 時点では見送り)。
      labels: ['送信', 'サイト表示', '認証'],
      datasets: [{ label: '件数', data: [s.sent_count, s.click_count, s.auth_count],
        backgroundColor: ['#4a90d9', '#e0a136', '#d64545'] }],
    },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
  });
  await renderFailures(id);
  await renderReportDetail(id);
}
// P7: v1同等の詳細レポート(会社別/役職別/コンテンツ別/日別タイムライン)
async function renderReportDetail(campaignId) {
  const query = { action: 'detail', campaign_id: campaignId };
  const sd = $('#reportStartDate')?.value, ed = $('#reportEndDate')?.value;
  if (sd) query.start_date = sd;
  if (ed) query.end_date = ed;
  // テスト送信(is_test)の内訳を見るフィルタ。prod 以外のときだけ送る(prod は確定
  // スナップショットを使うため付けない)。all配信のテストパターン開封をコンテンツ別に見る用途。
  if (reportTestFilter && reportTestFilter !== 'prod') query.test_filter = reportTestFilter;
  let d;
  try {
    d = await api('api/report.php', { query });
  } catch (e) { toast(e.message, 'err'); return; }
  // コミット(確定)状態の反映
  reportCommitCampaignId = campaignId;
  const committed = d.is_committed === true;
  const badge = $('#reportCommitBadge');
  if (badge) badge.innerHTML = committed
    ? `<span class="badge bg-secondary"><i class="bi bi-lock-fill me-1"></i>確定済み ${d.committed_at ? esc(d.committed_at) : ''}</span>`
    : '';
  const commitBtn = $('#reportCommitBtn'), uncommitBtn = $('#reportUncommitBtn');
  if (commitBtn) commitBtn.classList.toggle('d-none', committed);
  if (uncommitBtn) uncommitBtn.classList.toggle('d-none', !committed);
  // 会社別(サイト表示=click。beacon と click はほぼ同一事象のため click に統一・2026-08-24)
  $('#reportByCompany').innerHTML = (d.by_company || []).length
    ? d.by_company.map((r) => { const authTargetRate = authTargetRateOf(r.auth_count, r.count); return `<tr><td>${esc(r.company)}</td><td>${r.count}</td>
        <td class="${rateClass(r.link_rate,25,50)}">${countRate(r.link_clicked, r.link_rate)}</td>
        <td class="${rateClass(authRateOf(r.auth_count, r.link_clicked) ?? 0,5,20)}">${countRate(r.auth_count, authRateOf(r.auth_count, r.link_clicked))}</td>
        <td class="${authTargetRate === null ? '' : rateClass(authTargetRate,5,20)}">${authTargetRate === null ? '-' : pct(authTargetRate)}</td>
        <td class="${r.report_rate == null ? '' : goodRateClass(r.report_rate,5,20)}">${r.report_rate == null ? '-' : countRate(r.report_count, r.report_rate)}</td></tr>`; }).join('')
    : emptyRow(6);
  // 役職別
  $('#reportByPosition').innerHTML = (d.by_position || []).length
    ? d.by_position.map((r) => { const authTargetRate = authTargetRateOf(r.auth_count, r.count); return `<tr><td>${esc(r.position)}</td><td>${r.count}</td>
        <td class="${rateClass(r.link_rate,25,50)}">${countRate(r.link_clicked, r.link_rate)}</td>
        <td class="${rateClass(authRateOf(r.auth_count, r.link_clicked) ?? 0,5,20)}">${countRate(r.auth_count, authRateOf(r.auth_count, r.link_clicked))}</td>
        <td class="${authTargetRate === null ? '' : rateClass(authTargetRate,5,20)}">${authTargetRate === null ? '-' : pct(authTargetRate)}</td>
        <td class="${r.report_rate == null ? '' : goodRateClass(r.report_rate,5,20)}">${r.report_rate == null ? '-' : countRate(r.report_count, r.report_rate)}</td></tr>`; }).join('')
    : emptyRow(6);
  // コンテンツ別(No昇順・件名付き)
  $('#reportByContent').innerHTML = (d.by_content || []).length
    ? d.by_content.map((r) => { const authTargetRate = authTargetRateOf(r.auth_count, r.count); return `<tr><td>${esc(r.content_no)}</td>
        <td class="small" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(r.subject || '')}">${esc(r.subject || '')}</td>
        <td>${r.count}</td>
        <td class="${rateClass(r.link_rate,25,50)}">${countRate(r.link_clicked, r.link_rate)}</td>
        <td class="${rateClass(authRateOf(r.auth_count, r.link_clicked) ?? 0,5,20)}">${countRate(r.auth_count, authRateOf(r.auth_count, r.link_clicked))}</td>
        <td class="${authTargetRate === null ? '' : rateClass(authTargetRate,5,20)}">${authTargetRate === null ? '-' : pct(authTargetRate)}</td>
        <td class="${r.report_rate == null ? '' : goodRateClass(r.report_rate,5,20)}">${r.report_rate == null ? '-' : countRate(r.report_count, r.report_rate)}</td></tr>`; }).join('')
    : emptyRow(7);
  // 日別タイムライン(累積 beacon/auth)
  const tl = d.timeline || [];
  if (reportTimelineChart) reportTimelineChart.destroy();
  reportTimelineChart = new Chart($('#reportTimelineChart'), {
    type: 'line',
    data: {
      labels: tl.map((t) => t.date),
      datasets: [
        { label: '累積サイト表示', data: tl.map((t) => t.cum_beacon), borderColor: '#3a9d5d', backgroundColor: 'rgba(58,157,93,.1)', tension: .2, fill: true },
        { label: '累積認証', data: tl.map((t) => t.cum_auth), borderColor: '#d64545', backgroundColor: 'rgba(214,69,69,.1)', tension: .2, fill: true },
      ],
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
  });
  // ビーコン(tracking_id)単位の開封明細。all配信で1人×Nコンテンツを個別に確認する。
  await renderReportBeacons(campaignId);
}

// ビーコン別 開封明細の取得・描画(tracking_id 単位。人物単位に潰さず全パターンを出す)。
async function renderReportBeacons(campaignId) {
  const body = $('#reportBeaconsBody');
  const summaryEl = $('#reportBeaconSummary');
  if (!body) return;
  let d;
  try {
    d = await api('api/report.php', { query: { action: 'beacons', campaign_id: campaignId } });
  } catch (e) {
    body.innerHTML = `<tr><td colspan="8" class="text-muted small">明細の取得に失敗しました: ${esc(e.message)}</td></tr>`;
    return;
  }
  const mark = (on) => on ? '<span class="badge bg-danger">✓</span>' : '<span class="text-muted">–</span>';
  const beacons = d.beacons || [];
  body.innerHTML = beacons.length
    ? beacons.map((b) => `<tr>
        <td>${esc(b.name)}${b.is_test ? ' <span class="badge bg-secondary">TEST</span>' : ''}</td>
        <td>${esc(b.company)}</td>
        <td>${b.content_no === null ? '(単一)' : esc(b.content_no)}</td>
        <td class="small text-muted">${esc(b.tracking_id)}</td>
        <td>${mark(b.opened)}</td>
        <td>${mark(b.clicked)}</td>
        <td>${mark(b.authed)}</td>
        <td class="small">${b.opened_at ? esc(b.opened_at) : ''}</td>
      </tr>`).join('')
    : emptyRow(8);
  if (summaryEl && d.summary) {
    const s = d.summary;
    summaryEl.textContent = `全${s.total}件中 サイト表示${s.clicked} / 認証${s.authed}`;
  }
}
// レポート確定(コミット): 現時点の集計値を固定し、以後変更されないようにする
/** レポートを Excel (.xlsx) でダウンロードする。 */
function exportReportXlsx() {
  if (!reportSelectedId) { toast('先にキャンペーンを選択してください', 'warn'); return; }
  const tid = State.activeTenantId || State.user?.tenant_id;
  const query = new URLSearchParams({ action: 'export_xlsx', campaign_id: reportSelectedId });
  if (tid) query.set('tenant_id', tid);
  const sd = $('#reportStartDate')?.value, ed = $('#reportEndDate')?.value;
  if (sd) query.set('start_date', sd);
  if (ed) query.set('end_date', ed);
  // 認証クッキー付きで直接ダウンロードさせる(API は Content-Disposition: attachment で返す)
  window.location.href = `api/report.php?${query.toString()}`;
}

async function commitReport() {
  const id = reportCommitCampaignId;
  if (!id) return;
  if (!confirm('このレポートを確定しますか？\n確定後は集計値が固定され、以降のログ取込や対象者・ユーザの変更でも数値は変わりません。')) return;
  try {
    await api('api/report.php', { method: 'POST', query: { action: 'commit' }, body: { campaign_id: id } });
    toast('レポートを確定しました', 'ok');
    renderReportDetail(id);
  } catch (e) { toast(e.message, 'err'); }
}
// レポート確定解除: 再びリアルタイム集計に戻す(誤確定の救済, tenant_admin以上)
async function uncommitReport() {
  const id = reportCommitCampaignId;
  if (!id) return;
  if (!confirm('確定を解除しますか？\n再びリアルタイム集計に戻り、数値が現在のデータで再計算されます。')) return;
  try {
    await api('api/report.php', { method: 'POST', query: { action: 'uncommit' }, body: { campaign_id: id } });
    toast('確定を解除しました', 'ok');
    renderReportDetail(id);
  } catch (e) { toast(e.message, 'err'); }
}
async function renderFailures(campaignId) {
  const tbody = $('#failuresBody');
  tbody.innerHTML = `<tr><td colspan="4" class="text-center text-muted py-3">読込中…</td></tr>`;
  try {
    const data = await api('api/followup.php', { query: { action: 'failures', campaign_id: campaignId } });
    const rows = data.failures || [];
    Cache.failuresCampaign = campaignId;
    tbody.innerHTML = rows.length ? rows.map((f) => {
      const sev = Number(f.did_auth) ? '<span class="badge bg-danger">認証入力</span>'
        : Number(f.did_click) ? '<span class="badge bg-warning text-dark">クリック</span>' : '<span class="badge bg-secondary">開封</span>';
      return `<tr><td>${esc(f.email)}</td><td>${esc(f.name)}</td><td>${esc(f.department)}</td><td>${sev}</td></tr>`;
    }).join('') : `<tr><td colspan="4" class="text-center text-muted py-3">防衛失敗者はいません</td></tr>`;
    $('#toGroupBtn').classList.toggle('d-none', !rows.length || !roleAtLeast(State.user.role, 'operator'));
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="4" class="text-center text-danger py-3">${esc(e.message)}</td></tr>`;
  }
}
// 個人別統計(全キャンペーン横断・アーカイブ済み含む)。よく開封する人ランキング。
async function renderIndividuals() {
  const tbody = $('#individualsBody');
  tbody.innerHTML = `<tr><td colspan="8" class="text-center text-muted py-3">読込中…</td></tr>`;
  try {
    const { individuals } = await api('api/report.php', { query: { action: 'individuals' } });
    tbody.innerHTML = (individuals || []).length ? individuals.map((p) => {
      const archived = p.status === 'archived';
      const statusBadge = archived ? '<span class="badge bg-secondary">退職</span>'
        : p.status === 'suspended' ? '<span class="badge bg-light text-dark">停止中</span>' : '';
      return `<tr${archived ? ' class="text-muted"' : ''}>
        <td>${esc(p.name || p.email)}${Number(p.is_test) ? ' <span class="badge bg-info">TEST</span>' : ''}</td>
        <td>${esc(p.company)}</td>
        <td>${p.position_category ? esc(p.position_category) : ''}</td>
        <td>${p.campaigns}</td>
        <td class="${rateClass(p.click_rate,25,50)}">${pct(p.click_rate)}</td>
        <td class="${rateClass(p.auth_rate,5,20)}">${pct(p.auth_rate)}</td>
        <td>${statusBadge}</td>
      </tr>`;
    }).join('') : `<tr><td colspan="7" class="text-center text-muted py-3">対象データがありません</td></tr>`;
  } catch (e) {
    tbody.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-3">${esc(e.message)}</td></tr>`;
  }
}
function toggleIndividuals() {
  const card = $('#individualsCard');
  const show = card.classList.contains('d-none');
  card.classList.toggle('d-none', !show);
  if (show) renderIndividuals();
}
async function ingestLogs() {
  const btn = $('#ingestBtn');
  btn.disabled = true;
  try {
    const r = await api('api/report.php', { method: 'POST', query: { action: 'ingest' } });
    const total = Object.values(r.ingested || {}).reduce((a, b) => a + Number(b), 0);
    toast(`ログ取込完了（${total} 件）`, 'ok');
    renderReports();
  } catch (e) { toast(e.message, 'err'); }
  finally { btn.disabled = false; }
}
async function openToGroupModal() {
  const campaignId = Cache.failuresCampaign;
  if (!campaignId) return;
  const { groups } = await api('api/groups.php', { query: { action: 'list' } });
  const body = `<p class="small text-muted">この失敗者を既存グループに追加し、後続の debrief / eラーニング配信の対象にできます。</p>
    <div class="mb-2"><label class="form-label">追加先グループ</label>
      <select class="form-select" id="toGroupSelect">${(groups || []).map((g) => `<option value="${g.id}">${esc(g.name)}</option>`).join('')}</select></div>`;
  showModal('失敗者をグループ化', body, async () => {
    const groupId = Number($('#toGroupSelect').value);
    if (!groupId) throw new Error('グループを選択してください');
    const r = await api('api/followup.php', { method: 'POST', query: { action: 'to_group' }, body: { campaign_id: campaignId, group_id: groupId } });
    toast(`${r.added} 名を追加しました`, 'ok');
  });
}

/* ========== 対象者 ========== */
// 日時(YYYY-MM-DD HH:MM:SS)を日付だけにする。空なら空文字。
function dateOnly(v) { return v ? String(v).slice(0, 10) : ''; }

async function renderTargets() {
  // トグル ON のときだけ削除済み(アーカイブ)も取得する。
  const showArchived = $('#showArchivedTargets')?.checked === true;
  const query = { action: 'list' };
  if (showArchived) query.include_archived = '1';
  const { targets } = await api('api/targets.php', { query });
  cacheRows('targets', targets);
  // #列は表示上の通し番号(古い順に1,2,3…)。他の一覧と統一。削除しても詰まる。
  // list は tenant_no 順(=作成順)で返るため、その並びのまま連番を振る。
  $('#targetsBody').innerHTML = targets.length ? targets.map((t, i) => {
    const archived = t.status === 'archived';
    const canEdit = roleAtLeast(State.user.role, 'operator');
    // 削除済みは編集/削除ではなく「復活」だけを出す(履歴は保持したまま在籍に戻す)。
    const actions = !canEdit ? ''
      : archived
        ? `<button class="btn btn-sm btn-outline-success" onclick="restoreTarget(${t.id})" title="削除を取り消して在籍に戻す"><i class="bi bi-arrow-counterclockwise"></i></button>`
        : `<button class="btn btn-sm btn-outline-secondary" onclick="editTarget(${t.id})"><i class="bi bi-pencil"></i></button>
           <button class="btn btn-sm btn-outline-danger" onclick="deleteTarget(${t.id})"><i class="bi bi-trash"></i></button>`;
    return `
    <tr${archived ? ' class="text-muted table-light"' : ''}>
      <td>${i + 1}</td>
      <td>${esc(t.email)}${Number(t.is_test) ? ' <span class="badge bg-info">TEST</span>' : ''}${archived ? ' <span class="badge bg-secondary">削除済</span>' : ''}</td>
      <td>${esc(t.name)}</td>
      <td>${esc(t.company)}</td><td>${esc(t.department)}</td><td>${esc(t.title)}</td>
      <td>${t.position_category ? `<span class="badge bg-light text-dark">${esc(t.position_category)}</span>` : ''}</td>
      <td class="text-nowrap small">${esc(dateOnly(t.created_at))}</td>
      <td class="text-nowrap small">${t.archived_at ? esc(dateOnly(t.archived_at)) : ''}</td>
      <td class="text-nowrap">${actions}</td>
    </tr>`;
  }).join('') : emptyRow(10);
}
// 削除済み対象者を在籍に戻す。訓練履歴はもともと消えていないのでそのまま復活する。
async function restoreTarget(id) {
  if (!confirm('この対象者を在籍に戻しますか？')) return;
  try {
    await api('api/targets.php', { method: 'POST', query: { action: 'restore' }, body: { id } });
    toast('在籍に戻しました', 'ok'); renderTargets();
  } catch (e) { toast(e.message, 'err'); }
}
function targetForm(t = {}) {
  return `<form id="targetForm">
    <div class="mb-2"><label class="form-label">メール</label><input class="form-control" name="email" type="email" value="${esc(t.email)}" required></div>
    <div class="mb-2"><label class="form-label">氏名</label><input class="form-control" name="name" value="${esc(t.name)}"></div>
    <div class="row g-2">
      <div class="col-md-4 mb-2"><label class="form-label">会社</label><input class="form-control" name="company" value="${esc(t.company)}"></div>
      <div class="col-md-4 mb-2"><label class="form-label">部署</label><input class="form-control" name="department" value="${esc(t.department)}"></div>
      <div class="col-md-4 mb-2"><label class="form-label">役職</label><input class="form-control" name="title" value="${esc(t.title)}"></div>
    </div>
    <div class="mb-2"><label class="form-label">役職カテゴリ</label>
      <select class="form-select" name="position_category">
        <option value="">—</option>
        ${POSITION_CATEGORIES.map((c)=>`<option value="${c}"${t.position_category===c?' selected':''}>${c}</option>`).join('')}
      </select></div>
    <div class="form-check mb-2">
      <input class="form-check-input" type="checkbox" name="is_test" id="cbTargetTest"${Number(t.is_test) === 1 ? ' checked' : ''}>
      <label class="form-check-label" for="cbTargetTest">テストユーザ（レポート集計から除外）</label>
      <div class="form-text">検証用の宛先。訓練配信には使えますが、開封率などの集計には数えません。</div>
    </div></form>`;
}
function collectTarget() {
  const f = $('#targetForm');
  return { email: f.email.value.trim(), name: f.name.value.trim(), company: f.company.value.trim(),
    department: f.department.value.trim(), title: f.title.value.trim(),
    position_category: f.position_category.value, is_test: f.is_test.checked };
}
function newTarget() {
  showModal('新規対象者', targetForm(), async () => {
    await api('api/targets.php', { method: 'POST', query: { action: 'create' }, body: collectTarget() });
    toast('追加しました', 'ok'); renderTargets();
  });
}
function editTarget(id) {
  const t = Cache.targets[id];
  if (!t) return;
  showModal('対象者編集', targetForm(t), async () => {
    await api('api/targets.php', { method: 'POST', query: { action: 'update' }, body: { id: t.id, ...collectTarget() } });
    toast('更新しました', 'ok'); renderTargets();
  });
}
async function deleteTarget(id) {
  if (!confirm('この対象者を削除しますか？')) return;
  try { await api('api/targets.php', { method: 'POST', query: { action: 'delete' }, body: { id } });
    toast('削除しました', 'ok'); renderTargets(); } catch (e) { toast(e.message, 'err'); }
}
function importCsv() {
  const body = `<p class="small text-muted">1行目にヘッダ（メールアドレス/氏名/会社名/部署/役職/役職カテゴリ または email/name/company/department/title/position_category）。メール列は必須。役職カテゴリは「役員/管理職/一般従業員」のみ有効（旧称「社員」は「一般従業員」として取り込みます）。列が無い場合は役職名から役職マスタを引いて自動補完します。</p>
    <textarea class="form-control" id="csvText" rows="8" placeholder="メールアドレス,氏名,部署&#10;taro@example.com,山田太郎,営業部"></textarea>`;
  showModal('CSV 取込', body, async () => {
    const csv = $('#csvText').value.trim();
    if (!csv) throw new Error('CSV を入力してください');
    const r = await api('api/targets.php', { method: 'POST', query: { action: 'import_csv' }, body: { csv } });
    toast(`取込 ${r.imported} / 更新 ${r.updated} / スキップ ${r.skipped}`, 'ok'); renderTargets();
  });
}
// 現対象者を CSV でダウンロード。CSV(非JSON)なので api() は使わず直接 fetch → blob 保存。
// 既定は一覧と同じくアーカイブ(退職者)を除外。includeArchived=true で削除日付きの全件を出す。
async function exportTargetsCsv(includeArchived = false) {
  const btn = $('#exportCsvBtn');
  btn.disabled = true;
  try {
    const qs = new URLSearchParams({ action: 'export_csv' });
    if (includeArchived) qs.set('include_archived', '1');
    // superadmin がテナント切替中なら tenant_id を付与(api() と同じ挙動)
    if (State.user && State.user.role === 'superadmin' && State.activeTenantId) qs.set('tenant_id', State.activeTenantId);
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), 30000);
    let res;
    try {
      res = await fetch(`api/targets.php?${qs}`, { credentials: 'same-origin', signal: ctrl.signal });
    } finally { clearTimeout(timer); }
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `targets_${includeArchived ? 'all_' : ''}${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(a); a.click(); a.remove();
    URL.revokeObjectURL(url);
    toast(includeArchived ? 'CSV を出力しました（削除済みを含む）' : 'CSV を出力しました', 'ok');
  } catch (e) { toast(`出力に失敗しました（${e.message}）`, 'err'); }
  finally { btn.disabled = false; }
}

/* ========== グループ ========== */
async function renderGroups() {
  const { groups } = await api('api/groups.php', { query: { action: 'list' } });
  cacheRows('groups', groups);
  // #列は表示上の通し番号(古い順に1,2,3…)。削除しても詰まる。
  const groupsAsc = groups.slice().sort((a, b) => a.id - b.id);
  $('#groupsBody').innerHTML = groupsAsc.length ? groupsAsc.map((g, i) => {
    const n = Number(g.target_count || 0);
    const memberCell = n === 0
      ? '<span class="badge bg-warning text-dark">空（0名）</span>'
      : `<span class="badge bg-light text-dark">${n} 名</span>`;
    return `
    <tr><td>${i + 1}</td><td>${esc(g.name)}</td><td>${g.kind === 'department' ? '部署' : 'カスタム'}</td>
      <td>${memberCell}</td>
      <td class="text-nowrap">
        <button class="btn btn-sm btn-outline-primary" onclick="manageGroupMembers(${g.id})" title="メンバー管理（対象者の追加・削除）"><i class="bi bi-people"></i> メンバー</button>
        ${roleAtLeast(State.user.role,'operator')?`
        <button class="btn btn-sm btn-outline-secondary" onclick="editGroup(${g.id})"><i class="bi bi-pencil"></i></button>
        <button class="btn btn-sm btn-outline-danger" onclick="deleteGroup(${g.id})"><i class="bi bi-trash"></i></button>`:''}
      </td></tr>`;
  }).join('') : emptyRow(5);
}
function groupForm(g = {}) {
  return `<form id="groupForm">
    <div class="mb-2"><label class="form-label">名称</label><input class="form-control" name="name" value="${esc(g.name)}" required></div>
    <div class="mb-2"><label class="form-label">種別</label>
      <select class="form-select" name="kind"><option value="custom"${g.kind==='custom'?' selected':''}>カスタム</option><option value="department"${g.kind==='department'?' selected':''}>部署</option><option value="all"${g.kind==='all'?' selected':''}>全職員（自動）</option></select></div>
  </form>`;
}
function newGroup() {
  showModal('新規グループ', groupForm(), async () => {
    const f = $('#groupForm');
    await api('api/groups.php', { method: 'POST', query: { action: 'create' }, body: { name: f.name.value.trim(), kind: f.kind.value } });
    toast('作成しました', 'ok'); renderGroups();
  });
}
function editGroup(id) {
  const g = Cache.groups[id];
  if (!g) return;
  showModal('グループ編集', groupForm(g), async () => {
    const f = $('#groupForm');
    await api('api/groups.php', { method: 'POST', query: { action: 'update' }, body: { id: g.id, name: f.name.value.trim(), kind: f.kind.value } });
    toast('更新しました', 'ok'); renderGroups();
  });
}
async function deleteGroup(id) {
  if (!confirm('このグループを削除しますか？')) return;
  try { await api('api/groups.php', { method: 'POST', query: { action: 'delete' }, body: { id } });
    toast('削除しました', 'ok'); renderGroups(); } catch (e) { toast(e.message, 'err'); }
}
// グループのメンバー管理: 現メンバーと全対象者を一覧し、チェックで追加/削除する。
async function manageGroupMembers(groupId) {
  const g = Cache.groups[groupId];
  let members, targets;
  try {
    [members, targets] = await Promise.all([
      api('api/groups.php', { query: { action: 'members', group_id: groupId } }),
      api('api/targets.php', { query: { action: 'list' } }),
    ]);
  } catch (e) { toast(e.message, 'err'); return; }
  const memberIds = new Set((members.members || []).map((m) => m.id));
  const allTargets = targets.targets || [];
  if (!allTargets.length) {
    showInfoModal('メンバー管理', '<p class="text-muted">対象者が1人も登録されていません。先に「対象者」画面で登録してください。</p>');
    return;
  }
  // チェックボックス一覧(現メンバーは初期チェック済み)。
  const rows = allTargets.map((t) => `
    <div class="form-check">
      <input class="form-check-input gm-chk" type="checkbox" value="${t.id}" id="gm-${t.id}" ${memberIds.has(t.id) ? 'checked' : ''}>
      <label class="form-check-label" for="gm-${t.id}">${esc(t.email)}${t.name ? '（' + esc(t.name) + '）' : ''}${t.department ? ' <span class="text-muted small">' + esc(t.department) + '</span>' : ''}</label>
    </div>`).join('');
  const body = `
    <p class="small text-muted mb-2">グループ「${esc(g ? g.name : '')}」のメンバーを選びます。チェックした対象者がメンバーになります（現メンバーは初期選択済み）。</p>
    <div class="mb-2"><button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('.gm-chk').forEach(c=>c.checked=true)">全選択</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('.gm-chk').forEach(c=>c.checked=false)">全解除</button></div>
    <div style="max-height:340px;overflow-y:auto;border:1px solid #dee2e6;border-radius:6px;padding:8px">${rows}</div>`;
  showModal(`メンバー管理: ${g ? g.name : ''}`, body, async () => {
    const checked = new Set(Array.from(document.querySelectorAll('.gm-chk')).filter((c) => c.checked).map((c) => Number(c.value)));
    // 追加 = チェックあり かつ 元メンバーでない / 削除 = チェックなし かつ 元メンバー
    const toAdd = [...checked].filter((id) => !memberIds.has(id));
    const toRemove = [...memberIds].filter((id) => !checked.has(id));
    if (toAdd.length) await api('api/groups.php', { method: 'POST', query: { action: 'add_targets' }, body: { group_id: groupId, target_ids: toAdd } });
    if (toRemove.length) await api('api/groups.php', { method: 'POST', query: { action: 'remove_targets' }, body: { group_id: groupId, target_ids: toRemove } });
    toast(`メンバーを更新しました（追加${toAdd.length}・削除${toRemove.length}）`, 'ok');
    renderGroups();
  });
}

/* ========== テンプレート ========== */
// 件名・本文は「シナリオ」タブに統合(連動・連番共通)。偽ログイン/ネタバラシ/eラーニングは種別ごと。
// タブの表示区分。'scenario' は「件名＋本文」を1タブに統合するための表示専用の擬似種別で、
// DB 上の kind ではない(実体は subject と body の2レコード)。
const TPL_KINDS = [['scenario','件名＋本文'],['phish_login','偽ログイン'],['debrief','ネタバラシ'],['elearning','eラーニング']];
// テンプレート登録フォームの種別。バックエンドの TEMPLATE_KINDS と一致させること。
// (2026-08-04) ここに TPL_KINDS を流用していたため 'scenario' が送られ「kind が不正です」で保存できなかった。
const TPL_FORM_KINDS = [['subject','件名'],['body','本文'],['phish_login','偽ログイン'],['debrief','ネタバラシ'],['elearning','eラーニング']];
const KIND_LABELS = { subject:'件名', body:'本文', phish_login:'偽ログイン', debrief:'ネタバラシ', elearning:'eラーニング' };
const AUTH_FLAG_NAME = { '0':'通常（汎用）', '1':'Box', '2':'Microsoft365', '3':'Digital Arts', '4':'Microsoft 365（メールのみ）' };
let tplKindFilter = 'scenario';
async function renderTemplates() {
  $('#tplKindTabs').innerHTML = TPL_KINDS.map(([k, l]) =>
    `<li class="nav-item"><a class="nav-link${k===tplKindFilter?' active':''}" href="#" onclick="setTplKind('${k}');return false">${l}</a></li>`).join('');
  const { templates } = await api('api/templates.php', { query: { action: 'list' } });
  const all = templates || [];
  if (tplKindFilter === 'scenario') return renderTemplatesScenario(all);
  if (tplKindFilter === 'phish_login') return renderTemplatesPhish(all);
  return renderTemplatesSimple(all, tplKindFilter);
}
// 件名+本文をシナリオ連番で統合表示。行クリックで件名・本文を1画面プレビュー/編集。
function renderTemplatesScenario(all) {
  const subjects = all.filter((t) => t.kind === 'subject');
  const bodies = all.filter((t) => t.kind === 'body');
  // scenario_key を持つペアを集約。
  const keys = [...new Set(all.filter((t) => t.scenario_key && (t.kind === 'subject' || t.kind === 'body')).map((t) => t.scenario_key))];
  const pairedIds = new Set();
  let rowNumber = 0;
  const scenRows = keys.map((key) => {
    const s = subjects.find((t) => t.scenario_key === key);
    const b = bodies.find((t) => t.scenario_key === key);
    if (!s || !b) return '';
    pairedIds.add(s.id);
    pairedIds.add(b.id);
    return `<tr style="cursor:pointer" data-scenario-key="${esc(key)}" onclick="scenarioViewer(this.dataset.scenarioKey)">
      <td>${++rowNumber}</td><td>${esc(s.name)}</td><td class="text-muted small">${esc(b.name)}</td>
      <td>${Number(s.is_preset) ? '<span class="badge bg-secondary">共有</span>' : ''}</td>
      <td class="text-end"><i class="bi bi-chevron-right"></i></td></tr>`;
  }).join('');
  // ペアが欠けた場合も、元の件名・本文を一覧から失わない。
  const orphans = all.filter((t) => (t.kind === 'subject' || t.kind === 'body') && !pairedIds.has(t.id));
  const orphanRows = orphans.map((t) => `<tr style="cursor:pointer" onclick="tplViewer(${t.id})">
    <td>${++rowNumber}</td><td>${esc(t.name)}</td><td class="text-muted small">${KIND_LABELS[t.kind]}（単独）</td>
    <td>${Number(t.is_preset) ? '<span class="badge bg-secondary">共有</span>' : ''}</td>
    <td class="text-end"><i class="bi bi-chevron-right"></i></td></tr>`).join('');
  $('#templatesHead').innerHTML = '<tr><th>連番</th><th>件名</th><th>本文</th><th></th><th></th></tr>';
  $('#templatesBody').innerHTML = (scenRows + orphanRows) || emptyRow(5);
}
// 偽ログイン: 種別(auth_flag)ごとにグループ化し、種別内の通番を振る。
function renderTemplatesPhish(all) {
  const phish = all.filter((t) => t.kind === 'phish_login');
  const counters = {};
  const rows = phish.map((t) => {
    const flag = String(t.auth_flag ?? 0);
    counters[flag] = (counters[flag] || 0) + 1;
    return `<tr style="cursor:pointer" onclick="tplViewer(${t.id})">
      <td>${esc(AUTH_FLAG_NAME[flag] || flag)} #${counters[flag]}</td>
      <td>${esc(t.name)}</td><td>${esc(t.format)}</td>
      <td>${Number(t.is_preset) ? '<span class="badge bg-secondary">共有</span>' : ''}</td>
      <td class="text-end"><i class="bi bi-chevron-right"></i></td></tr>`;
  }).join('');
  $('#templatesHead').innerHTML = '<tr><th>種別・通番</th><th>名称</th><th>形式</th><th></th><th></th></tr>';
  $('#templatesBody').innerHTML = rows || emptyRow(5);
}
// ネタバラシ/eラーニング等: 一覧+ビューア。
function renderTemplatesSimple(all, kind) {
  const rows = all.filter((t) => t.kind === kind).map((t, i) => `<tr style="cursor:pointer" onclick="tplViewer(${t.id})">
    <td>${i + 1}</td><td>${esc(t.name)}</td><td>${esc(t.format)}</td>
    <td>${Number(t.is_preset) ? '<span class="badge bg-secondary">共有</span>' : ''}</td>
    <td class="text-end"><i class="bi bi-chevron-right"></i></td></tr>`).join('');
  $('#templatesHead').innerHTML = '<tr><th>連番</th><th>名称</th><th>形式</th><th></th><th></th></tr>';
  $('#templatesBody').innerHTML = rows || emptyRow(5);
}
function labelKind(k) { return KIND_LABELS[k] || k; }
function setTplKind(k) { tplKindFilter = k; renderTemplates(); }
// 本文の差し込みプレースホルダ定義(send_email.py の replace_placeholders と対応)。
const TPL_PLACEHOLDERS = [
  { ph: '#$1$#', label: 'リンク/URL', sample: 'https://example.com/link-XXXX.html' },
  { ph: '#$2$#', label: '姓', sample: '山田' },
  { ph: '#$3$#', label: '差込3', sample: '（差込3）' },
  { ph: '#$4$#', label: '差込4', sample: '（差込4）' },
  { ph: '#$6$#', label: 'メールアドレス', sample: 'taro@example.com' },
];
function templateForm(t = {}) {
  const phButtons = TPL_PLACEHOLDERS.map((p) =>
    `<button type="button" class="btn btn-sm btn-outline-secondary me-1 mb-1 tpl-ph-btn" data-ph="${esc(p.ph)}">${esc(p.label)} <code>${esc(p.ph)}</code></button>`).join('');
  return `<form id="tplForm">
    <div class="mb-2"><label class="form-label">名称</label><input class="form-control" name="name" value="${esc(t.name)}" required></div>
    <div class="row g-2">
      <div class="col-md-6 mb-2"><label class="form-label">種別</label>
        <select class="form-select" name="kind">${TPL_FORM_KINDS.map(([k,l])=>`<option value="${k}"${(t.kind||(tplKindFilter==='scenario'?'body':tplKindFilter))===k?' selected':''}>${l}</option>`).join('')}</select></div>
      <div class="col-md-6 mb-2"><label class="form-label">形式</label>
        <select class="form-select" name="format"><option value="html"${t.format==='html'?' selected':''}>HTML</option><option value="text"${t.format==='text'?' selected':''}>テキスト</option></select></div>
    </div>
    <div class="mb-1"><label class="form-label mb-1">差し込み支援（カーソル位置に挿入）</label><div>${phButtons}</div></div>
    <div class="mb-2"><label class="form-label">内容</label><textarea class="form-control" name="content" id="tplContent" rows="8" required>${esc(t.content)}</textarea></div>
    <div class="mb-2">
      <button type="button" class="btn btn-sm btn-outline-info" id="tplPreviewBtn"><i class="bi bi-eye"></i> プレビュー（差込をサンプル値で表示）</button>
    </div>
    <div id="tplPreviewArea" class="d-none"></div>
  </form>`;
}
// テンプレートフォームの差込ボタン/プレビューを配線(showModal 後に呼ぶ)。
function bindTemplateForm() {
  const ta = document.getElementById('tplContent');
  // (2026-08-05) 件名テンプレートは「名称＝そのまま件名」なので、名称を打つと内容欄へ複写する。
  // 内容を直接編集した後は追随しない(手で書いたものを名称の変更で消さないため)。
  // 種別が「件名」以外のときは何もしない(本文などは名称と中身が別物のため)。
  const nameInput = document.querySelector('#tplForm [name=name]');
  const kindSel = document.querySelector('#tplForm [name=kind]');
  if (nameInput && kindSel && ta) {
    // 空、または直前に名称から複写した値のままなら「未編集」とみなす。
    let mirrored = ta.value === '' ? '' : null;
    ta.addEventListener('input', () => { mirrored = null; });   // 手編集された時点で同期を止める
    const syncNameToContent = () => {
      if (kindSel.value !== 'subject') return;
      if (mirrored === null && ta.value !== '') return;
      ta.value = nameInput.value;
      mirrored = ta.value;
    };
    nameInput.addEventListener('input', syncNameToContent);
    kindSel.addEventListener('change', syncNameToContent);
  }
  document.querySelectorAll('.tpl-ph-btn').forEach((btn) => btn.addEventListener('click', () => {
    const ph = btn.dataset.ph;
    const s = ta.selectionStart ?? ta.value.length, e = ta.selectionEnd ?? ta.value.length;
    ta.value = ta.value.slice(0, s) + ph + ta.value.slice(e);
    ta.focus(); ta.selectionStart = ta.selectionEnd = s + ph.length;
  }));
  const pvBtn = document.getElementById('tplPreviewBtn');
  if (pvBtn) pvBtn.addEventListener('click', () => {
    const area = document.getElementById('tplPreviewArea');
    if (!area.classList.contains('d-none')) { area.classList.add('d-none'); area.innerHTML = ''; return; }
    let content = ta.value;
    for (const p of TPL_PLACEHOLDERS) content = content.split(p.ph).join(p.sample);
    const format = document.querySelector('#tplForm [name=format]').value;
    if (format === 'html') {
      area.innerHTML = `<div class="small text-muted mb-1">差込をサンプル値で置換した表示（スクリプトは無効化）。</div>
        <iframe sandbox srcdoc="${esc(content)}" style="width:100%;height:40vh;border:1px solid #dee2e6;border-radius:.375rem;background:#fff"></iframe>`;
    } else {
      area.innerHTML = `<div class="small text-muted mb-1">差込をサンプル値で置換した表示。</div>
        <pre class="border rounded p-2 bg-light" style="max-height:40vh;overflow:auto;white-space:pre-wrap">${esc(content)}</pre>`;
    }
    area.classList.remove('d-none');
  });
}
// 件名と本文は 1 つの訓練シナリオを構成する対なので、1 画面でまとめて登録する。
// scenario_key はサーバ側で自動採番されるため利用者には見せない。
function scenarioForm() {
  const phButtons = TPL_PLACEHOLDERS.map((p) =>
    `<button type="button" class="btn btn-sm btn-outline-secondary me-1 mb-1 tpl-ph-btn" data-ph="${esc(p.ph)}">${esc(p.label)} <code>${esc(p.ph)}</code></button>`).join('');
  return `<form id="scenForm">
    <div class="mb-2"><label class="form-label">シナリオ名</label>
      <input class="form-control" name="name" placeholder="例: 人事部からのマイナンバー確認" required>
      <div class="form-text">件名・本文の管理名として使われます。</div></div>
    <div class="mb-2"><label class="form-label">件名</label>
      <textarea class="form-control" name="subject_content" id="scenSubject" rows="2" required></textarea></div>
    <div class="mb-2"><label class="form-label">本文の形式</label>
      <select class="form-select" name="format"><option value="html">HTML</option><option value="text">テキスト</option></select></div>
    <div class="mb-1"><label class="form-label mb-1">差し込み支援（カーソル位置に挿入）</label><div>${phButtons}</div></div>
    <div class="mb-2"><label class="form-label">本文</label>
      <textarea class="form-control" name="body_content" id="tplContent" rows="10" required></textarea></div>
  </form>`;
}
function newScenario() {
  showModal('新規シナリオ（件名＋本文）', scenarioForm(), async () => {
    const f = $('#scenForm');
    await api('api/templates.php', { method: 'POST', query: { action: 'create_scenario' },
      body: {
        name: f.name.value.trim(),
        subject_content: f.subject_content.value.trim(),
        body_content: f.body_content.value,
        format: f.format.value,
      } });
    toast('シナリオを作成しました', 'ok');
    tplKindFilter = 'scenario';
    renderTemplates();
  });
  // 差し込みボタンは本文(#tplContent)に挿す。既存フォームと同じ id を使うため流用できる。
  bindTemplateForm();
}
function newTemplate() {
  showModal('新規テンプレート', templateForm(), async () => {
    const f = $('#tplForm');
    await api('api/templates.php', { method: 'POST', query: { action: 'create' },
      body: { name: f.name.value.trim(), kind: f.kind.value, format: f.format.value, content: f.content.value } });
    toast('作成しました', 'ok'); renderTemplates();
  });
  bindTemplateForm();
}
async function editTemplate(id) {
  const { template } = await api('api/templates.php', { query: { action: 'get', id } });
  showModal('テンプレート編集', templateForm(template), async () => {
    const f = $('#tplForm');
    await api('api/templates.php', { method: 'POST', query: { action: 'update' },
      body: { id, name: f.name.value.trim(), kind: f.kind.value, format: f.format.value, content: f.content.value } });
    toast('更新しました', 'ok'); renderTemplates();
  });
  bindTemplateForm();
}
async function deleteTemplate(id) {
  if (!confirm('このテンプレートを削除しますか？')) return;
  try { await api('api/templates.php', { method: 'POST', query: { action: 'delete' }, body: { id } });
    toast('削除しました', 'ok'); renderTemplates(); } catch (e) { toast(e.message, 'err'); }
}
// 指定 kind のテンプレートを CSV でダウンロード(CSV は非JSONなので直 fetch)。
async function downloadTplCsv(kind) {
  const qs = new URLSearchParams({ action: 'export_csv', kind });
  if (State.user && State.user.role === 'superadmin' && State.activeTenantId) qs.set('tenant_id', State.activeTenantId);
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), 30000);
  let res;
  try { res = await fetch(`api/templates.php?${qs}`, { credentials: 'same-origin', signal: ctrl.signal }); }
  finally { clearTimeout(timer); }
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = `templates_${kind}_${new Date().toISOString().slice(0, 10)}.csv`;
  document.body.appendChild(a); a.click(); a.remove();
  URL.revokeObjectURL(url);
}
// 現在のタブのテンプレートを CSV 出力。件名+本文タブは subject/body を両方DL。
async function exportTemplatesCsv() {
  try {
    if (tplKindFilter === 'scenario') { await downloadTplCsv('subject'); await downloadTplCsv('body'); }
    else await downloadTplCsv(tplKindFilter);
    toast('CSV を出力しました', 'ok');
  } catch (e) { toast(`出力に失敗しました（${e.message}）`, 'err'); }
}
// CSV でテンプレートを一括登録(mode=add:追加/upsert:上書き更新)。
function importTemplatesCsv(mode) {
  const label = mode === 'upsert' ? 'CSV上書き更新' : 'CSV追加';
  const body = `<p class="small text-muted">列: <code>name, kind, format, content, auth_flag, scenario_key</code>（name/kind/content 必須）。
    kind は subject/body/phish_login/debrief/elearning。${mode === 'upsert' ? '同名（name+kind）は<strong>上書き更新</strong>、無ければ追加。' : '同名は<strong>スキップ</strong>、無ければ追加。'}
    登録先は<strong>全テナント共通</strong>のテンプレートです。</p>
    <input type="file" class="form-control mb-2" id="tplCsvFile" accept=".csv,text/csv">
    <textarea class="form-control" id="tplCsvText" rows="8" placeholder="ファイルを選ぶか、CSVを貼り付け"></textarea>`;
  showModal(label, body, async () => {
    const csv = $('#tplCsvText').value.trim();
    if (!csv) throw new Error('CSV を選択または貼り付けてください');
    if (!confirm('全テナント共通のテンプレートに登録します。よろしいですか？')) return;
    const r = await api('api/templates.php', { method: 'POST', query: { action: 'import_csv' }, body: { csv, mode } });
    const errs = (r.errors || []).length ? `／エラー ${r.errors.length}` : '';
    toast(`追加 ${r.added}／更新 ${r.updated}／スキップ ${r.skipped}${errs}`, 'ok');
    renderTemplates();
  });
  // ファイル選択→textarea に読み込み
  const fileEl = document.getElementById('tplCsvFile');
  if (fileEl) fileEl.addEventListener('change', () => {
    const f = fileEl.files && fileEl.files[0]; if (!f) return;
    const reader = new FileReader();
    reader.onload = () => { document.getElementById('tplCsvText').value = String(reader.result || ''); };
    reader.readAsText(f, 'utf-8');
  });
}

// 差込プレースホルダをサンプル値に置換する。
function tplApplyPlaceholders(content) {
  let s = content || '';
  for (const p of TPL_PLACEHOLDERS) s = s.split(p.ph).join(p.sample);
  return s;
}
// プレビュー(差込サンプル置換)のHTMLを返す。html=iframe sandbox / text=pre。
function tplPreviewHtml(content, format, height = '40vh') {
  const c = tplApplyPlaceholders(content);
  if (format === 'html') {
    return `<iframe sandbox srcdoc="${esc(c)}" style="width:100%;height:${height};border:1px solid #dee2e6;border-radius:.375rem;background:#fff"></iframe>`;
  }
  return `<pre class="border rounded p-2 bg-light" style="max-height:${height};overflow:auto;white-space:pre-wrap">${esc(c)}</pre>`;
}

// 単一テンプレート(偽ログイン/ネタバラシ/eラーニング等)のプレビュー/HTML/編集ビューア。
// テンプレート編集可否: 共有プリセットは tenant_admin 以上、自テナント分は operator 以上。
function canEditTemplate(isPreset) {
  return Number(isPreset) ? roleAtLeast(State.user.role, 'tenant_admin') : roleAtLeast(State.user.role, 'operator');
}
async function tplViewer(id) {
  let t;
  try { t = (await api('api/templates.php', { query: { action: 'get', id } })).template; }
  catch (e) { toast(e.message, 'err'); return; }
  const editable = canEditTemplate(t.is_preset);
  const shared = Number(t.is_preset);
  const sharedBanner = shared
    ? (editable
        ? '<div class="alert alert-warning py-2 small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>これは<strong>全テナント共通</strong>のテンプレートです。変更はすべてのテナントに反映されます。</div>'
        : '<div class="alert alert-secondary py-2 small mb-2">共有テンプレート（編集にはテナント管理者以上の権限が必要です）。</div>')
    : '';
  const body = `${sharedBanner}
    <div class="small text-muted mb-2">${esc(labelKind(t.kind))}：${esc(t.name)}（形式：${esc(t.format)}）</div>
    <ul class="nav nav-pills mb-2" id="tvTabs">
      <li class="nav-item"><a class="nav-link active" href="#" data-tv="preview">プレビュー</a></li>
      <li class="nav-item"><a class="nav-link" href="#" data-tv="html">HTMLソース</a></li>
      ${editable ? '<li class="nav-item"><a class="nav-link" href="#" data-tv="edit">修正</a></li>' : ''}
    </ul>
    <div id="tvPreview">${tplPreviewHtml(t.content, t.format, '45vh')}</div>
    <div id="tvHtml" class="d-none"><pre class="border rounded p-2 bg-light" style="max-height:45vh;overflow:auto"><code>${esc(t.content || '')}</code></pre></div>
    ${editable ? `<div id="tvEdit" class="d-none"><textarea class="form-control" id="tvContent" rows="14">${esc(t.content || '')}</textarea></div>` : ''}
    ${editable ? `<div class="mt-2 text-end"><button type="button" class="btn btn-sm btn-outline-danger" id="tvDeleteBtn"><i class="bi bi-trash"></i> このテンプレートを削除</button></div>` : ''}`;
  if (editable) {
    showModal(`テンプレート: ${esc(t.name)}`, body, async () => {
      const content = $('#tvContent').value;
      if (!content.trim()) throw new Error('内容を入力してください');
      if (shared && !confirm('全テナント共通のテンプレートです。すべてのテナントに反映されます。保存しますか？')) return;
      await api('api/templates.php', { method: 'POST', query: { action: 'update' },
        body: { id, name: t.name, kind: t.kind, format: t.format, content } });
      toast('保存しました', 'ok'); renderTemplates();
    });
  } else {
    showInfoModal(`テンプレート: ${esc(t.name)}`, body);
  }
  const tabs = document.getElementById('tvTabs');
  if (tabs) tabs.addEventListener('click', (e) => {
    const a = e.target.closest('.nav-link'); if (!a) return;
    e.preventDefault();
    tabs.querySelectorAll('.nav-link').forEach((x) => x.classList.toggle('active', x === a));
    ['preview', 'html', 'edit'].forEach((k) => { const el = document.getElementById('tv' + k[0].toUpperCase() + k.slice(1)); if (el) el.classList.toggle('d-none', a.dataset.tv !== k); });
  });
  const delBtn = document.getElementById('tvDeleteBtn');
  if (delBtn) delBtn.addEventListener('click', () => deleteTemplateAndClose([id], shared));
}

// ビューアからテンプレートを削除し、モーダルを閉じて再描画する。ids は複数(シナリオ=件名+本文)対応。
async function deleteTemplateAndClose(ids, shared) {
  const msg = shared
    ? `全テナント共通のテンプレートを削除します（${ids.length}件）。すべてのテナントに影響します。よろしいですか？`
    : `このテンプレートを削除します（${ids.length}件）。よろしいですか？`;
  if (!confirm(msg)) return;
  try {
    for (const id of ids) {
      await api('api/templates.php', { method: 'POST', query: { action: 'delete' }, body: { id } });
    }
    toast('削除しました', 'ok');
    if (modalInstance) modalInstance.hide();
    renderTemplates();
  } catch (e) { toast(e.message, 'err'); } // 使用中(409)等はここで表示
}

// シナリオ(件名+本文)を1画面で見るビューア。件名・本文を並べてプレビュー/HTML/編集。
async function scenarioViewer(scenarioKey) {
  let list;
  try { list = (await api('api/templates.php', { query: { action: 'list' } })).templates || []; }
  catch (e) { toast(e.message, 'err'); return; }
  const subj = list.find((t) => t.scenario_key === scenarioKey && t.kind === 'subject');
  const bodyT = list.find((t) => t.scenario_key === scenarioKey && t.kind === 'body');
  if (!subj || !bodyT) { toast('シナリオの件名/本文が見つかりません', 'err'); return; }
  const preset = Number(subj.is_preset) || Number(bodyT.is_preset);
  const editable = canEditTemplate(preset);
  const section = (t, editId) => `
    <div class="mb-3">
      <div class="fw-bold small mb-1">${esc(labelKind(t.kind))}：${esc(t.name)}</div>
      <ul class="nav nav-pills nav-sm mb-1" data-sv="${t.kind}">
        <li class="nav-item"><a class="nav-link active py-0 px-2" href="#" data-svt="preview">プレビュー</a></li>
        <li class="nav-item"><a class="nav-link py-0 px-2" href="#" data-svt="html">HTML</a></li>
        ${editable ? `<li class="nav-item"><a class="nav-link py-0 px-2" href="#" data-svt="edit">修正</a></li>` : ''}
      </ul>
      <div data-svpanel="${t.kind}-preview">${tplPreviewHtml(t.content, t.format, '22vh')}</div>
      <div class="d-none" data-svpanel="${t.kind}-html"><pre class="border rounded p-2 bg-light" style="max-height:22vh;overflow:auto"><code>${esc(t.content || '')}</code></pre></div>
      ${editable ? `<div class="d-none" data-svpanel="${t.kind}-edit"><textarea class="form-control" id="${editId}" rows="6">${esc(t.content || '')}</textarea></div>` : ''}
    </div>`;
  const sharedBanner = preset
    ? (editable
        ? '<div class="alert alert-warning py-2 small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>この件名・本文は<strong>全テナント共通</strong>です。変更はすべてのテナントに反映されます。</div>'
        : '<div class="alert alert-secondary py-2 small mb-2">共有テンプレート（編集にはテナント管理者以上の権限が必要です）。</div>')
    : '';
  const body = `${sharedBanner}<div class="small text-muted mb-2">シナリオ「${esc(subj.name)}」の件名と本文</div>
    ${section(subj, 'svSubject')}${section(bodyT, 'svBody')}
    ${editable ? `<div class="mt-2 text-end"><button type="button" class="btn btn-sm btn-outline-danger" id="svDeleteBtn"><i class="bi bi-trash"></i> このシナリオ（件名＋本文）を削除</button></div>` : ''}`;
  if (editable) {
    showModal('シナリオ内容', body, async () => {
      if (preset && !confirm('全テナント共通の件名・本文です。すべてのテナントに反映されます。保存しますか？')) return;
      await api('api/templates.php', { method: 'POST', query: { action: 'update' }, body: { id: subj.id, name: subj.name, kind: 'subject', format: subj.format, content: $('#svSubject').value } });
      await api('api/templates.php', { method: 'POST', query: { action: 'update' }, body: { id: bodyT.id, name: bodyT.name, kind: 'body', format: bodyT.format, content: $('#svBody').value } });
      toast('保存しました', 'ok'); renderTemplates();
    });
  } else {
    showInfoModal('シナリオ内容', body);
  }
  // 各セクションのタブ切替
  document.querySelectorAll('[data-sv]').forEach((navEl) => {
    navEl.addEventListener('click', (e) => {
      const a = e.target.closest('.nav-link'); if (!a) return;
      e.preventDefault();
      const kind = navEl.dataset.sv;
      navEl.querySelectorAll('.nav-link').forEach((x) => x.classList.toggle('active', x === a));
      ['preview', 'html', 'edit'].forEach((k) => {
        const p = document.querySelector(`[data-svpanel="${kind}-${k}"]`); if (p) p.classList.toggle('d-none', a.dataset.svt !== k);
      });
    });
  });
  const svDel = document.getElementById('svDeleteBtn');
  if (svDel) svDel.addEventListener('click', () => deleteTemplateAndClose([subj.id, bodyT.id], preset));
}

/* ========== セキュリティ教育: 配信 ========== */
const EDU_DTYPE = { elearning: 'eラーニング', awareness_quiz: 'アウェアネス' };
async function renderEduDeliveries() {
  const { deliveries } = await api('api/edu_report.php', { query: { action: 'deliveries' } });
  cacheRows('eduDeliveries', deliveries || []);
  // #列は表示上の通し番号(古い順に1,2,3…)。削除しても詰まる。
  const deliveriesAsc = (deliveries || []).slice().sort((a, b) => a.id - b.id);
  $('#eduDeliveriesBody').innerHTML = deliveriesAsc.length ? deliveriesAsc.map((d, i) => `
    <tr><td>${i + 1}</td><td>${esc(d.title)}</td><td>${EDU_DTYPE[d.delivery_type] || esc(d.delivery_type)}</td>
      <td><span class="badge bg-${d.status==='running'?'success':d.status==='done'?'secondary':'light text-dark'}">${esc(d.status)}</span></td>
      <td>${d.assigned}</td><td>${d.completed}</td><td>${d.completion_rate}%</td><td>${d.average_score}%</td>
      <td class="text-nowrap">
        <button class="btn btn-sm btn-outline-primary" onclick="viewEduDelivery(${d.id})" title="配信レポート"><i class="bi bi-graph-up"></i> レポート</button>
        ${roleAtLeast(State.user.role,'operator') && d.status==='draft'?`
        <button class="btn btn-sm btn-outline-success" onclick="launchEduDelivery(${d.id})" title="配信開始"><i class="bi bi-send"></i></button>`:''}
        ${roleAtLeast(State.user.role,'operator') && d.status==='running'?`
        <button class="btn btn-sm btn-outline-warning" onclick="remindEduDelivery(${d.id})" title="未完了者へ催促メール"><i class="bi bi-envelope-exclamation"></i></button>`:''}
        ${roleAtLeast(State.user.role,'operator') ? (Number(d.completed) > 0 || Number(d.started_count) > 0
          ? '<span class="small text-muted ms-1">受講履歴を保持（削除不可）</span>'
          : `<button class="btn btn-sm btn-outline-danger" onclick="deleteEduDelivery(${d.id})" title="受講開始前の配信を削除"><i class="bi bi-trash"></i> 削除</button>`) : ''}
      </td></tr>`).join('') : emptyRow(9);
}
function eduDeliveryForm() {
  const catOpts = (Cache.eduCats || []).map((c) =>
    `<option value="${c.id}"${c.slug === 'phishing' ? ' selected' : ''}>${esc(c.name)}</option>`).join('');
  const materialOpts = (Cache.eduMaterialList || []).map((m) =>
    `<option value="${m.id}">${esc(m.title)}（${m.slide_count}枚）</option>`).join('');
  const targetOpts = (Cache.eduTargets || []).map((t) =>
    `<option value="${t.id}">${Number(t.is_test) === 1 ? '[テスト] ' : ''}${esc(t.email)}（${esc(t.name || '')}）</option>`).join('');
  return `<form id="eduDeliveryForm">
    <div class="alert alert-primary py-2"><button type="button" class="btn btn-sm btn-primary me-2" id="eduRiskPreset">訓練失敗者向けを設定</button><span class="small">標的型メール訓練の直後に、スライド教材と確認テストを配信します。</span></div>
    <div class="mb-2"><label class="form-label">タイトル</label><input class="form-control" name="title" value="標的型メール訓練 フォローアップ" required></div>
    <div class="mb-2"><label class="form-label">種別</label>
      <select class="form-select" name="delivery_type" id="eduDType">
        <option value="elearning">eラーニング（合格点まで再受講）</option>
        <option value="awareness_quiz">アウェアネス（回答提出で完了・合否なし）</option>
      </select><div class="form-text" id="eduTypeHelp"></div></div>
    <div class="mb-2" id="eduMaterialField"><label class="form-label">スライド教材</label>
      <select class="form-select" name="material_id"><option value="">教材なし</option>${materialOpts}</select></div>
    <div class="mb-2"><label class="form-label">配信対象</label>
      <select class="form-select" name="target_type" id="eduTargetType">
        <option value="risk">訓練失敗者のみ（実対象者）</option>
        <option value="all">全対象者（テスト宛先を除く）</option>
        <option value="individual">個別選択（テスト宛先も選択可）</option>
      </select></div>
    <div class="mb-2 d-none" id="eduAutoEnrollField">
      <div class="form-check"><input class="form-check-input" type="checkbox" name="auto_enroll" id="eduAutoEnroll">
        <label class="form-check-label" for="eduAutoEnroll">訓練失敗者を自動で追加し続ける</label></div>
      <div class="form-text">開始後も毎時、新たに訓練で失敗した人を自動で受講対象に加え、受講案内を送ります。配信を作成した時点より後の失敗が対象です。</div></div>
    <div class="mb-2 d-none" id="eduIndividualTargets"><label class="form-label">個別対象者</label>
      <input class="form-control form-control-sm mb-2" id="eduTargetSearch" placeholder="氏名またはメールアドレスで絞り込み">
      <select class="form-select" name="target_ids" multiple size="7">${targetOpts}</select>
      <div class="form-text"><span id="eduTargetCount">0名選択</span>・Ctrl / commandキーで複数選択できます。</div></div>
    <div class="mb-2"><label class="form-label">確認テストのカテゴリ（複数選択可）</label>
      <select class="form-select" name="category_ids" multiple size="4">${catOpts}</select></div>
    <div class="row">
      <div class="col-6 mb-2"><label class="form-label">出題数</label><input class="form-control" type="number" name="question_count" min="1" value="3"></div>
      <div class="col-6 mb-2" id="eduPassScoreField"><label class="form-label">合格点</label><input class="form-control" type="number" name="pass_score" min="1" max="100" value="80"></div>
    </div>
  </form>`;
}

function syncEduDeliveryForm() {
  const f = $('#eduDeliveryForm');
  const elearning = f.delivery_type.value === 'elearning';
  $('#eduMaterialField').classList.toggle('d-none', !elearning);
  $('#eduPassScoreField').classList.toggle('d-none', !elearning);
  $('#eduIndividualTargets').classList.toggle('d-none', f.target_type.value !== 'individual');
  // 自動連携は「訓練失敗者」を対象にしたときだけ意味を持つ(他の対象種別では二重投入になる)
  const risk = f.target_type.value === 'risk';
  $('#eduAutoEnrollField').classList.toggle('d-none', !risk);
  if (!risk) f.auto_enroll.checked = false;
  $('#eduTypeHelp').textContent = elearning
    ? '教材を読んで確認テストに合格すると完了します。不合格の場合は再受講できます。'
    : '理解度を測る継続教育です。回答提出で完了し、合格・不合格は付けません。';
}

function filterEduIndividualTargets() {
  const form = $('#eduDeliveryForm');
  const keyword = $('#eduTargetSearch').value.trim().toLowerCase();
  Array.from(form.target_ids.options).forEach((option) => {
    option.hidden = keyword !== '' && !option.textContent.toLowerCase().includes(keyword);
  });
  $('#eduTargetCount').textContent = `${form.target_ids.selectedOptions.length}名選択`;
}

function eduDeliveryPayload(form) {
  const categories = Array.from(form.category_ids.selectedOptions).map((o) => Number(o.value));
  const targets = Array.from(form.target_ids.selectedOptions).map((o) => Number(o.value));
  const type = form.delivery_type.value;
  if (form.target_type.value === 'individual' && !targets.length) throw new Error('個別対象者を選択してください');
  const body = { title: form.title.value.trim(), delivery_type: type, target_type: form.target_type.value,
    question_count: Number(form.question_count.value) || 3, category_ids: categories.length ? categories : undefined };
  if (type === 'elearning') {
    body.pass_score = Number(form.pass_score.value) || 80;
    body.material_id = Number(form.material_id.value) || undefined;
  }
  if (form.target_type.value === 'individual') body.target_ids = targets;
  if (form.target_type.value === 'risk' && form.auto_enroll.checked) body.triggered_by = 'phishing_failure';
  return body;
}

async function newEduDelivery() {
  const [cats, materials, targets] = await Promise.all([
    api('api/edu_categories.php', { query: { action: 'list' } }),
    api('api/edu_materials.php', { query: { action: 'list' } }),
    api('api/targets.php', { query: { action: 'list' } }),
  ]);
  Cache.eduCats = cats.categories || []; Cache.eduMaterialList = materials.materials || []; Cache.eduTargets = targets.targets || [];
  showModal('新規教育配信', eduDeliveryForm(), async () => {
    await api('api/edu_deliveries.php', { method: 'POST', query: { action: 'create' }, body: eduDeliveryPayload($('#eduDeliveryForm')) });
    toast('配信を作成しました', 'ok'); renderEduDeliveries();
  }, { size: 'lg' });
  $('#eduDType').addEventListener('change', syncEduDeliveryForm);
  $('#eduTargetType').addEventListener('change', syncEduDeliveryForm);
  $('#eduTargetSearch').addEventListener('input', filterEduIndividualTargets);
  $('#eduDeliveryForm').target_ids.addEventListener('change', filterEduIndividualTargets);
  $('#eduRiskPreset').addEventListener('click', () => {
    const f = $('#eduDeliveryForm'); f.delivery_type.value = 'elearning'; f.target_type.value = 'risk';
    const preset = Array.from(f.material_id.options).find((option) => option.textContent.includes('フォローアップ基礎'));
    if (preset) f.material_id.value = preset.value;
    // 訓練直後のジャストインタイム教育が本来の用途なので、自動追加を既定で入れる
    f.auto_enroll.checked = true;
    syncEduDeliveryForm();
  });
  $('#eduRiskPreset').click();
}
async function deleteEduDelivery(id) {
  if (!roleAtLeast(State.user.role, 'operator')) return;
  const delivery = Cache.eduDeliveries?.[id];
  if (!delivery) return toast('配信が見つかりません。画面を更新してください', 'err');
  if (Number(delivery.completed) > 0 || Number(delivery.started_count) > 0) return toast('受講履歴を保持するため、受講開始・完了の履歴がある配信は削除できません', 'err');
  if (!confirm(`教育配信「${delivery.title}」を削除しますか？\n未受講の割当と受講リンクも削除されます。受講開始・完了の履歴がある配信は削除できません。`)) return;
  try {
    await api('api/edu_deliveries.php', { method: 'POST', query: { action: 'delete' }, body: { id } });
    toast('教育配信を削除しました', 'ok');
    await renderEduDeliveries();
  } catch (e) { toast(e.message, 'err'); }
}
async function launchEduDelivery(id) {
  if (!confirm('この配信を開始し、対象者に受講を割り当てますか？')) return;
  try {
    const r = await api('api/edu_deliveries.php', { method: 'POST', query: { action: 'launch' }, body: { id } });
    toast(`${r.assigned}名に割り当てました`, 'ok'); renderEduDeliveries();
  } catch (e) { toast(e.message, 'err'); }
}
async function remindEduDelivery(id) {
  if (!confirm('未完了の受講者へ受講催促メールを送信しますか？')) return;
  try {
    const r = await api('api/edu_deliveries.php', { method: 'POST', query: { action: 'remind' }, body: { id } });
    if (r.targets === 0) { toast(r.message || '未完了の受講者はいません', 'info'); return; }
    const msg = r.failed > 0 ? `${r.sent}名に送信（${r.failed}名は失敗）` : `${r.sent}名に催促メールを送信しました`;
    toast(msg, r.failed > 0 ? 'err' : 'ok');
  } catch (e) { toast(e.message, 'err'); }
}
async function viewEduDelivery(id) {
  const r = await api('api/edu_report.php', { query: { action: 'delivery', id } });
  const s = r.summary;
  const rows = (r.by_question || []).map((q) => `
    <tr><td>${esc(q.title)}</td><td>難${q.difficulty}</td><td>${q.correct}/${q.answered}</td><td>${q.correct_rate}%</td></tr>`).join('');
  const html = `
    <div class="mb-3">
      <div>受講率: <b>${s.completion_rate}%</b> (${s.completed}/${s.assigned})</div>
      <div>平均正答率: <b>${s.average_score}%</b>${s.pass_rate!==null?` / 合格率: <b>${s.pass_rate}%</b>`:''}</div>
    </div>
    <table class="table table-sm"><thead><tr><th>設問</th><th>難易度</th><th>正答</th><th>正答率</th></tr></thead>
    <tbody>${rows || emptyRow(4)}</tbody></table>`;
  showModal(`配信レポート: ${esc(r.delivery.title)}`, html, null);
}

/* ========== セキュリティ教育: 教材バンク ========== */
async function renderEduMaterials() {
  const { materials } = await api('api/edu_materials.php', { query: { action: 'list' } });
  Cache.eduMaterialList = materials || [];
  const canEdit = (material) => roleAtLeast(State.user.role, 'operator')
    && (Number(material.is_shared) !== 1 || State.user.role === 'superadmin');
  $('#eduMaterialsBody').innerHTML = Cache.eduMaterialList.length ? Cache.eduMaterialList.map((material, index) => `
    <tr><td>${index + 1}</td><td>${esc(material.title)}${Number(material.is_shared) === 1 ? ' <span class="badge bg-info">共有</span>' : ''}</td>
      <td class="small text-muted">${esc(material.description || '')}</td><td>${material.slide_count}枚</td>
      <td class="text-nowrap"><button class="btn btn-sm btn-outline-primary" onclick="previewEduMaterial(${material.id})"><i class="bi bi-play-circle"></i> 教材を試行</button>
        ${canEdit(material) ? `<button class="btn btn-sm btn-outline-secondary" onclick="editEduMaterial(${material.id})"><i class="bi bi-pencil"></i> 差し替え</button>` : '<span class="small text-muted ms-1">閲覧のみ</span>'}</td></tr>`).join('') : emptyRow(5);
}

let eduMaterialPreviewIndex = 0;
function eduMaterialPreviewHtml() {
  return `<div class="alert alert-info py-2 small"><i class="bi bi-eye me-1"></i>プレビューです。受講履歴や採点結果は保存されません。</div>
    <div class="mx-auto" style="max-width:760px">
      <div class="d-flex justify-content-between mb-2"><span id="eduMaterialPreviewProgress" class="small text-muted" aria-live="polite"></span><span class="badge bg-primary">教材試行</span></div>
      <div class="progress mb-3" style="height:6px"><div id="eduMaterialPreviewBar" class="progress-bar" style="width:0%"></div></div>
      <div class="card shadow-sm"><div class="card-body p-4" style="min-height:280px">
        <h4 id="eduMaterialPreviewTitle"></h4><div id="eduMaterialPreviewBody" class="mt-3" style="white-space:pre-wrap;line-height:1.9"></div>
      </div></div>
      <div class="d-flex justify-content-between mt-3"><button id="eduMaterialPreviewPrev" class="btn btn-outline-secondary"><i class="bi bi-chevron-left"></i> 前へ</button>
        <button id="eduMaterialPreviewNext" class="btn btn-primary">次へ <i class="bi bi-chevron-right"></i></button></div>
    </div>`;
}
function renderEduMaterialPreview(material) {
  const slides = material.slides || [];
  const slide = slides[eduMaterialPreviewIndex];
  $('#eduMaterialPreviewProgress').textContent = `${eduMaterialPreviewIndex + 1} / ${slides.length}`;
  $('#eduMaterialPreviewBar').style.width = `${Math.round((eduMaterialPreviewIndex + 1) * 100 / slides.length)}%`;
  $('#eduMaterialPreviewTitle').textContent = slide.title;
  $('#eduMaterialPreviewBody').textContent = slide.body;
  $('#eduMaterialPreviewPrev').disabled = eduMaterialPreviewIndex === 0;
  $('#eduMaterialPreviewNext').innerHTML = eduMaterialPreviewIndex === slides.length - 1
    ? '<i class="bi bi-arrow-counterclockwise"></i> 最初に戻る' : '次へ <i class="bi bi-chevron-right"></i>';
}
function previewEduMaterial(id) {
  const material = (Cache.eduMaterialList || []).find((row) => Number(row.id) === Number(id));
  if (!material?.slides?.length) return toast('試行できるスライドがありません', 'err');
  eduMaterialPreviewIndex = 0;
  showInfoModal(`教材試行: ${material.title}`, eduMaterialPreviewHtml(), { size: 'xl' });
  $('#eduMaterialPreviewPrev').addEventListener('click', () => {
    if (eduMaterialPreviewIndex > 0) eduMaterialPreviewIndex--;
    renderEduMaterialPreview(material);
  });
  $('#eduMaterialPreviewNext').addEventListener('click', () => {
    eduMaterialPreviewIndex = eduMaterialPreviewIndex === material.slides.length - 1 ? 0 : eduMaterialPreviewIndex + 1;
    renderEduMaterialPreview(material);
  });
  renderEduMaterialPreview(material);
}

function eduMaterialSlideRow(slide = {}) {
  return `<div class="border rounded p-2 mb-2 edu-material-slide">
    <div class="d-flex justify-content-between mb-2"><strong class="small">スライド</strong><button type="button" class="btn btn-sm btn-outline-danger eduMaterialRemove"><i class="bi bi-trash"></i></button></div>
    <input class="form-control mb-2" name="slide_title" placeholder="スライドタイトル" value="${esc(slide.title || '')}" required>
    <textarea class="form-control" name="slide_body" rows="4" placeholder="本文（改行可）" required>${esc(slide.body || '')}</textarea>
  </div>`;
}

function eduMaterialForm(material = null) {
  const slides = material?.slides?.length ? material.slides : [{ title: '', body: '' }];
  return `<form id="eduMaterialForm">
    <div class="mb-2"><label class="form-label">教材名</label><input class="form-control" name="title" value="${esc(material?.title || '')}" required></div>
    <div class="mb-3"><label class="form-label">説明</label><textarea class="form-control" name="description" rows="2">${esc(material?.description || '')}</textarea></div>
    <div class="d-flex justify-content-between align-items-center mb-2"><label class="form-label mb-0">スライド</label><button type="button" class="btn btn-sm btn-outline-primary" id="eduMaterialAdd"><i class="bi bi-plus-lg"></i> 追加</button></div>
    <div id="eduMaterialSlides">${slides.map(eduMaterialSlideRow).join('')}</div>
  </form>`;
}

function bindEduMaterialEditor() {
  $('#eduMaterialAdd').addEventListener('click', () => $('#eduMaterialSlides').insertAdjacentHTML('beforeend', eduMaterialSlideRow()));
  $('#eduMaterialSlides').addEventListener('click', (event) => {
    const remove = event.target.closest('.eduMaterialRemove');
    if (!remove) return;
    if ($('#eduMaterialSlides').querySelectorAll('.edu-material-slide').length <= 1) return toast('スライドは1枚以上必要です', 'err');
    remove.closest('.edu-material-slide').remove();
  });
}

function readEduMaterialForm() {
  const form = $('#eduMaterialForm');
  const slides = Array.from(form.querySelectorAll('.edu-material-slide')).map((row) => ({
    title: row.querySelector('[name=slide_title]').value.trim(),
    body: row.querySelector('[name=slide_body]').value.trim(),
  }));
  return { title: form.title.value.trim(), description: form.description.value.trim(), slides };
}

function openEduMaterial(material = null) {
  showModal(material ? 'スライド教材の差し替え' : '新規スライド教材', eduMaterialForm(material), async () => {
    const body = readEduMaterialForm();
    if (material) body.id = material.id;
    await api('api/edu_materials.php', { method: 'POST', query: { action: material ? 'update' : 'create' }, body });
    toast(material ? '教材を差し替えました' : '教材を作成しました', 'ok'); renderEduMaterials();
  }, { size: 'lg' });
  bindEduMaterialEditor();
}

function newEduMaterial() { openEduMaterial(); }
function editEduMaterial(id) {
  const material = (Cache.eduMaterialList || []).find((row) => Number(row.id) === Number(id));
  if (material) openEduMaterial(material);
}

let eduCatFilter = null;
async function renderEduQuestions() {
  await renderEduMaterials();
  const { categories } = await api('api/edu_categories.php', { query: { action: 'list' } });
  Cache.eduCats = categories || [];
  if (eduCatFilter === null && categories && categories.length) eduCatFilter = categories[0].id;
  $('#eduCatTabs').innerHTML = (categories || []).map((c) =>
    `<li class="nav-item"><a class="nav-link${c.id===eduCatFilter?' active':''}" href="#" onclick="setEduCat(${c.id});return false">${esc(c.name)} <span class="badge bg-secondary">${c.question_count}</span></a></li>`).join('');
  renderEduCatToolbar();
  if (eduCatFilter === null) { $('#eduQuestionsBody').innerHTML = emptyRow(5); return; }
  const { questions } = await api('api/edu_questions.php', { query: { action: 'list', category_id: eduCatFilter } });
  cacheRows('eduQuestions', questions || []);
  // #列は表示上の通し番号(古い順に1,2,3…)。削除しても詰まる。
  const questionsAsc = (questions || []).slice().sort((a, b) => a.id - b.id);
  const typeName = { single_choice: '単一選択', true_false: '正誤', multiple_choice: '複数選択' };
  const canEdit = (q) => roleAtLeast(State.user.role, 'operator')
    && (Number(q.is_shared) !== 1 || State.user.role === 'superadmin');
  $('#eduQuestionsBody').innerHTML = questionsAsc.length ? questionsAsc.map((q, i) => `
    <tr><td>${i + 1}</td><td>${esc(q.title)}${Number(q.is_shared)===1?' <span class="badge bg-info">共有</span>':''}${Number(q.is_active)!==1?' <span class="badge bg-secondary">無効</span>':''}</td><td>${typeName[q.question_type]||esc(q.question_type)}</td><td>難${q.difficulty}</td>
      <td class="text-nowrap"><button class="btn btn-sm btn-outline-primary" onclick="previewEduQuestion(${q.id})"><i class="bi bi-play-circle"></i> 試行</button>
        ${canEdit(q) ? `
        <button class="btn btn-sm btn-outline-secondary" onclick="editEduQuestion(${q.id})" title="設問を編集"><i class="bi bi-pencil"></i> 編集</button>
        <button class="btn btn-sm btn-outline-danger" onclick="deleteEduQuestion(${q.id})" title="設問を削除"><i class="bi bi-trash"></i> 削除</button>`
        : '<span class="text-muted small ms-1">閲覧のみ</span>'}</td></tr>`).join('') : emptyRow(5);
}
function setEduCat(id) { eduCatFilter = id; renderEduQuestions(); }

function eduPreviewArray(value) {
  if (Array.isArray(value)) return value;
  try { const parsed = JSON.parse(value || '[]'); return Array.isArray(parsed) ? parsed : []; }
  catch (_) { return []; }
}
function eduQuestionPreviewHtml() {
  return `<div class="alert alert-info py-2 small"><i class="bi bi-eye me-1"></i>プレビューです。受講履歴や採点結果は保存されません。</div>
    <div class="mx-auto" style="max-width:760px"><div class="card shadow-sm"><div class="card-body p-4">
      <div id="eduQuestionPreviewTitle" class="fw-bold mb-3"></div><div id="eduQuestionPreviewOptions" class="d-grid gap-2"></div>
      <button id="eduQuestionPreviewCheck" class="btn btn-primary w-100 mt-3">回答を確認</button>
      <div id="eduQuestionPreviewFeedback" class="alert mt-3 mb-0 d-none" style="white-space:pre-wrap" aria-live="polite"></div>
    </div></div></div>`;
}
function renderEduQuestionPreviewOptions(question, selected) {
  const box = $('#eduQuestionPreviewOptions');
  box.innerHTML = '';
  eduPreviewArray(question.options).forEach((option, index) => {
    const button = document.createElement('button');
    button.type = 'button'; button.className = 'btn btn-outline-secondary text-start'; button.textContent = option;
    button.addEventListener('click', () => {
      if (question.question_type !== 'multiple_choice') selected.clear();
      if (selected.has(index)) selected.delete(index); else selected.add(index);
      box.querySelectorAll('button').forEach((item, itemIndex) => item.classList.toggle('active', selected.has(itemIndex)));
      $('#eduQuestionPreviewFeedback').classList.add('d-none');
    });
    box.appendChild(button);
  });
}
function previewEduQuestion(id) {
  const question = Cache.eduQuestions?.[id];
  if (!question) return toast('設問が見つかりません', 'err');
  const selected = new Set();
  showInfoModal('確認テストを試行', eduQuestionPreviewHtml(), { size: 'lg' });
  $('#eduQuestionPreviewTitle').textContent = question.title;
  renderEduQuestionPreviewOptions(question, selected);
  $('#eduQuestionPreviewCheck').addEventListener('click', () => {
    if (!selected.size) return toast('回答を選択してください', 'err');
    const actual = [...selected].sort((a, b) => a - b);
    const correct = eduPreviewArray(question.correct_answer).map(Number).sort((a, b) => a - b);
    const passed = actual.length === correct.length && actual.every((value, index) => value === correct[index]);
    const correctLabels = correct.map((index) => eduPreviewArray(question.options)[index]).filter(Boolean).join(' / ');
    const feedback = $('#eduQuestionPreviewFeedback');
    feedback.className = `alert mt-3 mb-0 ${passed ? 'alert-success' : 'alert-danger'}`;
    feedback.textContent = `${passed ? '正解です' : '不正解です'}\n正解: ${correctLabels}${question.explanation ? `\n解説: ${question.explanation}` : ''}`;
  });
}

/* ---- カテゴリ管理 ---- */
/* 選択中カテゴリの操作ツールバー。共有カテゴリはコピー(fork)、テナント固有は編集/削除。 */
function renderEduCatToolbar() {
  const el = $('#eduCatToolbar'); if (!el) return;
  const c = (Cache.eduCats || []).find((x) => x.id === eduCatFilter);
  if (!c) { el.innerHTML = ''; return; }
  const isShared = Number(c.is_shared) === 1;
  const canManage = roleAtLeast(State.user.role, 'operator');
  const canEditShared = State.user.role === 'superadmin';
  let btns = '';
  if (isShared) {
    if (canManage) btns += `<button class="btn btn-sm btn-outline-success" onclick="forkEduCategory(${c.id})"><i class="bi bi-files me-1"></i>自組織にコピー</button>`;
    if (canEditShared) btns += ` <button class="btn btn-sm btn-outline-secondary" onclick="editEduCategory(${c.id})"><i class="bi bi-pencil me-1"></i>編集</button>`;
  } else if (canManage) {
    btns += `<button class="btn btn-sm btn-outline-secondary" onclick="editEduCategory(${c.id})"><i class="bi bi-pencil me-1"></i>編集</button>`;
    btns += ` <button class="btn btn-sm btn-outline-danger" onclick="deleteEduCategory(${c.id})"><i class="bi bi-trash me-1"></i>削除</button>`;
  }
  const tag = isShared ? '<span class="badge bg-info">共有教材</span>' : '<span class="badge bg-primary">自組織</span>';
  el.innerHTML = btns ? `<div class="d-flex align-items-center gap-2 small text-muted">${tag}<span>${esc(c.name)}（設問 ${c.question_count}）</span>${btns}</div>` : '';
}
function eduCategoryForm(c = {}) {
  return `<form id="eduCatForm">
    <div class="mb-2"><label class="form-label">カテゴリ名</label><input class="form-control" name="name" value="${esc(c.name)}" required></div>
    <div class="mb-2"><label class="form-label">スラッグ（英小文字・数字・ハイフン）</label><input class="form-control" name="slug" value="${esc(c.slug)}" ${c.id?'readonly':'required'} pattern="[a-z0-9-]+" placeholder="例: cloud-security"></div>
    <div class="mb-2"><label class="form-label">色（任意・#rrggbb）</label><input class="form-control" name="color" value="${esc(c.color)}" placeholder="#4f46e5"></div>
    ${c.id?`<div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="eduCatActive"${Number(c.is_active)===1?' checked':''}><label class="form-check-label" for="eduCatActive">有効</label></div>`:''}
  </form>`;
}
function newEduCategory() {
  showModal('新規カテゴリ', eduCategoryForm({}), async () => {
    const f = $('#eduCatForm');
    const name = f.name.value.trim(), slug = f.slug.value.trim();
    if (!name || !slug) { toast('カテゴリ名とスラッグは必須です', 'err'); return false; }
    if (!/^[a-z0-9-]+$/.test(slug)) { toast('スラッグは英小文字・数字・ハイフンのみです', 'err'); return false; }
    const body = { name, slug };
    const color = f.color.value.trim(); if (color) body.color = color;
    await api('api/edu_categories.php', { method: 'POST', query: { action: 'create' }, body });
    toast('作成しました', 'ok'); eduCatFilter = null; renderEduQuestions();
  });
}
function editEduCategory(id) {
  const c = (Cache.eduCats || []).find((x) => x.id === id); if (!c) return;
  showModal('カテゴリ編集', eduCategoryForm(c), async () => {
    const f = $('#eduCatForm');
    const body = { id, name: f.name.value.trim(), is_active: f.is_active.checked ? 1 : 0 };
    const color = f.color.value.trim(); if (color) body.color = color;
    await api('api/edu_categories.php', { method: 'POST', query: { action: 'update' }, body });
    toast('更新しました', 'ok'); renderEduQuestions();
  });
}
async function deleteEduCategory(id) {
  const c = (Cache.eduCats || []).find((x) => x.id === id); if (!c) return;
  if (!confirm(`カテゴリ「${c.name}」を削除しますか？（設問が残っていると削除できません）`)) return;
  try {
    await api('api/edu_categories.php', { method: 'POST', query: { action: 'delete' }, body: { id } });
    toast('削除しました', 'ok'); eduCatFilter = null; renderEduQuestions();
  } catch (e) { /* api() が 409 等を toast 表示 */ }
}
async function forkEduCategory(id) {
  const c = (Cache.eduCats || []).find((x) => x.id === id); if (!c) return;
  if (!confirm(`共有教材「${c.name}」を自組織にコピーしますか？（設問ごと複製され、編集可能になります）`)) return;
  try {
    const r = await api('api/edu_categories.php', { method: 'POST', query: { action: 'fork' }, body: { id } });
    toast('コピーしました', 'ok');
    eduCatFilter = r.category ? r.category.id : null;
    renderEduQuestions();
  } catch (e) { /* api() が表示 */ }
}

/* 設問フォーム: 選択肢は改行区切りテキスト、正答は0始まりindex(複数はカンマ区切り) */
function eduQuestionForm(q = {}) {
  const opts = Array.isArray(q.options) ? q.options : (q.options ? JSON.parse(q.options) : []);
  const correct = Array.isArray(q.correct_answer) ? q.correct_answer : (q.correct_answer ? JSON.parse(q.correct_answer) : []);
  const type = q.question_type || 'single_choice';
  const cats = (Cache.eduCats || []).filter((c) => Number(c.is_shared) !== 1 || State.user.role === 'superadmin');
  const catOpts = cats.map((c) => `<option value="${c.id}"${Number(q.category_id)===c.id?' selected':''}>${esc(c.name)}</option>`).join('');
  const typeOpts = [['single_choice','単一選択'],['true_false','正誤'],['multiple_choice','複数選択']]
    .map(([v,l]) => `<option value="${v}"${type===v?' selected':''}>${l}</option>`).join('');
  return `<form id="eduQForm">
    <div class="mb-2"><label class="form-label">カテゴリ</label><select class="form-select" name="category_id" required>${catOpts}</select></div>
    <div class="mb-2"><label class="form-label">設問文</label><input class="form-control" name="title" value="${esc(q.title)}" required></div>
    <div class="mb-2"><label class="form-label">種別</label><select class="form-select" name="question_type">${typeOpts}</select></div>
    <div class="mb-2"><label class="form-label">選択肢（1行に1つ）</label><textarea class="form-control" name="options" rows="4">${esc(opts.join('\n'))}</textarea></div>
    <div class="mb-2"><label class="form-label">正答の番号（1始まり・複数選択はカンマ区切り）</label><input class="form-control" name="correct" value="${esc(correct.map((i)=>Number(i)+1).join(','))}" placeholder="例: 1"></div>
    <div class="mb-2"><label class="form-label">難易度（1〜3）</label><input class="form-control" name="difficulty" type="number" min="1" max="3" value="${q.difficulty||1}"></div>
    <div class="mb-2"><label class="form-label">解説</label><textarea class="form-control" name="explanation" rows="2">${esc(q.explanation)}</textarea></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" id="eduQActive"${q.id===undefined||Number(q.is_active)===1?' checked':''}><label class="form-check-label" for="eduQActive">有効（出題対象にする）</label></div>
  </form>`;
}
/* フォーム値 → API body。選択肢テキストと正答番号を配列化して検証する。 */
function eduQFormBody(f) {
  const options = f.options.value.split('\n').map((s) => s.trim()).filter((s) => s !== '');
  if (options.length < 2) { toast('選択肢は2つ以上入力してください', 'err'); return null; }
  const correct = f.correct.value.split(',').map((s) => parseInt(s.trim(), 10) - 1).filter((n) => !Number.isNaN(n));
  if (correct.length === 0) { toast('正答の番号を入力してください', 'err'); return null; }
  if (correct.some((i) => i < 0 || i >= options.length)) { toast('正答の番号が選択肢の範囲外です', 'err'); return null; }
  return {
    category_id: parseInt(f.category_id.value, 10),
    title: f.title.value.trim(),
    question_type: f.question_type.value,
    options,
    correct_answer: correct,
    difficulty: parseInt(f.difficulty.value, 10) || 1,
    explanation: f.explanation.value.trim(),
    is_active: f.is_active.checked ? 1 : 0,
  };
}
function newEduQuestion() {
  if (!Cache.eduCats || !Cache.eduCats.length) { toast('先にカテゴリを用意してください', 'err'); return; }
  const preset = eduCatFilter ? { category_id: eduCatFilter } : {};
  showModal('新規設問', eduQuestionForm(preset), async () => {
    const body = eduQFormBody($('#eduQForm')); if (!body) return false;
    await api('api/edu_questions.php', { method: 'POST', query: { action: 'create' }, body });
    toast('作成しました', 'ok'); renderEduQuestions();
  });
}
function editEduQuestion(id) {
  const q = Cache.eduQuestions[id]; if (!q) return;
  showModal('設問編集', eduQuestionForm(q), async () => {
    const body = eduQFormBody($('#eduQForm')); if (!body) return false;
    body.id = q.id;
    await api('api/edu_questions.php', { method: 'POST', query: { action: 'update' }, body });
    toast('更新しました', 'ok'); renderEduQuestions();
  });
}
async function deleteEduQuestion(id) {
  const q = Cache.eduQuestions[id]; if (!q) return;
  if (!confirm(`設問「${q.title}」を削除しますか？`)) return;
  try {
    await api('api/edu_questions.php', { method: 'POST', query: { action: 'delete' }, body: { id } });
    toast('削除しました', 'ok'); renderEduQuestions();
  } catch (e) { /* api() が 409 等を toast 表示 */ }
}

/* ========== セキュリティ教育: レポート ========== */
async function renderEduReport() {
  const r = await api('api/edu_report.php', { query: { action: 'overview' } });
  const s = r.summary;
  $('#eduReportKpi').innerHTML = [
    ['受講率', `${s.completion_rate}%`, 'bi-check2-circle'],
    ['リテラシースコア', `${s.literacy_score}`, 'bi-mortarboard'],
    ['割当', s.assigned, 'bi-people'],
    ['完了', s.completed, 'bi-clipboard-check'],
  ].map(([label, val, icon]) => `
    <div class="col-6 col-md-3"><div class="card text-center"><div class="card-body py-3">
      <i class="bi ${icon} fs-4 text-primary"></i>
      <div class="fs-4 fw-bold">${val}</div><div class="small text-muted">${label}</div>
    </div></div></div>`).join('');
  $('#eduReportDeptBody').innerHTML = (r.by_department || []).length ? r.by_department.map((d) =>
    `<tr><td>${esc(d.department)}</td><td>${d.average_score}%</td><td>${d.respondent_count}</td></tr>`).join('') : emptyRow(3);
  $('#eduReportCatBody').innerHTML = (r.by_category || []).filter((c) => c.answered > 0).length ?
    r.by_category.filter((c) => c.answered > 0).map((c) =>
      `<tr><td>${esc(c.name)}</td><td>${c.correct_rate}%</td><td>${c.answered}</td></tr>`).join('') : emptyRow(3);
  await renderEduTrend();
}

let eduTrendChart = null;
async function renderEduTrend() {
  let t;
  try { t = await api('api/edu_report.php', { query: { action: 'trend' } }); }
  catch (e) { return; }
  const company = t.company || [];
  const note = $('#eduTrendNote');
  if (eduTrendChart) { eduTrendChart.destroy(); eduTrendChart = null; }
  if (!company.length) {
    if (note) note.textContent = 'スナップショット未生成（日次バッチが積むと表示されます）';
    return;
  }
  if (note) note.textContent = `${company.length}日分 / 部署比較日: ${t.group_latest_date || '-'}`;
  eduTrendChart = new Chart($('#eduTrendChart'), {
    type: 'line',
    data: {
      labels: company.map((p) => p.date),
      datasets: [{
        label: 'リテラシースコア（%）',
        data: company.map((p) => p.average_score),
        borderColor: '#4a90d9', backgroundColor: 'rgba(74,144,217,.15)',
        fill: true, tension: 0.25, pointRadius: 3,
      }],
    },
    options: {
      responsive: true, maintainAspectRatio: true,
      scales: { y: { beginAtZero: true, max: 100, ticks: { callback: (v) => v + '%' } } },
      plugins: { legend: { display: true, position: 'bottom' },
        tooltip: { callbacks: { afterLabel: (ctx) => `回答者 ${company[ctx.dataIndex].respondent_count} 名` } } },
    },
  });
}

/* ========== ユーザ管理 ========== */
async function renderUsers() {
  await renderTargets();
  if (!roleAtLeast(State.user.role, 'tenant_admin')) return;
  await renderAdminUsers();
}

async function renderAdminUsers() {
  const { users } = await api('api/users.php', { query: { action: 'list' } });
  cacheRows('users', users);
  // #列は表示上の通し番号(古い順に1,2,3…)。削除しても詰まる。
  const usersAsc = users.slice().sort((a, b) => a.id - b.id);
  $('#usersBody').innerHTML = usersAsc.length ? usersAsc.map((u, i) => `
    <tr><td>${i + 1}</td><td>${esc(u.email)}</td><td>${esc(u.name)}</td><td>${roleLabel(u.role)}</td>
      <td>${u.status==='active'?'<span class="badge bg-success">有効</span>':'<span class="badge bg-secondary">停止</span>'}</td>
      <td class="text-nowrap">
        <button class="btn btn-sm btn-outline-secondary" onclick="editUser(${u.id})"><i class="bi bi-pencil"></i></button>
        <button class="btn btn-sm btn-outline-danger" onclick="deleteUser(${u.id})"><i class="bi bi-trash"></i></button>
      </td></tr>`).join('') : emptyRow(6);
}
function userRoleOptions(cur) {
  const roles = State.user.role === 'superadmin'
    ? [['tenant_admin','組織管理者'],['operator','オペレータ'],['viewer','閲覧者']]
    : [['operator','オペレータ'],['viewer','閲覧者']];
  return roles.map(([v,l])=>`<option value="${v}"${cur===v?' selected':''}>${l}</option>`).join('');
}
function userForm(u = {}, isNew = true) {
  return `<form id="userForm">
    <div class="mb-2"><label class="form-label">メール</label><input class="form-control" name="email" type="email" value="${esc(u.email)}" ${isNew?'required':'readonly'}></div>
    <div class="mb-2"><label class="form-label">氏名</label><input class="form-control" name="name" value="${esc(u.name)}"></div>
    <div class="mb-2"><label class="form-label">ロール</label><select class="form-select" name="role">${userRoleOptions(u.role)}</select></div>
    <div class="mb-2"><label class="form-label">パスワード${isNew?'':'（変更時のみ入力）'}</label><input class="form-control" name="password" type="password" ${isNew?'required':''}></div>
    ${!isNew?`<div class="mb-2"><label class="form-label">状態</label><select class="form-select" name="status"><option value="active"${u.status==='active'?' selected':''}>有効</option><option value="suspended"${u.status==='suspended'?' selected':''}>停止</option></select></div>`:''}
  </form>`;
}
function newUser() {
  showModal('新規ユーザ', userForm({}, true), async () => {
    const f = $('#userForm');
    await api('api/users.php', { method: 'POST', query: { action: 'create' },
      body: { email: f.email.value.trim(), name: f.name.value.trim(), role: f.role.value, password: f.password.value } });
    toast('作成しました', 'ok'); renderAdminUsers();
  });
}
function editUser(id) {
  const u = Cache.users[id];
  if (!u) return;
  showModal('ユーザ編集', userForm(u, false), async () => {
    const f = $('#userForm');
    const body = { id: u.id, name: f.name.value.trim(), role: f.role.value, status: f.status.value };
    if (f.password.value) body.password = f.password.value;
    await api('api/users.php', { method: 'POST', query: { action: 'update' }, body });
    toast('更新しました', 'ok'); renderAdminUsers();
  });
}
async function deleteUser(id) {
  if (!confirm('このユーザを削除しますか？')) return;
  try { await api('api/users.php', { method: 'POST', query: { action: 'delete' }, body: { id } });
    toast('削除しました', 'ok'); renderAdminUsers(); } catch (e) { toast(e.message, 'err'); }
}

/* ========== テナント管理 ========== */
async function renderTenants() {
  const { tenants } = await api('api/tenants.php', { query: { action: 'list' } });
  State.tenants = tenants;
  cacheRows('tenants', tenants);
  // #列は表示上の通し番号(古い順に1,2,3…)。削除しても詰まる。
  const tenantsAsc = tenants.slice().sort((a, b) => a.id - b.id);
  $('#tenantsBody').innerHTML = tenantsAsc.length ? tenantsAsc.map((t, i) => `
    <tr><td>${i + 1}</td><td>${esc(t.name)}</td><td><code>${esc(t.slug)}</code></td>
      <td>${t.status==='active'?'<span class="badge bg-success">有効</span>':'<span class="badge bg-secondary">停止</span>'}</td>
      <td><button class="btn btn-sm btn-outline-secondary" onclick="editTenant(${t.id})"><i class="bi bi-pencil"></i></button></td>
    </tr>`).join('') : emptyRow(5);
}
function tenantForm(t = {}, isNew = true) {
  return `<form id="tenantForm">
    <div class="mb-2"><label class="form-label">組織名</label><input class="form-control" name="name" value="${esc(t.name)}" required></div>
    <div class="mb-2"><label class="form-label">slug（英数字・ハイフン）</label><input class="form-control" name="slug" value="${esc(t.slug)}" ${isNew?'required':'readonly'}></div>
    ${!isNew?`<div class="mb-2"><label class="form-label">状態</label><select class="form-select" name="status"><option value="active"${t.status==='active'?' selected':''}>有効</option><option value="suspended"${t.status==='suspended'?' selected':''}>停止</option></select></div>`:''}
  </form>`;
}
function newTenant() {
  showModal('新規テナント', tenantForm({}, true), async () => {
    const f = $('#tenantForm');
    await api('api/tenants.php', { method: 'POST', query: { action: 'create' }, body: { name: f.name.value.trim(), slug: f.slug.value.trim() } });
    toast('作成しました', 'ok'); renderTenants(); setupTenantSwitcher();
  });
}
function editTenant(id) {
  const t = Cache.tenants[id];
  if (!t) return;
  showModal('テナント編集', tenantForm(t, false), async () => {
    const f = $('#tenantForm');
    await api('api/tenants.php', { method: 'POST', query: { action: 'update' }, body: { id: t.id, name: f.name.value.trim(), status: f.status.value } });
    toast('更新しました', 'ok'); renderTenants();
  });
}

/* ========== モーダル ========== */
let modalInstance = null;
function setAppModalSize(size = null) {
  const dialog = $('#appModal .modal-dialog');
  dialog.classList.remove('modal-sm', 'modal-lg', 'modal-xl', 'modal-fullscreen');
  if (['sm', 'lg', 'xl', 'fullscreen'].includes(size)) dialog.classList.add(`modal-${size}`);
}
function showModal(title, bodyHtml, onSave, options = {}) {
  setAppModalSize(options.size || null);
  $('#appModalTitle').textContent = title;
  $('#appModalBody').innerHTML = bodyHtml;
  const saveBtn = $('#appModalSave');
  const newBtn = saveBtn.cloneNode(true);
  newBtn.classList.remove('d-none');
  saveBtn.parentNode.replaceChild(newBtn, saveBtn);
  newBtn.addEventListener('click', async () => {
    newBtn.disabled = true;
    try { await onSave(); modalInstance.hide(); }
    catch (e) { toast(e.message, 'err'); }
    finally { newBtn.disabled = false; }
  });
  if (!modalInstance) modalInstance = new bootstrap.Modal($('#appModal'));
  modalInstance.show();
}
function showInfoModal(title, bodyHtml, options = {}) {
  setAppModalSize(options.size || null);
  $('#appModalTitle').textContent = title;
  $('#appModalBody').innerHTML = bodyHtml;
  const saveBtn = $('#appModalSave');
  const newBtn = saveBtn.cloneNode(true);
  saveBtn.parentNode.replaceChild(newBtn, saveBtn);
  newBtn.classList.add('d-none');
  if (!modalInstance) modalInstance = new bootstrap.Modal($('#appModal'));
  modalInstance.show();
}
function emptyRow(cols) { return `<tr><td colspan="${cols}" class="text-center text-muted py-4">データがありません</td></tr>`; }

/* ========== 起動 ========== */
document.addEventListener('DOMContentLoaded', () => {
  $('#loginForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = $('#loginBtn'), spin = $('#loginSpin'), err = $('#loginError');
    err.classList.add('d-none'); btn.disabled = true; spin.classList.remove('d-none');
    try { await login($('#loginEmail').value.trim(), $('#loginPassword').value); }
    catch (ex) { err.textContent = ex.message; err.classList.remove('d-none'); }
    finally { btn.disabled = false; spin.classList.add('d-none'); }
  });
  $('#logoutBtn').addEventListener('click', () => logout());
  $('#sidebarToggle').addEventListener('click', () => $('#sidebar').classList.toggle('open'));
  $$('.app-sidebar .nav-link').forEach((a) => a.addEventListener('click', () => navigate(a.dataset.view)));
  // 引数なしで呼ぶ(click Event が campaignId に渡ると編集モードと誤判定されるため)。
  $('#newCampaignBtn').addEventListener('click', () => openCampaignModal());
  $('#ingestBtn').addEventListener('click', ingestLogs);
  $('#individualsBtn')?.addEventListener('click', toggleIndividuals);
  $('#individualsRefreshBtn')?.addEventListener('click', renderIndividuals);
  $('#toGroupBtn').addEventListener('click', openToGroupModal);
  $('#reportPeriodBtn')?.addEventListener('click', () => { if (reportSelectedId) renderReportDetail(reportSelectedId); });
  $('#reportPeriodClearBtn')?.addEventListener('click', () => {
    $('#reportStartDate').value = ''; $('#reportEndDate').value = '';
    if (reportSelectedId) renderReportDetail(reportSelectedId);
  });
  $('#reportExportBtn')?.addEventListener('click', exportReportXlsx);
  $('#reportCommitBtn')?.addEventListener('click', commitReport);
  $('#reportUncommitBtn')?.addEventListener('click', uncommitReport);
  $('#logsTabs')?.addEventListener('click', (e) => {
    const a = e.target.closest('.nav-link'); if (!a) return;
    e.preventDefault(); switchLogTab(a.dataset.log);
  });
  for (const selector of ['#reportMailStatus', '#reportMailUnmatched']) {
    $(selector)?.addEventListener('change', () => { logsState.offset = 0; loadLogs(); });
  }
  $('#logsBody')?.addEventListener('click', (e) => {
    const button = e.target.closest('[data-report-decision]');
    if (button) decideReportMail(button);
  });
  $('#logsRefreshBtn')?.addEventListener('click', () => { logsState.offset = 0; loadLogs(); });
  $('#logsCampaignFilter')?.addEventListener('change', () => { logsState.offset = 0; loadLogs(); });
  $('#logsPrevBtn')?.addEventListener('click', () => {
    if (logsState.type === 'webaccess') { logsState.webPage = Math.max(1, (logsState.webPage || 1) - 1); }
    else { logsState.offset = Math.max(0, logsState.offset - logsState.limit); }
    loadLogs();
  });
  $('#logsNextBtn')?.addEventListener('click', () => {
    if (logsState.type === 'webaccess') { logsState.webPage = (logsState.webPage || 1) + 1; }
    else { logsState.offset += logsState.limit; }
    loadLogs();
  });
  $('#logsApplyBtn')?.addEventListener('click', () => { logsState.webPage = 1; loadLogs(); });
  $('#logsStatusFilter')?.addEventListener('change', () => loadLogs());
  $('#logsTypeFilter')?.addEventListener('change', () => loadLogs());
  $('#logsInclSystem')?.addEventListener('change', () => loadLogs());
  $('#logsSenderFilter')?.addEventListener('change', () => loadLogs());
  $('#logsKeyword')?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { logsState.webPage = 1; loadLogs(); } });
  $('#logsPathFilter')?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { logsState.webPage = 1; loadLogs(); } });
  // CSV出力はタブ別にディスパッチ。
  $('#logsCsvBtn')?.addEventListener('click', () => {
    switch (logsState.type) {
      case 'training_results': return downloadTrainingResultsCsv();
      case 'training_log_detail': return downloadTrainingLogDetailCsv();
      case 'webaccess': return downloadWebAccessCsv();
      case 'reply_maildir': return downloadReplyMaildirCsv();
      case 'campaign_files': return downloadCampaignFilesCsv();
      default: return downloadTrainingResultsCsv();
    }
  });
  // 生ログ全件DLはタブ(raw_mail/raw_web)別。
  $('#logsRawDlBtn')?.addEventListener('click', () => {
    if (logsState.type === 'raw_mail' || logsState.type === 'raw_web') downloadRawLog(logsState.type);
  });
  // Excel(XLSX)出力はタブ別にディスパッチ。
  $('#logsXlsxBtn')?.addEventListener('click', () => {
    const type = logsState.type;
    const qs = new URLSearchParams({ action: type + '_xlsx' });
    const cid = $('#logsCampaignFilter')?.value; if (cid) qs.set('campaign_id', cid);
    const tid = State.activeTenantId || State.user?.tenant_id;
    if (tid) qs.set('tenant_id', tid);
    // タブ固有のフィルタを引き継ぐ
    if (type === 'training_log_detail') {
      const tp = $('#logsTypeFilter')?.value; if (tp) qs.set('type', tp);
      const sd = logDateVal('#logsStartDate'); if (sd) qs.set('start_date', sd);
      const ed = logDateVal('#logsEndDate'); if (ed) qs.set('end_date', ed);
    }
    if (type === 'webaccess') {
      const sd = logDateVal('#logsStartDate'); if (sd) qs.set('start_date', sd);
      const ed = logDateVal('#logsEndDate'); if (ed) qs.set('end_date', ed);
      const pf = $('#logsPathFilter')?.value.trim(); if (pf) qs.set('path', pf);
    }
    if (type === 'reply_maildir') {
      const sender = $('#logsSenderFilter')?.value; if (sender) qs.set('sender', sender);
      const kw = $('#logsKeyword')?.value.trim(); if (kw) qs.set('q', kw);
      const sd = logDateVal('#logsStartDate'); if (sd) qs.set('start_date', sd);
      const ed = logDateVal('#logsEndDate'); if (ed) qs.set('end_date', ed);
    }
    if (type === 'training_results') {
      const st = $('#logsStatusFilter')?.value; if (st) qs.set('status', st);
    }
    window.open(`api/logs.php?${qs}`, '_blank');
  });
  // 受信メール本文表示ボタン(イベント委譲)。
  $('#logsBody')?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-mailview]'); if (!btn) return;
    const row = btn.closest('tr');
    const cells = row ? row.querySelectorAll('td') : [];
    const meta = cells.length >= 4
      ? `<dt class="col-3">受信日時</dt><dd class="col-9">${cells[0].textContent}</dd>
         <dt class="col-3">差出人</dt><dd class="col-9">${esc(cells[2].textContent)}</dd>
         <dt class="col-3">件名</dt><dd class="col-9">${esc(cells[3].textContent)}</dd>` : '';
    viewMailBody(btn.dataset.account, btn.dataset.file, meta);
  });
  $('#newTargetBtn').addEventListener('click', newTarget);
  $('#importCsvBtn').addEventListener('click', importCsv);
  // click イベントを引数に渡さない(Event オブジェクトが includeArchived=true 扱いになるため)。
  $('#exportCsvBtn')?.addEventListener('click', () => exportTargetsCsv(false));
  $('#exportCsvAllBtn')?.addEventListener('click', () => exportTargetsCsv(true));
  $('#showArchivedTargets')?.addEventListener('change', renderTargets);
  $('#newGroupBtn').addEventListener('click', newGroup);
  $('#newScenarioBtn').addEventListener('click', newScenario);
$('#newTemplateBtn').addEventListener('click', newTemplate);
  $('#tplExportBtn')?.addEventListener('click', exportTemplatesCsv);
  $('#tplImportAddBtn')?.addEventListener('click', () => importTemplatesCsv('add'));
  $('#tplImportUpsertBtn')?.addEventListener('click', () => importTemplatesCsv('upsert'));
  $('#newEduDeliveryBtn').addEventListener('click', newEduDelivery);
  $('#newUserBtn').addEventListener('click', newUser);
  $('#newTenantBtn').addEventListener('click', newTenant);
  checkSession();
});

// インライン onclick から呼ぶためグローバル公開
Object.assign(window, {
  launchCampaign, stopCampaign, resumeCampaign, showCampaignProgress, showCampaignData, loadCampaignFile, viewMaster, editMaster, downloadMaster, deleteCampaign, editTarget, deleteTarget, restoreTarget,
  editGroup, deleteGroup, setTplKind, editTemplate, deleteTemplate, tplViewer, scenarioViewer,
  editUser, deleteUser, editTenant, showReportDetail, generateCampaign,
});

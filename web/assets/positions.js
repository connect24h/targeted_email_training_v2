/**
 * 役職マスタ画面と対象者集計画面。
 *
 * app.js 本体には手を入れず、VIEWS へ後付け登録する(campaign-automations.js と同じ方式)。
 * カテゴリ定数とバッジ色は app.js の POSITION_CATEGORIES / POSITION_CATEGORY_BADGE を使う。
 */

/* ========== 共通 ========== */

// マスタ一覧のキャッシュ。編集モーダルは HTML 属性に値を埋め込まずここから引く。
const PositionCache = {};
// 未登録役職の役職名。行インデックスで引く(属性値に生の文字列を置かないため)。
const UnmappedCache = [];

function positionBadge(category) {
  const color = POSITION_CATEGORY_BADGE[category] || 'light text-dark';
  return `<span class="badge bg-${color}">${esc(category)}</span>`;
}

// カテゴリの <option> 群。selected を付ける値を渡せる。
function positionCategoryOptions(selected = '') {
  return POSITION_CATEGORIES
    .map((c) => `<option value="${c}"${c === selected ? ' selected' : ''}>${c}</option>`)
    .join('');
}

/* ========== 役職マスタ ========== */

async function renderPositions() {
  const data = await api('api/positions.php', { query: { action: 'coverage' } });
  const { masters, unmapped, stale, summary } = data;
  masters.forEach((m) => { PositionCache[m.id] = m; });

  // --- カバレッジ表示。未登録と未適用のズレを最上部で知らせる ---
  const alerts = [];
  if (summary.unmapped > 0) {
    alerts.push(`<div class="alert alert-warning mb-2">
      <i class="bi bi-exclamation-triangle me-1"></i>
      マスタに無い役職が <strong>${summary.unmapped_titles} 種類・${summary.unmapped} 名</strong> います。
      下の「未登録の役職」から割り当ててください。
    </div>`);
  }
  if (summary.stale > 0) {
    alerts.push(`<div class="alert alert-info mb-2">
      <i class="bi bi-arrow-repeat me-1"></i>
      マスタと対象者のカテゴリが <strong>${summary.stale} 名分</strong> ずれています（マスタ編集後に未適用）。
      「マスタを再適用」を押すと揃います。
    </div>`);
  }
  if (summary.no_title > 0) {
    alerts.push(`<div class="alert alert-secondary mb-2 py-2 small">
      役職が未入力の対象者が ${summary.no_title} 名います（マスタでは割り当てられません）。
    </div>`);
  }
  if (alerts.length === 0) {
    alerts.push(`<div class="alert alert-success mb-2">
      <i class="bi bi-check-circle me-1"></i>
      対象者 ${summary.total} 名の役職はすべてマスタに登録済みで、カテゴリも適用済みです。
    </div>`);
  }
  $('#positionCoverage').innerHTML = alerts.join('');

  // --- 未登録の役職。行内で select → ボタンで登録まで完結させる ---
  const unmappedCard = $('#positionUnmappedCard');
  unmappedCard.classList.toggle('d-none', unmapped.length === 0);
  if (unmapped.length > 0) {
    // 役職名は属性値ではなく行インデックスで引く(esc() が引用符を実体参照にするため、
    // 属性セレクタで元の文字列と突合できない)。実体は UnmappedCache に持つ。
    UnmappedCache.length = 0;
    unmapped.forEach((u) => UnmappedCache.push(u.title));
    $('#positionUnmappedBody').innerHTML = unmapped.map((u, i) => `
      <tr>
        <td>${esc(u.title)}</td>
        <td>${u.count} 名</td>
        <td>
          <select class="form-select form-select-sm" data-unmapped-select="${i}">
            ${positionCategoryOptions()}
          </select>
        </td>
        <td>
          <button class="btn btn-sm btn-primary" data-position-action="add" data-index="${i}">
            <i class="bi bi-plus-lg"></i> マスタに追加
          </button>
        </td>
      </tr>`).join('');
  }

  // --- マスタ一覧 ---
  const canEdit = roleAtLeast(State.user.role, 'operator');
  $('#positionsBody').innerHTML = masters.length ? masters.map((m, i) => {
    const zero = Number(m.target_count) === 0;
    const noteBadge = m.note ? `<span class="badge bg-warning text-dark">${esc(m.note)}</span>` : '';
    return `
    <tr${zero ? ' class="text-muted"' : ''}>
      <td>${i + 1}</td>
      <td>${esc(m.title)}</td>
      <td>${positionBadge(m.category)}</td>
      <td>${m.target_count} 名</td>
      <td>${noteBadge}</td>
      <td class="text-nowrap">
        ${canEdit ? `<button class="btn btn-sm btn-outline-secondary" data-position-action="edit" data-id="${m.id}"><i class="bi bi-pencil"></i></button>
        <button class="btn btn-sm btn-outline-danger" data-position-action="delete" data-id="${m.id}"><i class="bi bi-trash"></i></button>` : ''}
      </td>
    </tr>`;
  }).join('') : emptyRow(6);
}

function positionForm(m = {}) {
  const isEdit = Boolean(m.id);
  return `<form id="positionForm">
    <div class="mb-2"><label class="form-label">役職名</label>
      <input class="form-control" name="title" value="${esc(m.title)}"${isEdit ? ' readonly' : ''} required>
      ${isEdit ? '<div class="form-text">役職名は対象者との突合キーのため変更できません。変えたい場合は削除して登録し直してください。</div>'
               : '<div class="form-text">対象者の「役職」欄と完全に一致する必要があります。</div>'}
    </div>
    <div class="mb-2"><label class="form-label">役職カテゴリ</label>
      <select class="form-select" name="category">${positionCategoryOptions(m.category || '')}</select>
    </div>
    <div class="mb-2"><label class="form-label">メモ</label>
      <input class="form-control" name="note" value="${esc(m.note)}" placeholder="例: 推定">
    </div>
  </form>`;
}

function newPosition() {
  showModal('役職マスタ追加', positionForm(), async () => {
    const f = $('#positionForm');
    const title = f.title.value.trim();
    if (!title) throw new Error('役職名を入力してください');
    const r = await api('api/positions.php', {
      method: 'POST', query: { action: 'create' },
      body: { title, category: f.category.value, note: f.note.value.trim() },
    });
    toast(`追加しました（${r.applied} 名へ反映）`, 'ok');
    renderPositions();
  });
}

function editPosition(id) {
  const m = PositionCache[id];
  if (!m) return;
  showModal('役職マスタ編集', positionForm(m), async () => {
    const f = $('#positionForm');
    await api('api/positions.php', {
      method: 'POST', query: { action: 'update' },
      body: { id: m.id, category: f.category.value, note: f.note.value.trim() },
    });
    toast('更新しました。対象者へ反映するには「マスタを再適用」を押してください', 'ok');
    renderPositions();
  });
}

async function deletePosition(id) {
  const m = PositionCache[id];
  if (!m) return;
  const count = Number(m.target_count) || 0;
  const message = count > 0
    ? `「${m.title}」には ${count} 名が該当します。マスタから削除すると「未登録の役職」に戻ります（対象者の役職カテゴリ自体は消えません）。よろしいですか？`
    : `「${m.title}」をマスタから削除しますか？`;
  if (!confirm(message)) return;
  try {
    await api('api/positions.php', { method: 'POST', query: { action: 'delete' }, body: { id } });
    toast('削除しました', 'ok');
    renderPositions();
  } catch (e) { toast(e.message, 'err'); }
}

// 未登録の役職を、行内で選んだカテゴリでマスタへ登録する。
async function addUnmappedPosition(title, category) {
  try {
    const r = await api('api/positions.php', {
      method: 'POST', query: { action: 'create' },
      body: { title, category, note: null },
    });
    toast(`「${title}」を${category}として登録しました（${r.applied} 名へ反映）`, 'ok');
    renderPositions();
  } catch (e) { toast(e.message, 'err'); }
}

async function applyPositions() {
  if (!confirm('役職マスタの内容を対象者へ一括反映します。よろしいですか？')) return;
  const btn = $('#applyPositionsBtn');
  btn.disabled = true;
  try {
    const r = await api('api/positions.php', { method: 'POST', query: { action: 'apply' } });
    const suffix = r.unmapped > 0 ? `（マスタ未登録が ${r.unmapped} 名残っています）` : '';
    toast(`${r.updated} 名へ反映しました${suffix}`, 'ok');
    renderPositions();
  } catch (e) { toast(e.message, 'err'); }
  finally { btn.disabled = false; }
}

function handlePositionAction(event) {
  const button = event.target.closest('[data-position-action]');
  if (!button) return;
  const action = button.dataset.positionAction;
  if (action === 'edit') editPosition(Number(button.dataset.id));
  if (action === 'delete') deletePosition(Number(button.dataset.id));
  if (action === 'add') {
    const index = Number(button.dataset.index);
    const title = UnmappedCache[index];
    if (title === undefined) return;
    const select = $(`[data-unmapped-select="${index}"]`);
    addUnmappedPosition(title, select ? select.value : POSITION_CATEGORIES[0]);
  }
}

/* ========== 対象者集計 ========== */

let statCompanyChart = null;
let statCategoryChart = null;
// 現在のタブ。会社別と役職別でスコープ切替を共有する。
let statTab = 'company';

// スコープ切替(テストユーザ/削除済みを含めるか)を query に変換する。
function statScopeQuery() {
  const query = {};
  if ($('#statIncludeTest')?.checked) query.include_test = '1';
  if ($('#statIncludeArchived')?.checked) query.include_archived = '1';
  return query;
}

async function renderTargetStats() {
  $('#statCompanyPane').classList.toggle('d-none', statTab !== 'company');
  $('#statPositionPane').classList.toggle('d-none', statTab !== 'position');
  $$('#statTabs .nav-link').forEach((a) => a.classList.toggle('active', a.dataset.stat === statTab));
  if (statTab === 'company') {
    await renderStatByCompany();
  } else {
    await renderStatByPosition();
  }
}

async function renderStatByCompany() {
  const data = await api('api/positions.php', { query: { action: 'by_company', ...statScopeQuery() } });
  const { rows, categories, total } = data;
  // 実在するカテゴリ列（正規3つ＋未設定）を確定する。
  const columns = categories.slice();
  if (rows.some((r) => r.by_category['(未設定)'])) columns.push('(未設定)');

  $('#statSummary').innerHTML = `<div class="alert alert-light border py-2 mb-0">
    集計対象 <strong>${total}</strong> 名 / <strong>${rows.length}</strong> 社
  </div>`;

  $('#statCompanyHead').innerHTML = `<tr><th>会社名</th>${
    columns.map((c) => `<th class="text-end">${esc(c)}</th>`).join('')
  }<th class="text-end">合計</th></tr>`;

  $('#statCompanyBody').innerHTML = rows.length ? rows.map((r) => `
    <tr>
      <td>${esc(r.company)}</td>
      ${columns.map((c) => `<td class="text-end">${r.by_category[c] || 0}</td>`).join('')}
      <td class="text-end fw-bold">${r.count}</td>
    </tr>`).join('') : emptyRow(columns.length + 2);

  // 上位10社の積み上げ横棒。長い会社名が潰れないよう indexAxis='y'。
  const top = rows.slice(0, 10);
  if (statCompanyChart) statCompanyChart.destroy();
  statCompanyChart = new Chart($('#statCompanyChart'), {
    type: 'bar',
    data: {
      labels: top.map((r) => r.company),
      datasets: columns.map((c) => ({
        label: c,
        data: top.map((r) => r.by_category[c] || 0),
        backgroundColor: { '役員': '#dc3545', '管理職': '#ffc107', '一般従業員': '#6c757d' }[c] || '#dee2e6',
      })),
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      plugins: { legend: { position: 'bottom' }, title: { display: true, text: '会社別 人数（上位10社）' } },
      scales: { x: { stacked: true, beginAtZero: true, ticks: { precision: 0 } }, y: { stacked: true } },
    },
  });
}

async function renderStatByPosition() {
  const data = await api('api/positions.php', { query: { action: 'by_position', ...statScopeQuery() } });
  const { by_category: byCategory, by_title: byTitle, total } = data;

  const unmappedCount = byTitle.filter((t) => !Number(t.in_master)).reduce((a, t) => a + Number(t.count), 0);
  $('#statSummary').innerHTML = `<div class="alert alert-light border py-2 mb-0">
    集計対象 <strong>${total}</strong> 名 / 役職 <strong>${byTitle.length}</strong> 種類
    ${unmappedCount > 0 ? `<span class="text-warning ms-2"><i class="bi bi-exclamation-triangle"></i> マスタ未登録 ${unmappedCount} 名</span>` : ''}
  </div>`;

  $('#statCategoryBody').innerHTML = byCategory.map((c) => {
    const pct = total > 0 ? ((Number(c.count) / total) * 100).toFixed(1) : '0.0';
    return `<tr>
      <td>${positionBadge(c.category)}</td>
      <td class="text-end">${c.count} 名</td>
      <td class="text-end text-muted small">${pct}%</td>
    </tr>`;
  }).join('');

  // マスタ未登録は行を強調し、その場でマスタ画面へ誘導する。
  $('#statPositionBody').innerHTML = byTitle.length ? byTitle.map((t) => {
    const missing = !Number(t.in_master);
    return `<tr${missing ? ' class="table-warning"' : ''}>
      <td>${esc(t.title)}</td>
      <td>${t.category === '(未設定)' ? '<span class="text-muted">—</span>' : positionBadge(t.category)}</td>
      <td>${t.count} 名</td>
      <td>${missing
        ? '<span class="badge bg-warning text-dark">未登録</span>'
        : '<span class="text-success"><i class="bi bi-check-lg"></i></span>'}</td>
    </tr>`;
  }).join('') : emptyRow(4);

  if (statCategoryChart) statCategoryChart.destroy();
  statCategoryChart = new Chart($('#statCategoryChart'), {
    type: 'doughnut',
    data: {
      labels: byCategory.map((c) => c.category),
      datasets: [{
        data: byCategory.map((c) => Number(c.count)),
        backgroundColor: byCategory.map((c) =>
          ({ '役員': '#dc3545', '管理職': '#ffc107', '一般従業員': '#6c757d' }[c.category] || '#dee2e6')),
      }],
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' }, title: { display: true, text: '役職カテゴリ別' } } },
  });
}

/* ========== 登録 ========== */

VIEWS.positions = renderPositions;
VIEWS.targetStats = renderTargetStats;

document.addEventListener('DOMContentLoaded', () => {
  $('#newPositionBtn')?.addEventListener('click', newPosition);
  $('#applyPositionsBtn')?.addEventListener('click', applyPositions);
  // 一覧と未登録テーブルの両方をイベント委譲で拾う(再描画で要素が入れ替わるため)。
  $('#positionsBody')?.addEventListener('click', handlePositionAction);
  $('#positionUnmappedBody')?.addEventListener('click', handlePositionAction);
  $('#statIncludeTest')?.addEventListener('change', renderTargetStats);
  $('#statIncludeArchived')?.addEventListener('change', renderTargetStats);
  $('#statTabs')?.addEventListener('click', (event) => {
    const link = event.target.closest('[data-stat]');
    if (!link) return;
    event.preventDefault();
    statTab = link.dataset.stat;
    renderTargetStats();
  });
});

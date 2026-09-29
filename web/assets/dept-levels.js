/* 部署の階層(C3、G14)。部署の表の「部署のまとめ方」(全部 / 1段目 / 2段目)。
 * 部署を「本部/部/課」のように「/」で区切って登録したテナントだけに選択を出す(ない時は隠し、今までと同じ表)。
 * 「全部」の時は dept_level を送らない(今までと同じ要求と出力)。 */
const DeptLevels = { key: '', at: 0, info: null };
const DEPT_LEVELS_TTL_MS = 60000;

/** テナントに階層のある部署があるか(テナントごとに1分だけ覚える。対象者の取込で変わるため)。 */
function deptLevelsInfo() {
  const key = String(State.activeTenantId || State.user?.tenant_id || '');
  if (DeptLevels.info && DeptLevels.key === key && Date.now() - DeptLevels.at < DEPT_LEVELS_TTL_MS) return DeptLevels.info;
  DeptLevels.key = key;
  DeptLevels.at = Date.now();
  // 取れなくても表は今までどおり出す(選択を隠すだけ)
  DeptLevels.info = api('api/edu_report.php', { query: { action: 'dept_levels' } }).catch(() => ({ has_hierarchy: false }));
  return DeptLevels.info;
}

/** 選択の表示をテナントに合わせ、その表の今の段(0、1、2)を返す。選択を隠す時は 0。 */
async function deptLevelFor(selectId) {
  const select = document.getElementById(selectId);
  if (!select) return 0;
  const info = await deptLevelsInfo();
  const has = info?.has_hierarchy === true;
  const wrap = select.closest('[data-dept-level-wrap]');
  if (wrap) {
    wrap.classList.toggle('d-none', !has);
    wrap.classList.toggle('d-flex', has);
  }
  if (!has) select.value = '0';
  return Number(select.value) || 0;
}

/** API のクエリに足す分。全部(0)の時は何も足さない。 */
function deptLevelQuery(level) {
  return level ? { dept_level: String(level) } : {};
}

/* ---- 訓練のレポートの部署別 ---- */
async function renderReportDepartments(campaignId) {
  const body = document.getElementById('reportByDept');
  if (!body) return;
  const level = await deptLevelFor('reportDeptLevel');
  const closed = Boolean(Cache.reports[campaignId]?.closed_at);
  const query = { action: 'departments', campaign_id: campaignId, ...deptLevelQuery(level) };
  const sd = $('#reportStartDate')?.value, ed = $('#reportEndDate')?.value;
  if (sd) query.start_date = sd;
  if (ed) query.end_date = ed;
  if (!closed && reportTestFilter && reportTestFilter !== 'prod') query.test_filter = reportTestFilter;
  let d;
  try {
    d = await api('api/report.php', { query });
  } catch (e) {
    body.innerHTML = `<tr><td colspan="6" class="text-muted small">部署別の取得に失敗しました: ${esc(e.message)}</td></tr>`;
    return;
  }
  if (reportSelectedId && Number(reportSelectedId) !== Number(campaignId)) return;
  document.getElementById('reportDeptNote')?.classList.toggle('d-none', d.is_committed !== true);
  const rows = d.departments || [];
  body.innerHTML = rows.length ? rows.map((r) => {
    const authTargetRate = authTargetRateOf(r.auth_count, r.count);
    return `<tr><td>${esc(r.department)}</td><td>${Number(r.count)}</td>
      <td class="${rateClass(r.link_rate, 25, 50)}">${countRate(r.link_clicked, r.link_rate)}</td>
      <td class="${rateClass(authRateOf(r.auth_count, r.link_clicked) ?? 0, 5, 20)}">${countRate(r.auth_count, authRateOf(r.auth_count, r.link_clicked))}</td>
      <td class="${authTargetRate === null ? '' : rateClass(authTargetRate, 5, 20)}">${authTargetRate === null ? '-' : pct(authTargetRate)}</td>
      <td class="${r.report_rate == null ? '' : goodRateClass(r.report_rate, 5, 20)}">${r.report_rate == null ? '-' : countRate(r.report_count, r.report_rate)}</td></tr>`;
  }).join('') : emptyRow(6);
}

document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('eduReportDeptLevel')?.addEventListener('change', () => renderEduReportDeptRank());
  document.getElementById('eduRepDeptsLevel')?.addEventListener('change', () => renderEduRepDepts());
  document.getElementById('eduRepTagMatrixLevel')?.addEventListener('change', () => renderEduRepTagMatrix());
  document.getElementById('reportDeptLevel')?.addEventListener('change', () => {
    if (reportSelectedId) renderReportDepartments(reportSelectedId);
  });
});

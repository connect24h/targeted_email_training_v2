'use strict';

(function installRiskDashboard(global) {
  const BAND_LABELS = { high: '高', medium: '中', low: '低' };
  const BAND_CLASSES = { high: 'danger', medium: 'warning', low: 'success' };
  const DETAIL_LABELS = {
    open: 'サイト表示', click: 'リンククリック', auth: '認証情報入力', report: '報告',
    clean_campaigns: '反応記録なしの訓練', edu_assigned: '教育割当',
    edu_completed: '教育完了', edu_overdue: '教育期限超過',
  };

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => (
      { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]
    ));
  }

  function createRiskDashboard({ api, root, getContext, getView }) {
    let generation = 0;
    let selectedBand = '';

    function contextKey() {
      const context = getContext();
      return `${context.userKey}|${context.tenantId}`;
    }

    function isCurrent(requestGeneration, requestContext) {
      return requestGeneration === generation
        && requestContext === contextKey()
        && getView() === 'riskDashboard';
    }

    function bandBadge(band) {
      const safeBand = Object.hasOwn(BAND_LABELS, band) ? band : 'medium';
      return `<span class="badge bg-${BAND_CLASSES[safeBand]}">${BAND_LABELS[safeBand]}</span>`;
    }

    function detailRows(detail) {
      const data = detail && typeof detail === 'object' ? detail : {};
      return Object.entries(DETAIL_LABELS).map(([key, label]) => (
        `<dt class="col-8">${label}</dt><dd class="col-4 text-end">${Number(data[key] || 0)}</dd>`
      )).join('');
    }

    function peopleRows(individuals) {
      if (!individuals.length) {
        return '<tr><td colspan="6" class="text-center text-muted py-4">条件に一致する対象者はいません</td></tr>';
      }
      return individuals.map((person) => `<tr>
        <td><strong>${escapeHtml(person.name || person.email)}</strong><div class="small text-muted">${escapeHtml(person.email)}</div></td>
        <td>${escapeHtml(person.company || '（未設定）')}</td>
        <td>${escapeHtml(person.position_category || '（未設定）')}</td>
        <td class="text-nowrap">${Number(person.score)} ${bandBadge(person.band)}</td>
        <td class="small">訓練 +${Number(person.phish_component || 0)} / 教育 ${Number(person.edu_component || 0)} / 適切な行動 −${Number(person.report_credit || 0)}</td>
        <td><details><summary class="btn btn-sm btn-outline-secondary">内訳</summary>
          <dl class="row small mt-2 mb-0">${detailRows(person.detail)}</dl></details></td>
      </tr>`).join('');
    }

    function companyRows(companies) {
      if (!companies.length) {
        return '<tr><td colspan="6" class="text-center text-muted py-4">会社別データはありません</td></tr>';
      }
      return companies.map((company) => `<tr>
        <td>${escapeHtml(company.company)}</td><td>${Number(company.count)}</td><td>${Number(company.avg_score)}</td>
        <td>${Number(company.high_count)}</td><td>${Number(company.medium_count)}</td><td>${Number(company.low_count)}</td>
      </tr>`).join('');
    }

    function renderContent(individualData, companyData) {
      const bands = individualData.bands || { high: 0, medium: 0, low: 0 };
      const total = Number(individualData.total || 0);
      const shown = (individualData.individuals || []).length;
      const truncation = shown < total ? `上位 ${shown} 名を表示（該当 ${total} 名）` : `${total} 名`;
      root.innerHTML = `
        <div class="alert alert-info small" role="note">リスクスコアはフォロー対象を優先するための指標です。未訓練者の基準点は50で、訓練履歴がないことは安全を意味しません。スコアの再計算はこの画面では行いません。</div>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
          <span id="riskComputedDate" class="text-muted">最新計算日: ${escapeHtml(individualData.computed_date)}</span>
          <button id="riskRetryBtn" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-clockwise"></i> 再読込</button>
        </div>
        <div id="riskBandSummary" class="row g-3 mb-4">
          ${[['high', '高リスク'], ['medium', '中リスク'], ['low', '低リスク']].map(([band, label]) => `
            <div class="col-4"><div class="card h-100"><div class="card-body text-center">
              <div class="text-muted small">${label}</div><div class="fs-3 fw-bold text-${BAND_CLASSES[band]}">${Number(bands[band] || 0)}</div>
            </div></div></div>`).join('')}
        </div>
        <div class="card mb-4"><div class="card-header">会社別スコア・リスク帯分布</div>
          <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th>会社</th><th>人数</th><th>平均スコア</th><th>高</th><th>中</th><th>低</th></tr></thead>
            <tbody id="riskCompaniesBody">${companyRows(companyData.companies || [])}</tbody>
          </table></div></div>
        <div class="card"><div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
          <span>個人別（高スコア順） <span id="riskPeopleCount" class="small text-muted">${truncation}</span></span>
          <label class="small">リスク帯 <select id="riskBandFilter" class="form-select form-select-sm d-inline-block w-auto ms-1">
            <option value=""${selectedBand === '' ? ' selected' : ''}>すべて</option>
            <option value="high"${selectedBand === 'high' ? ' selected' : ''}>高</option>
            <option value="medium"${selectedBand === 'medium' ? ' selected' : ''}>中</option>
            <option value="low"${selectedBand === 'low' ? ' selected' : ''}>低</option>
          </select></label>
        </div><div class="table-responsive"><table class="table table-hover align-middle mb-0">
          <thead><tr><th>氏名</th><th>会社</th><th>役職</th><th class="text-nowrap">スコア</th><th>構成要素</th><th>説明</th></tr></thead>
          <tbody id="riskPeopleBody">${peopleRows(individualData.individuals || [])}</tbody>
        </table></div></div>`;
      root.querySelector?.('#riskRetryBtn')?.addEventListener('click', render);
      root.querySelector?.('#riskBandFilter')?.addEventListener('change', (event) => {
        selectedBand = event.target.value;
        render();
      });
    }

    async function render() {
      const requestGeneration = ++generation;
      const requestContext = contextKey();
      root.innerHTML = '<div id="riskLoading" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span>リスクスナップショットを読込中です</div>';
      try {
        const query = { action: 'risk_individuals', limit: 100 };
        if (selectedBand) query.band = selectedBand;
        const [individualData, companyData] = await Promise.all([
          api('api/report.php', { query }),
          api('api/report.php', { query: { action: 'risk_by_company' } }),
        ]);
        if (!isCurrent(requestGeneration, requestContext)) return;
        if (individualData.computed_date !== companyData.computed_date) {
          throw new Error('スナップショットの更新中です。少し待って再試行してください');
        }
        if (!individualData.computed_date) {
          root.innerHTML = '<div id="riskEmpty" class="alert alert-secondary">リスクスナップショットはまだありません。日次計算後に再度確認してください。 <button id="riskRetryBtn" class="btn btn-sm btn-outline-secondary ms-2">再試行</button></div>';
          root.querySelector?.('#riskRetryBtn')?.addEventListener('click', render);
          return;
        }
        renderContent(individualData, companyData);
      } catch (error) {
        if (!isCurrent(requestGeneration, requestContext)) return;
        root.innerHTML = `<div id="riskError" class="alert alert-danger">${escapeHtml(error?.message || '読込に失敗しました')} <button id="riskRetryBtn" class="btn btn-sm btn-outline-danger ms-2">再試行</button></div>`;
        root.querySelector?.('#riskRetryBtn')?.addEventListener('click', render);
      }
    }

    return {
      render,
      setBand(band) {
        selectedBand = Object.hasOwn(BAND_LABELS, band) ? band : '';
      },
      invalidate() {
        generation++;
        root.innerHTML = '';
      },
    };
  }

  global.createRiskDashboard = createRiskDashboard;
})(typeof window === 'undefined' ? globalThis : window);

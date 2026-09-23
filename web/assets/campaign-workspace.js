'use strict';

function createCampaignWorkspace(root, actions) {
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const count = (value) => Number.isFinite(Number(value)) ? Number(value) : 0;
  const modeLabel = { link: 'リンク', attachment: '添付', form: 'フォーム', qr: 'QRコード' };
  root.addEventListener('click', (event) => {
    const button = event.target.closest('[data-campaign-action]');
    if (!button || !root.contains(button) || button.disabled) return;
    actions[button.dataset.campaignAction]?.();
  });

  function loading() {
    root.innerHTML = '<div class="campaign-review-loading" role="status">保存内容と配信条件を確認しています…</div>';
  }
  function error(message) {
    root.innerHTML = `<div class="alert alert-danger" role="alert">配信前確認を取得できません: ${escapeHtml(message)}</div>
      <button type="button" class="btn btn-outline-secondary" data-campaign-action="back">訓練一覧へ戻る</button>
      <button type="button" class="btn btn-outline-primary ms-2" data-campaign-action="refresh">再読み込み</button>`;
  }
  function setLaunching(launching) {
    const button = root.querySelector('[data-campaign-action="launch"]');
    if (button) button.disabled = launching || button.dataset.ready !== 'true';
  }
  function render({ review }) {
    const summary = review.summary;
    const isTest = Boolean(summary.is_test);
    const timeline = ['準備', '検証', '配信', '観測', 'フォロー', '報告']
      .map((label, index) => `<li class="${index === 1 ? 'is-current' : index === 0 ? 'is-complete' : ''}"><span>${index + 1}</span>${label}</li>`).join('');
    const blockerHtml = review.blockers.length
      ? `<div class="campaign-review-issues" role="alert"><h3>開始できない理由</h3><ul>${review.blockers.map((issue) => `<li>${escapeHtml(issue)}</li>`).join('')}</ul></div>`
      : '<div class="campaign-review-ready" role="status">配信前チェックを通過しました。内容と宛先を確認してください。</div>';
    const warningHtml = review.warnings.length
      ? `<div class="campaign-review-warnings"><h3>確認事項</h3><ul>${review.warnings.map((warning) => `<li>${escapeHtml(warning)}</li>`).join('')}</ul></div>` : '';
    const contentHtml = review.contents.map((content, index) => {
      const subject = content.subject_name;
      const body = content.body_name;
      const mode = modeLabel[content.link_mode] || '配信形式不明';
      return `<li class="campaign-review-content"><span class="campaign-review-sequence">${index + 1}</span>
        <div><strong>${escapeHtml(subject)}</strong><div class="small text-muted">${escapeHtml(body)}</div>
          <div class="campaign-review-flow">メール <span aria-hidden="true">→</span> ${escapeHtml(mode)} <span aria-hidden="true">→</span> 到達画面 <span aria-hidden="true">→</span> 種明かし</div></div></li>`;
    }).join('');
    const distribution = summary.test_distribution.length
      ? `<div class="campaign-review-distribution"><h3>転送先ごとの予定通数</h3><ul>${summary.test_distribution.map((row) =>
          `<li><span>${escapeHtml(row.email)}</span><strong>${count(row.count)}通</strong></li>`).join('')}</ul></div>` : '';

    root.innerHTML = `<div class="campaign-review">
      <header class="campaign-review-heading"><button type="button" class="btn btn-link p-0" data-campaign-action="back">← 訓練一覧</button>
        <p class="campaign-review-eyebrow">訓練 / 配信前確認</p><div class="d-flex align-items-center flex-wrap gap-2">
          <h2>${escapeHtml(summary.campaign_name)}</h2><span class="campaign-review-mode ${isTest ? 'is-test' : 'is-production'}">${isTest ? 'TEST・全件転送' : '本番'}</span>
        </div></header>
      <ol class="campaign-review-timeline" aria-label="運用の進行段階">${timeline}</ol>
      <div class="campaign-review-layout"><div>
        <section class="campaign-review-section"><h3>配信前チェック</h3>${blockerHtml}${warningHtml}
          <p class="small text-muted mb-0">確認後に設定・対象者・テンプレートが変わると、開始時に再確認が必要です。</p></section>
        <section class="campaign-review-section"><h3>シナリオと計測の流れ</h3>
          <ol class="campaign-review-contents">${contentHtml || '<li>コンテンツがありません</li>'}</ol></section>
      </div><aside class="campaign-review-manifest" aria-label="送信マニフェスト">
        <p class="campaign-review-eyebrow">送信マニフェスト</p><h3>誰に、何通、いつ送るか</h3>
        <dl><div><dt>対象者</dt><dd>${count(summary.target_count)}名</dd></div>
          <div class="is-total"><dt>総通数</dt><dd>${count(summary.send_count)}通</dd></div>
          <div><dt>コンテンツ</dt><dd>${count(summary.content_count)}件</dd></div>
          <div><dt>開始</dt><dd>${escapeHtml(summary.start_at || '未設定')}</dd></div>
          <div><dt>終了</dt><dd>${escapeHtml(summary.end_at || '未設定')}</dd></div></dl>
        ${distribution}
        <p class="small text-muted">${isTest ? '全送信行をTEST宛先へ転送します。元の対象者へは送信しません。' : '本番の対象者へ送信します。開始日時に達すると送信ワーカーが処理します。'}</p>
        <div class="campaign-review-actions"><button type="button" class="btn btn-outline-secondary" data-campaign-action="edit">設定を修正</button>
          <button type="button" class="btn btn-outline-primary" data-campaign-action="refresh">配信前チェックを更新</button>
          <button type="button" class="btn btn-primary" data-campaign-action="launch" data-ready="${review.can_launch ? 'true' : 'false'}" ${review.can_launch ? '' : 'disabled'}>配信を予約する</button></div>
      </aside></div></div>`;
  }
  return { loading, error, render, setLaunching };
}

window.createCampaignWorkspace = createCampaignWorkspace;

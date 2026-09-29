'use strict';
/* 段D の送信(D-a)の設定の画面。どれも既定は切で、保存してもその場では送らない。
 *   - 自動の催促の設定(D1): 教育配信の編集と、開始後の配信の「催促の設定」
 *   - 種明かしメール(D2): キャンペーンの一覧の「種明かしメール」(オペレータ以上)
 *   - 担当者への報告の通知(D3): 不審メールの画面の「担当者への通知」(組織管理者以上)
 * app.js の api / $ / esc / toast / showModal / State / roleAtLeast / fmtDate を使う。 */

/* ---------- D1 自動の催促 ---------- */

/** 催促の設定の欄(教育配信の編集と、開始後の設定で共通)。空欄は既定(今までと同じ動き)。 */
function eduReminderFields(prefix, values = {}) {
  const num = (key) => (values[key] === null || values[key] === undefined ? '' : Number(values[key]));
  return `<fieldset class="border rounded p-2 mb-2" id="${prefix}Fieldset"><legend class="form-label small fw-semibold float-none w-auto px-1 mb-0">自動の催促（未受講の人へ）</legend>
    <div class="row g-2">
      <div class="col-6"><label class="form-label small" for="${prefix}Interval">何日ごとに送るか</label>
        <input class="form-control form-control-sm" type="number" min="1" max="30" id="${prefix}Interval" data-remind="remind_interval_days" value="${num('remind_interval_days')}" placeholder="3（既定）"></div>
      <div class="col-6"><label class="form-label small" for="${prefix}Start">期限の何日前から送るか</label>
        <input class="form-control form-control-sm" type="number" min="1" max="60" id="${prefix}Start" data-remind="remind_start_days" value="${num('remind_start_days')}" placeholder="開始から（既定）"></div>
      <div class="col-6"><label class="form-label small" for="${prefix}Max">上限の回数</label>
        <input class="form-control form-control-sm" type="number" min="1" max="20" id="${prefix}Max" data-remind="remind_max_count" value="${num('remind_max_count')}" placeholder="上限なし（既定）"></div>
      <div class="col-6 d-flex align-items-end"><div class="form-check">
        <input class="form-check-input" type="checkbox" id="${prefix}After" data-remind-flag="remind_after_deadline"${Number(values.remind_after_deadline) === 1 ? ' checked' : ''}>
        <label class="form-check-label small" for="${prefix}After">期限の後も送る（上限の回数まで）</label></div></div>
    </div>
    <div class="form-text">空欄は今までと同じ（3日ごと、期限まで）。期限の後も送るには、上限の回数と「期限の後も受講できるようにする」が要ります。自動の催促は、自動の処理（tet2-edu-reminder）を動かしている時だけ送ります。</div>
  </fieldset>`;
}

/** 催促の設定の値(API に送る形)。 */
function eduReminderValues(root) {
  const out = {};
  root.querySelectorAll('[data-remind]').forEach((input) => {
    const v = input.value.trim();
    out[input.dataset.remind] = v === '' ? null : Number(v);
  });
  root.querySelectorAll('[data-remind-flag]').forEach((input) => { out[input.dataset.remindFlag] = input.checked; });
  return out;
}

/** 開始後の配信の催促の設定だけを変える。 */
async function openEduReminderSettings(id) {
  let delivery;
  try { ({ delivery } = await api('api/edu_deliveries.php', { query: { action: 'get', id } })); }
  catch (e) { toast(e.message, 'err'); return; }
  const note = Number(delivery.allow_after_deadline) === 1 ? '' : '<div class="small text-muted mb-2">この配信は期限の後の受講を許していないので、期限の後は送れません。</div>';
  showModal(`催促の設定: ${delivery.title}`, `<form id="eduReminderForm">${note}${eduReminderFields('eduRem', delivery)}</form>`, async () => {
    await api('api/edu_deliveries.php', { method: 'POST', query: { action: 'reminder' }, body: { id, ...eduReminderValues($('#eduReminderForm')) } });
    toast('催促の設定を保存しました', 'ok');
  });
}

/* ---------- D2 種明かしメール ---------- */

const REVEAL_SCOPE_LABEL = { all: '送った全員', failed: '防衛に失敗した人だけ', not_failed: '失敗しなかった人だけ' };

async function openRevealMailSettings(campaignId) {
  let data;
  try { data = await api('api/reveal_mail.php', { query: { action: 'get', campaign_id: campaignId } }); }
  catch (e) { toast(e.message, 'err'); return; }
  const s = data.settings;
  const sent = (kind) => Number(data.sent?.[kind]?.sent || 0);
  const scopeOpts = Object.entries(REVEAL_SCOPE_LABEL).map(([v, l]) => `<option value="${v}"${s.close_scope === v ? ' selected' : ''}>${l}</option>`).join('');
  const testNote = data.campaign.is_test ? '<div class="alert alert-warning py-2 small">テスト送信の訓練には、どの条件でも送りません。</div>' : '';
  const state = data.campaign.closed_at ? `クローズ済み（${esc(fmtDate(data.campaign.closed_at))}）` : '実施中（クローズ前）';
  const body = `<form id="revealMailForm">
    ${testNote}
    <p class="small text-muted mb-2">訓練: ${esc(data.campaign.name)}・${state}。既定はすべて送りません。保存してもその場では送らず、自動の処理（tet2-reveal-mail）を動かしている時と、訓練をクローズした時に送ります。文面は「ユーザ管理 → 通知の文面」で変えられます。</p>
    <div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="rmOnFail"${s.on_fail ? ' checked' : ''}>
      <label class="form-check-label" for="rmOnFail">防衛に失敗した直後に、その本人へ送る</label></div>
    <div class="form-text mb-2">リンクを開いたか入力した本人にだけ、訓練の実施中でも送ります。入れた後の失敗だけが対象です。送った数: ${sent('reveal_failed')}</div>
    <div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="rmOnClose"${s.on_close ? ' checked' : ''}>
      <label class="form-check-label" for="rmOnClose">訓練をクローズした後に送る</label></div>
    <div class="ms-4 mb-1"><label class="form-label small mb-0" for="rmScope">送る範囲</label>
      <select class="form-select form-select-sm w-auto" id="rmScope">${scopeOpts}</select></div>
    <div class="form-text mb-2">訓練の測定が終わってから、訓練のメールを送った人に送ります。送った数: ${sent('reveal_closed')}</div>
    <div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="rmOnReport"${s.on_report ? ' checked' : ''}>
      <label class="form-check-label" for="rmOnReport">訓練のメールを報告した人へ送る（クローズした後）</label></div>
    <div class="form-text mb-2">実施中に送ると測定が崩れるので、クローズした後に送ります。送った数: ${sent('reveal_reported')}</div>
    <div class="small text-muted">届かない宛先、在籍していない人、送っていない人には送りません。1人に1つの条件で1通だけです。</div>
  </form>`;
  showModal('種明かしメール', body, async () => {
    await api('api/reveal_mail.php', { method: 'POST', query: { action: 'save' }, body: {
      campaign_id: campaignId, on_fail: $('#rmOnFail').checked, on_close: $('#rmOnClose').checked,
      close_scope: $('#rmScope').value, on_report: $('#rmOnReport').checked,
    } });
    toast('種明かしメールの設定を保存しました', 'ok');
  });
}

/* ---------- D3 担当者への報告の通知 ---------- */

async function openReportNotifySettings() {
  let data;
  try { data = await api('api/report_notify.php', { query: { action: 'get' } }); }
  catch (e) { toast(e.message, 'err'); return; }
  const since = data.since ? `<div class="small text-muted mb-2">通知の開始: ${esc(fmtDate(data.since))}（これより後に取り込んだ報告を知らせます）</div>` : '';
  const body = `<form id="reportNotifyForm">
    ${since}
    <label class="form-label" for="rnEmails">通知先のメールアドレス（1行に1つ、${Number(data.limits.emails)}件まで）</label>
    <textarea class="form-control" id="rnEmails" rows="4" placeholder="security@example.co.jp">${esc((data.emails || []).join('\n'))}</textarea>
    <div class="form-text">報告用のアドレスに不審メールの報告が届いたら、社内の担当者へ知らせます（訓練のメールの報告とアップロードは除く）。空にすると知らせません（既定）。
      通知には件名・差出人・報告者・受信日時だけを入れ、本文と添付は入れません。1時間に${Number(data.limits.per_window)}通までで、超えた分は後で送ります。
      自動の処理（tet2-reveal-mail）を動かしている時だけ送ります。</div>
  </form>`;
  showModal('担当者への報告の通知', body, async () => {
    await api('api/report_notify.php', { method: 'POST', query: { action: 'save' }, body: { emails: $('#rnEmails').value } });
    toast('通知先を保存しました', 'ok');
  });
}

document.getElementById('smNotifyBtn')?.addEventListener('click', () => openReportNotifySettings());

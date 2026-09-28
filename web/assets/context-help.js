'use strict';

// 画面に紐づく一次HELP。顧客固有情報やログ本文はここへ埋め込まない。
const TET2_HELP_ARTICLES = Object.freeze([
  { id: 'first-campaign', title: '初めての訓練', views: ['dashboard', 'campaigns', 'campaignWorkspace'], reviewed: '2026-09-23',
    summary: '対象者、送信環境、テスト、配信、結果の順に確認します。',
    body: '対象者と内容を登録し、生成確認で通数と差し込みを確認します。TESTキャンペーンで全件転送を試し、受信結果を確認してから本番キャンペーンを開始します。保存や下書き作成だけでは送信されません。' },
  { id: 'test-delivery', title: 'テスト送信と通数', views: ['campaigns', 'campaignWorkspace', 'campaignAutomations'], reviewed: '2026-09-23',
    summary: '現行のTESTは全件転送テストです。',
    body: '全件転送では、対象者・コンテンツの全送信行を指定したテスト宛先に均等分配します。宛先が空または未指定なら配信を拒否します。少量プレビューは未実装です。TESTでは元の対象者の差し込み・追跡情報を検証し、本番対象へ送信しません。' },
  { id: 'delivery-trouble', title: 'メールが届かないとき', views: ['campaigns', 'logs'], reviewed: '2026-09-23',
    summary: '未実行、SMTP受付、受信側隔離を切り分けます。',
    body: 'まずキャンペーン状態と送信停止表示を確認します。次に配信ログでSMTP受付・エラーを確認し、受付済みなら受信側の隔離・許可リストを調べます。SMTP受付は受信箱への到達や閲読の保証ではありません。' },
  { id: 'metrics', title: '指標と計測の意味', views: ['reports', 'riskDashboard', 'targetStats', 'logs'], reviewed: '2026-09-23',
    summary: 'サイト表示、入力行動、報告、教育完了を区別します。',
    body: '現行のビーコンは偽サイト側にあり、メールの開封を測っていません。「サイト表示」はリンク等から到達した記録です。新規生成の偽ログイン画面は入力値を送らず送信操作だけを記録します。既存の配信済み画面は移行確認中です。「報告」は照合・確定された記録で、未照合は含みません。比率を見る際はTEST除外と対象人数を確認してください。' },
  { id: 'scanner', title: '操作していないのに計上された', views: ['reports', 'riskDashboard', 'logs'], reviewed: '2026-09-23',
    summary: 'メール保護製品などによる自動アクセスの可能性があります。',
    body: 'アクセスの時刻、IP、UserAgentとシステム判定を確認します。判定不能は人間の操作と断定しません。訂正が必要な場合は元記録を保持したまま運用担当へ相談してください。確定済みレポートは無言で上書きしません。' },
  { id: 'reporting', title: '不審メールの報告方法', views: ['logs', 'suspiciousMails'], reviewed: '2026-09-23',
    summary: '訓練報告と実メールの解析は別の受付です。',
    body: '顧客ごとに指定された報告先・添付転送方法を確認します。通常転送では元メールのヘッダーや添付が欠ける場合があります。Outlook標準の報告ボタンがTET2の結果へ自動反映されるとは限りません。未照合は担当者が確認します。' },
  { id: 'education', title: '教育の期限と催促', views: ['eduDeliveries', 'eduQuestions', 'eduReport'], reviewed: '2026-09-23',
    summary: '割当、受講、完了、期限超過を区別します。',
    body: '配信ごとの対象者と期限を確認します。完了者へ重複して催促しないよう対象を見直してください。自動催促の稼働は環境設定に依存します。リンク期限切れや再開できない場合は、配信の状態を運用担当に確認してください。' },
  { id: 'monthly-report', title: '月次報告を作る', views: ['reports', 'eduReport', 'riskDashboard'], reviewed: '2026-09-23',
    summary: '本番・TEST、観測期間、確定状態をそろえて出力します。',
    body: 'キャンペーンと期間を指定し、報告の照合状況、システムアクセスの除外、母数を確認します。TESTは本番成果に混ぜません。前月比較は対象者・難易度・観測期間が異なる場合に注意書きを付けます。暫定値を確定済みと表現しないでください。' },
]);

function createContextHelp(root, getView) {
  const input = root.querySelector('[data-help-search]');
  const list = root.querySelector('[data-help-list]');
  const title = root.querySelector('[data-help-title]');
  const body = root.querySelector('[data-help-body]');
  let selectedId = null;
  let previousView = null;
  function render(view = getView()) {
    const query = input.value.trim().toLocaleLowerCase('ja');
    const matches = TET2_HELP_ARTICLES.filter((article) => !query || `${article.title} ${article.summary} ${article.body}`.toLocaleLowerCase('ja').includes(query));
    matches.sort((a, b) => Number(b.views.includes(view)) - Number(a.views.includes(view)));
    list.replaceChildren();
    for (const article of matches) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `help-article-link${article.views.includes(view) ? ' is-contextual' : ''}`;
      button.textContent = article.title;
      button.addEventListener('click', () => select(article.id));
      list.append(button);
    }
    if (!matches.length) list.textContent = '一致する記事はありません。';
    if (previousView !== view && !query) selectedId = null;
    previousView = view;
    if (!selectedId || !TET2_HELP_ARTICLES.some((article) => article.id === selectedId && matches.includes(article))) {
      select(matches[0]?.id || null);
    }
  }
  function select(id) {
    selectedId = id;
    const article = TET2_HELP_ARTICLES.find((item) => item.id === id);
    title.textContent = article?.title || '記事を選択してください';
    body.replaceChildren();
    if (!article) return;
    const summary = document.createElement('p');
    summary.className = 'fw-semibold';
    summary.textContent = article.summary;
    const detail = document.createElement('p');
    detail.textContent = article.body;
    const reviewed = document.createElement('p');
    reviewed.className = 'small text-muted';
    reviewed.textContent = `確認日: ${article.reviewed}`;
    body.append(summary, detail, reviewed);
  }
  input.addEventListener('input', () => render());
  // 閉じる時は短い動きのあとで隠す(動きを止める設定でも animationend か時間切れで必ず隠す)
  let closeTimer = null;
  function hide() {
    clearTimeout(closeTimer);
    root.classList.remove('is-closing');
    root.classList.add('d-none');
  }
  root.addEventListener('animationend', (event) => { if (event.animationName === 'helpOut') hide(); });
  return {
    render,
    open() { clearTimeout(closeTimer); root.classList.remove('is-closing', 'd-none'); render(); input.focus(); },
    close() {
      if (root.classList.contains('d-none')) return;
      root.classList.add('is-closing');
      closeTimer = setTimeout(hide, 250);
    },
  };
}

window.TET2_HELP_ARTICLES = TET2_HELP_ARTICLES;
window.createContextHelp = createContextHelp;

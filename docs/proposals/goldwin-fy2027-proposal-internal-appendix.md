# 【社内用・顧客非開示】ゴールドウイン様 2027 年度提案 実装状況対応表

作成: 2026-09-16。
最終更新: 2026-09-17。

提案書 `goldwin-fy2027-proposal.md` の8機能をTET2の実装状況と開発計画（`../market-research-and-feature-plan-2026-09.md`）に紐付ける。

| # | 提案書の機能 | 実装状況 | 根拠 | 未実装分の計画 |
|---|---|---|---|---|
| ① | 継続キャンペーン | 実装済み。rule から draft 自動生成、rotation（pool から従業員ごとに異なるコンテンツ）、営業時間内送信、緊急停止。自動送信はしない方針 | `campaign_automations`、`docs/product-roadmap.md` P1 | MFA 窃取型・ClickFix 型はフェーズ 3（候補 F、1 週） |
| ② | 個人・組織リスク評価 | 実装済み。`HumanRiskScore`、日次計算、`human_risk_scores` | `docs/kpi-baseline-2026-09.md` | — |
| ③ | リスクダッシュボード | 実装済み。`risk-dashboard.js`、`risk_recommendations` API | project_tet_v2 2026-09-05 | 部署間比較・前年比の表示強化はフェーズ 1 候補 C の延長 |
| ④ | スライド型 e ラーニング（その場介入） | 部分実装。スライド型教材の作成・差し替え・試行に加え、PowerPoint（`.pptx`）からタイトルと本文を取り込める。訓練失敗者の自動割当コードはあるが`tet2-edu-enroll.timer`は停止中。種明かしページ内クイズと閲覧記録は未実装 | [`education-material-bank.md`](../education-material-bank.md)、`edu_materials`、`OfficeDocumentReader` | フェーズ2候補B（3週）。実在従業員への配信有効化はユーザー明示指示が必須 |
| ⑤ | 理解度テスト | 実装済み。12カテゴリ100問、`elearning` / `awareness_quiz`、token方式ポータル`sat.cojp.online`を使用。全カテゴリ一覧、試行、回答付き全設問一覧、Excelテンプレート、全設問出力、Excel一括追加に対応 | [`education-material-bank.md`](../education-material-bank.md)、`edu_questions`、`education_office_e2e.mjs` | 四半期出題は`campaign_automations`同様の定期配信ruleが要る（未実装、小） |
| ⑥ | アンケート | **未実装**。`edu_deliveries` に採点なしの配信タイプを追加し、設問を自由記述・単一選択対応にすれば既存ポータルで実現できる見込み | — | 新規。フェーズ 2 に併載（見積 1〜2 週） |
| ⑦ | 不審メール受付・調査 | 部分実装。報告用アドレス`report@gwin.gr.cojp.online`と`ReportMailIngest`（5分毎）は本番導入済み。管理画面で取込一覧、状態filter、保留行の確定・却下を操作できる。`TET2_REPORT_INGEST_MODE=match_only`のため自動ではeventsを書かない。報告者への自動feedbackと実攻撃メールの安全性調査は未実装 | [`report-mail-rollout-2026-09.md`](../report-mail-rollout-2026-09.md)、`ReportMailIngest`、`api/logs.php` | `normal`モード切替は実メーラー3種の回収率確認後。feedbackはロードマップP2。実攻撃メールの調査機能は新規（見積2〜3週） |
| ⑧ | マイページ（個人ログイン・セルフ成績確認） | **未実装**。フェーズ 4 候補 H（顧客閲覧ポータル）は管理者向け viewer 想定で、従業員個人向けは別設計（個人認証、本人分のみのスコープ、sat と別ホスト） | — | 新規。OWASP 診断・実 HTTP 検証・`/e2e` `/security-audit` をリリース条件にする（見積 4 週以上） |
| — | 経営層向け PDF 報告書・ガイドライン対応表 | 未実装 | 計画 候補 E | フェーズ 4（3 週） |
| — | 受講記録・修了証 | 未実装 | 計画 候補 G | フェーズ 2（1 週） |

## 提案書で約束した時期と開発順の整合

- 2027 年度開始時（4 月）: ⑦ 受付・自動照合 → `match_only` から `normal` へ切り替えて events を書く。これが最小の前提作業
- 2027 年度前半（4〜9 月）: ④ ⑥ 受講記録・修了証・⑦ 調査画面・① 新シナリオ 2 種・③ 表示強化
- 2027 年度後半（10〜3 月）: ⑧ マイページ・経営層向け PDF

計画書の週数（フェーズ 2: 4 週、フェーズ 3: 6 週、フェーズ 4: 6 週）を足すと約 16 週で、⑥ ⑧ の新規分（約 6 週）を加えて 22 週前後。2026 年 10 月着手なら 2027 年 9 月までに前半分が収まる。**⑧ は後半に置いたが、個人認証の設計次第で伸びる可能性が最も高い。**

## 提案書に書かなかったこと

- 価格・見積（別途）
- SMS 訓練、Teams/Slack 経路、音声・ディープフェイク（計画で後回し）
- 数値は `kpi-baseline-2026-09.md` の id=104 分のみ。研究知見の 40% / 37% は `market-research-and-feature-plan-2026-09.md` 4.4 節の値

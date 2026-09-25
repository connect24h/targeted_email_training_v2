# 03. 詳細設計書

対象システム: TET v2（標的型メール訓練およびセキュリティ教育システム）
版: 2026-09-24 時点の実装（リポジトリ HEAD `9aafa33`）に基づく。
位置づけ: 本書は API の仕様、データベーススキーマ、マイグレーション、送信処理とタイマーの動作、生成物を定める。全体の構成は基本設計書を参照する。

## 1. API 仕様

すべての API は `web/api/` に置き、`?action=` で操作を選ぶ。参照は GET で viewer 以上、変更は POST で operator 以上とし、変更には CSRF トークン（`X-CSRF-Token`）を伴う。外部連携（`api/integrations/`）は Bearer 認証を用いる。

### 1.1 認証とテナント

| ファイル | action | 概要 |
|---|---|---|
| auth.php | login / logout / me | ログイン、ログアウト、現在の利用者情報 |
| tenants.php | create / list / update | テナントの作成、一覧、更新（superadmin） |
| users.php | create / delete / list / update | 管理アカウント（role: superadmin / tenant_admin / operator / viewer） |

### 1.2 対象者、グループ、役職、テンプレート

| ファイル | action | 概要 |
|---|---|---|
| targets.php | create / delete / get / list / update / import_csv / export_csv / restore | 対象者。論理削除と復元、CSV 入出力 |
| groups.php | create / delete / list / update / members / add_targets / remove_targets | グループとメンバー |
| positions.php | list / create / update / delete / apply / by_company / by_position / coverage | 役職マスタ、対象者への適用、集計 |
| templates.php | list / get / create / update / delete / create_scenario / import_csv / export_csv | テンプレート（件名、本文、偽ログイン、種明かし、eラーニング） |
| master_upload.php | get / upload | 偽ログインページ用 HTML |

### 1.3 キャンペーン

| ファイル | action | 概要 |
|---|---|---|
| campaigns.php | list / get / create / update / delete / duplicate / rename / cancel / set_test / beacon_bases | キャンペーンの CRUD、複製、名称変更、取消、テスト切替 |
| campaign_generate.php | generate | 生成 |
| campaign_launch.php | preflight / launch / stop / resume / to_draft / progress | 配信前確認、起動、停止、再開、下書き戻し、進捗 |
| campaign_files.php | list / content | 生成物の一覧と内容 |
| campaign_automations.php | list / get / create / update / delete / pause / resume / preview / generate_now | 定期キャンペーン（下書き生成のみ） |
| send_control.php | status / alerts / clear_alerts | 送信の状態とアラート |

### 1.4 レポートとログ

| ファイル | action | 概要 |
|---|---|---|
| report.php | summary / detail / campaigns / beacons / individuals / export_xlsx / ingest / commit / uncommit / close / risk_by_company / risk_individuals / risk_recommendations | 集計、Excel 書き出し、確定と解除、クローズ、リスク集計 |
| logs.php | delivery / events / schedule / audit / raw_mail / raw_web / webaccess / webaccess_geoip / training_results / training_log_detail / training_log_geoip / report_mail / report_mail_confirm / report_mail_reject / reply_maildir / campaign_files（各 _csv / _xlsx を含む） | ログ管理、報告メールの確定と却下、地域推定 |
| followup.php | failures / to_group | 失敗者の抽出とグループ化 |

### 1.5 承認制の入力値収集

| ファイル | action | 概要 |
|---|---|---|
| credential_capture.php | （公開受付、POST） | 偽ログインページからの入力受付 |
| credential_captures.php | list / reveal / approve | 収集本文の一覧、復号閲覧、承認（システム管理者専用） |
| url_check.php | （URL 判定） | URL 検査 |

### 1.6 教育

| ファイル | action | 概要 |
|---|---|---|
| edu_categories.php | list / get / create / update / delete / fork | 教材カテゴリ |
| edu_materials.php | list / create / update / import_pptx | 教材、PowerPoint 取込 |
| edu_questions.php | list / get / create / update / delete / import_xlsx / export_xlsx / template_xlsx | 設問、Excel 入出力 |
| edu_deliveries.php | list / get / create / update / delete / launch / remind | 教育配信 |
| edu_take.php | start / submit | 受講（トークン型） |
| edu_report.php | overview / delivery / deliveries / cross / trend | 教育レポート |

### 1.7 不審メール

| ファイル | action | 概要 |
|---|---|---|
| suspicious_mails.php | list / get / upload / reanalyze / reputation / update | 不審メールの受付、解析、レピュテーション照会 |

### 1.8 アンケート

| ファイル | action | 概要 |
|---|---|---|
| surveys.php | list / get / templates / create / update / duplicate / delete / deliveries / deliver / close / results / tokens_csv / export_csv / export_xlsx / send_invitations / remind | アンケートの作成と編集（下書きのみ）、配信（対象の固定とトークン発行）、集計と出力。`tokens_csv` は GET でも operator 以上。送信の2操作は `TET2_SURVEY_MAIL_ENABLED=1` のときだけ動き、既定は 409 |
| survey_take.php | start / submit | 回答（トークン型、認証なし）。回答済み、期限切れ、終了した配信は拒否 |

## 2. データベーススキーマ

SQLite（`/opt/training/tet2-db/tet2.sqlite`、WAL）。スキーマは `web/db/schema*.sql` の11ファイルに分かれる。すべての業務テーブルは `tenant_id` を持ち、リポジトリ層で分離する（一部の連携用テーブルを除く）。

### 2.1 中核（schema.sql）

| テーブル | 主な列 |
|---|---|
| tenants | id, name, slug, data_dir, status |
| users | id, tenant_id, email, password_hash, name, role, status, failed_count, locked_until, last_login_at |
| targets | id, tenant_id, email, name, company, department, title, position_category, tenant_no, status, archived_at, is_test |
| groups / target_group | グループと、対象者との対応 |
| templates | id, tenant_id, kind, name, lang, format, content, auth_flag, is_preset, scenario_key |
| campaigns | id, tenant_id, name, status, subject/body/phish_template_id, from_address, from_domain, link_mode, attachment_ext/filename/zip, send_mode, split_count, split_interval_min, weekdays_only, business_start/end, start_at, end_at, is_test, data_dir, beacon_base, content_delivery, deleted_at, closed_at, closed_by, credential_capture_approval_ref, test_redirect_emails, created_by |
| campaign_targets | id, campaign_id, target_id, tracking_id, koban, auth_flag, from_address, attachment_path, send_status, sent_at, content_no |
| campaign_contents | id, campaign_id, content_no, subject/body/phish_template_id, link_mode, attachment_*, from_address, beacon_base, suppress_body_url, suppress_prefill_email |
| send_schedule | id, campaign_id, batch_no, scheduled_at, koban_from/to, interval_sec, status, claimed_at, worker_pid, attempts |
| delivery_log | id, campaign_id, tracking_id, to_email, result, smtp_message, occurred_at |
| events | id, tenant_id, campaign_id, tracking_id, event_type, auth_variant, occurred_at, source, raw |
| audit_log | id, tenant_id, user_id, action, detail, ip, occurred_at |
| campaign_report_snapshots | 確定した集計の保存 |
| integration_idempotency_keys | 外部連携の重複防止 |
| schema_migrations | 適用済みマイグレーションの記録 |

### 2.2 機能別スキーマ

| ファイル | テーブル |
|---|---|
| schema-automation.sql | campaign_automations, campaign_automation_groups, campaign_automation_runs |
| schema-position.sql | position_masters |
| schema-risk.sql | human_risk_scores |
| schema-report-mail.sql | report_mails, report_mail_matches |
| schema-suspicious-mail.sql | suspicious_mails, suspicious_mail_history, reputation_cache |
| schema-template-snapshots.sql | campaign_template_snapshots |
| schema-credential-captures.sql | credential_captures |
| schema-survey.sql | surveys, survey_questions, survey_deliveries, survey_assignments, survey_responses, survey_answers |
| schema-edu.sql | edu_categories, edu_materials, edu_questions, edu_deliveries, edu_delivery_questions, edu_delivery_targets, edu_assignments, edu_responses, edu_response_answers, edu_score_snapshots |

## 3. マイグレーション

スキーマの変更は `web/db/MigrationRunner.php` の版一覧に登録する。`schema*.sql` を直すだけでは合成テストは通るが本番へは届かない。新しいテーブルはテスト用のデータベース定義（`web/tests/fixtures/TestDatabase.php`）にも足す。

登録済みの版（18件、新しい順の一部）は次のとおりである。

- 20260925-surveys（アンケート）
- 20260923-campaign-close（統計確定後のクローズ）
- 20260923-credential-captures（承認制の入力値収集）
- 20260923-template-snapshots（配信時のテンプレート不変記録）
- 20260917-suspicious-mail（不審メール）
- 20260906-report-mail-ingest（報告メール取込）
- 20260819-attachment-filename-prefix
- 20260816-human-risk-score（リスク）
- 20260812-suppress-prefill-email
- 20260810-position-masters / all-members-group / elearning-materials
- 20260809-campaign-automations / campaign-rotation / awareness-participant-integration / targets-archived-at / targets-is-test
- 20260808-current-schema（基準）

本番への適用は、複製したデータベースで確認（dry-run）してから、`--apply` と、本番の場合はさらに `--allow-production` を付けて行う。適用前には必ずバックアップを取る。

## 4. 送信処理とタイマー

### 4.1 送信ワーカー

`tet2-worker.py` は常駐し、送信スケジュール（`send_schedule`）を監視する。配信中のキャンペーンの送信期限が来たバッチ（`status` が完了でも失敗でもない行）を取り出し、生成済みデータを再検証してから `send_email.py` で送る。
送信の各通は、SMTP へ渡す直前に対象者の在籍状態、所属、元のメールアドレスを再確認する。必須の添付が欠けていたり読めなかったりする場合は、本文だけで送らずキャンペーンを一時停止する。途中で停止した場合も、CSV で送信済みと確認できる行はデータベースへ同期する。

### 4.2 タイマー

systemd タイマーで定期処理を行う。周期は次のとおりである。

| ユニット | 実行内容 | 周期 | 既定 |
|---|---|---|---|
| tet2-worker.service | 送信ワーカー | 常駐 | 有効 |
| tet2-maildir-perms.timer | Maildir の権限補正 | 5分ごと | 有効 |
| tet2-campaign-automation.timer | 定期キャンペーンの下書き生成 | 毎時20分 | 有効 |
| tet2-report-ingest.timer | 報告メールの取込 | 5分ごと | 有効 |
| tet2-edu-snapshot.timer | 教育スコアの集計 | 毎日 02:30 | 有効 |
| tet2-edu-enroll.timer | 教育の割当 | 毎時10分 | 停止 |
| tet2-edu-reminder.timer | 教育の催促 | 毎日 09:00 | 停止 |

教育の割当と催促は、実在の従業員へメールを送るため、既定で停止している。

## 5. 生成物

キャンペーンの起動時に、次を生成する。

- 送信用 CSV（`PipelineRunner` が生成、追跡 ID は先頭のゼロを保つため全列を文字列として扱う）。
- 偽サイトのビーコン、リンク、添付（`create_beacon_files.py`）。
- QR コード付き文書（`qr_doc_gen.py`、docx / pdf / html）。

生成物はキャンペーンごとのデータ保存先に置く。全行の必須生成物を検証し、成功したときにだけ送信スケジュールを作る。

## 6. 検証報告

- API 仕様: `web/api/*.php` の action を抽出して一覧化した（27ファイル）。証拠は素材メモ `tet2-doc-facts.md` の API 表。
- スキーマ: `web/db/schema*.sql` の11ファイルから CREATE TABLE を抽出し、中核テーブルの主な列を実列名で記載した。
- マイグレーション: `MigrationRunner.php` の版一覧17件を確認して記載した。
- タイマー: systemd ユニットの実行コマンドと OnCalendar / OnUnitActiveSec を読んで周期を記載し、教育の割当と催促が既定停止であることを確認した。
- 未確認: 本番データベースの実際の列の値と、タイマーの現在の有効と無効の状態は本書作成時に確認していない。既定の設定として記述した。
- 一番弱い箇所: 各 API のパラメータの完全な一覧までは載せていない。action と権限と役割の粒度にとどめたため、リクエストの全パラメータが要る場合は実コードを参照する必要がある。

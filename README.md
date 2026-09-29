# TET v2 — 標的型メール訓練 + セキュリティ教育システム

マルチテナント対応の標的型攻撃メール訓練（Targeted Email Training）と、
訓練失敗者向けセキュリティ教育を統合したシステム。素 PHP 8.4 + 素 Python 3.13
（フレームワーク不採用）。v1（`connect24h/targeted_email_training`）の後継。

## リポジトリ構成

```
web/        Web アプリ本体（本番: /var/www/html/tet2）
  api/        REST API（?action= パターン、CSRF/セッション認証、連携APIはBearer認証）
  lib/        リポジトリ層・PipelineRunner 等
  db/         現行スキーマ、migration runner、seed スクリプト
  assets/     SPA アセット（app.css, vendor/ にBootstrap/Chart.jsローカル配置）
  tests/      PHP テスト（合成DBを使い tests/run.sh で一括実行）
  index.html app.js take.php  SPA エントリ + 受講ページ
bin/        メール送信処理（本番: /opt/training/bin）
  tet2-worker.py       send_schedule ポーリング → 送信起動（systemd常駐）
  send_email.py        SMTP(localhost:25) 送信本体（v1由来・共用）
  create_beacon_files.py  ビーコン/リンク/添付生成
  qr_doc_gen.py        QR埋め込み文書（docx/pdf/html）生成
  tet2-purge-campaigns.py  論理削除済みキャンペーンの物理パージ
  replace_url.py / training_config.py / requirements.txt / config.ini
  master*.html / __BeaconMst.png  生成用HTML・ビーコン素材
deploy/systemd/  systemd unit 17本（worker + Maildir権限補正 + automation + edu enroll/reminder/snapshot/scheduler + report-ingest + delivery-ingest）。edu scheduler と delivery-ingest の timer は既定で無効
deploy/cron/     論理削除済みキャンペーンのパージ定義
deploy/*.sh      dry-run既定のallowlist deploy / rollback / backup prune
```

## 本番配置と DB

- Web: `/var/www/html/tet2`（受講者ポータル = `sat.cojp.online`）
- メール送信: `/opt/training/bin`
- DB: `/opt/training/tet2-db/tet2.sqlite`（Web到達不可・WAL・busy_timeout）
  - 全業務テーブルに `tenant_id`、`Db::forTenant()` で論理分離
  - **DB 実体・個人情報・ログは Git 管理外**（`.gitignore` 参照）。スキーマと seed のみ管理

## メール送信フロー

キャンペーンの下書き編集は「基本情報→対象者→シナリオ→日時・送信量→送信環境→最終確認」の6手順で表示する。「すべて表示」で一覧編集に切り替えられ、どちらも同じ下書きを保存する。保存だけでは配信されない。
下書きの「配信前確認」では対象人数・総通数・TEST転送先ごとの通数と開始できない理由を表示する。
確認後に設定・対象者・テンプレートが変わった場合、開始操作は409で拒否し、再確認を求める。
管理画面の開始操作は送信scheduleを公開する前に、次を同期実行する:

1. `PipelineRunner::generateCsv(campaignId)`（PHP CLI）で DB → 送信用CSV生成
2. `create_beacon_files.py` でビーコン/リンク/添付を生成
3. 全行の必須生成物を検証し、成功後にだけ`send_schedule`を作成

`tet2-worker.py`（systemd常駐）は`scheduled/running`キャンペーンのdue batchだけを取得し、
生成済みデータを再検証して`send_email.py`でSMTP送信する。必須添付の欠落・読取不能・
追加失敗は本文だけで送信せず、campaignを一時停止する。worker起動の各通はSMTP送信直前に
対象者の在籍状態・所属・元メールアドレスをDBで再確認し、不一致なら残りの送信を停止する。
途中停止時もCSVで送信済みと確認できる行はDBへ同期する。SMTP受付後にCSVへ記録できなかった
成否不明分は自動再送せず、手動調査が必要。

TESTキャンペーンは対象者・コンテンツの全送信行を、指定したテスト宛先へ均等に転送する。
テスト宛先が空の場合は生成・配信を拒否し、本番宛先へは送らない。全content配信のsplit/slowは
content行数ではなく重複のない従業員項番で分割する。少量プレビューは別機能として検討中。

緊急停止は data_dir に `stop_sending.flag` を置くとバッチが`cancelled`、campaignが`paused`になる。

## 定期キャンペーン

月次・四半期のルールからレビュー用draftを自動生成できる。管理画面の「定期キャンペーン」で
元キャンペーン、対象group、実施日、固定時刻またはランダム時間帯を設定する。生成処理は
`send_schedule`を作らず、既存のlaunch操作を行うまでメールを送信しない。

完了済みの「均等割り」キャンペーンをコンテンツpoolとして選ぶと、従業員ごとに前回とは別の
コンテンツを割り当てる個別rotationを設定できる。実施回数はpool内のコンテンツ数以下に制限され、
上限到達後はruleが自動停止する。キャンペーン編集画面は最大100コンテンツに対応し、過去の
複数キャンペーンからの一括取込、折りたたみ、並べ替えを利用できる。

runner、DB migration、systemd unitは本番へ配備済みである。2026-08-09に停止状態の
pilotでdraft生成まで確認し、automation timerはenabled・activeである。24時間の
timer実稼働監視中はpilot ruleをpausedのままとし、draft生成と送信を停止している。

## 教材バンク

教材バンクでは、eラーニング用スライド教材と確認テスト設問を管理できる。
PowerPoint（`.pptx`）からスライドのタイトルと本文を取り込み、保存前に編集できる。
確認テストは全カテゴリ一覧、1問ずつの回答確認、正答・解説を含む全設問一覧を利用できる。

設問はExcel（`.xlsx`）のテンプレートをダウンロードして一括追加できる。
自組織と共有の全設問はExcelへ出力できる。
ファイル上限、権限、Excel列仕様、未対応要素、検証履歴は
[`docs/education-material-bank.md`](docs/education-material-bank.md)を参照する。

## セットアップ（概要）

本リポジトリは本番からの集約であり、そのままの自動デプロイスクリプトは持たない。
配置は上記「本番配置」のパスへ web/ と bin/ を展開し、deploy/systemd/ の unit を
`/etc/systemd/system/` へ、deploy/cron/ の定義を `/etc/cron.d/` へ配置して
`systemctl daemon-reload` する。DB は別途
`db/schema*.sql` から構築し、`db/seed_*.php` で共有テンプレ/教材を投入する。

既存DBのmigrationは明示したcopyでdry-runしてから適用する。`--apply`なしでは変更せず、
本番DBへの直接適用にはさらに`--allow-production`が必要になる。実際の本番適用前には必ず
SQLite backupを作成する。

```bash
php web/db/migrate.php --db=/absolute/path/to/tet2-copy.sqlite
php web/db/migrate.php --db=/absolute/path/to/tet2-copy.sqlite --apply
```

## テスト

PHP testは`web/db/schema*.sql`と架空の`.test` domainだけから合成DBを毎回生成し、
本番DBや個人情報へ依存しない。Python回帰testも標準`unittest`で実行する。

```bash
bash web/tests/run.sh
python3 -m unittest discover -s bin/tests -p 'test_*.py'
```

deploy/rollbackの詳しい安全手順は[`deploy/README.md`](deploy/README.md)を参照する。

## セキュリティ

- OWASP Top 10 診断・修正済み（内部URL遮断/レート制限/内部パス403/IDOR多重防御）
- CSP 対応のため外部CDN非依存（`assets/vendor` にライブラリをローカル配置）
- CSRF トークン（`X-CSRF-Token`）、`esc()` 徹底、fetch 30秒 timeout
- SecurityAwarenessのユーザ管理統合: [`docs/security-awareness-user-management.md`](docs/security-awareness-user-management.md)
- 教材バンクのOffice入出力と確認テスト管理: [`docs/education-material-bank.md`](docs/education-material-bank.md)

## 関連

- 文書一式（仕様書・基本設計・詳細設計・運用手順・利用マニュアル・提案書）: [`docs/spec/README.md`](docs/spec/README.md)
- 教育コンテンツ（題材選定、教材の企画、アウェアネス小問）と制作の手順: [`docs/content/README.md`](docs/content/README.md)
- 教育機能の拡張の検討（教材の画像表示、設問の画像など）: [`docs/spec/08-feature-expansion-review.md`](docs/spec/08-feature-expansion-review.md)
- v1: `connect24h/targeted_email_training`（`/tet`, `/opt/training/git-repo`）— 当面共存
- 将来の機能候補と判断記録: [`docs/product-roadmap.md`](docs/product-roadmap.md)
- 第1期実装計画: [`plans/tet2-phase1-campaign-automation.md`](plans/tet2-phase1-campaign-automation.md)
- 詳細な到達点はメモリ `project_tet_v2` を参照

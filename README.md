# TET v2 — 標的型メール訓練 + セキュリティ教育システム

マルチテナント対応の標的型攻撃メール訓練（Targeted Email Training）と、
訓練失敗者向けセキュリティ教育を統合したシステム。素 PHP 8.4 + 素 Python 3.13
（フレームワーク不採用）。v1（`connect24h/targeted_email_training`）の後継。

## リポジトリ構成

```
web/        Web アプリ本体（本番: /var/www/html/tet2）
  api/        REST API 22本（?action= パターン、CSRF/セッション認証）
  lib/        リポジトリ層・PipelineRunner 等
  db/         現行スキーマ、migration runner、seed スクリプト
  assets/     SPA アセット（app.css, vendor/ にBootstrap/Chart.jsローカル配置）
  tests/      PHP テスト（32ファイル、合成DBを使い tests/run.sh で一括実行）
  index.html app.js take.php  SPA エントリ + 受講ページ
bin/        メール送信処理（本番: /opt/training/bin）
  tet2-worker.py       send_schedule ポーリング → 送信起動（systemd常駐）
  send_email.py        SMTP(localhost:25) 送信本体（v1由来・共用）
  create_beacon_files.py  ビーコン/リンク/添付生成
  qr_doc_gen.py        QR埋め込み文書（docx/pdf/html）生成
  tet2-purge-campaigns.py  論理削除済みキャンペーンの物理パージ
  replace_url.py / training_config.py / requirements.txt / config.ini
  master*.html / __BeaconMst.png  生成用HTML・ビーコン素材
deploy/systemd/  systemd unit 11本（worker + automation + edu enroll/reminder/snapshot + report-ingest）
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

`tet2-worker.py`（systemd常駐）が `send_schedule` をポーリングし、送信バッチを検出すると:

1. `PipelineRunner::generateCsv(campaignId)`（PHP CLI）で DB → 送信用CSV生成
2. `create_beacon_files.py` でビーコン/リンク/添付を生成
3. `send_email.py --data-dir ... --interval ... --auto-pause` で SMTP 送信

緊急停止は data_dir に `stop_sending.flag` を置くとバッチが `cancelled` になる。

## 定期キャンペーン

月次・四半期のルールからレビュー用draftを自動生成できる。管理画面の「定期キャンペーン」で
元キャンペーン、対象group、実施日、固定時刻またはランダム時間帯を設定する。生成処理は
`send_schedule`を作らず、既存のlaunch操作を行うまでメールを送信しない。

runner、DB migration、systemd unitは本番へ配備済みである。2026-08-09に停止状態の
pilotでdraft生成まで確認し、automation timerはenabled・activeである。24時間の
timer実稼働監視中はpilot ruleをpausedのままとし、draft生成と送信を停止している。

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

## 関連

- v1: `connect24h/targeted_email_training`（`/tet`, `/opt/training/git-repo`）— 当面共存
- 将来の機能候補と判断記録: [`docs/product-roadmap.md`](docs/product-roadmap.md)
- 第1期実装計画: [`plans/tet2-phase1-campaign-automation.md`](plans/tet2-phase1-campaign-automation.md)
- 詳細な到達点はメモリ `project_tet_v2` を参照

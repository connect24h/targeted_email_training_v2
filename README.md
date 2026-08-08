# TET v2 — 標的型メール訓練 + セキュリティ教育システム

マルチテナント対応の標的型攻撃メール訓練（Targeted Email Training）と、
訓練失敗者向けセキュリティ教育を統合したシステム。素 PHP 8.4 + 素 Python 3.13
（フレームワーク不採用）。v1（`connect24h/targeted_email_training`）の後継。

## リポジトリ構成

```
web/        Web アプリ本体（本番: /var/www/html/tet2）
  api/        REST API 11本（?action= パターン、CSRF/セッション認証）
  lib/        リポジトリ層・PipelineRunner 等
  db/         スキーマ(schema.sql/schema-edu.sql) と seed スクリプト
  assets/     SPA アセット（app.css, vendor/ にBootstrap/Chart.jsローカル配置）
  tests/      PHPUnit テスト（22ファイル / 425 assertions）
  index.html app.js take.php  SPA エントリ + 受講ページ
bin/        メール送信処理（本番: /opt/training/bin）
  tet2-worker.py       send_schedule ポーリング → 送信起動（systemd常駐）
  send_email.py        SMTP(localhost:25) 送信本体（v1由来・共用）
  create_beacon_files.py  ビーコン/リンク/添付生成
  replace_url.py / training_config.py / requirements.txt / config.ini
deploy/systemd/  systemd unit 8本（worker + edu enroll/reminder/snapshot + report-ingest）
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

## セットアップ（概要）

本リポジトリは本番からの集約であり、そのままの自動デプロイスクリプトは持たない。
配置は上記「本番配置」のパスへ web/ と bin/ を展開し、deploy/systemd/ の unit を
`/etc/systemd/system/` へ配置して `systemctl daemon-reload` する。DB は別途
`db/schema*.sql` から構築し、`db/seed_*.php` で共有テンプレ/教材を投入する。

## セキュリティ

- OWASP Top 10 診断・修正済み（内部URL遮断/レート制限/内部パス403/IDOR多重防御）
- CSP 対応のため外部CDN非依存（`assets/vendor` にライブラリをローカル配置）
- CSRF トークン（`X-CSRF-Token`）、`esc()` 徹底、fetch 30秒 timeout

## 関連

- v1: `connect24h/targeted_email_training`（`/tet`, `/opt/training/git-repo`）— 当面共存
- 将来の機能候補と判断記録: [`docs/product-roadmap.md`](docs/product-roadmap.md)
- 第1期実装計画: [`plans/tet2-phase1-campaign-automation.md`](plans/tet2-phase1-campaign-automation.md)
- 詳細な到達点はメモリ `project_tet_v2` を参照

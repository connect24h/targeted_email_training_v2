# tet2 — プロジェクト規則（Claude / Codex 共通）

実在の従業員へ訓練メールを送る本番システム。構成・テスト・配備手順は README.md と deploy/README.md が正本。ここには、間違えると事故になる約束事だけを書く。

## 本番資産
- DB: `/opt/training/tet2-db/tet2.sqlite`（WAL）。調査は読み取りだけにする。変更を伴う確認はコピー（`--db=/絶対パス/copy.sqlite`）で行う
- Web: `/var/www/html/tet2`、送信: `/opt/training/bin`。v1（`/tet`、`/opt/training/git-repo`）は共存中なので触らない。`bin/send_email.py` は v1 と共用
- systemd: `tet2-worker.service`（常駐）、timer は campaign-automation / report-ingest（5分）/ maildir-perms / tet-backup-cleanup。edu-snapshot は 2026-09-29 に停止（推移は edu_responses から直接集計するので、積んだ記録を読むところがない。過去の記録と CLI は残す）
- `tet2-edu-enroll` と `tet2-edu-reminder` はユーザー方針で disabled。有効化すると実在の従業員へ教育メールが届くので、明示の指示なしに有効化しない
- 送信の緊急停止は data_dir に `stop_sending.flag` を置く

## 配備と migration
- `deploy/tet2-deploy.sh` は既定で dry-run。`--apply`、本番 migration（`--allow-production`）、`daemon-reload`・restart は、ユーザーの承認を得てから別々の工程で行う
- 配備されるのは git 追跡済みの web/・deploy/systemd・deploy/cron と、`deploy/tet2-files.sh` の `TET2_BIN_FILES` だけ。新しいファイルは追跡と allowlist を確認しないと本番に届かない
- スキーマ変更は `web/db/MigrationRunner.php` の VERSIONS に新しい版を足す。schema*.sql を直すだけではテストは通るが、本番 DB には届かない。新しいテーブルは `web/tests/fixtures/TestDatabase.php` にも足し、件数を固定している schema_bootstrap_test と migration_idempotency_test も直す
- migration が要る機能は、コードより先に migration を適用する（deploy/README.md の各機能の注記を読む）
- app.js・assets/*.js・*.css を変えたら、配備前に `deploy/tet2-cache-bust.sh` を実行する（8/16 の `bootstrap is not defined` 事故の対策）

## 落とし穴
- 派生データを是正するときは、先に再取込の入口（report-ingest の filter）を本番へ入れる。順番が逆だと、5分 timer が誤データを戻してしまう
- `open` はメールの開封ではなく、偽サイト HTML 側のビーコン
- 静的診断とテストの後に、認証済みの実 HTTP でも確かめる（Apache の Location が DirectoryMatch の deny を上書きしていた前例がある）
- 訓練メールの本文に、訓練だと見破れる手掛かり（「報告はこちら」など）を足さない。測定の目的が壊れる
- テストは合成 DB と `.test` ドメインだけで行う（`bash web/tests/run.sh`）

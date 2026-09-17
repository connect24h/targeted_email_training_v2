# 不審メール受付・解析の導入手順（2026-09-18）

対象ブランチ: `feat/suspicious-mail`。管理画面のサイドバー「不審メール」から .eml をアップロードして解析し、
報告用アドレス（`report@gwin.gr.cojp.online`）に届いた訓練メール以外のメールも同じ解析にかける。
VirusTotal 照会はハッシュと URL の参照（GET）だけで、ファイルや URL を送信しない。

## 構成

| 層 | ファイル | 役割 |
|---|---|---|
| DB | `web/db/schema-suspicious-mail.sql`、migration `20260917-suspicious-mail` | `suspicious_mails` / `suspicious_mail_history` / `reputation_cache` |
| 解析 | `web/lib/ReportMailParser.php`（`collect` オプション） | 全ヘッダー・本文テキスト・URL・添付メタを収集。既定（collect なし）の照合動作は不変 |
| 判定 | `web/lib/SuspiciousMailAnalyzer.php` | 所見と推奨分類（脅威 ≥ 25 点、迷惑メール ≥ 10 点、それ未満は安全、訓練メールは固定） |
| 保存 | `web/lib/SuspiciousMailStore.php` | raw .eml を `tenants.data_dir/suspicious/<sha256>.eml`（0640）へ保存。テナント未確定は `/opt/training/tet2-data/suspicious-unassigned/` |
| 外部照会 | `web/lib/VirusTotalClient.php`、`web/lib/Secrets.php` | API キーは `/opt/training/tet2-data/secrets.ini` |
| API | `web/api/suspicious_mails.php` | `list / get`（viewer）、`upload / update / reanalyze / reputation`（operator） |
| 画面 | `web/assets/suspicious-mails.js`、`web/index.html` | 一覧・アップロード・詳細タブ（概要/ヘッダー/URL/添付/本文/生データ/履歴） |
| 取込連携 | `web/lib/ReportMailIngest.php` | 追跡 ID が実在しない報告メールを `suspicious_mails` に自動登録（source=`maildir`） |

## 1. コード配備

```bash
cd /root/tet2
bash web/tests/run.sh                       # 全 PASS を確認
deploy/tet2-deploy.sh                       # dry-run
deploy/tet2-deploy.sh --apply
for f in /var/www/html/tet2/lib/ReportMailParser.php /var/www/html/tet2/lib/SuspiciousMailStore.php /var/www/html/tet2/api/suspicious_mails.php; do php -l "$f"; done
apache2ctl configtest
```

## 2. migration

```bash
sqlite3 -readonly /opt/training/tet2-db/tet2.sqlite "VACUUM INTO '/var/backups/tet2/pre-suspicious-mail-$(date +%Y%m%dT%H%M%S).sqlite'"
cd /var/www/html/tet2
php db/migrate.php --db=/opt/training/tet2-db/tet2.sqlite                             # dry-run: 20260917-suspicious-mail が pending
php db/migrate.php --db=/opt/training/tet2-db/tet2.sqlite --apply --allow-production
sqlite3 -readonly /opt/training/tet2-db/tet2.sqlite "SELECT version FROM schema_migrations ORDER BY applied_at DESC LIMIT 1"
```

## 3. 保存先ディレクトリ

テナント別は `tenants.data_dir/suspicious/` を初回に自動作成する（`data_dir` は `training:www-data` の setgid ディレクトリなので www-data から作成できる）。
テナント未確定分の置き場だけ先に作る。

```bash
mkdir -p /opt/training/tet2-data/suspicious-unassigned
chown www-data:www-data /opt/training/tet2-data/suspicious-unassigned
chmod 2775 /opt/training/tet2-data/suspicious-unassigned
```

`tet2-report-ingest.service` は `training:www-data` で動くため、timer 経由で作られたファイル（0640、グループ www-data）も Web 側から読める。

## 4. VirusTotal API キー（任意）

キーが無い間は画面の「VirusTotal 照会」が無効表示になり、他の機能は影響を受けない。

```bash
cat > /opt/training/tet2-data/secrets.ini <<'EOF'
[virustotal]
api_key = <VirusTotal の API キー>
EOF
chown root:www-data /opt/training/tet2-data/secrets.ini
chmod 0640 /opt/training/tet2-data/secrets.ini
```

無料枠は 4 リクエスト/分・500/日。1 回の「照会」ボタンで未キャッシュ 4 件まで、結果は 7 日キャッシュする。

## 5. 疎通

1. 管理画面 → 不審メール → 「.eml をアップロード」で `web/tests/fixtures/eml/forwarded_rfc822.eml` を選ぶ（実在 PII なし）。推奨分類が「脅威」、詳細の URL タブに `http://192.0.2.5/login` が表示されればよい。
2. 分類を「脅威」・確認状況を「確認中」にして保存 → 履歴タブに 2 行。
3. 報告用アドレスに訓練メールでないメールを転送 → 5 分後に一覧に「受付元: 報告アドレス」で現れる。

```bash
sqlite3 -readonly -header /opt/training/tet2-db/tet2.sqlite "SELECT id, tenant_id, source, from_email, subject, score, suggested_category, category, status FROM suspicious_mails ORDER BY id DESC LIMIT 10"
```

4. 疎通で入れた行は画面上の分類を「安全」・対応済にするか、次で削除する（raw ファイルも消す）。

```bash
sqlite3 /opt/training/tet2-db/tet2.sqlite "SELECT raw_path FROM suspicious_mails WHERE id=<id>"   # → rm
sqlite3 /opt/training/tet2-db/tet2.sqlite "DELETE FROM suspicious_mails WHERE id=<id>"
```

## ロールバック

- コード: `deploy/tet2-rollback.sh --apply --backup-id=<配備時の ID>`
- DB: 手順 2 の `VACUUM INTO` バックアップ。3 表は追加のみなので、残したままでも旧コードは動く
- 旧コードに戻すと `ReportMailIngest` は不審メールへの登録をしなくなるだけで、報告メール照合は従来どおり

## 次段（未実装）

報告者への自動フィードバックメール、Received IP と WHOIS の照会、類似メールのグルーピング、raw .eml のダウンロード、保存ファイルの保持期限とパージ。

# 報告メール取込の本番導入手順（2026-09-06）

対象コミット: `e64985c`（リスク画面）`aa9944b`（基盤）`841dfc1`（照合と取込）`0b72ef9`（画面）と本書を含む unit 変更。
報告用アドレスは **report@gwin.gr.cojp.online**（全テナント共通、2026-09-06 決定）。
導入直後は `match_only`（照合だけ記録し `events` を書かない）で運用し、実際の転送形式で回収率を確認してから `normal` に切り替える。

## 1. コード配備（backup → 配備。migration と daemon-reload は自動では行われない）

```bash
cd /root/tet2
deploy/tet2-deploy.sh            # dry-run。web の変更・新規と systemd/tet2-report-ingest.service が対象に出る
deploy/tet2-deploy.sh --apply
for f in /var/www/html/tet2/lib/ReportMailIngest.php /var/www/html/tet2/lib/ReportMailParser.php /var/www/html/tet2/api/logs.php; do php -l "$f"; done
apache2ctl configtest
curl -fsS -o /dev/null -w '%{http_code}\n' https://www.filesend.cojp.online/tet2/
curl -s -o /dev/null -w '%{http_code}\n' https://www.filesend.cojp.online/tet/   # 401 のまま
```

## 2. migration（`report_mails` / `report_mail_matches`）

```bash
sqlite3 -readonly /opt/training/tet2-db/tet2.sqlite "VACUUM INTO '/var/backups/tet2/pre-report-mail-$(date +%Y%m%dT%H%M%S).sqlite'"
cd /var/www/html/tet2
php db/migrate.php --db=/opt/training/tet2-db/tet2.sqlite                             # dry-run: 20260906-report-mail-ingest が pending
php db/migrate.php --db=/opt/training/tet2-db/tet2.sqlite --apply --allow-production
sqlite3 -readonly /opt/training/tet2-db/tet2.sqlite "SELECT version FROM schema_migrations ORDER BY applied_at DESC LIMIT 1"
```

## 3. 報告メールボックス（Postfix 仮想メールボックス）

既存の仮想メールボックスと同じ形（ユーザー = ローカルパート、home = `/home/<user>`、Maildir は `<user>:vmail`、`virtual_uid_maps` にユーザーの uid、`virtual_gid_maps` に 5000）。

```bash
useradd -m -d /home/report -s /usr/sbin/nologin report
mkdir -p /home/report/Maildir/{new,cur,tmp}
chown -R report:vmail /home/report/Maildir
chmod 751 /home/report
chmod 755 /home/report/Maildir /home/report/Maildir/{new,cur,tmp}
echo "report@gwin.gr.cojp.online report/Maildir/" >> /etc/postfix/virtual_mailbox_maps
echo "report@gwin.gr.cojp.online $(id -u report)"  >> /etc/postfix/virtual_uid_maps
echo "report@gwin.gr.cojp.online 5000"             >> /etc/postfix/virtual_gid_maps
postmap /etc/postfix/virtual_mailbox_maps /etc/postfix/virtual_uid_maps /etc/postfix/virtual_gid_maps
postfix check && postfix reload
```

`gwin.gr.cojp.online` は `virtual_mailbox_domains` に登録済みなので追加不要。`tet2-maildir-perms.timer` は `/home/*/Maildir` を全て対象にするため、新しい Maildir も 600→640 の補正対象になる。

## 4. unit の反映（vmail 補助グループ、match_only、Maildir パス）

```bash
systemctl daemon-reload
systemctl cat tet2-report-ingest.service | grep -E 'SupplementaryGroups|Environment'
systemctl restart tet2-report-ingest.timer
systemctl list-timers tet2-report-ingest.timer --no-pager
```

## 5. 疎通（テストテナントのみ。本番対象者へは送らない）

1. `is_test=1` のテストキャンペーンをテスト用メールボックス宛に送る（送信後の Message-ID が `<t{追跡ID}.…@…>` 形式になっていることを `mail.log` で確認）。
2. 受け取った訓練メールを、Outlook・Gmail・Thunderbird からそれぞれ「インライン転送」と「添付として転送（.eml）」で `report@gwin.gr.cojp.online` へ送る。
3. 5 分後に確認:

```bash
sqlite3 -readonly -header /opt/training/tet2-db/tet2.sqlite "SELECT id, received_at, from_email, parse_status, ingest_mode FROM report_mails ORDER BY id DESC LIMIT 10"
sqlite3 -readonly -header /opt/training/tet2-db/tet2.sqlite "SELECT report_mail_id, tracking_id, method, status, evidence FROM report_mail_matches ORDER BY id DESC LIMIT 10"
tail -5 /opt/training/tet2-data/report-ingest.log
```

4. 管理画面 → ログ管理 → 「報告メール」タブで保留行が見えること。テストテナントの行を「確定」して `events` に `report` が 1 件入り、レポートの報告率が動くこと。
5. 回収方法（`method`）が `msgid` / `body` のどちらで取れたか、転送形式ごとに記録する。

## 6. normal への切り替え（回収率確認後）

`deploy/systemd/tet2-report-ingest.service` の `TET2_REPORT_INGEST_MODE=match_only` を `normal` に変えてコミット → 配備 → `systemctl daemon-reload`。
match_only 期間に `pending` になった行は画面から手動で確定できる。

## ロールバック

- コード: `deploy/tet2-rollback.sh --apply --backup-id=<配備時の ID>`
- DB: 手順 2 の `VACUUM INTO` バックアップ。`report_mails` / `report_mail_matches` は追加テーブルなので、残したままでも旧コードは動く
- メールボックス: `virtual_*maps` の 3 行を削除して `postmap` と `postfix reload`。受信済みメールは `/home/report/Maildir` に残る

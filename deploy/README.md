# TET2 deploy / rollback

## 安全境界

- すべてのcommandは既定でdry-run
- deploy対象はtracked `web/`、明示allowlistの`bin/`、systemd unit、cronだけ
- `bin/config.ini`、DB実体、log、runtime data、credentialはsource deploy対象外
- delete同期、migration、`daemon-reload`、service restartは自動実行しない
- apply前に上書き対象source、unit、cronとSQLiteの整合backupを作る
- 配備modeはsourceのambient modeを使わず、web/static=0644、Python=0755をpolicyとして適用する
- 配備ファイルは`root:root`とし、ビーコン素材は`www-data`と`training`のread可否を検証する
- backup manifestにはchecksumに加えてmode/uid/gidを保存し、rollbackで復元する
- rollbackは明示したbackup IDだけを使い、manifest全件のpath/state/checksumを事前検証する
- DB rollbackは`--restore-db --db-offline-confirmed`の二重flagが必須
- `tet2-campaign-automation.timer`は配備だけではenableせず、pilot承認後に別工程でenableする

## アセット変更時のキャッシュバスティング（デプロイ前に必須）

`app.js` / `assets/app.css` / `assets/campaign-automations.js` / `assets/positions.js`
のいずれかを変更したら、**デプロイ前に**次を実行して `index.html` の参照へ
内容ハッシュ（`?v=<hash>`）を埋め込む。

```bash
deploy/tet2-cache-bust.sh
```

これをしないと、ブラウザが古い `app.js` をキャッシュから使い続け、初期化が壊れる
（2026-08-16 の `bootstrap is not defined` 事故）。冪等なので何度実行してもよい。
実行し忘れると `web/tests/cache_bust_test.php` が落ちて気づける。

## Dry-runとapply

最初に差分とchecksumを確認する。

```bash
deploy/tet2-deploy.sh
```

applyはbackup IDを出力する。migrationとservice操作は別工程のまま残る。
配信時シナリオ版固定を含む配備では、`20260923-template-snapshots` の追加migrationを先に適用し、`campaign_template_snapshots` 表を確認してから新しい `CampaignLaunchService.php` を有効にする。deployはmigrationを自動実行しない。migration未適用なら予約処理は失敗側に倒れる。本番DBの適用・送信は別承認とする。

認証入力本文の収集を含む配備では、`20260923-credential-captures` migrationを**コードより先に**適用する。本文は`credential_captures`へ暗号文のみ保存し、`events.raw`・通常レポート・CSV/XLSXには載せない。Apache/PHPには`TET2_CAPTURE_KEY_FILE`としてWeb root・repository外の32-byte raw key fileの絶対pathを設定し、PHP実行者だけが読める権限にする。鍵をsource/DB/backup manifestへ入れず、紛失すると本文は復号不能になる。鍵の更新・バックアップとDB backupに残る暗号文の保持期間は配備前に運用設計を確認する。

`/tet2` 全体はフォーム認証で保護されているため、受講者がPOSTする`/tet2/api/credential_capture.php`だけApacheで認証を除外する。`credential_captures.php`など管理APIを公開しない。反映後は未認証のダミーPOSTがアプリ由来の403を返すこと、管理APIはログインへ転送されること、`/tet2/lib/`は403のままであることを実HTTPで確認する。暗号鍵はGit・Web root・通常の配備backupに含めず、復旧可能な保護済みの別保管先と保持期限を運用で管理する。Apache版PHPの`open_basedir`が鍵の置き場を許可する必要がある。本番では`/opt/training/tet2-data/keys/credential-capture.key`を使い、`/etc/tet2`は許可対象外である。

収集は既定で無効。システム管理者が顧客承認の参照番号を`POST /tet2/api/credential_captures.php?action=approve`へ`tenant_id`・`campaign_id`・`approval_ref`とCSRF tokenで登録したキャンペーンだけ、新規生成するHTTPSの偽ログインページが本文を送る。既存生成ページは自動更新されない。閲覧はログ管理の「入力本文（管理者限定）」と同APIの`list`/`reveal`をシステム管理者に限定し、revealはPOST+CSRF・監査付き。顧客管理者には開放しない。`20260923-campaign-close` migrationもコード配備前に適用する。システム管理者は配信終了・中止後にレポートを確定し、訓練レポートの「クローズ」で暗号化本文を消去できる。クローズ後は再収集・確定解除・再確定を拒否し、確定統計を保持する。キャンペーン削除時にも本文を消去する。DBの行削除は既存backup/WALからの物理消去を保証しないため、backup保持・鍵管理は別途運用設計が必要。稼働中の共用`/training_log.php`と既存実データはこの配備では変更・削除しない。

```bash
sudo deploy/tet2-deploy.sh --apply
```

キャンペーン生成・送信の安全修正だけを配備し、未配備の他機能を巻き込まない場合は
明示allowlist scopeを使う。

```bash
sudo deploy/tet2-deploy.sh --scope=campaign-safety
sudo deploy/tet2-deploy.sh --apply --scope=campaign-safety
```

## Rollback

まずsource rollbackの対象を確認し、同じbackup IDでapplyする。

```bash
sudo deploy/tet2-rollback.sh --backup-id=YYYYMMDDTHHMMSS-PID
sudo deploy/tet2-rollback.sh --apply --backup-id=YYYYMMDDTHHMMSS-PID
```

DBまで戻す場合は関連serviceとWeb書込みを停止し、offlineを確認してから明示する。

```bash
sudo deploy/tet2-rollback.sh --apply \
  --backup-id=YYYYMMDDTHHMMSS-PID \
  --restore-db --db-offline-confirmed
```

rollback直前のsource/DBもbackup内の`rollback-current/`へ保存される。
配備前に存在しなかったfileは削除せず`rollback-removed/`へ移す。

## Backup retention

運用方針は最新10世代・30日未満を保持する。deployは自動pruneしない。
専用commandも既定dry-runで、対象を物理削除せず`.trash/`へ移動する。

```bash
deploy/tet2-prune-backups.sh
sudo deploy/tet2-prune-backups.sh --apply
```

`.trash/`の物理削除は本scriptの責務外とし、別の承認済み運用で行う。

## 配備後の手動verification

```bash
find /var/www/html/tet2 -name '*.php' -type f -exec php -l {} \;
apache2ctl configtest
systemctl daemon-reload
systemctl is-active tet2-worker.service
curl -fsS https://www.filesend.cojp.online/tet2/ >/dev/null
```

`daemon-reload`以降は外部状態を変更または参照するため、production deploy承認後に実行する。

## Rehearsal test

一時directoryだけでdeploy、DB backup、rollback、tamper拒否、pruneを検証する。

```bash
bash deploy/tests/deploy_rehearsal_test.sh
systemd-analyze verify deploy/systemd/*.service deploy/systemd/*.timer
```

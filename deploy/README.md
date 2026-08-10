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

## Dry-runとapply

最初に差分とchecksumを確認する。

```bash
deploy/tet2-deploy.sh
```

applyはbackup IDを出力する。migrationとservice操作は別工程のまま残る。

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

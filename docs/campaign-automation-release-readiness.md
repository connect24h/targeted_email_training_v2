# キャンペーン自動化 配備・pilot準備レポート

検証日: 2026-08-09

対象branch: `chore/tet2-source-convergence`

判定: **本番source・DB migration・paused pilot完了、24時間監視中**

## 判定範囲

第1期Step 1〜7の実装、Step 8の配備前gate、本番source配備、本番DB migration、
停止状態のpilot draft生成を完了した。automation timerのenableと実メール送信は実施していない。

## Gate結果

| Gate | 結果 | 証拠 |
|---|---|---|
| PHP回帰 | PASS | `web/tests/run.sh`: 32/32 test files |
| PHP構文 | PASS | `web/`配下の全PHPで`php -l`成功 |
| Python回帰・構文 | PASS | unittest 1件、対象3scriptの`py_compile`成功 |
| JavaScript構文 | PASS | `campaign-automations.js`の`node --check`成功 |
| production DB copy migration | PASS | 初回2件、2回目0件、既存22tableの件数不変、automation 3table、FK error 0 |
| runner | PASS | production copyでdry-run/applyともdue 0、synthetic testで成功・失敗継続・重複防止を確認 |
| draft-only | PASS | API/runner testでcampaignは`draft`、`send_schedule`増加0 |
| tenant・role境界 | PASS | APIのIDOR test、operator/viewer browser smoke成功 |
| UI | PASS | desktop/mobile 390px、document overflowなし、console error/warning 0 |
| deploy/rollback | PASS | deploy rehearsal成功、dry-runは124対象を列挙して変更なし |
| 本番source配備 | PASS | backup ID `20260809T112000-900001`、124/124ファイルで配備後drift 0 |
| 本番DB migration | PASS | 2件適用、2回目no-op、既存22table件数不変、FK error 0 |
| systemd unit | PASS | `systemd-analyze verify`成功。saslauthdの既存legacy PID警告のみ |
| Apache設定 | PASS | `apache2ctl configtest`: `Syntax OK` |
| 既存service | PASS | workerと既存4timerはactive |
| automation timer | 意図どおり停止 | unit配備済み、disabled・inactive |
| HTTP認証境界 | PASS | originとCloudflareの双方が401を返し、Basic認証を維持 |
| paused pilot | PASS | automation 1、run 1、draft 81、target 3、content 3、rule paused |
| 本番送信状態 | PASS | backup比較で`send_schedule`は21件のまま、runner due 0、送信操作なし |

deploy dry-runの内訳は、同一83、追加予定23、更新予定17、sandbox内で読めない表示1だった。
読めない表示は`/opt/training/bin/__BeaconMst.png`で、sandbox外のread-only checksum確認では
Git正本と同じSHA-256だった。配備後は全124ファイルのsource/destination checksumが一致した。

## Security review

- automation APIの変更操作はoperator以上かつCSRF必須、読取りはviewer以上
- automation、source campaign、group、targetをtenant境界で検証
- SQLはparameterized queryを使用し、UIのAPI由来文字列は`esc()`または`textContent`を使用
- runner/API/UIから`campaign_launch.php`、`Scheduler`、送信workerを呼ばない
- occurrence unique制約とtransactionで同一予定回の重複draftを防止
- auditとrunner errorはIDと非PII codeだけを記録
- hardcoded secret、shell実行、動的SQL補間は追加していない

## 本番実施記録

1. `/var/backups/tet2/20260809T112000-900001`へsourceとSQLite整合backupを作成した。
2. allowlist 124ファイルを配備し、配備後drift 0を確認した。
3. migration 2件をtransaction適用し、2回目no-op、既存22table件数不変、FK error 0を確認した。
4. `daemon-reload`後もautomation timerはdisabled・inactive、既存workerと4timerはactiveである。
5. 配備済みsourceから合成DBの全32 PHP testを実行し、32/32 PASSを確認した。
6. 外形確認は一度Cloudflare 521となったが、直後のorigin切り分けと再試行では双方401へ復帰した。
7. pilot直前backup `/var/backups/tet2/20260809T115000-900002` のchecksumを照合した。
8. backup複製DBでdry-runと適用を再現し、重複適用が拒否されることを確認した。
9. tenant 1、source campaign 61、group 3でautomation 1を作成し、run 1からdraft 81を生成した。
10. automation 1を同一処理内でpausedへ戻し、target 3、content 3、監査3件、FK error 0、
    `send_schedule` 21件不変、runner due 0を確認した。

## Paused pilot結果と残る稼働gate

2026-08-09 12:07 JST、tenant 1、source campaign 61、group 3（active target 3件）で実施した。
HTTP APIはsessionとCSRFを必要とするため、同じdomain serviceを呼ぶ一回限りのCLIを使い、
prepared query、transaction、監査記録を維持した。検証後、CLIと複製DBは削除した。

1. 事前dry-runでsource content 3、active target 3を確認した。
2. timerがdisabledのままruleを作成し、draft生成後に同一処理でpausedへ戻した。
3. runの選択日時は2026-08-15 09:00 JST、次回生成予定は2026-09-10 09:00 JSTとなった。
4. draft 81は`draft`、target 3、content 3で、`send_schedule`増加0を確認した。
5. 監視終了予定の2026-08-10 12:07 JST以降にDB、audit、service、HTTPを再確認する。
6. 監視合格後に別承認でtimerをenableする。実メール送信は既存の手動launch手順で行う。

## Rollback判断

異常時はautomation timerをdisableし、生成済みdraftは履歴として残す。sourceは配備backup IDを指定して
rollbackする。追加automation tableは削除せずruleをpauseする。既存tableに問題がある場合だけ、
全書込み停止を確認してDB backupを復元する。

## 検証報告

- 実装完了条件: ✅ 確認済み（Step 1〜7、PHP 32/32、browser smoke）
- 本番配備条件: ✅ 確認済み（backup、124ファイルdrift 0、migration no-op・FK 0）
- 安全条件: ✅ 確認済み（draft-only、tenant/role/CSRF、冪等性、rollback rehearsal）
- paused pilot: ✅ 確認済み（automation 1 / run 1 / draft 81、paused、due 0）
- 機械確認: ✅ 19 Gate行、10本番実施項目、6 pilot段階、32 test files、22既存table
- 24時間監視: ⚠ 継続中（終了予定: 2026-08-10 12:07 JST）
- 一番弱い箇所: 本番認証・実DBを使うUI統合試験は未実施。今回のbrowser smokeはAPI mockであり、
  pilotはCLI経由で補完した。既存の認証済みbrowser sessionがなかったため、timer有効化前に
  管理UIからのread-only表示確認を残す。

# キャンペーン自動化 配備準備レポート

検証日: 2026-08-09  
対象branch: `chore/tet2-source-convergence`  
判定: **実装完了・承認後の段階配備が可能**

## 判定範囲

第1期Step 1〜7の実装と、Step 8のうち本番を変更しない配備前gateを完了した。
本番source配備、本番DB migration、service reload、automation timerのenable、pilot作成、
実メール送信は実施していない。

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
| systemd unit | PASS | `systemd-analyze verify`成功。saslauthdの既存legacy PID警告のみ |
| Apache設定 | PASS | `apache2ctl configtest`: `Syntax OK` |
| 既存service | PASS | workerと既存4timerはactive |
| automation timer | 意図どおり停止 | 本番はinactive、unit未配備・未enable |
| 本番非変更 | PASS | 検証前後で既存22tableの件数とschemaが一致 |

deploy dry-runの内訳は、同一83、追加予定23、更新予定17、sandbox内で読めない表示1だった。
読めない表示は`/opt/training/bin/__BeaconMst.png`で、sandbox外のread-only checksum確認では
Git正本と同じSHA-256だった。本番への書込みは発生していない。

## Security review

- automation APIの変更操作はoperator以上かつCSRF必須、読取りはviewer以上
- automation、source campaign、group、targetをtenant境界で検証
- SQLはparameterized queryを使用し、UIのAPI由来文字列は`esc()`または`textContent`を使用
- runner/API/UIから`campaign_launch.php`、`Scheduler`、送信workerを呼ばない
- occurrence unique制約とtransactionで同一予定回の重複draftを防止
- auditとrunner errorはIDと非PII codeだけを記録
- hardcoded secret、shell実行、動的SQL補間は追加していない

## 承認後の段階配備

1. deploy dry-runの追加23・更新17ファイルを人が最終確認する。
2. timestamp付きbackupを作成し、sourceをallowlist deployする。
3. Apache configtestとHTTP smokeを行う。
4. 本番DBをbackup後、migrationを適用し、2回目no-opとFK error 0を確認する。
5. automation timerはenableせず、pilot tenantのruleをpausedで作成する。
6. preview後に手動`generate_now`し、draft内容・対象者・`send_schedule`増加0を確認する。
7. 24時間監視後、別承認でtimerをenableする。実メール送信は既存の手動launch手順で行う。

## Rollback判断

異常時はautomation timerをdisableし、生成済みdraftは履歴として残す。sourceは配備backup IDを指定して
rollbackする。追加automation tableは削除せずruleをpauseする。既存tableに問題がある場合だけ、
全書込み停止を確認してDB backupを復元する。

## 検証報告

- 実装完了条件: ✅ 確認済み（Step 1〜7、PHP 32/32、browser smoke）
- 本番非変更条件: ✅ 確認済み（既存22table件数・schema不変、timer inactive）
- 安全条件: ✅ 確認済み（draft-only、tenant/role/CSRF、冪等性、rollback rehearsal）
- 配備手順: ✅ 確認済み（backup、migration、paused pilot、24時間監視を7段階で記載）
- 本番段階配備: ⚠ 未実施（利用者の明示承認とpilot tenant/source指定が必要）
- 機械確認: ✅ 15 Gate行、7段階配備、32 test files、22既存table
- 一番弱い箇所: 本番認証・実DBを使うUI統合試験は未実施。今回のbrowser smokeはAPI mockであり、
  本番source配備後のpaused pilotで補完する。

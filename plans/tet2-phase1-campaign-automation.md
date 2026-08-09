---
project: /root/tet2
created: 2026-08-08
status: active
approved: 2026-08-08
execution_requires_user_confirmation: false
---

# TET v2 第1期実装計画 — 安全な開発基盤とキャンペーン半自動化

## 目的

本番との差分をGit正本へ安全に集約し、再現可能なschema・test・deploy経路を整えたうえで、
定期キャンペーンのdraftを自動生成する。一人運用での反復作業を減らしつつ、実送信は必ず人が確認して開始する。

## 第1期の完成像

- 月次または四半期のautomation ruleをテナント単位で登録できる
- 指定日と時間帯から、固定またはランダムな送信予定時刻を計算できる
- source campaignの設定・コンテンツを基にdraftを生成する
- 指定groupの生成時点のactive targetをdraftへ取り込む
- systemd timerと手動操作のどちらでも同じ処理を使える
- 同じ予定回からdraftが重複生成されない
- 生成されたcampaignは編集可能で、既存のlaunch操作を行うまで送信されない
- 操作・生成結果・選択された日時をaudit logで追跡できる

## 対象外

- 四眼承認（作業者が一人のため保留）
- draftの自動launch、完全無人送信
- 失敗宛先の自動retry
- risk score、教育自動選択、SSO/MFA
- app.js全体のframework移行や全面refactor

## 調査済みの前提

- Git正本 `/root/tet2` は2026-07-25の初回commit以降、本番変更を取り込んでいない
- 追跡済み16ファイルが本番と異なり、`api/send_control.php`、
  `tests/pipeline_qr_extension_test.php`、QR文書生成・物理パージscript、生成用HTML/画像の
  合計9ファイルは本番にのみ存在する
- `schema.sql` は本番の22テーブルと追加columnを再現できない
- 現行test helperは本番DBを一時copyするため、GitHub CIや独立環境では実行できない
- GitHub remoteは設定済みだが、2026-08-08時点で`gh`の認証tokenが失効している
- repositoryにはREADMEとroadmapの未commit変更があるため、同期時に上書きしない

## 守るべき不変条件

1. 本番DB、個人情報、log、生成済み添付、credentialをGitへ入れない
2. tenantを持たない子tableは必ず親campaignまたはautomation経由でtenant ownershipを検証する
3. automationから`campaign_launch.php`、`Scheduler::expand()`、送信workerを呼ばない
4. 同一automation・同一予定回は最大1campaignとする
5. targetとgroupは同一tenantかつactiveなものだけを生成時点でsnapshotする
6. tracking IDは文字列として扱い、先頭ゼロを保持する
7. schema変更は本番DBのcopyで検証し、backupとrollbackを用意してから適用する
8. source campaignが削除済み・他tenant・不完全な場合、draftを部分生成せずrun全体を失敗させる

## 依存関係

```text
Step 1 正本同期
   └─ Step 2 schema/migration/test基盤
         ├─ Step 3 deploy/rollback
         └─ Step 4 CampaignDraftFactory
                └─ Step 5 automation schema/API
                       ├─ Step 6 runner/timer
                       └─ Step 7 UI
                              └─ Step 8 統合検証・段階配備
```

Step 3と4、Step 6と7は論理上は並行可能。ただし現在は一人運用なので、review負荷を下げるため番号順に実施する。

## 実装開始gate

- 本計画について利用者の承認を得る
- 現在のREADME、roadmap、plan変更を専用branchへ保持し、mainのdirty stateを解消する
- local branch/commitで進めるか、GitHub認証を復旧してPR方式にするかを開始時に決める
- Step 1では本番を変更せず、repositoryへのsource集約だけを行う

## Step 1 — 本番sourceをGit正本へ集約

想定branch: `chore/tet2-source-convergence`

### Context

本番 `/var/www/html/tet2` と `/opt/training/bin` がGitより新しい。backup・runtime data・secretを除外し、
実行sourceだけをallowlist方式で取り込む。README、roadmap、plansは本番同期の対象外とする。

### Tasks

- 追跡済み16ファイルの差分を個別reviewしてGit側へ反映する
- 本番のみのAPI/test、QR文書生成・物理パージscript、生成用HTML/画像を確認して追加する
- `*.bak-*`、`*.BROKEN-*`、DB、data、Attachment、log、`.ssh`、`.claude`を除外する
- 本番schemaとGit schemaの差分一覧をdataなしで保存する
- READMEのAPI/test件数など、古くなった現況記述を更新する
- `git diff --check`とsecret scanを実行する

### Verification

```bash
bash web/tests/run.sh
find web -name '*.php' -type f -exec php -l {} \;
python3 -m py_compile bin/create_beacon_files.py bin/send_email.py bin/tet2-worker.py
git diff --check
```

### Exit criteria

- allowlist対象sourceのhashが本番と一致する
- runtime data・credential・backupがGit差分に含まれない
- 既存testが全件成功する

### Rollback

Git側だけの作業とし、本番は変更しない。branchを破棄すれば元に戻せる。

## Step 2 — 現行schema、migration、合成test DBを再現可能にする

想定branch: `chore/tet2-schema-test-baseline`

### Context

本番には`campaign_contents`、`campaign_report_snapshots`、追加campaign columnなどがあるが、
repositoryのschemaだけでは再構築できない。testも本番DB copyに依存している。

### Tasks

- 本番の`sqlite_master`をdataなしで取得し、`schema.sql`と`schema-edu.sql`を現行形へ更新する
- `schema_migrations` tableとidempotentなmigration runnerを追加する
- migrationは明示されたDB path以外へ接続せず、既定で本番へ書き込まない設計にする
- schemaから空DBを作り、synthetic tenant/user/target/templateを投入するtest fixtureを追加する
- `tests/helpers.php`を本番DB copy方式から合成DB方式へ切り替える
- fresh DBと本番DB copyの両方でmigrationを2回実行し、2回目がno-opになることをtestする
- `PRAGMA foreign_key_check`と期待table/column一覧をtestする

### Verification

```bash
php web/tests/schema_bootstrap_test.php
php web/tests/migration_idempotency_test.php
bash web/tests/run.sh
sqlite3 -readonly 'file:/tmp/tet2-test.sqlite?immutable=1' 'PRAGMA foreign_key_check;'
```

### Exit criteria

- repositoryだけで22tableの空DBを構築できる
- testが本番DBなしで全件成功する
- production copyに対するmigration rehearsalがdata件数を変えない

### Rollback

本番適用前はbranch破棄。本番適用後は適用直前backupへ戻す。SQLiteの逆migrationは原則使わない。

## Step 3 — allowlist deployとrollbackを整備

想定branch: `chore/tet2-deploy-pipeline`

### Context

現行READMEは手動配置だけを説明しており、配備漏れ・backup堆積・本番直接編集を防げない。

### Tasks

- `deploy/tet2-deploy.sh`を追加し、`--dry-run`を既定にする
- 配備対象を`web/`、選択した`bin/`、systemd unitのallowlistに限定する
- delete同期は行わず、差分一覧とchecksum manifestを表示する
- 実配備前にsource、DB、unitのtimestamp付きbackupを作る
- `deploy/tet2-rollback.sh`は明示したbackup IDだけを対象にする
- backupの保持数・保持日数を定義し、削除は別の明示操作にする
- 配備後のPHP構文、Apache configtest、service status、HTTP smoke testを手順化する

### Verification

```bash
bash -n deploy/tet2-deploy.sh deploy/tet2-rollback.sh
deploy/tet2-deploy.sh --dry-run
systemd-analyze verify deploy/systemd/*.service deploy/systemd/*.timer
```

### Exit criteria

- 一時directoryへのdeploy rehearsalでsourceが一致する
- secret、DB、runtime dataが配備元にもbackup対象外にも漏れない
- rollback rehearsalで配備前checksumへ戻る

### Rollback

deploy script自体はGit revert。本番配備は生成したbackup IDを指定してrollbackする。

## Step 4 — campaign draft生成処理をserviceへ分離

想定branch: `refactor/tet2-campaign-draft-factory`

### Context

現在のcampaign作成・duplicate処理は`api/campaigns.php`にあり、HTTP応答、validation、SQLが結合している。
automation runnerからAPI fileを直接includeすると、tenant検証や例外処理を再利用できない。

### Tasks

- `web/lib/CampaignDraftFactory.php`を追加する
- source campaignの設定・campaign_contentsをcopyし、target IDsを受け取ってdraftをtransaction内で作る
- tracking ID生成、data_dir生成、content割当をservice境界へ集約する
- serviceは`json_error()`を呼ばず、domain exceptionを返す
- 既存duplicate APIをfactory利用へ変更し、HTTP payloadとstatus codeを維持する
- source campaignとtarget/groupのtenant ownershipをfactory入口で検証する

### Verification

```bash
php web/tests/campaign_draft_factory_test.php
php web/tests/api_campaigns_duplicate_test.php
bash web/tests/run.sh
```

### Exit criteria

- 既存duplicateの挙動が変わらない
- 他tenant、deleted source、zero targetで部分的なrowが残らない
- multi-content/all-contentとtracking ID先頭ゼロを回帰testする

### Rollback

新serviceとAPI切替commitをrevertする。DB schemaは変更しない。

## Step 5 — automation schemaとAPI

想定branch: `feat/tet2-campaign-automation-api`

### Context

MVPはsource campaignを雛形とし、指定groupの最新memberからdraftを作る。自動launchは行わない。

### Tasks

#### Data model

- `campaign_automations`: tenant、name、source campaign、frequency、day_of_month、generation lead、
  send window、time mode、next_due_at、status、created_by、timestamps
- `campaign_automation_groups`: automationとgroupの対応
- `campaign_automation_runs`: occurrence key、選択日時、生成campaign、status、error、timestamps
- `UNIQUE(automation_id, occurrence_key)`で重複生成を防ぐ

MVP制約:

- `frequency`は`monthly`または`quarterly`
- `day_of_month`は1～28
- server timezoneのAsia/Tokyoを使用する
- `time_mode`は`fixed`または`random_window`
- source campaignは同一tenantかつ論理削除されていないもの

#### API

`web/api/campaign_automations.php`へ次のactionを追加する。

- `list`, `get`: viewer以上
- `create`, `update`, `pause`, `resume`: operator以上 + CSRF
- `preview`: 次回予定、対象人数、source campaign、groupを返す。DB変更なし
- `generate_now`: operator以上。draft生成のみ

#### Tests

- role、CSRF、入力値、tenant IDOR、親子ownership
- source campaign/groupが他tenantまたは削除済みの場合の拒否
- month/year境界、quarterly、1～28制約、fixed/random window
- occurrence keyのunique制約と並行実行
- audit logにPIIを記録しない

### Verification

```bash
php web/tests/api_campaign_automations_test.php
php web/tests/campaign_automation_schedule_test.php
php web/tests/migration_idempotency_test.php
bash web/tests/run.sh
```

### Exit criteria

- APIだけでruleのCRUD、preview、pause/resumeが可能
- automation APIからsend_schedule rowが作られない
- 全existing testと新testが成功する

### Rollback

UI/runner導入前ならAPIをrevertしてtableを未使用のまま残す。本番table削除は行わない。

## Step 6 — idempotent runnerとsystemd timer

想定branch: `feat/tet2-campaign-automation-runner`

### Context

timer再起動、二重起動、途中失敗があっても、同一予定回のdraftを二重生成してはならない。

### Tasks

- `web/lib/CampaignAutomationRunner.php`を追加する
- `web/db/campaign_automation.php`をCLI entrypointにする
- due ruleをtransactionとunique occurrenceでclaimする
- current group membershipからactive targetを収集し、`CampaignDraftFactory`を呼ぶ
- 選択したrandom日時をrun rowへ保存し、再実行時に変えない
- zero target、source invalid、生成失敗をrunへ記録し、次回ruleを失わない
- `tet2-campaign-automation.service/.timer`を追加する
- 初期timerは1時間ごと、Persistent=true、oneshot、重複起動防止とする
- stdout/stderrにはPIIを出さない

### Verification

```bash
php web/tests/campaign_automation_runner_test.php
TET2_DB_PATH=/tmp/tet2-automation.sqlite php web/db/campaign_automation.php
systemd-analyze verify deploy/systemd/tet2-campaign-automation.*
bash web/tests/run.sh
```

### Exit criteria

- runnerを同時・連続実行しても1予定回1draftになる
- 生成campaignは常に`draft`で、`send_schedule`は0件
- failureが他tenant/ruleの処理を止めない

### Rollback

timerをdisableしてrunner codeをrevertする。生成済みdraftは履歴として残し、利用者判断で削除する。

## Step 7 — automation管理UI

想定branch: `feat/tet2-campaign-automation-ui`

### Context

既存`app.js`は大きいため、全面refactorをせずautomation画面だけを独立scriptに分ける。

### Tasks

- navigationと`campaignAutomations` viewを`index.html`へ追加する
- `assets/campaign-automations.js`へlist/form/preview/pause/resume/generate-nowを実装する
- source campaign、group、頻度、日、時間帯、固定/ランダムを選択できるようにする
- 次回生成日、予定送信時刻、想定対象数、最終run結果を表示する
- 「draftだけを生成し、自動送信しない」ことを画面上で明示する
- generate-now後は生成campaignへの導線を表示する
- 既存`api()`、CSRF、`esc()`、timeout、role表示規則を再利用する

### Verification

```bash
node --check web/assets/campaign-automations.js
php web/tests/api_campaign_automations_test.php
bash web/tests/run.sh
```

- operatorでCRUDとpreview、viewerでread-onlyを確認する
- 他tenant IDを直接指定したHTTP requestが403/404になることを確認する
- CSP違反、console error、既存13 viewの回帰がないことをbrowser smoke testする
- mobile widthでもformとtableが操作可能であることを確認する

### Exit criteria

- UIから安全にruleを作成し、preview後に手動draft生成できる
- 自動launchに見える文言・buttonが存在しない
- 既存画面のnavigationと操作が壊れていない

### Rollback

navigationとscript includeをrevertすればAPI/runnerはUIなしでpause可能。

## Step 8 — 統合検証、段階配備、pilot

想定branch: `release/tet2-campaign-automation`

### Context

production deployとtimer enableは外部状態を変更するため、実装完了後に改めて明示承認を得る。

### Tasks

- production DBのbackup copyへmigrationとrunnerを適用する
- synthetic ruleでpreview、生成、二重実行、失敗復旧を検証する
- local HTTP環境でlogin、CSRF、tenant境界、UIを確認する
- deploy dry-runの差分をreviewし、production deploy承認を得る
- production migration、source deploy、Apache configtest、service reloadを順に行う
- timerは最初pause状態で配備し、手動`generate_now`を先に確認する
- pilot tenantとsource campaignは利用者が指定する。実メール送信は行わない
- 24時間監視後にtimerをenableする

### Verification

```bash
bash web/tests/run.sh
find web -name '*.php' -type f -exec php -l {} \;
python3 -m py_compile bin/create_beacon_files.py bin/send_email.py bin/tet2-worker.py
systemd-analyze verify deploy/systemd/*.service deploy/systemd/*.timer
deploy/tet2-deploy.sh --dry-run
```

### Release gate

- 全PHP test成功、全PHP構文OK、Python compile成功
- migration 2回目no-op、foreign key error 0
- `send_schedule`増加0、送信process起動0
- filesend管理画面の認証境界維持、sat受講画面の回帰なし
- workerと既存timerがactive
- rollback rehearsal成功

### Rollback

automation timerをdisableし、sourceを配備前backupへ戻す。DBは追加tableを残して機能をpauseする。
既存tableを変更したmigrationに問題がある場合のみDB全体をbackupへ戻す。

## Adversarial review

| Failure mode | 防止策 |
|---|---|
| 本番差分同期でsecretや生成物をcommit | tracked/allowlist方式、secret scan、差分個別review |
| migrationで現行DBを破損 | copy rehearsal、backup、idempotency、foreign key check |
| timer二重起動でdraft重複 | occurrence unique制約 + transaction |
| automationが意図せず送信 | launch/Scheduler/workerを呼ばない不変条件とtest |
| group経由のtenant越境 | automation・group・targetを同一tenant joinで検証 |
| groupが空で空campaign生成 | zero targetはrun failure、transaction rollback |
| random日時が再実行ごとに変化 | 最初の選択値をrun rowへ保存 |
| source campaign変更・削除 | 生成時に再検証し、不正なら部分生成せずfailure |
| testが本番データに依存 | Step 2でsynthetic DBへ移行してから機能実装 |
| GitHubへPRを作れない | local branch/commitで進め、push前にgh認証を別途復旧 |

外部sub-agentによるadversarial reviewは、現在のsingle-agent実行制約により未実施。
実装開始前または各Stepのreview段階で、agyによる独立検証を別途実施する。

## Plan mutation protocol

- 新しい本番差分が見つかった場合はStep 1へ追加し、機能branchへ直接混ぜない
- schema再現に失敗した場合はStep 2を分割し、後続Stepを開始しない
- automationから送信経路が呼ばれる設計変更は、本計画のscope変更として利用者の再承認を得る
- 各Step完了時にこの文書へcommit、test結果、未解決事項を追記する
- Stepのskip・並べ替え・分割は理由を記録し、依存先のexit criteriaを再確認する

## 概算

| Step | 目安 |
|---|---:|
| 1. 正本同期 | 1～2日 |
| 2. schema/test基盤 | 2～3日 |
| 3. deploy/rollback | 1～2日 |
| 4. DraftFactory | 1～2日 |
| 5. schema/API | 2～3日 |
| 6. runner/timer | 1～2日 |
| 7. UI | 2～3日 |
| 8. 統合検証・pilot | 1～2日 |

合計は11～19営業日。最初の提供可能単位はStep 1～4の「安全に開発できる基盤」、
利用者向けMVPはStep 1～7、production有効化はStep 8完了後とする。

## 検証報告

- 利用者要件: 確認済み（計画を作成し、実装前の承認gateを設定）
- 四眼承認の扱い: 確認済み（第1期対象外、将来候補のまま維持）
- 実装分解: 確認済み（Step 1～8、各StepにContext、Tasks、Verification、完了条件、Rollback）
- 依存関係: 確認済み（正本同期とschema/test基盤を機能実装より前に配置）
- 安全性: 確認済み（自動launch禁止、tenant境界、冪等性、本番DB backupを不変条件に設定）
- 外部adversarial review: Step 4でagyへ依頼したが無応答のため停止。Codex側security reviewと自動testで代替
- 一番弱い箇所: 11～19営業日の概算は実績工数ではなく、現在確認できたsource/schema差分に基づく推定。
  Step 1完了時に実差分とtest修正量を測定し、残りの見積りを更新する。

## 実行記録

| 日付 | Step | Commit / 状態 | 検証結果 |
|---|---|---|---|
| 2026-08-08 | 1 | `f255d0c` 完了 | 本番source allowlist同期、PHP 24/24、Python compile、secret/diff確認 |
| 2026-08-08 | P0 | `5c35d80`, `31f4475` 完了 | GeoIP更新権限とtracking ID先頭ゼロをTDD修正 |
| 2026-08-08 | 2 | `2457c96` 完了 | 22業務table再現、synthetic DB、migration二重実行no-op、production copy件数不変 |
| 2026-08-08 | 3 | `2975860` 完了 | dry-run deploy、整合backup、atomic rollback、recoverable pruneを一時directoryで検証 |
| 2026-08-09 | 4 | `ee4e53f` 完了 | DraftFactory分離、tenant/active/content検証、transaction rollback、duplicate API互換、PHP 28/28・Python PASS |
| 2026-08-09 | 5 | `69dfcf2` 完了 | automation 3table、月次/四半期schedule、CRUD/preview/generate API、重複防止、PHP 30/30 PASS |
| 2026-08-09 | 6 | `0129924` 完了 | atomic runner、dry-run CLI、hourly timer unit、失敗継続・重複防止、PHP 31/31 PASS |
| 2026-08-09 | 7 | `27a5403`, `539fb92` 完了 | 管理UI、role別操作、preview、最終run表示、desktop/mobile browser smoke、PHP 32/32 PASS |

Step 2のproduction copy rehearsalは22業務tableすべてで適用前後の件数が一致し、
`PRAGMA foreign_key_check`は0件だった。本番DB自体へのmigration・source配備は未実施。

Step 4の外部reviewは`codex-agy-delegate`へ読み取り専用で依頼したが、約98分応答がなく停止した。
外部review結果は未取得で、source変更は発生していない。Codex側ではtenant分離、parameterized query、
tracking IDの文字列性・一意性、transaction原子性を確認し、全28 PHP testとPython unittestを再実行した。

Step 5のproduction copy rehearsalでは、未version管理の現行DB copyへ2 migrationを適用し、2回目はno-op、
既存table件数不変、automation table 3件、`PRAGMA foreign_key_check` 0件を確認した。本番DBは未変更。

Step 6ではclaim・draft生成・run完了・次回更新を単一transaction化した。timer unitは未配備・未enableで、
CLIの本番applyは`--apply --allow-production`の二重flagを要求する。

Step 7では独立scriptとして管理画面を追加した。operatorは作成・編集・停止・再開・手動draft生成、
viewerは一覧とpreviewだけを利用できる。390px幅でdocument overflowなし、JavaScript consoleのerror/warning 0件を
Playwright smokeで確認した。本番source・DB・service・timerは変更していない。

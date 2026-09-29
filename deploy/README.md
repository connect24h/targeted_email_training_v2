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

アンケート（U7）を含む配備では、`20260925-surveys` migrationを**コードより先に**適用する。既存テーブルには触れず、`survey*` の6表だけを追加する。回答画面`survey.php`と回答API`api/survey_take.php`は、受講画面`take.php`・`api/edu_take.php`と同じく認証なしで受講者が開くため、受講者ポータル（`TET2_EDU_BASE_URL`の既定）でこの2つだけを公開し、管理API`api/surveys.php`は公開しない。Apache設定はリポジトリ外なので、現行の`take.php`の公開方法を確認してから同じ形で追加する（本番設定の変更は別承認）。反映後、ポータルで無効トークンの`survey.php`が「回答用のリンクが正しくありません」を返し、`api/surveys.php`がログインへ転送されることを実HTTPで確認する。

アンケートのメール送信（案内と締切前の催促）は既定で無効。`TET2_SURVEY_MAIL_ENABLED=1`をApache/PHPとCLIの環境に設定したときだけ送る。未設定なら管理APIは409を返し、`web/db/survey_reminder.php`は何も送らずに終わる。催促用のsystemd timerは用意していない。送信の有効化とtimerの作成は、実在の従業員へメールが届くため利用者の明示の承認を得てから行う。無効のままでも、配信一覧の「回答用 URL（CSV）」を社内メールで配布すれば運用できる。

教育の配信の自動化（予約の自動開始、毎月の配信、役職と訓練の結果での対象、新入社員への出題）を含む配備では、`20261001-edu-delivery-features` migrationを**コードより先に**適用する。`edu_deliveries`へ列（`send_invites`・`series_id`・`target_positions`・`risk_results`・`new_target_days`）を足し、`edu_delivery_series`表を作るだけで、既存の配信の行は変えない。既存の配信は`send_invites=0`になり、手動の開始（launch）でも受講の案内メールを送らなくなる（催促`remind`は明示の操作なので従来どおり送る）。`tet2-edu-enroll`の自動の投入も、配信で「案内メールを送る」を選んだものだけ送る。

自動の処理は`web/db/edu_scheduler.php`の1つにまとめてある。予約の日時が来た配信の開始、毎月の配信の回の作成、新入社員の投入を順に行い、結果を1行で出す。冪等で、`TET2_DB_PATH`で隔離DBに向けられる。timerは`deploy/systemd/tet2-edu-scheduler.{service,timer}`（15分ごと）を用意したが、配備では有効にならない。**有効化は利用者の承認が要る**（案内メールを選んだ配信があると、実在の従業員へ教育メールが届く）。承認までは、画面の開始ボタンか、CLIの手動実行で使う。

```bash
# 本番のコピーで結果を確かめる
TET2_DB_PATH=/abs/path/tet2-copy.sqlite php web/db/edu_scheduler.php
# 承認後だけ
sudo systemctl daemon-reload
sudo systemctl enable --now tet2-edu-scheduler.timer
```

ユーザ管理の改善（招待メール、パスワード再設定、CSV の一括登録・出力、パスワードの決まり）を含む配備では、`20261008-user-password-tokens` migrationを**コードより先に**適用する。`users`へ`password_pending`と`session_epoch`の列を足し、`user_password_tokens`表を作るだけで、既存のユーザのパスワードとセッションは変えない。新しい`bootstrap.php`は毎回`users.session_epoch`を読むため、migration前にコードを配備すると全APIが500になる。

- 招待・再設定のメールは教育の案内と同じ`EduMailer`（`TET2_EDU_MAIL_FROM`、localhost:25）で送る。管理者の明示の操作（作成時の選択、一覧のボタン、CSV一括登録の選択）でだけ送り、timerはない。
- メールのURLの基点は`TET2_ADMIN_BASE_URL`（既定`https://www.filesend.cojp.online/tet2`）。パスワード設定のページは`set_password.php`、APIは`api/password_set.php`（ログイン前にトークンだけで動く）。
- 管理画面の`/tet2`は外側のフォーム認証の後ろにあるため、外側の認証の資格を持たない人は招待のリンクを開けない。資格を持たない人を招待するなら、`set_password.php`と`api/password_set.php`だけを`credential_capture.php`と同じ形で認証から外す（Apacheの変更は別承認）。受講者のマイページ（sat.cojp.online）で使う時は、許可のリストにこの2つを足す。
- テストとE2Eでは`TET2_MAIL_OUTBOX_DIR`（メールをファイルに書き、投函しない）か`TET2_EDU_MAIL_DISABLE=1`を使う。本番では設定しない。

受講者のマイページ（L1〜L5、L7）を含む配備では、`20261012-learner-portal` migrationを**コードより先に**適用する。`users.target_id`、`edu_deliveries.allow_retake_after_pass`（既定1）、`edu_attempts`表を足し、既存の回答を1回目の回として写す。既存の割当と回答の行は変えない。新しい`edu_take.php`は提出のたびに`edu_attempts`へ書くため、migration前にコードを配備すると受講の提出が500になる。

- 受講者のサイト（sat.cojp.online）の許可のリストに、`my.php`（`<Files>`は名前で合うので`api/my.php`も同じ1行で許可される）、`set_password.php`、`password_set.php`を足す。管理APIの`api/learners.php`は公開しない。Apacheの変更は別承認。反映後、`https://sat.cojp.online/my.php`がログイン画面を出すこと、`api/learners.php`と`index.html`が403のままであることを実HTTPで確かめる。
- 招待のメールのURLの基点は`TET2_LEARNER_BASE_URL`（既定`https://sat.cojp.online`）。リンクは`set_password.php?token=...&site=my`で、設定の後に`my.php`へ案内する。
- 招待は管理画面の対象者の一覧（組織管理者以上）からだけ送る。timerはない。

配信ごとの受講の設定（選択肢の並べ替え、テスト中の教材、期限後の受講、テストからの受け直し）と社内の問い合わせ先を含む配備では、`20261020-edu-delivery-options` migrationを**コードより先に**適用する。`edu_deliveries`へ4列（既定0。既存の配信は今と同じ動き）、`edu_attempts.test_started_at`、`tenants.edu_contact`を足すだけ。新しい`edu_take.php`はこれらの列を毎回読むため、migration前にコードを配備すると受講が500になる。教育レポートの概要と推移（段0）はmigrationなしで変わる（テスト用と削除済みの対象者を除き、推移は`edu_responses`から月ごとに集計）。
- セッションのクッキーは`TET2MYSESID`（path `/`）で、管理画面の`TET2SESID`とは別。

### 測定の正しさ（段B1、G01・G57・G04・G26）

`20261025-measurement-b1` migrationを**コードより先に**適用する。`events`へ判定の列（`verdict`・`verdict_reason`・`verdict_source`・`verdict_by`・`verdict_at`）、`campaign_targets`へ送達の列（`delivery_state`・`delivery_state_at`・`delivery_detail`）を足し、返信の取込の台帳`reply_mails`を作るだけ。既存の行動は`verdict='user'`（今と同じく数える）、既存の宛先は`delivery_state` NULL（率の分母は今のまま）になる。新しいコードはどの集計も`verdict='user'`で絞るので、migration前にコードを配備するとレポートとログのAPIが500になる。

- 装置のクリック: `EventIngest`（report-ingest の5分のtimer）は、User-Agentで装置と判断したクリックを捨てずに`verdict='scanner'`で残す。集計には入らないので数字は変わらない。配備後の最初の取込で、access.log と access.log.1 に残っている過去の装置の行も scanner として入る（集計は変わらない）。
- 行動履歴のタブと判定の修正: `api/report.php?action=actions|people|set_verdict`。直すのはオペレータ以上で、監査ログ`report.event_verdict`に残る。
- 届かない宛先と返信の取込: `web/db/delivery_ingest.php`（冪等）。postfix の mail.log（と .1）の`status=bounced|expired|sent`、訓練の送信元の Maildir の返信と戻りメール（DSN）を読み、宛先の`delivery_state`と`events`の`reply`を書く。送信の処理（`bin/send_email.py`、v1と共用）は変えない。宛先は送信の処理が付ける Message-ID `<t{tracking_id}.…>`（2026-09-05 から）で決める。これより前の送信は、宛先・送った時刻（1日以内）・送信元（qmgr の from=）が合う宛先が1件だけの時に限って結ぶ。
- 届かない宛先は訓練の率の分母（対象数と送信済み）から外し、レポートの概要と利用者ごと、対象者の一覧（2回以上続けて届かない宛先は警告）に出す。対象者は自動では消さない。

timerは既存の report-ingest に相乗りせず、`deploy/systemd/tet2-delivery-ingest.{service,timer}`（5分ごと、`*:2/5`）を別に用意した。理由: 有効化を report-ingest と別に承認・停止できる、失敗しても反応の取込を止めない、読む物（mail.log、送信元の Maildir）が違う。権限は report-ingest と同じく`User=training`と`SupplementaryGroups=adm vmail`で足りる（mail.log は`syslog:adm 640`、送信元の Maildir のメールは maildir-perms の timer が`640`・グループ vmail にそろえる）。配備では有効にならない。**有効化は利用者の承認が要る**（メールは送らないが、本番の DB に書く）。

```bash
# 本番のコピーで結果を確かめる(mail.log は読むだけ)
TET2_DB_PATH=/abs/path/tet2-copy.sqlite php web/db/delivery_ingest.php
# 承認後だけ
sudo systemctl daemon-reload
sudo systemctl enable --now tet2-delivery-ingest.timer
```

限界: Message-ID が tracking を持たない送信（2026-09-05 より前、v1）の返信は突き合わせない。mail.log は週ごとにローテートされ、読むのは今と1つ前のファイルだけなので、それより古い不達は取り込めない。相手のサーバーが一度受け取った後に戻す不達は、戻りメールが送信元の Maildir に届いた時だけ分かる。

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

### 管理画面の多要素認証とパスワードの方針（段階1、G43・G44）

`20261015-admin-mfa` migrationを**コードより先に**適用する。`users`へ列（`mfa_secret`・`mfa_enabled_at`・`mfa_last_step`）を足し、`user_mfa_recovery_codes`と`tenant_security_policies`の2表を作るだけで、既存のユーザは多要素認証なし、方針の行もないまま（従来どおりパスワードだけでログインでき、パスワードの決まりも`PasswordPolicy`のまま）。新しい`bootstrap.php`は毎リクエストで`tenant_security_policies`を読むので、migration前にコードを配備すると管理画面の全APIが失敗する。

TOTPの秘密鍵は、`secrets.ini`（既定`/opt/training/tet2-data/secrets.ini`、`TET2_SECRETS_FILE`で差し替え可）の`[mfa] secret_key`（32バイトの乱数のbase64）で暗号化してDBに置く。鍵はGit・Web root・配備backupに入れず、DB backupとは別の保護された場所に控える。

```ini
[mfa]
; php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;' で作る
secret_key = <44文字のbase64>
```

- 鍵が未設定の間は、登録の開始が503になり、方針で多要素認証を必須にする保存も409で断る（誰も登録できず全員が止まるのを防ぐ）。
- 鍵をなくす・変えると、登録済みの全員の確認コードが通らなくなる（回復コードは鍵なしでも使える）。その場合は下のCLIで全員を解除し、登録し直してもらう。
- 方針（最小の文字数12〜64、文字の種類3か4、多要素認証の必須）は、組織管理者が自組織、システム管理者が全テナント共通（`tenant_id` NULLの1行）を設定する。両方あれば厳しい方が効き、`PasswordPolicy`より弱くはできない。必須にした組織の未登録のユーザは、次の操作から登録の画面とAPI（`users.php`の`mfa_*`）以外が403になる。

**回復手順（システム管理者が端末と回復コードの両方を失い、画面で解除できる管理者もいない時）**：サーバのrootで`web/db/mfa_reset.php`を使う。既定は確認だけで、`--apply`で秘密鍵・時刻窓・回復コードを消し、`--unlock`で5回失敗のロックも外す。監査ログに`user.mfa_reset_cli`を残す。パスワードは変えないので、本人はパスワードでログインし、改めて登録する（方針で必須なら登録の画面になる）。DBを読めるだけでは秘密鍵も回復コードも取り出せない。

```bash
# 本番のコピーで結果を確かめる
TET2_DB_PATH=/abs/path/tet2-copy.sqlite php web/db/mfa_reset.php --email=admin@example.test
# 承認後だけ（本番DBへの書き込み）
php /var/www/html/tet2/db/mfa_reset.php --email=admin@example.test --apply --unlock
```

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

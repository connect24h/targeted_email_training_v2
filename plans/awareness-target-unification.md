---
project: /root/tet2 + /root/SecurityAwareness
created: 2026-08-09
status: production_rollout_completed
execution_requires_user_confirmation: false
recommended_source_of_truth: TET2 targets
phase_1_tenant_scope: single configured tenant
architecture_review: passed
---

# Awareness受講者と標的型メール訓練対象者の機能統合計画

## 2026-08-09 実装・本番稼働記録

feature branch上の実装、test、security review、agy review、SQLite本番copyと一時PostgreSQLでの
migration rehearsalを完了し、利用者の稼働開始指示後に本番rolloutまで実施した。

- TET2 backup: `/var/backups/tet2/20260809T195837-900002`
- SecurityAwareness backup: `/var/backups/security-awareness/20260809T200457-900003`
- TET2: source drift 0、migration 7件、SQLite integrity/FK error 0、PHP test 35/35成功
- service API: tokenなし401、tokenあり200、tenant 1固定、Bearer header転送を確認
- Awareness: Prisma migration 2件、health 200、app/DBともloopback限定で起動
- 初回preview: created 8、conflict 0。apply: created 8、group created 1、failed 0
- 再preview: noop 8、conflict 0、TET2 external ID 8件がすべて一意
- 毎日01:30（Asia/Tokyo）のparticipant syncをsystemd timerで運用する

実装ではTET2のtenant固定Bearer API、永続idempotency、group/target archive、stable target ID付き結果、
Awarenessの外部identity・journal・full snapshot・write-through UI・CSV preview/apply・single-run lockを追加した。
API契約の正本は`docs/awareness-participant-integration.md`、Awareness側運用手順は
`/root/SecurityAwareness/docs/TET2_PARTICIPANT_INTEGRATION.md`に記録した。

残存する運用上の注意は、現在の約1,890 targetを大きく超える規模では逐次transactionの所要時間を
再計測すること、TET2 API tokenを定期rotationすること、SecurityAwareness管理UIの外部公開先を
既存TET2受講画面と競合しない形で別途決定することである。現状のAwareness appはloopback限定で稼働する。

## 目的

Awarenessの「受講者管理」とTET2の「訓練対象者管理」を二重入力しなくてよい状態にする。
TET2の`targets`を人物マスター（正本）、Awarenessの`Respondent`を同期された参照・履歴保持用の投影とし、
一人の対象者を同じ組織属性・グループでAwareness教育と標的型メール訓練の双方に利用できるようにする。

これはSQLiteとPostgreSQLの物理統合ではない。サービス間APIで機能を統合し、既存の受講履歴、配信履歴、
標的型メール訓練履歴を保持したまま段階移行する。

## 推奨する完成像

- TET2 `targets`が氏名、メールアドレス、会社、部署、役職、在籍状態、グループ所属の正本になる
- Awarenessの受講者画面から追加・編集・無効化すると、TET2へ書き込み後にAwarenessへ反映される
- TET2側で変更した対象者も定期同期によりAwarenessへ反映される
- Awarenessは既存の`Respondent.id`（UUID）を維持し、教育配信・回答・分析の外部キーを変更しない
- TET2は既存の`targets.id`を維持し、campaign target、event、教育履歴の外部キーを変更しない
- 削除は物理削除せず、TET2の`archived`とAwarenessの`isActive=false`へ統一する
- 同期はdry-run、競合一覧、適用結果、最終成功時刻を管理画面で確認できる
- phishing result連携はcampaignごとのtracking IDではなく、安定したTET2 target IDを主キーにする

## 調査済みの現状

### TET2

- `/root/tet2/web/db/schema.sql`の`targets`はtenant単位で管理され、`(tenant_id, email)`が一意
- `targets`は会社、部署、役職、職位区分、状態を持ち、`target_group`で複数groupに所属できる
- `/root/tet2/web/api/targets.php`はtenant scope、role、CSRFを確認する人間向けsession API
- 対象者削除は`status='archived'`への論理削除で、campaignと教育の履歴を保持する
- campaign結果は`campaign_targets.target_id`へ結び付き、tracking IDはcampaignごとに発行される

### SecurityAwareness

- `/root/SecurityAwareness/prisma/schema.prisma`の`Respondent`はPostgreSQL上のUUIDで、emailが全体一意
- `Respondent`は教育配信、回答、snapshot、phishing resultから参照されており、ID置換は履歴を壊す
- `/root/SecurityAwareness/app/(admin)/respondents`に独立したCRUD・CSV import画面がある
- `/root/SecurityAwareness/lib/phishing-sync.ts`はTET2の結果を`randomId`で照合するだけで、人物マスターは同期しない
- Awarenessにはtenant modelがなく、現状は実質的に単一組織運用

## 守るべき不変条件

1. 既存のTET2 target IDとAwareness respondent UUIDを変更しない
2. 既存の教育、回答、campaign、event、分析履歴を削除・付け替えしない
3. TET2を人物属性と在籍状態の唯一の正本にし、双方向のlast-write-wins同期を作らない
4. DBファイル・DB接続をサービス間で共有せず、tenant固定の認証済みAPIだけを使う
5. Phase 1はAwareness一環境につきTET2の一tenantだけを明示的に設定する
6. email一致は初回移行候補の発見にだけ使い、移行後は`(tetTenantId, tetTargetId)`で照合する
7. 一致が曖昧な人物、重複、形式不正を自動上書きせず、競合として人へ提示する
8. 同期は再実行可能かつ冪等にし、途中失敗で片側だけを成功扱いにしない
9. 削除はarchive/deactivateとし、過去履歴のある人物を物理削除しない
10. API token、メールアドレス、氏名をlogやaudit detailへ不要に記録しない
11. service credentialは環境変数で管理し、Gitへ保存しない
12. 既存のAwareness単独データは、紐付けが確定するまで破棄しない
13. service writeのidempotency resultをTET2 DBへ永続化し、process再起動やHTTP応答喪失後も同じ結果を返す
14. Awarenessのremote mutationとpull syncはitem単位のjournalを持ち、片側成功と変更前状態を追跡できる
15. groupは現在の対象集合であり、実施済み訓練の宛先履歴はTET2 `campaign_targets`とAwareness
    `DeliveryAssignment`を不変のsnapshotとして扱う

## Phase 1の判断事項

推奨値は次のとおり。実装開始前に利用者の承認を得る。

| 判断 | 推奨値 | 理由 |
|---|---|---|
| 人物マスター | TET2 `targets` | 標的型メール訓練がtenant・group・archiveを既に持つため |
| Awarenessの役割 | 同期投影 + 教育履歴の所有 | 既存UUIDと履歴を安全に維持できるため |
| tenant範囲 | 一つの設定済みtenant | Awarenessが現在single-tenantで、誤tenant混入を防ぐため |
| 管理画面 | Awareness画面を残し、TET2へwrite-through | 利用者が教育運用の画面から離れず管理できるため |
| 削除 | 論理削除のみ | 両システムの履歴参照を壊さないため |
| group | TET2を正本としてAwarenessへ投影 | 配信と訓練の対象集合を揃えるため |

## 依存関係

```text
Step 1 契約・データ棚卸し
   └─ Step 2 TET2 integration schema/API
         └─ Step 3 Awareness外部ID schema
               └─ Step 4 dry-run対応同期engine
                     └─ Step 5 phishing resultのstable ID化
                           └─ Step 6 Awareness管理画面のwrite-through化
                                 └─ Step 7 移行rehearsal・段階配備
                                       └─ Step 8 legacy経路の縮退
```

participant/resultのread同期が安定するまでwrite-through UIを有効にしない。
Step 7までは本番データを書き換えない。

## 実装開始gate

- 本計画と「TET2を正本・Phase 1は一tenant」の方針について利用者の承認を得る
- `/root/SecurityAwareness/data/questions-seed.csv`の既存変更を利用者の変更として保持し、commitへ含めない
- TET2とSecurityAwarenessの双方で専用feature branchを作る。`main`へ直接pushしない
- 失効中のGitHub認証を直すまではlocal branch/commitまでとし、PR作成・pushは行わない
- 本番データ移行はStep 7のpreview結果とbackupが揃ってから別途明示承認を得る

## Step 1 — API契約と既存データのread-only棚卸し

想定branch: `docs/shared-participant-contract`

### Tasks

- versioned contract `docs/integration/awareness-participants-v1.md`をTET2側に追加する
- participant payloadを`tenantId`, `targetId`, `email`, `name`, `company`, `department`, `title`,
  `positionCategory`, `status`, `sourceVersion`, `groups[]`へ固定する。現行TET2 targetには`updated_at`がないため、
  `sourceVersion`は正規化payloadのSHA-256とする
- snapshot pagination、安定したID cursor、error code、idempotency key、archive semanticsを定義する
- Awareness本番DBとTET2本番DBをread-onlyで集計し、次を件数だけ出す
  - 正規化emailで一意に一致
  - Awarenessのみ、TET2のみ
  - 大文字小文字・空白・Unicode正規化で衝突
- 同一emailの属性不一致
- group名の衝突
- TET2でnameが空のtarget、Awarenessで未知のcompany名
- Awarenessのglobal unique email/group名と、対象tenantおよび`LOCAL_LEGACY` rowの正規化衝突
- PIIを含まないmigration reportを保存し、個別の競合詳細は一時保護ファイルだけに出す
- Phase 1 tenant IDを設定値として確定し、API payloadからtenantを任意指定できない契約にする
- phishing result exportを同じv1 contractへ含め、`campaignId`, `tenantId`, `targetId`, `trackingId`,
  `contentNo`, event timestamps, snapshot/commit状態、pagination、未知targetの扱いを定義する

### Verification

- contract exampleをJSON Schemaで検証する
- 集計scriptがread-only接続以外を拒否するtestを追加する
- 件数合計が各systemのactive/archived総数と一致する

### Exit criteria

- 自動対応できる行と人の判断が必要な行が明確に分かれる
- tenant、field ownership、archive、conflict時の挙動に未決事項がない
- 対象tenantの正規化email/group名衝突が0、または全件に明示的な解決方針が付いている

### Rollback

文書とread-only reportのみ。branchをrevertし、本番への影響はない。

## Step 2 — TET2にintegration schemaとtenant固定service APIを追加

想定branch: `feat/awareness-participant-api`

### Files

- `web/api/integrations/awareness_targets.php`（新規）
- `web/lib/IntegrationAuth.php`（新規）
- `web/lib/TargetService.php`（既存targets処理の安全な再利用境界、新規）
- `web/api/targets.php`（`TargetService`利用へ最小変更）
- `web/db/MigrationRunner.php`、`web/db/schema.sql`
- `web/tests/api_awareness_targets_test.php`（新規）
- `web/tests/integration_idempotency_test.php`（新規）
- `.env.example`相当の運用文書（secret値は含めない）

### Tasks

- 人間向けsession/CSRF APIとは別に、Authorization Bearer tokenのintegration APIを追加する
- tokenからtenantをserver側で決定し、requestのtenant指定を信用しない
- token比較は`hash_equals`、未設定時はfail-closed、TLS必須、応答は必要最小限にする
- `integration_idempotency_keys` tableにtenant、key、request hash、処理状態、response、期限を保存する。
  同じkeyで異なるrequest hashは409、同じrequestはprocess再起動後も保存済みresponseを返す
- `GET snapshot`でactive/archived targetとgroup所属をID cursor paginationで取得できるようにする。
  pagination中の変更混入を防ぐため、request開始時の最大target IDをsnapshot境界として返す
- `POST upsert`はemailとtarget IDの整合性をtenant内で検証し、idempotency keyを受け付ける
- `POST archive`は物理削除せず、既存campaign/education履歴を保持する
- `groups`へ`status`と`archived_at`を加えるexpand migrationを作り、人間向け/API向け双方でgroup削除を
  archiveへ変更する。既存Delivery/campaignが参照するgroupとmembershipの履歴を物理削除しない
- 同じ認証境界にtarget ID付きphishing result snapshot APIを追加し、Step 1 contractへ適合させる
- 人間向け`targets.php`とintegration APIが同じvalidation・transactionを使うようserviceへ分離する
- auditにはactor種別、target ID、結果だけを記録し、氏名・email・tokenを記録しない
- IP allowlistは運用環境で利用可能なら追加し、token rotation手順を文書化する

### Verification

```bash
php web/tests/api_awareness_targets_test.php
php web/tests/api_targets_test.php
bash web/tests/run.sh
find web -name '*.php' -type f -exec php -l {} \;
```

追加test:

- tokenなし、不正token、未設定tokenは401/503で拒否
- tenant越境ID、requestでのtenant差し替えは拒否
- 同一idempotency keyの再送で重複作成しない
- archived targetを再同期できるが物理削除しない
- groupが別tenantの場合はtransaction全体を失敗させる
- group archive後も既存campaign/教育配信の参照が残る
- 保存済みidempotency responseをPHP process再起動相当の再初期化後も返す

### Exit criteria

- synthetic DBでintegration CRUDとfull snapshot取得がtenant越境なしに完了する
- 既存targets APIの挙動と全testが維持される

### Rollback

API routeをweb serverから無効化し、旧codeへ戻す。追加table/columnはexpand-onlyで残して旧codeから無視できる。
group archive migration適用前の物理削除挙動へは戻さず、必要時は管理画面をread-onlyにする。

## Step 3 — Awarenessに安定したTET2外部identityを追加

想定branch: `feat/respondent-external-identity`

### Files

- `prisma/schema.prisma`
- `prisma/migrations/<timestamp>_add_tet_participant_identity/migration.sql`
- `models/respondent.ts`
- `models/group.ts`
- `lib/validations/respondent.ts`
- schema migration test（新規）

### Data model

- `Respondent.tetTenantId Int?`
- `Respondent.tetTargetId Int?`
- `Respondent.source` enum相当（`TET2`, `LOCAL_LEGACY`）
- `Respondent.lastSyncedAt DateTime?`
- `Respondent.syncVersion String?`（TET2 payloadの`sourceVersion`）
- `@@unique([tetTenantId, tetTargetId])`
- Groupにも`tetTenantId`, `tetGroupId`のnullable外部identity、`source`、`isActive`、複合uniqueを追加する。
  TET2でgroupが削除されても、既存Deliveryが参照するAwareness groupは非activeで保持する
- `ParticipantMutation`にidempotency key、command hash、remote target ID、
  `PENDING/REMOTE_COMMITTED/PROJECTED/FAILED`、retry情報を保存する
- `ParticipantSyncRun`と`ParticipantSyncItem`にrespondent/group ID、external identity、operation、
  before/after state、membership deltaを保存する。before stateはDBと同等のaccess control下に置き、保持期限を定める
- `PhishingSyncPending`にtenant/target/campaign/tracking ID、payload hash、event summary、status、retry countを保存する。
  unknown targetを扱うために既存`PhishingResult.respondentId`をnullableにはしない

既存`Respondent.id`、`randomId`、全外部キーは維持する。`randomId`は移行期間のlegacy fallbackに限定する。

### Tasks

- nullable columnだけを先に追加するexpand migrationにする
- migration前後でRespondent、DeliveryAssignment、Answer、PhishingResult等の件数とFK整合性を確認する
- TET2由来fieldを通常のlocal model更新から変更できない境界を追加する
- external identityを持たない既存rowは`LOCAL_LEGACY`として明示する
- TET2のcompany文字列はAwarenessの既存Company.nameへ正規化一致した場合だけ自動linkする。
  未知の会社名や同名衝突は、必須かつuniqueな`Company.abbreviation`を推測せず競合にする
- Prisma client regenerate後にstrict TypeScriptを通す
- global unique constraintに達する前に正規化email/group名の衝突を検出し、migration/applyを停止する

### Verification

```bash
npx prisma validate
npx prisma migrate diff --from-empty --to-schema-datamodel prisma/schema.prisma
npx vitest run
npx tsc --noEmit
npm run lint
```

### Exit criteria

- 既存rowを変更せずmigrationできる
- external identity重複をDB constraintで拒否する
- rollback用backupから旧schemaへ復元できるrehearsalが完了する

### Rollback

本番適用前はcommit revert。本番適用後は新columnを参照しない旧appへ戻せるexpand-only設計とし、column削除はしない。

## Step 4 — Awarenessにpreview可能な冪等同期engineを追加

想定branch: `feat/respondent-tet2-sync`

### Files

- `lib/tet2-client.ts`（新規）
- `lib/participant-sync.ts`（新規）
- `lib/validations/tet2-participant.ts`（新規）
- `app/api/settings/sync-participants/route.ts`（新規）
- `app/api/cron/sync-participants/route.ts`（新規）
- `app/(admin)/settings/page.tsx`
- `lib/__tests__/participant-sync.test.ts`（新規）

### Tasks

- TET2応答をschema validationし、不正payloadをDBへ入れないtyped clientを作る
- cursorを使って毎回full snapshotをpullし、`sourceVersion`が変化したrowだけを
  external identityでRespondent/Groupへupsertする
- 新規row、更新row、archive、競合、no-opの件数を返すdry-runを既定動作にする
- apply時は既存Respondent UUIDと履歴を保ったまま属性とactive状態だけを更新する
- 初回だけ正規化emailで`LOCAL_LEGACY` rowとの候補照合を行い、一意一致だけを紐付ける
- 不一致・複数一致は自動作成/上書きせずconflict queueへ出す
- TET2に存在しない既存Awareness rowは`LOCAL_LEGACY`のまま保持し、管理画面に警告する
- groupはTET2 external IDで同期し、既存の教育deliveryが参照するAwareness group IDは維持する
- CRON_SECRET保護の定期同期とadminの手動preview/applyを同じserviceで実行する
- timeout、retry、指数backoff、1回だけの同時実行lockを追加する
- sync runのstatus/count/cursor/error summaryを保存し、PIIは保存しない
- full snapshot完了時にだけ「sourceに存在しない」を判定し、途中page失敗で既存rowを誤archiveしない
- item journalへ変更対象、before/after state、membership deltaを記録し、run単位でreconcile/補償できるようにする

### Verification

```bash
npx vitest run lib/__tests__/participant-sync.test.ts
npx vitest run
npx tsc --noEmit
npm run lint
npm run build
```

追加test:

- 同じpayloadを2回applyして2回目がno-op
- existing UUIDと教育履歴FKが変わらない
- archiveが`isActive=false`だけを変更する
- email変更後もexternal identityで同じRespondentを更新する
- malformed payload、cursor loop、network途中失敗で部分成功を成功扱いしない
- 別tenant payload、external ID重複、曖昧emailを拒否する
- 途中page失敗後の再実行、およびscan中のsource更新が次回full snapshotで収束する

### Exit criteria

- synthetic dataでpreview件数とapply件数が一致する
- 再実行で重複、履歴欠損、group membership増殖が起きない

### Rollback

cronを停止し、同期routeを無効化する。Awarenessの既存UUIDとTET2 dataは変更しない。

## Step 5 — phishing result照合をstable target identityへ切り替える

想定branch: `fix/phishing-result-target-identity`

### Files

- TET2のAwareness report integration API（Step 2 routeへ追加または専用route）
- `/root/SecurityAwareness/lib/phishing-sync.ts`
- `/root/SecurityAwareness/models/phishing.ts`
- `/root/SecurityAwareness/lib/__tests__/phishing-sync.test.ts`

### Tasks

- Step 1で定義しStep 2で実装した結果APIからtenant IDとtarget IDを取得する
- Awarenessは`(tetTenantId, tetTargetId)`でRespondentを解決する
- 既存campaignの移行期間だけ`randomId` fallbackを許可し、利用件数をmetricにする
- 未同期targetは結果を破棄せずpending/conflictとして記録し、participant sync後に再処理する
- campaign/result upsertを冪等にする
- fallback利用が0になった後に廃止できるfeature flagを追加する

### Verification

- target ID照合、legacy fallback、未知target、別tenant、再同期のbehavioral test
- campaignごとにtracking IDが変わっても同一Respondentへ蓄積されることを確認する
- 両projectの全test/buildを実行する

### Exit criteria

- 新規campaign結果は100% stable target identityで紐付く
- 未解決resultが可視化され、silent skipされない

### Rollback

feature flagでlegacy `randomId`照合へ戻す。追加payload fieldは後方互換として残す。

## Step 6 — Awarenessの受講者管理をTET2へのwrite-through画面にする

想定branch: `feat/unified-participant-management`

### Files

- `app/(admin)/respondents/page.tsx`
- `app/(admin)/respondents/[id]/page.tsx`
- `app/(admin)/respondents/actions.ts`
- `app/(admin)/respondents/respondent-form.tsx`
- `app/(admin)/respondents/respondent-table.tsx`
- `app/(admin)/respondents/csv-import.tsx`
- `app/(admin)/respondents/bulk-actions.tsx`
- `app/api/respondents/**`
- component/API tests（追加）

### Tasks

- 画面名称を「受講者・訓練対象者」に変更し、TET2同期状態と最終同期時刻を表示する
- create/update/archiveはTET2 integration APIを先に成功させ、そのtarget IDをAwarenessへ同期する
- TET2失敗時はAwarenessだけを更新せず、retry可能なエラーとして表示する
- 分散transactionは存在しない前提とし、TET2成功後にAwareness更新が失敗した操作を
  `REMOTE_COMMITTED_LOCAL_PENDING`として記録するoperation journalを追加する
- pending operationは同じidempotency keyで再処理し、定期full snapshotでも最終的に収束させる。
  UIはremote commit済みを単なる失敗と表示せず「同期保留」として識別する
- requestごとにidempotency keyを発行し、二重submitを防ぐ
- TET2由来rowと未移行`LOCAL_LEGACY` rowを明確に表示する
- CSV importはdry-runで作成、更新、競合、エラーをpreviewし、確定後にTET2へbatch upsertする
- CSV batchは行ごとにoperation journalを持ち、途中失敗後に未完了行だけをresume/reconcileできる
- group編集もTET2を先に更新し、Awarenessへ同期する
- bulk deleteはarchiveへ名称と挙動を変更する
- 認可、入力validation、CSRF/Better Auth境界を既存routeと同等以上に保つ

### Verification

- create/edit/archive/CSV/groupのAPI test
- TET2 timeout、409 conflict、401、部分batch失敗のUI test
- `npx vitest run`, `npx tsc --noEmit`, `npm run lint`, `npm run build`
- Playwrightで管理者の主要journeyをsynthetic環境で確認する

### Exit criteria

- Awareness画面から一度登録すれば両systemで同じ人物として参照できる
- TET2が失敗した操作でAwarenessだけが更新されない
- 過去のdelivery/answer/phishing resultが同じRespondent UUIDから閲覧できる

### Rollback

画面をread-onlyにし、mutationを停止する。旧local CRUDを戻せるのはTET2未連携の`LOCAL_LEGACY` rowだけとし、
TET2由来rowの正本をAwarenessへ分岐させない。同期済みexternal identityは保持する。

## Step 7 — 移行rehearsalと段階配備

想定branch: `chore/participant-unification-rollout`

### Tasks

- 両本番DBのtimestamp付きbackupとchecksumを作成し、restore rehearsalを行う
- 本番copy上でStep 1のmatchingを実行し、dry-run reportを利用者へ提示する
- 自動一致、一方のみ、属性競合、group競合を人が確認できるCSV/画面へ分ける
- maintenance window中にAwareness schema expand migrationを適用する
- TET2 integration APIをread-onlyで有効化し、participant sync previewだけを実行する
- preview承認後に一意一致のlinkとTET2-only targetのAwareness作成をapplyする
- Awareness-only rowは自動削除せず、`LOCAL_LEGACY`として残す
- write-throughを少人数pilotで有効化し、create/update/archive/group/resultを確認する
- 問題がなければ全管理者へ有効化し、定期pull syncを開始する
- 24時間、7日、30日で件数差、sync error、legacy fallback、unresolved conflictを確認する

### Production verification

- TET2 active target数とAwareness TET2-active respondent数が一致する
- external identityの重複が0
- campaign/education履歴件数が移行前後で一致する
- `PRAGMA foreign_key_check`とPostgreSQL FK checkに異常がない
- create/update/archive/group変更が両画面で一致する
- tokenをlog、HTTP response、browser bundleへ露出していない

### Rollback

- write-throughとcronをfeature flagで停止する
- Awarenessをread-onlyにする。必要時も旧local CRUDは`LOCAL_LEGACY` rowだけに限定する
- schemaはexpand-onlyなので旧appを再配備できる
- data整合性に問題があれば、sync itemのbefore stateとmembership deltaから変更対象だけを補償する。
  TET2正本自体の値が誤っている場合はTET2を訂正して再同期し、backupから全DBを無条件上書きしない

## Step 8 — legacy経路を観測後に縮退

開始条件: 30日間、未解決競合0、legacy result fallback 0、重大sync error 0。

### Tasks

- Awarenessの独立したlocal create/updateを無効化する
- `randomId` result fallbackを無効化し、後方互換columnの削除は別migrationとして計画する
- `LOCAL_LEGACY` rowをTET2へ作成・紐付け、または明示的にarchiveする
- 運用手順、障害対応、token rotation、tenant変更禁止事項をREADMEへ追記する
- 将来のAwareness multi-tenant化は別PRD/計画へ分離する

### Rollback

観測期間中はfeature flagを保持し、legacy経路を再有効化できるようにする。

## 全体のDefinition of Done

- 人物の追加・編集・archive・group変更を一つの管理操作で両systemへ反映できる
- TET2とAwarenessで人物IDの対応が一意で、email変更後も対応が維持される
- 教育配信と標的型メール訓練が同じ対象者・group集合を利用できる
- 既存履歴の件数・外部キー・閲覧性が維持される
- tenant越境、無認証、二重作成、曖昧match、partial failureの自動testがある
- TET2 PHP test全件、Awareness Vitest/typecheck/lint/build、統合E2Eが成功する
- dry-run、backup、rollback、pilot、監視手順が本番運用文書に記録される

## 今回の対象外

- Awareness自体のmulti-tenant化
- TET2とAwarenessのDB物理統合
- SSO/SCIM、人事マスターとの直接連携
- campaign/教育履歴の過去ID再採番
- mail server、port、SSL、DKIM、認証設定の変更
- 受講者以外のAwareness admin accountとTET2 login userの統合

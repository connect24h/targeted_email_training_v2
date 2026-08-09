# SecurityAwareness 人物マスター連携

TET2を標的型メール訓練対象者・groupの正本とし、SecurityAwarenessへ人物と訓練結果を投影する。
個人を追跡用random IDやメールアドレスではなく、`tenantId + targetId`で識別する。

## API

Endpoint: `/api/integrations/awareness_targets.php`

- `GET ?action=snapshot&cursor=0&limit=200[&boundary=N]`: active/suspended/archivedを含む人物snapshot
- `GET ?action=group_snapshot`: active/archivedを含むgroup snapshot
- `GET ?action=phishing_results&cursor=0&limit=200`: campaign target単位の訓練結果
- `POST ?action=upsert`: 人物作成・更新
- `POST ?action=archive`: 人物の論理archive
- `POST ?action=group_upsert`: group作成・更新
- `POST ?action=group_archive`: groupの論理archive

認証は`Authorization: Bearer <token>`、更新系は8〜128文字の`Idempotency-Key`が必須。
tenantはrequestから受け取らず、server側の`TET2_AWARENESS_TENANT_ID`で固定する。
snapshotの初回responseに含まれる`boundary`を後続pageでも指定すると、同期中の追加を次回へ分離できる。
`is_test=1`の対象者とその訓練結果は、本番受講者・統計へ検証データを混ぜないためsnapshotから除外する。
役職カテゴリは`役員 / 管理職 / 一般従業員`を正規値とし、旧称`社員`は入力時に`一般従業員`へ変換する。
人物responseの`archivedAt`は論理削除日時で、activeへ戻すと`null`になる。

## 環境変数

```text
TET2_AWARENESS_TOKEN=<24文字以上の十分にランダムな値>
TET2_AWARENESS_TENANT_ID=1
```

APIはHTTPSを必須とする。同一hostのreverse proxy以外で`X-Forwarded-Proto`を使う場合だけ、
proxyが外部から同headerを除去・上書きすることを確認した上で`TET2_TRUST_PROXY_HEADERS=1`を設定する。
`TET2_INTEGRATION_ALLOW_HTTP=1`はlocal test専用で、本番では設定しない。

## Ownershipと障害復旧

- 人物・group・所属・active状態の正本: TET2
- 教育履歴・quiz回答・配信履歴の正本: SecurityAwareness
- TET2更新成功後にAwareness投影が失敗した場合: mutation journalを保持し、次回full snapshotで収束
- 未同期の`targetId`を含むphishing結果: pending queueへ保持し、人物同期後に再処理
- 削除: 両systemとも物理削除せずarchive

本番適用は、DB backup、migration copy rehearsal、API疎通、SecurityAwareness previewでconflict件数を確認してから行う。

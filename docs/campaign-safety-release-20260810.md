# キャンペーン生成・送信安全化（2026-08-10）

## 障害原因

`/opt/training/bin/__BeaconMst.png`がdeploy時に`root:root 0750`となり、生成処理の
`www-data`と送信workerの`training`が読み取れなかった。生成scriptが行単位の失敗を
無視して終了コード0を返し、必須添付pathが空でも本文だけ送信できる複合障害だった。

## 反映内容

- deploy modeをsource任せにせず、static 0644・Python 0755・`root:root`へ固定
- `www-data`・`training`によるビーコン素材のread検証
- 必須ビーコン・link・添付の完全性検証と非0終了
- 必須添付の欠落・空file・read不能・管理外pathを送信前に拒否
- 生成成功後にだけscheduleを作成し、workerは`scheduled/running`だけ取得
- 停止flagを送信processが解除しないようにし、`paused`を`done`で上書きしない
- 全content配信のsplit/slowを重複しない従業員項番で分割
- TEST配信を本番対象全件の転送からcontent×test宛先の限定matrixへ変更
- backup/rollback manifestへmode・uid・gidを追加

## 本番反映

- commits: `7dbf15d`, `fc19c9b`, `0502174`, `f82c883`
- deploy scope: `campaign-safety`（runtime 8ファイル限定）
- backup ID: `20260810T175058-354724`
- DB migration: なし
- mailserver変更: なし
- worker: 再起動済み、active
- 配備後queued/running: 0件
- source/production hash: 8ファイル一致
- `__BeaconMst.png`: `root:root 0644`、両runtime userでread可能

## 検証

- PHP: 全42 test file PASS
- Python: 全12 test case PASS
- deploy/rollback rehearsal: PASS
- Bash/PHP syntax: PASS
- HTTPS endpoint: Basic認証の401を正常応答

## 次期アーキテクチャ候補

今回の修正で誤送信はfail-closedになった。大量生成のHTTP request時間を切り離す場合は、
`draft → preparing → ready → scheduled → running`の非同期準備jobと、campaign単位の
artifact manifest・atomic publishを別migrationとして導入する。

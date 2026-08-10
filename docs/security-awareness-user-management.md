# SecurityAwareness ユーザ管理統合

## 管理UIの正本

SecurityAwarenessの管理機能はTET2の管理UI内で提供する。独立した管理UIは運用しない。

- 教育配信
- 教材バンク
- 教育レポート
- ユーザ管理
- テナント管理（システム管理者のみ）

## 人物マスター

「ユーザ管理」の「受講者・訓練対象者」タブが人物マスターの正本である。
データは既存の`targets`と`target_group`に保存し、教育配信と標的型メール訓練の双方で直接利用する。
別DBへの同期、二重入力、last-write-wins方式の双方向同期は行わない。

「管理画面ユーザ」タブはTET2へログインする管理アカウントを扱う。これは受講者とは別の`users`データであり、
組織管理者以上にだけ表示・APIアクセスを許可する。

## 権限

- 閲覧者: 受講者・訓練対象者の閲覧
- オペレータ: 受講者・訓練対象者の登録、編集、CSV入出力、論理削除・復活
- 組織管理者: 上記に加えて管理画面ユーザの管理
- システム管理者: 上記に加えてテナント管理

## 退役した構成

2026-08-10に次を停止・退役した。

- 旧standalone appとPostgreSQL（container停止、`/root/_archive/SecurityAwareness-retired-20260810`へ未commit変更を含めて保全）
- `security-awareness-participant-sync.timer`とservice
- TET2の外部participant Bearer APIおよびApache公開例外

元の`/root/SecurityAwareness`は退役案内だけのtombstoneとし、旧projectを同じpathで誤って改修・再稼働しない。
`/root/AGENTS.md`と`/root/_archive/AGENTS.md`の双方でarchive配下の変更を禁止している。

外部連携用のexpand-only migrationは、適用済みDBとの整合性とrollback安全性のため残す。
アプリケーションからは参照せず、将来のschema整理は別migrationで行う。

## 将来の4眼承認

現時点では作業者が一人のため4眼承認を必須化しない。将来、運用担当者が複数になった時点で、
教育配信開始・標的型メール訓練開始・対象者の一括変更にmaker-checker承認を追加する。
それまでは監査ログ、preview、論理削除、backupを安全境界として維持する。

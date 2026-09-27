# TET v2 文書一式

標的型メール訓練およびセキュリティ教育システム（TET v2）の公式文書です。
2026-09-24 時点の実装（リポジトリ HEAD `9aafa33`）に基づきます。旧版 v1（`/tet`、`/opt/training/git-repo/docs/`）の文書とは別に管理します。

## 文書の一覧

| # | ファイル | 用途 | 想定読者 |
|---|---|---|---|
| 01 | [01-system-specification.md](01-system-specification.md) | システム仕様書。機能要件、非機能要件、入出力 | 企画、要件定義 |
| 02 | [02-basic-design.md](02-basic-design.md) | 基本設計書。全体構成、コンポーネント、データ分離、送信の流れ、認証 | アーキテクト |
| 03 | [03-detailed-design.md](03-detailed-design.md) | 詳細設計書。API 仕様、DB スキーマ、マイグレーション、ワーカーとタイマー、生成物 | 開発者 |
| 04 | [04-operations-manual.md](04-operations-manual.md) | 運用手順書。配備、ロールバック、日常運用、障害対応 | 運用担当 |
| 05 | [05-user-manual.md](05-user-manual.md) | 利用マニュアル。管理者の画面操作、受講者の操作 | 管理者、受講者 |
| 06 | [../proposals/goldwin-fy2027-proposal.md](../proposals/goldwin-fy2027-proposal.md) | 提案書（ゴールドウイン様向け 2027 年度）。社内用対応表は同じディレクトリの appendix | 営業、顧客 |
| 07 | [07-seculio-benchmark-update-design.md](07-seculio-benchmark-update-design.md) | アップデート設計書。セキュリオの実機調査にもとづく差の分析と、機能ごとの設計、リリース計画 | 企画、開発者 |
| 08 | [08-feature-expansion-review.md](08-feature-expansion-review.md) | 機能拡張の検討。07 の差 G01〜G15 に続く教育機能の差 G16〜G23（教材の画像表示、設問の画像と選択肢ごとの解説など）と推奨の実装順。第7章に利用者の確認結果 | 企画、開発者 |

## 関連文書

- リポジトリ全体の説明: [../../README.md](../../README.md)
- 配備とロールバックの安全手順: [../../deploy/README.md](../../deploy/README.md)
- 教材バンクの詳細: [../education-material-bank.md](../education-material-bank.md)
- 教育コンテンツ（題材選定、教材の企画、アウェアネス小問）: [../content/README.md](../content/README.md)
- ユーザ管理統合: [../security-awareness-user-management.md](../security-awareness-user-management.md)
- 将来の機能候補と判断記録: [../product-roadmap.md](../product-roadmap.md)

## 位置づけ

本書一式は、実装を根拠に記述しています。各文書の末尾に検証報告を付け、コードで確認した項目と、本番の稼働状態など未確認の項目を区別しています。実在の顧客情報、パスワード、暗号鍵、IP アドレスは本文に含めません。

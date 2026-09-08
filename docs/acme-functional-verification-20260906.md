# Acme株式会社 機能修正・総合検証（2026-09-06〜2026-09-08）

## 対象と境界

- 対象システム: `https://filesend.cojp.online/tet2/`
- 対象テナント: Acme株式会社（tenant_id=1）
- 実送信・教育配信の対象は次の有効な3名だけに限定した。
  - target_id=9: `connect24h.now@gmail.com`
  - target_id=10: `cn24h@hotmail.com`
  - target_id=11: `hamamoto@sharedsecurity.co.jp`
- テナント管理とユーザ管理は操作していない。対象者、管理ユーザー、共通テンプレート、共通マスタも変更していない。
- 既存の本番ログ、訓練イベント、教育履歴は削除していない。

## 確認した問題と変更

| 問題 | 原因・対応 |
|---|---|
| 定期キャンペーンを削除できない | APIと画面に確認付き削除を追加。ルール、対象グループ関連、生成履歴を削除し、生成済みキャンペーンと送信・訓練履歴を保持する。 |
| 元キャンペーン削除後にも定期ルールが動く | 元キャンペーンの論理削除と関連する有効ルールの停止を同一transactionで行う。 |
| テンプレートの項番欠落 | 件名・本文、偽ログイン、ネタバラシ、eラーニングの全表示行に項番を表示。対応する件名または本文が欠けた行も省略しない。 |
| テンプレートの安全性 | `scenario_key` のonclickへの直接挿入を廃止し、escapeしたdata属性から渡す。 |
| キャンペーン削除後のログ残存 | キャンペーンは論理削除し、送信ログと訓練イベントは監査・証跡として保持する。画面にも説明を追加した。 |
| 教育配信の削除導線なし | 削除ボタンを追加。未受講配信は削除でき、受講中・完了済みは履歴保護のため削除を拒否する。 |
| 教育削除と受講開始の競合 | 履歴保護条件をDELETE文に含め、受講開始後にも割当を再確認する。 |
| 教材バンクUI | 教材に項番、設問操作に文字ラベルを追加。設問・カテゴリ作成を設問見出しの隣に配置し、viewerには編集操作を表示しない。 |
| 教育レポート残存 | 教育配信と受講履歴はキャンペーンから独立した教育証跡として保持する旨を画面に表示する。 |
| 削除済み訓練から教育対象を再追加 | 自動追加とrisk対象抽出で、キャンペーンのtenant一致と未削除を確認する。既存履歴は保持する。 |
| 日次リスクスコアがSQLite lockで欠落 | 02:30に同時起動するsnapshotとreport ingestが、deferred transactionのread→write upgradeで競合していた。リスクスコアbatchを`BEGIN IMMEDIATE`へ変更し、競合時は開始前に待機させる。 |
| 教育配信launchが500 | transaction closure内で参照する`$delivery`がcaptureされていなかった。closureへ渡し、個別配信launchの割当・メール件数・token期限を実行経路で確認する回帰テストを追加した。 |

## 本番ブラウザ検証

Acme株式会社へ切り替え、次の14画面を認証済みブラウザで表示した。

1. ダッシュボード
2. キャンペーン
3. 定期キャンペーン
4. レポート
5. リスクダッシュボード
6. グループ
7. テンプレート
8. 役職マスタ
9. 対象者集計
10. マスタ管理
11. ログ管理
12. 教育配信
13. 教材バンク
14. 教育レポート

全画面で表示内容があり、error toast、console error、失敗したapp responseは0件だった。テナント管理とユーザ管理の画面は開いていない。

ログ管理は表示される12タブすべてを読み込んだ。送信ログ、訓練イベント、スケジュール、訓練結果、訓練結果ログ、リンク/ビーコンファイル、報告メール、操作ログ、生メールログ、生Webログ、WebアクセスLog、返信者を確認した。

テンプレートは次を確認した。

- 件名＋本文: 14行。数値項番あり。
- 偽ログイン: 8行。各シナリオ名に`#項番`あり。
- ネタバラシ: 3行。数値項番あり。
- eラーニング: 2行。数値項番あり。

外側Apache認証にはE2E専用の一時ユーザーを追加した。各実行の終了時に認証ファイルを元へ戻し、実行前後のSHA-256一致と既存`admin`行の維持を確認した。最終的な認証ユーザーは`admin`だけである。

## 3名への実送信

### 標的型メール訓練

- campaign_id: 105
- 名称: `[総合テスト] 3名×4形式 2026-09-08-231113`
- `is_test=1`、`content_delivery=all`
- 対象IDとredirect先を上記3名だけに固定
- 形式: リンク、添付PDF、フォーム、QR付きPDF
- 送信結果: 3名 × 4形式 = 12件すべて`sent`、pending 0、schedule 1件`done`
- 各対象者についてcontent_no 1〜4が1件ずつ存在し、対象外の送信先は0件
- 生成物:
  - beacon PNG: 12/12件
  - link HTML: 12/12件
  - 添付PDF: 3/3件
  - QR付きPDF: 3/3件

Postfixログでも、Gmail、Hotmail、sharedsecurity.co.jpの各受信MTAが4通ずつを2xxで受理した。送信後にcampaign_id=105を論理削除し、キャンペーン一覧から消えたことを確認した。一方、送信ログ12件とscheduleは保持されている。

削除済みcampaign_idをログAPIの絞り込み値へ直接指定すると404になる。ログ管理の全体一覧には削除済みcampaignの12件が残り、campaign名付きで確認できる。

### 教育配信

- 未送信の削除確認draft（delivery_id=6）を作成し、削除後に一覧から消えたことを確認した。
- delivery_id: 7
- 名称: `[総合テスト] 3名アウェアネスクイズ 2026-09-08-231525`
- 個別対象: target_id 9、10、11だけ
- launch結果: assigned 3、mail_sent 3、question_count 3
- Postfixログで3つの受信MTAが案内メールを2xxで受理
- 3件のaccess tokenそれぞれで受講開始、3問回答、提出、完了まで実行
- 教育レポート: assigned 3、completed 3、respondent 3、完了率100%

回答操作はシステム経路の確認を目的として全問の先頭選択肢を選んだため、3名ともスコアは0%である。知識評価の結果としては扱わない。

## 日次リスクスコアの復旧

- 2026-09-07に修正版を配備後、`tet2-edu-snapshot.service`を同日手動実行し成功した。
- 2026-09-08 02:30のtimer自動実行でもSQLite lockは再発せず、1876件を保存した。
- lockで欠けていた2026-08-24、2026-08-27、2026-09-03を修正版でbackfillした。
- 2026-08-18〜2026-09-08に欠落日はなく、各対象日の件数とDB整合性を確認した。

## 自動回帰と直接検証

- PHP: `bash web/tests/run.sh` 全67テストファイル成功。
- 教育launch回帰: 修正前に本番と同じ`Undefined variable $delivery`とTypeErrorを再現。修正後は個別2名の割当、mail成功件数、assignment状態、token expiryを確認。
- Python: `python3 -m unittest discover -s bin/tests -p 'test_*.py'` 39件成功。
- JavaScript: 定期キャンペーン、教育配信、テンプレート、リスクの対象テスト成功。
- PHP/JavaScript構文、`git diff --check`、キャッシュ参照hash検証成功。
- 本番DB: `PRAGMA quick_check`は`ok`、`PRAGMA foreign_key_check`は違反0件。
- `apache2`、`tet2-worker.service`、`tet2-edu-snapshot.timer`、`tet2-report-ingest.timer`はactive。
- セキュリティを含む差分レビューで未解決の重大所見なし。

証跡:

- `/tmp/tet2-prod-e2e-20260908/readonly-report.json`
- `/tmp/tet2-prod-e2e-20260908/*.png`
- `/tmp/tet2-prod-mutating-e2e-20260908/mutating-report.json`
- `/tmp/tet2-prod-mutating-e2e-20260908/education-report.json`

## 本番反映

| Backup ID | 内容 |
|---|---|
| `20260906T201004-859817` | 定期キャンペーン削除、テンプレート項番、教育削除・教材UI・履歴説明など |
| `20260907T173046-1745359` | SQLite即時transactionによる日次リスクスコアlock修正 |
| `20260908T231823-2928300` | 教育配信launchのclosure capture修正と回帰テスト |

最終配備後、local/prod hash一致、PHP構文、HTTPS入口、DB整合性を確認した。migrationやサービス再起動は不要で、実施していない。

## 残る確認範囲

- メールは送信元Postfixと受信側MTAの2xx受理まで確認した。Gmail/Hotmail等の受信箱・迷惑メールフォルダ内での表示は各メールボックスを開いていないため未確認。
- 標的型メールの開封、リンククリック、偽ログイン入力は受信者行動として実行していない。送信データ、HTML、添付、QR生成とログ保持までは確認済み。
- campaignの物理ファイルは論理削除から90日後にpurge対象となる。今回の検証では履歴保護のため削除していない。

# KPI 基準値（2026-09-05、報告動線の実装前）

`docs/market-research-and-feature-plan-2026-09.md` フェーズ 0 の記録。以後の変更（報告イベント取込、その場介入、再訓練推奨）の効果は
この値との比較で説明する。数値は本番 DB の `VACUUM INTO` コピーに対して `api/report.php` の `report_handle_campaigns()`
（`test_filter=prod`）を呼んで取得した。率の定義は同ファイルのコードに一本化されているため、手書き SQL は使っていない。

## 対象

- テナント: goldwin（`tenants.id=3`）
- 本番キャンペーン（`is_test=0`, `status=done`）: id=104「ゴールドウィンテスト 第１回」のみ。開始 2026-08-20 10:00、終了 2026-08-21 23:25
- 対象者: active かつ `is_test=0` が 1,876 名（テスト用 3 名は除外済み）

## キャンペーン id=104 の指標

| 指標 | 定義（`report.php`） | 値 |
|---|---|---|
| 対象数 | `campaign_targets`（テスト対象者除く） | 1,876 |
| 送信数 / 送信率 | `sent_count` / 対象数 | 1,876 / 100% |
| 開封数 / 開封率 | ビーコン取得の distinct tracking_id / 対象数 | 181 / 9.6% |
| クリック数 / サイト表示率 | `link-*.html` 取得の distinct tracking_id / 対象数 | 185 / 9.9% |
| 認証数 | 偽ログインへの入力 distinct tracking_id | 108 |
| 認証率（認証 ÷ 表示） | `auth_rate` | 58.4% |
| 認証率（認証 ÷ 対象数） | `auth_target_rate` | 5.8% |
| 報告数 / 報告率 | `event_type='report'` の distinct tracking_id / 対象数 | 0 / 0%（取込経路が未実装のため） |
| resilience 比（報告 ÷ クリック） | `resilience_ratio` | 0 |
| bot クリック | `click_bot` | 0 |

## リスクスコア（`human_risk_scores`、算出日 2026-09-05）

| 帯 | 人数 |
|---|---|
| high（≥60） | 130 |
| medium（≥30） | 1,746 |
| low | 0 |
| 平均スコア | 48.7 |

low が 0 なのは、基準点 50 から無反応者が clean campaign クレジット最大 20 を引いても 30 に留まる設計のため。
推移: 08-30 124/1,752 → 09-01 128/1,748 → 09-05 130/1,746（high/medium）。09-03 は欠測（原因未確認）。

## 教育

- goldwin の教育配信は draft 1 件（`edu_deliveries.id=4`、フィッシング、対象 risk）、割当 0 件。教育完了率の基準値は無し。

## 次回の比較で見る指標

1. 報告率と resilience 比（実装後に 0 から動く。目標は次回訓練で報告率 5% 以上、resilience 比 0.5 以上）
2. 認証率（認証 ÷ 対象数）の低下
3. high 帯の人数と、high 帯のうち再訓練を受けた人の割合

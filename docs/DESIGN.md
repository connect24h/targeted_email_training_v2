# DESIGN.md: TET v2 管理画面の見た目の決まり

管理画面（`web/index.html`、`web/assets/app.css`）の色、文字、間隔、角の丸み、影、画面の骨組みの決まり。CSS の値はこの表の CSS 変数だけを使い、色のコードを直接書かない。受講画面（`take.php`）は対象外。

## 参考にしたサイトと採った点

| 参考 | 採った点 |
|---|---|
| Linear | 明るい灰色の地、細い区切り線、左の一覧の現在地を背景の色で示す |
| Vercel（Geist） | 強調の色を1色にしぼる、影を控えめにする、表は線だけで区切る |
| GitHub Primer | 上のバーと左の一覧を固定し、中央と右の欄だけを別々にスクロールする |

## 色

| 変数 | 値 | 使う所 |
|---|---|---|
| `--tet-bg` | `#f6f7f9` | 中央の地 |
| `--tet-surface` | `#ffffff` | カード、表、上のバー、右の欄 |
| `--tet-surface-2` | `#f9fafb` | 左の一覧、表の見出し、hover |
| `--tet-border` | `#e4e7ec` | 区切り線、カードの枠 |
| `--tet-border-strong` | `#d0d5dd` | 入力欄の枠 |
| `--tet-text` | `#101828` | 本文、見出し |
| `--tet-text-2` | `#475467` | 補足、ラベル |
| `--tet-text-3` | `#667085` | 注記、グループの見出し（白地でコントラスト比 4.8:1） |
| `--tet-accent` | `#2563eb` | 主なボタン、リンク、現在地、フォーカス |
| `--tet-accent-hover` | `#1d4ed8` | 主なボタンの hover |
| `--tet-accent-soft` | `#eff4ff` | 現在地の背景、選択中の行 |
| `--tet-danger` | `#d92d20` | 削除、エラー |
| `--tet-warning` | `#b54708` | 注意（文字に使える濃さ） |
| `--tet-success` | `#067647` | 完了 |
| `--tet-danger-soft` | `#fef3f2` | 危険の地（本番の表示、確認の問題点） |
| `--tet-warning-soft` | `#fffaeb` | 注意の地 |
| `--tet-success-soft` | `#ecfdf3` | 完了、準備ができた時の地 |

強調の色は `--tet-accent` の1色だけにする。状態の色（danger、warning、success）は状態を示す時だけ使う。

グラフ（Chart.js）は CSS 変数を読めないので、同じ値を直接書く。件数は `#2563eb`、注意は `#b54708`、危険や失敗は `#d92d20`、良い結果は `#067647`。

## 文字

- 書体: `-apple-system, "Segoe UI", "Hiragino Sans", "Noto Sans JP", "Yu Gothic UI", Meiryo, sans-serif`
- 大きさ: 12px（注記）、13px（表、ラベル）、14px（本文、既定）、16px（カードの見出し）、20px（画面の見出し）
- 太さ: 400（本文）、500（ボタン、一覧）、600（見出し）。700 は数値の強調だけ。

## 間隔、角の丸み、影

- 間隔: 4px の倍数（4、8、12、16、24、32）。中央の余白は 24px（幅 767px 以下は 16px）。
- 角の丸み: `--tet-radius-sm` 6px（ボタン、入力欄）、`--tet-radius` 10px（カード、右の欄）、999px（バッジ）。
- 影: `--tet-shadow-xs`（カード）、`--tet-shadow-md`（重ねて出す欄、トースト）の2段だけ。

## 画面の骨組み

| 領域 | 大きさ | スクロール |
|---|---|---|
| 上のバー | 高さ 56px 以上（文字を拡大すると伸びる） | しない（固定） |
| 左の一覧 | 幅 240px | 一覧だけで縦にスクロール |
| 中央 | 残り全部 | 中央だけで縦にスクロール |
| 右の欄（HELP） | 幅 360px | 欄だけで縦にスクロール |

- 画面全体は `100dvh` に固定し、ページ全体はスクロールさせない。
- 幅 1200px 以上では、HELP を中央に重ねず右の列として並べる。1199px 以下では右から重ねて出す。
- 幅 767px 以下では、左の一覧を引き出し式にし、開いている間は背景を暗くして、背景を押すか Escape で閉じる。
- 幅 767px 以下では、ボタンの高さと幅を 44px 以上にする。表は最低 640px の幅でセルを折り返さず、表の中だけを横にスクロールする。
- 左の一覧の項目は `href="#画面名"` を持ち、Tab で届いて Enter で移れる。画面を移ると、ブラウザのタブの表題を「画面名 | TET v2」にする。

## 部品

- ボタン: 主な操作は `btn-primary`（1画面に1つを目安）、ほかは `btn-outline-secondary`。削除は `btn-outline-danger`、緊急停止は `btn-danger`。hover、押下、disabled、フォーカスの見た目を必ず持つ。アイコンだけのボタンには `title` と `aria-label` を付ける。
- 絞り込みの切り替え（本番のみ、テストのみ、全部）: `btn-group` の `btn-outline-secondary` に、選ばれた方だけ `active` と `aria-pressed="true"` を付ける。主ボタンの青は使わない。
- カード: 白地、`--tet-border` の枠、`--tet-radius`、`--tet-shadow-xs`。見出しは 14px の 600。
- 表: 見出しは `--tet-surface-2` の地に 12px の `--tet-text-2`。行の区切りは線だけ。
- フォーカス: `outline: 2px solid var(--tet-accent); outline-offset: 2px`。
- 動き: 開閉は 150〜200ms。`prefers-reduced-motion: reduce` では動きを止める。

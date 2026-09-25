#!/usr/bin/env bash
# キャッシュバスティング: index.html / take.php のローカルアセット参照に
# ファイル内容ハッシュを ?v=<hash8> として埋め込む。
#
# なぜ必要か: index.html は静的配信で <script src="app.js"> を直書きしている。
# app.js を更新してもファイル名が変わらないため、ブラウザが古い app.js を
# キャッシュから使い続け、bootstrap 依存の初期化が壊れる事故が起きた
# (2026-08-16、ハードリロードで復旧)。内容ハッシュを付けることで、
# ファイルが変わったときだけ URL が変わり、ブラウザが自動で取り直す。
#
# なぜデプロイ時書き換えでなくソース側書き換えか:
#   tet2-deploy.sh は install でリポジトリのファイルをそのまま本番へコピーし、
#   sha256 の一致で差分検出・検証している。配信後に本番を書き換えると
#   リポジトリと不一致になり検証が壊れる。ソース側を先に更新し、通常の
#   デプロイに乗せることで「リポジトリ=本番」を保つ。
#
# 使い方: アセット(app.js/app.css/campaign-automations.js/positions.js)を
# 変更したら、コミット前にこれを実行して index.html / take.php を更新する。
# 冪等: 内容が同じなら ?v= は変わらず、diff も出ない。

set -euo pipefail

WEB_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/web

# ハッシュ対象にするローカルアセット(相対パスは HTML 内の記述に一致させる)。
# vendor(bootstrap/chart)は更新頻度が低く CDN 由来のため対象外。自作ファイルのみ。
ASSETS=(
  app.js
  assets/app.css
  assets/campaign-automations.js
  assets/positions.js
  assets/risk-dashboard.js
  assets/suspicious-mails.js
  assets/context-help.js
  assets/campaign-workspace.js
  assets/campaign-editor-steps.js
  assets/surveys.js
)

asset_hash() {
  local rel=$1
  local path="$WEB_DIR/$rel"
  if [[ ! -f $path ]]; then
    echo "アセットが見つかりません: $path" >&2
    return 1
  fi
  sha256sum "$path" | cut -c1-8
}

# HTML/PHP 1ファイル内の、指定アセット参照を ?v=<hash> 付きに書き換える。
# 既存の ?v=... は新しい値に置換する(冪等)。参照が無ければ何もしない。
bust_file() {
  local file=$1
  [[ -f $file ]] || return 0
  local rel hash
  for rel in "${ASSETS[@]}"; do
    # そのファイルが当該アセットを参照していなければスキップ
    grep -qE "(src|href)=\"${rel}(\?v=[0-9a-f]+)?\"" "$file" || continue
    hash=$(asset_hash "$rel")
    # src="app.js" や src="app.js?v=abc12345" を src="app.js?v=<hash>" に統一
    sed -i -E "s#((src|href)=\"${rel})(\?v=[0-9a-f]+)?\"#\1?v=${hash}\"#g" "$file"
  done
}

main() {
  bust_file "$WEB_DIR/index.html"
  bust_file "$WEB_DIR/take.php"
  echo "cache-bust 適用済み:"
  local rel
  for rel in "${ASSETS[@]}"; do
    printf '  %s -> ?v=%s\n' "$rel" "$(asset_hash "$rel")"
  done
}

main "$@"

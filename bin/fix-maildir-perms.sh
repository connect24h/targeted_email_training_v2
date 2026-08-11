#!/bin/bash
#
# Maildir のメールファイルを www-data から読める権限 (640) に揃える。
#
# 背景:
#   Postfix virtual(8) は配送時にメールファイルを 0600 で作成する。この権限は
#   Postfix の設定パラメータでは変更できない (該当する umask パラメータが無い)。
#   一方 TET v2 の返信者一覧は Apache (www-data) が Maildir を直接読むため、
#   0600 のメールは読めず、一覧から静かに消えていた。実際に 2026-05-07 以降に
#   届いた外部メール 8 件が一覧に出ず、返信の見落としに直結した。
#
#   なお 2026-03-08 以前のメールは 640 で作られていた。いつ・何によって挙動が
#   変わったのかは journal がローテートで消えており特定できていない。原因が
#   不明なままでも運用が壊れないよう、配送後に権限を揃える方式を採る。
#
# 安全性:
#   - 権限を 640 に「上げる」だけで、メール本文には一切触れない
#   - 対象は 600 のファイルのみ。既に 640 のものは変更しない
#   - Postfix / Dovecot の設定は変更しない
#
# 既知のセキュリティ上の限界 (2026-08-11 自動レビュー指摘・現状維持で合意):
#   root で /home/*/Maildir を find→chmod するため、理論上は Symlink TOCTOU
#   (find がファイルを見つけてから chmod するまでに対象を symlink に差し替えると
#    root 権限でリンク先の権限を変えられる) が成立しうる。
#   現環境では Maildir 所有者が実在の対話ユーザーではなく訓練システムの配送用
#   アカウントで、シェルログインして攻撃を仕掛ける主体が想定されないため当面許容する。
#   将来 Maildir 所有者が対話ユーザーになる場合は、O_NOFOLLOW + fstat 検証 + fchmod
#   を使う Python 版に書き換えること (chmod は symlink を辿るため bash では防げない)。
#
set -uo pipefail

DATA_DIR=/opt/training/tet2-data
LOG="$DATA_DIR/maildir-perms.log"
FORENSIC="$DATA_DIR/maildir-perms-forensic.log"

mkdir -p "$DATA_DIR"

log() { echo "$(date '+%Y-%m-%dT%H:%M:%S%z') $*" >>"$LOG"; }

changed=0
failed=0

for maildir in /home/*/Maildir; do
  [ -d "$maildir" ] || continue
  for sub in new cur; do
    dir="$maildir/$sub"
    [ -d "$dir" ] || continue
    while IFS= read -r -d '' f; do
      # 原因調査用: 600 で作られたファイルの素性を残す。作成時刻と所有者が
      # 分かれば配送経路を切り分けられる。根本原因を特定する一次情報になる。
      stat -c '%y %a %U:%G %n' "$f" >>"$FORENSIC"
      if chmod 640 "$f" 2>/dev/null; then
        changed=$((changed + 1))
        log "fixed 600 -> 640: $f"
      else
        failed=$((failed + 1))
        log "FAILED to chmod: $f"
      fi
    done < <(find "$dir" -maxdepth 1 -type f -perm 600 -print0 2>/dev/null)
  done
done

# 変更ゼロの回はログを増やさない (5分毎実行でログが膨らむのを避ける)。
if [ "$changed" -gt 0 ] || [ "$failed" -gt 0 ]; then
  log "run complete: fixed=$changed failed=$failed"
fi

exit 0

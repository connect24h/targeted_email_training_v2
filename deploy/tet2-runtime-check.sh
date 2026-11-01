#!/usr/bin/env bash
# 本番同条件の自己診断: Apache(www-data)が予約時に行う処理を、apache2.service と同じ
# systemd の制限(RestrictSUIDSGID 等)をかけたプロセスで実際に試し、OK/NG を一覧する。
#
# なぜ必要か: 2026-10-21 の OS 更新で apache2.service に RestrictSUIDSGID=yes 等が入り、
# 予約時の mkdir(02775) が EPERM で失敗した。CLI での確認はこの制限の外で動くため
# すべて通ってしまい、画面からだけ失敗した。Python のモジュール喪失(3.13→3.14)、
# 共有ログの所有ズレも同じく「更新後に画面からだけ壊れる」型で再発している。
#
# 使い方(root): 配備前と OS/パッケージ更新の後に実行する。NG が1つでもあれば非0で終わる。
#   deploy/tet2-runtime-check.sh                      # 本番のコード(/var/www/html/tet2/lib)
#   deploy/tet2-runtime-check.sh --lib=web/lib        # 配備前のリポジトリのコード
# 本番の data root に一意の一時フォルダを作って試し、終了時にそれだけを消す。
# 本番 DB・既存の送信データ・ログの中身には触れない(ログは追記モードで開くだけ)。

set -euo pipefail

REPO=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
LIB_DIR=/var/www/html/tet2/lib
DATA_ROOT=/opt/training/tet2-data
OPERATION_LOG=/opt/training/logs/operation.log
BEACON_SCRIPT=/opt/training/bin/create_beacon_files.py
# /opt/training/bin の生成・送信・worker スクリプトが import する外部モジュール(2026-11-01 に AST で抽出)
PY_MODULES=(pandas qrcode docx jinja2 markupsafe openpyxl pptx reportlab jpholiday)
# apache2.service から写す制限。値が空のものは写さない。
SANDBOX_KEYS=(RestrictSUIDSGID MemoryDenyWriteExecute ProtectSystem ProtectHome PrivateTmp
  ProtectKernelTunables ProtectKernelModules ProtectKernelLogs ProtectControlGroups ProtectClock
  ProtectHostname ProtectProc LockPersonality RestrictNamespaces RestrictRealtime
  SystemCallArchitectures NoNewPrivileges ReadWritePaths InaccessiblePaths UMask)

for arg in "$@"; do
  case "$arg" in
    --lib=*) LIB_DIR=$(cd "${arg#--lib=}" && pwd) ;;
    -h|--help) sed -n '2,15p' "$0"; exit 0 ;;
    *) echo "不明な引数: $arg" >&2; exit 2 ;;
  esac
done
[ "$(id -u)" -eq 0 ] || { echo "root で実行してください" >&2; exit 2; }

RUN_ID=_runtime_check_$(date +%Y%m%d%H%M%S)_$$
# STAGE は root 専用で atomic に作る(www-data が先回りで symlink を置けないように)。
# DATA_ROOT は www-data 書込可のため、install -d で www-data 所有にしてから root が
# 書き込むと symlink 追従の恐れがある。mktemp -d は 0700/root で原子的に作られる。
STAGE=$(mktemp -d "$DATA_ROOT/.rtcheck.XXXXXXXX")
PROBES=()
cleanup() {
  rm -rf -- "$STAGE"
  for p in "${PROBES[@]}"; do rm -rf -- "$p"; done
}
trap cleanup EXIT

props=()
for key in "${SANDBOX_KEYS[@]}"; do
  val=$(systemctl show apache2 -p "$key" --value)
  [ -n "$val" ] && props+=(-p "$key=$val")
done
# Apache 内の PHP と同じ制限・同じユーザーで1コマンド実行する
as_apache() {
  systemd-run --quiet --pipe --wait --collect "${props[@]}" -p User=www-data -p Group=www-data -- "$@" 2>&1
}
# training(ビーコン生成・送信worker)として1コマンド実行する
as_training() {
  systemd-run --quiet --pipe --wait --collect -p User=training -p Group=training -p UMask=0022 -- "$@" 2>&1
}

ng=0
report() { # report OK|NG 項目 詳細
  printf '%-3s %-34s %s\n' "$1" "$2" "$3"
  [ "$1" = NG ] && ng=$((ng + 1))
  return 0
}

echo "apache2 の制限: $(systemctl show apache2 -p RestrictSUIDSGID -p MemoryDenyWriteExecute -p ProtectSystem --value | paste -sd' ')"
echo "検査するコード: $LIB_DIR"
echo

# 検査対象のコードを www-data が読める場所へ写す(/root は Apache から見えない)。
# root 所有の STAGE に root がコピーし、その後 www-data に読み取り(traverse+read)だけ許す。
cp -r "$LIB_DIR" "$STAGE/lib"
chmod -R a+rX "$STAGE/lib"
chmod 0711 "$STAGE"
cat > "$STAGE/ensure_dir.php" <<'PHP'
<?php
require __DIR__ . '/lib/PipelineRunner.php';
try {
    PipelineRunner::ensureDir($argv[1] . '/Attachment');
    if (file_put_contents($argv[1] . '/list.csv', "probe\n") === false) throw new RuntimeException('CSV を書けない');
    clearstatcache();
    printf('OK %o', fileperms($argv[1]) & 07777);
} catch (Throwable $e) {
    echo 'ERR ' . $e->getMessage();
}
PHP
chmod 0644 "$STAGE/ensure_dir.php"

# 1-2. テナントごとの保存先: Web が作成・書込でき、training が中に書けるか
mapfile -t tenants < <(find "$DATA_ROOT" -mindepth 2 -maxdepth 2 -type d -name 'campaign_*' -printf '%h\n' | sort -u)
for tenant in "${tenants[@]}"; do
  probe="$tenant/$RUN_ID"
  PROBES+=("$probe")
  out=$(as_apache /usr/bin/php "$STAGE/ensure_dir.php" "$probe" | grep -v '^PHP Warning' || true)
  if [[ "$out" == "OK 2775" ]]; then
    report OK "保存先の作成 $(basename "$tenant")" "2775"
  else
    report NG "保存先の作成 $(basename "$tenant")" "$out"
    continue
  fi
  out=$(as_training /usr/bin/python3 -c \
    "import sys; open(sys.argv[1] + '/Attachment/probe.html', 'w').write('x')" "$probe" || true)
  if [ -z "$out" ]; then report OK "training の書込 $(basename "$tenant")" "Web が作った保存先"; else report NG "training の書込 $(basename "$tenant")" "$out"; fi
done

# 3. ビーコン生成キュー: Web が .req を置き、training サービスが .res を返す往復(sudo を使わない)
BEACON_QUEUE=/opt/training/tet2-data/_beacon_queue
BEACON_RUNNER=/opt/training/bin/tet2-beacon-runner.py
qreq="$BEACON_QUEUE/$RUN_ID.req"; qres="$BEACON_QUEUE/$RUN_ID.res"
PROBES+=("$qreq" "$qres")
# Web(www-data, Apache 制限下)が依頼を書けるか
install -d -m 02775 -o www-data -g www-data "$BEACON_QUEUE" 2>/dev/null || true
chmod 2775 "$BEACON_QUEUE" 2>/dev/null || true
wrote=$(as_apache /bin/sh -c 'echo "{\"campaign_id\":0,\"beacon_base\":\"\"}" > '"$qreq"'.tmp && mv '"$qreq"'.tmp '"$qreq"' && echo ok' 2>&1)
if [ "$wrote" = ok ]; then report OK "Web が生成依頼を書ける" "$BEACON_QUEUE"; else report NG "Web が生成依頼を書ける" "$wrote"; fi
if [ -r "$BEACON_RUNNER" ]; then
  # systemd(training) の代わりにランナーを training として1回起動し、.res が返るか
  as_training /usr/bin/python3 "$BEACON_RUNNER" >/dev/null 2>&1 || true
  if [ -f "$qres" ] && [ ! -L "$qres" ]; then report OK "training サービスが結果を返す" "$(head -c 60 "$qres")"; elif [ -L "$qres" ]; then report NG "training サービスが結果を返す" ".res が symlink"; else report NG "training サービスが結果を返す" ".res が出ない"; fi
else
  report NG "ビーコン生成ランナー" "未配備: $BEACON_RUNNER"
fi

# 4. Python 実行環境(training)
out=$(as_training /usr/bin/python3 -c "
import importlib, sys
missing = []
for m in sys.argv[1:]:
    try: importlib.import_module(m)
    except Exception as e: missing.append(f'{m}({type(e).__name__})')
print(sys.version.split()[0], ' '.join(missing) or '-')
" "${PY_MODULES[@]}" || true)
read -r pyver missing <<<"$out"
if [ "${missing:-x}" = "-" ]; then report OK "Python モジュール" "python $pyver / ${#PY_MODULES[@]} 個"; else report NG "Python モジュール" "python ${pyver:-?} 不足: ${missing:-$out}"; fi
if [ -r "$BEACON_SCRIPT" ]; then report OK "ビーコン生成スクリプト" "$BEACON_SCRIPT"; else report NG "ビーコン生成スクリプト" "読めない: $BEACON_SCRIPT"; fi

# 5. 共有ログ: training(生成・送信)と www-data(画面)の両方が追記できるか(中身は書かない)
out=$(as_apache /usr/bin/php -r 'echo @fopen($argv[1], "a") ? "" : "www-data が追記できない";' "$OPERATION_LOG" || true)
out2=$(as_training /usr/bin/python3 -c "import sys; open(sys.argv[1], 'a').close()" "$OPERATION_LOG" || true)
if [ -z "$out$out2" ]; then report OK "共有ログ operation.log" "$(stat -c '%U:%G %a' "$OPERATION_LOG")"; else report NG "共有ログ operation.log" "$(stat -c '%U:%G %a' "$OPERATION_LOG") $out $out2"; fi

echo
if [ "$ng" -eq 0 ]; then echo "結果: すべて OK"; else echo "結果: NG が $ng 件"; fi
[ "$ng" -eq 0 ]

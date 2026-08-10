#!/usr/bin/env bash
set -euo pipefail

TET2_REPO_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
TET2_WEB_DEST=/var/www/html/tet2
TET2_BIN_DEST=/opt/training/bin
TET2_SYSTEMD_DEST=/etc/systemd/system
TET2_CRON_DEST=/etc/cron.d
TET2_BACKUP_ROOT=/var/backups/tet2
TET2_DB_PATH=/opt/training/tet2-db/tet2.sqlite
TET2_APPLY=0
TET2_BACKUP_ID=$(date '+%Y%m%dT%H%M%S')-$$
readonly TET2_BACKUP_KEEP_COUNT=10
readonly TET2_BACKUP_KEEP_DAYS=30

# shellcheck source=deploy/tet2-files.sh
source "$TET2_REPO_ROOT/deploy/tet2-files.sh"

usage() {
  echo 'Usage: tet2-deploy.sh [--apply] [--backup-id=YYYYMMDDTHHMMSS-PID]'
  echo '  [--web-dest=PATH] [--bin-dest=PATH] [--systemd-dest=PATH]'
  echo '  [--cron-dest=PATH] [--backup-root=PATH] [--db-path=PATH]'
  echo '既定はdry-runです。delete同期・migration・service reloadは行いません。'
}

parse_args() {
  local argument
  for argument in "$@"; do
    case "$argument" in
      --apply) TET2_APPLY=1 ;;
      --backup-id=*) TET2_BACKUP_ID=${argument#*=} ;;
      --web-dest=*) TET2_WEB_DEST=${argument#*=} ;;
      --bin-dest=*) TET2_BIN_DEST=${argument#*=} ;;
      --systemd-dest=*) TET2_SYSTEMD_DEST=${argument#*=} ;;
      --cron-dest=*) TET2_CRON_DEST=${argument#*=} ;;
      --backup-root=*) TET2_BACKUP_ROOT=${argument#*=} ;;
      --db-path=*) TET2_DB_PATH=${argument#*=} ;;
      --help) usage; exit 0 ;;
      *) echo "不明な引数です: $argument" >&2; usage >&2; exit 1 ;;
    esac
  done
}

validate_config() {
  tet2_validate_backup_id "$TET2_BACKUP_ID"
  tet2_require_safe_path web-dest "$TET2_WEB_DEST"
  tet2_require_safe_path bin-dest "$TET2_BIN_DEST"
  tet2_require_safe_path systemd-dest "$TET2_SYSTEMD_DEST"
  tet2_require_safe_path cron-dest "$TET2_CRON_DEST"
  tet2_require_safe_path backup-root "$TET2_BACKUP_ROOT"
  tet2_require_safe_path db-path "$TET2_DB_PATH"
  if [[ ! -f $TET2_DB_PATH ]]; then
    echo "DBが見つかりません: $TET2_DB_PATH" >&2
    exit 1
  fi
}

print_plan() {
  local source category relative destination state source_hash destination_hash
  echo "mode=$([[ $TET2_APPLY -eq 1 ]] && echo APPLY || echo DRY-RUN) backup_id=$TET2_BACKUP_ID"
  while IFS=$'\t' read -r source category relative destination; do
    source_hash=$(sha256sum "$source" | awk '{print $1}')
    state=MISSING
    destination_hash=-
    if [[ -f $destination ]]; then
      if destination_hash=$(sha256sum "$destination" 2>/dev/null); then
        state=PRESENT
        destination_hash=${destination_hash%% *}
      else
        state=UNREADABLE
        destination_hash=-
      fi
    fi
    printf '%s\t%s\t%s\t%s\t%s\n' "$category" "$relative" "$state" "$source_hash" "$destination_hash"
  done < "$TET2_MAPPING_FILE"
}

backup_sources() {
  local source category relative destination state destination_hash backup_file
  mkdir -p "$TET2_BACKUP_DIR/files" "$TET2_BACKUP_DIR/db"
  printf '# category\trelative\tstate\tsha256\n' > "$TET2_BACKUP_DIR/files.tsv"
  while IFS=$'\t' read -r source category relative destination; do
    state=MISSING
    destination_hash=-
    if [[ -f $destination ]]; then
      state=PRESENT
      destination_hash=$(sha256sum "$destination" | awk '{print $1}')
      backup_file="$TET2_BACKUP_DIR/files/$category/$relative"
      mkdir -p "$(dirname "$backup_file")"
      cp --preserve=mode,timestamps "$destination" "$backup_file"
    fi
    printf '%s\t%s\t%s\t%s\n' "$category" "$relative" "$state" "$destination_hash" \
      >> "$TET2_BACKUP_DIR/files.tsv"
  done < "$TET2_MAPPING_FILE"
  sqlite3 -readonly "$TET2_DB_PATH" "VACUUM INTO '$TET2_BACKUP_DIR/db/tet2.sqlite';"
  sha256sum "$TET2_BACKUP_DIR/db/tet2.sqlite" > "$TET2_BACKUP_DIR/db/tet2.sqlite.sha256"
}

deploy_sources() {
  local source category relative destination mode
  while IFS=$'\t' read -r source category relative destination; do
    mkdir -p "$(dirname "$destination")"
    mode=$(tet2_deploy_mode "$category" "$relative")
    install -o root -g root -m "$mode" "$source" "$destination"
  done < "$TET2_MAPPING_FILE"
}

verify_runtime_access() {
  local beacon="$TET2_BIN_DEST/__BeaconMst.png"
  local runtime_user
  if [[ $(stat -c '%U:%G:%a' "$beacon") != 'root:root:644' ]]; then
    echo "ビーコン素材のowner/modeがpolicy違反です: $(stat -c '%U:%G:%a' "$beacon")" >&2
    return 1
  fi
  # Rehearsalの一時destinationではuser namespace制約を避け、mode検証までとする。
  [[ $TET2_BIN_DEST == /opt/training/bin ]] || return 0
  for runtime_user in www-data training; do
    if id -u "$runtime_user" >/dev/null 2>&1 \
      && ! runuser -u "$runtime_user" -- test -r "$beacon"; then
      echo "runtime userがビーコン素材を読めません: $runtime_user ($beacon)" >&2
      return 1
    fi
  done
}

write_deployed_manifest() {
  local source category relative destination
  printf '# category\trelative\tsha256\n' > "$TET2_BACKUP_DIR/deployed.tsv"
  while IFS=$'\t' read -r source category relative destination; do
    printf '%s\t%s\t%s\n' "$category" "$relative" \
      "$(sha256sum "$destination" | awk '{print $1}')" >> "$TET2_BACKUP_DIR/deployed.tsv"
  done < "$TET2_MAPPING_FILE"
}

main() {
  parse_args "$@"
  validate_config
  TET2_MAPPING_FILE=$(mktemp)
  trap 'rm -f "$TET2_MAPPING_FILE"' EXIT
  export TET2_REPO_ROOT TET2_WEB_DEST TET2_BIN_DEST TET2_SYSTEMD_DEST TET2_CRON_DEST
  tet2_write_mappings "$TET2_MAPPING_FILE"
  print_plan
  echo "retention_policy=count:$TET2_BACKUP_KEEP_COUNT days:$TET2_BACKUP_KEEP_DAYS auto_prune:false"
  if [[ $TET2_APPLY -eq 0 ]]; then
    echo '[DRY-RUN] 配備・backup・削除は行っていません'
    return
  fi
  TET2_BACKUP_DIR="$TET2_BACKUP_ROOT/$TET2_BACKUP_ID"
  if [[ -e $TET2_BACKUP_DIR ]]; then
    echo "backup IDは既に存在します: $TET2_BACKUP_ID" >&2
    exit 1
  fi
  backup_sources
  deploy_sources
  verify_runtime_access
  write_deployed_manifest
  echo "[APPLY] 配備完了 backup_id=$TET2_BACKUP_ID"
  echo 'migration・service reload・backup自動削除は実行していません'
}

main "$@"

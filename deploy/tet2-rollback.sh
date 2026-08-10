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
TET2_BACKUP_ID=
TET2_RESTORE_DB=0
TET2_DB_OFFLINE_CONFIRMED=0

# shellcheck source=deploy/tet2-files.sh
source "$TET2_REPO_ROOT/deploy/tet2-files.sh"

usage() {
  echo 'Usage: tet2-rollback.sh --backup-id=ID [--apply]'
  echo '  [--restore-db --db-offline-confirmed] [destination options]'
  echo '既定はdry-runです。DB復元は明示した二重flagがある場合だけ行います。'
}

parse_args() {
  local argument
  for argument in "$@"; do
    case "$argument" in
      --apply) TET2_APPLY=1 ;;
      --backup-id=*) TET2_BACKUP_ID=${argument#*=} ;;
      --restore-db) TET2_RESTORE_DB=1 ;;
      --db-offline-confirmed) TET2_DB_OFFLINE_CONFIRMED=1 ;;
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
  if [[ -z $TET2_BACKUP_ID ]]; then
    echo '--backup-idは必須です' >&2
    exit 1
  fi
  tet2_validate_backup_id "$TET2_BACKUP_ID"
  tet2_require_safe_path web-dest "$TET2_WEB_DEST"
  tet2_require_safe_path bin-dest "$TET2_BIN_DEST"
  tet2_require_safe_path systemd-dest "$TET2_SYSTEMD_DEST"
  tet2_require_safe_path cron-dest "$TET2_CRON_DEST"
  tet2_require_safe_path backup-root "$TET2_BACKUP_ROOT"
  tet2_require_safe_path db-path "$TET2_DB_PATH"
  TET2_BACKUP_DIR="$TET2_BACKUP_ROOT/$TET2_BACKUP_ID"
  if [[ ! -f $TET2_BACKUP_DIR/files.tsv ]]; then
    echo "backup manifestが見つかりません: $TET2_BACKUP_ID" >&2
    exit 1
  fi
  if [[ $TET2_APPLY -eq 1 && $TET2_RESTORE_DB -eq 1 && $TET2_DB_OFFLINE_CONFIRMED -ne 1 ]]; then
    echo 'DB復元には--db-offline-confirmedが必要です' >&2
    exit 1
  fi
  validate_manifest
  if [[ $TET2_RESTORE_DB -eq 1 ]]; then
    validate_database_backup
  fi
}

validate_manifest() {
  local category relative state expected mode uid gid backup_file actual key metadata
  declare -A seen=()
  while IFS=$'\t' read -r category relative state expected mode uid gid; do
    [[ $category == \#* ]] && continue
    destination_for "$category" "$relative" > /dev/null
    key="$category/$relative"
    [[ -z ${seen[$key]:-} ]] || { echo "manifest entryが重複しています: $key" >&2; return 1; }
    seen[$key]=1
    case "$state" in
      PRESENT)
        [[ $expected =~ ^[a-f0-9]{64}$ ]] || { echo "checksum形式が不正です: $key" >&2; return 1; }
        backup_file="$TET2_BACKUP_DIR/files/$key"
        [[ -f $backup_file && ! -L $backup_file ]] || { echo "backup fileが不正です: $key" >&2; return 1; }
        actual=$(sha256sum "$backup_file" | awk '{print $1}')
        [[ $actual == "$expected" ]] || { echo "backup checksum不一致: $key" >&2; return 1; }
        if [[ -n ${mode:-} ]]; then
          [[ $mode =~ ^[0-7]{3,4}$ && $uid =~ ^[0-9]+$ && $gid =~ ^[0-9]+$ ]] \
            || { echo "backup metadata形式が不正です: $key" >&2; return 1; }
          metadata=$(stat -c '%a:%u:%g' "$backup_file")
          [[ $metadata == "$mode:$uid:$gid" ]] \
            || { echo "backup metadata不一致: $key" >&2; return 1; }
        fi
        ;;
      MISSING)
        [[ $expected == - ]] || { echo "MISSING entryが不正です: $key" >&2; return 1; }
        ;;
      *) echo "manifest stateが不正です: $state" >&2; return 1 ;;
    esac
  done < "$TET2_BACKUP_DIR/files.tsv"
}

validate_database_backup() {
  local backup_db="$TET2_BACKUP_DIR/db/tet2.sqlite"
  local checksum_file="$backup_db.sha256"
  [[ -f $backup_db && ! -L $backup_db ]] || { echo 'DB backupが不正です' >&2; return 1; }
  [[ -f $checksum_file && ! -L $checksum_file ]] || { echo 'DB checksumがありません' >&2; return 1; }
  local expected actual
  expected=$(awk 'NR==1 {print $1}' "$checksum_file")
  [[ $expected =~ ^[a-f0-9]{64}$ ]] || { echo 'DB checksum形式が不正です' >&2; return 1; }
  actual=$(sha256sum "$backup_db" | awk '{print $1}')
  [[ $actual == "$expected" ]] || { echo 'DB backup checksum不一致' >&2; return 1; }
}

destination_for() {
  local category=$1
  local relative=$2
  tet2_validate_relative_path "$relative"
  case "$category" in
    web) echo "$TET2_WEB_DEST/$relative" ;;
    bin) echo "$TET2_BIN_DEST/$relative" ;;
    systemd) echo "$TET2_SYSTEMD_DEST/$relative" ;;
    cron) echo "$TET2_CRON_DEST/$relative" ;;
    *) echo "manifest categoryが不正です: $category" >&2; return 1 ;;
  esac
}

print_plan() {
  local category relative state expected destination
  echo "mode=$([[ $TET2_APPLY -eq 1 ]] && echo APPLY || echo DRY-RUN) backup_id=$TET2_BACKUP_ID"
  while IFS=$'\t' read -r category relative state expected; do
    [[ $category == \#* ]] && continue
    destination=$(destination_for "$category" "$relative")
    printf '%s\t%s\t%s\t%s\n' "$category" "$relative" "$state" "$destination"
  done < "$TET2_BACKUP_DIR/files.tsv"
  echo "restore_db=$TET2_RESTORE_DB"
}

save_current_file() {
  local destination=$1
  local category=$2
  local relative=$3
  local saved="$TET2_BACKUP_DIR/rollback-current/$category/$relative"
  if [[ -f $destination && ! -e $saved ]]; then
    mkdir -p "$(dirname "$saved")"
    cp --preserve=all "$destination" "$saved"
  fi
}

restore_files() {
  local category relative state expected mode uid gid destination backup_file removed actual
  while IFS=$'\t' read -r category relative state expected mode uid gid; do
    [[ $category == \#* ]] && continue
    destination=$(destination_for "$category" "$relative")
    save_current_file "$destination" "$category" "$relative"
    if [[ $state == PRESENT ]]; then
      backup_file="$TET2_BACKUP_DIR/files/$category/$relative"
      actual=$(sha256sum "$backup_file" | awk '{print $1}')
      [[ $actual == "$expected" ]] || { echo "backup checksum不一致: $category/$relative" >&2; exit 1; }
      mkdir -p "$(dirname "$destination")"
      if [[ -n ${mode:-} ]]; then
        install -o "$uid" -g "$gid" -m "$mode" "$backup_file" "$destination"
      else
        mode=$(stat -c '%a' "$backup_file")
        install -m "$mode" "$backup_file" "$destination"
      fi
    elif [[ $state == MISSING && -e $destination ]]; then
      removed="$TET2_BACKUP_DIR/rollback-removed/$category/$relative"
      mkdir -p "$(dirname "$removed")"
      mv "$destination" "$removed"
    fi
  done < "$TET2_BACKUP_DIR/files.tsv"
}

restore_database() {
  local backup_db="$TET2_BACKUP_DIR/db/tet2.sqlite"
  if [[ ! -f $backup_db ]]; then
    echo 'DB backupが見つかりません' >&2
    exit 1
  fi
  mkdir -p "$TET2_BACKUP_DIR/rollback-current/db"
  if [[ -f $TET2_DB_PATH && ! -e $TET2_BACKUP_DIR/rollback-current/db/tet2.sqlite ]]; then
    sqlite3 -readonly "$TET2_DB_PATH" \
      "VACUUM INTO '$TET2_BACKUP_DIR/rollback-current/db/tet2.sqlite';"
  fi
  sqlite3 "$TET2_DB_PATH" ".restore '$backup_db'"
  if [[ $(sqlite3 "$TET2_DB_PATH" 'PRAGMA integrity_check;') != ok ]]; then
    echo 'DB restore後のintegrity checkに失敗しました' >&2
    exit 1
  fi
}

main() {
  parse_args "$@"
  validate_config
  print_plan
  if [[ $TET2_APPLY -eq 0 ]]; then
    echo '[DRY-RUN] rollback・削除・DB復元は行っていません'
    return
  fi
  restore_files
  if [[ $TET2_RESTORE_DB -eq 1 ]]; then
    restore_database
  fi
  echo "[APPLY] rollback完了 backup_id=$TET2_BACKUP_ID"
}

main "$@"

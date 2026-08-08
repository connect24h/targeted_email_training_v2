#!/usr/bin/env bash
set -euo pipefail

TET2_REPO_ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
TET2_BACKUP_ROOT=/var/backups/tet2
TET2_KEEP_COUNT=10
TET2_MIN_AGE_DAYS=30
TET2_APPLY=0

# shellcheck source=deploy/tet2-files.sh
source "$TET2_REPO_ROOT/deploy/tet2-files.sh"

usage() {
  echo 'Usage: tet2-prune-backups.sh [--apply] [--backup-root=PATH]'
  echo '  [--keep-count=N] [--min-age-days=N]'
  echo '既定はdry-runです。対象は削除せず backup-root/.trash へ移動します。'
}

parse_args() {
  local argument
  for argument in "$@"; do
    case "$argument" in
      --apply) TET2_APPLY=1 ;;
      --backup-root=*) TET2_BACKUP_ROOT=${argument#*=} ;;
      --keep-count=*) TET2_KEEP_COUNT=${argument#*=} ;;
      --min-age-days=*) TET2_MIN_AGE_DAYS=${argument#*=} ;;
      --help) usage; exit 0 ;;
      *) echo "不明な引数です: $argument" >&2; usage >&2; exit 1 ;;
    esac
  done
}

validate_config() {
  tet2_require_safe_path backup-root "$TET2_BACKUP_ROOT"
  [[ $TET2_KEEP_COUNT =~ ^[0-9]+$ ]] || { echo 'keep-countは0以上の整数です' >&2; exit 1; }
  [[ $TET2_MIN_AGE_DAYS =~ ^[0-9]+$ ]] || { echo 'min-age-daysは0以上の整数です' >&2; exit 1; }
  [[ -d $TET2_BACKUP_ROOT && ! -L $TET2_BACKUP_ROOT ]] \
    || { echo "backup rootが見つかりません: $TET2_BACKUP_ROOT" >&2; exit 1; }
}

list_backups() {
  local directory backup_id
  for directory in "$TET2_BACKUP_ROOT"/*; do
    [[ -d $directory && ! -L $directory ]] || continue
    backup_id=${directory##*/}
    [[ $backup_id == .trash ]] && continue
    if [[ $backup_id =~ ^[0-9]{8}T[0-9]{6}-[0-9]+$ ]]; then
      printf '%s\n' "$backup_id"
    fi
  done | sort -r
}

prune_backups() {
  local now index backup_id directory modified age_days trash_destination
  now=$(date +%s)
  index=0
  while IFS= read -r backup_id; do
    [[ -n $backup_id ]] || continue
    index=$((index + 1))
    if (( index <= TET2_KEEP_COUNT )); then
      echo "KEEP\t$backup_id\treason=count"
      continue
    fi
    directory="$TET2_BACKUP_ROOT/$backup_id"
    modified=$(stat -c '%Y' "$directory")
    age_days=$(((now - modified) / 86400))
    if (( age_days < TET2_MIN_AGE_DAYS )); then
      echo "KEEP\t$backup_id\treason=age:$age_days"
      continue
    fi
    echo "PRUNE\t$backup_id\tage_days=$age_days"
    if [[ $TET2_APPLY -eq 1 ]]; then
      trash_destination="$TET2_BACKUP_ROOT/.trash/$backup_id"
      [[ ! -e $trash_destination ]] || { echo "trashに同名backupがあります: $backup_id" >&2; exit 1; }
      mkdir -p "$TET2_BACKUP_ROOT/.trash"
      mv "$directory" "$trash_destination"
    fi
  done < <(list_backups)
}

main() {
  parse_args "$@"
  validate_config
  echo "mode=$([[ $TET2_APPLY -eq 1 ]] && echo APPLY || echo DRY-RUN)"
  prune_backups
  if [[ $TET2_APPLY -eq 0 ]]; then
    echo '[DRY-RUN] backupは移動・削除していません'
  else
    echo '[APPLY] 対象backupを.trashへ移動しました。物理削除は行っていません'
  fi
}

main "$@"

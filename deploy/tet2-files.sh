#!/usr/bin/env bash

TET2_BIN_FILES=(
  __BeaconMst.png
  create_beacon_files.py
  master.html
  master2.html
  master3.html
  master4.html
  qr_doc_gen.py
  replace_url.py
  requirements.txt
  send_email.py
  tet2-purge-campaigns.py
  tet2-worker.py
  training_config.py
)

tet2_deploy_mode() {
  local category=$1
  local relative=$2
  case "$category" in
    bin)
      case "$relative" in
        *.py) echo 0755 ;;
        *) echo 0644 ;;
      esac
      ;;
    web|systemd|cron) echo 0644 ;;
    *) echo "deploy categoryが不正です: $category" >&2; return 1 ;;
  esac
}

tet2_require_safe_path() {
  local label=$1
  local path=$2
  if [[ ! $path =~ ^/[A-Za-z0-9._/-]+$ ]] || [[ $path == / ]]; then
    echo "$label は安全な絶対pathで指定してください: $path" >&2
    return 1
  fi
}

tet2_validate_backup_id() {
  local backup_id=$1
  if [[ ! $backup_id =~ ^[0-9]{8}T[0-9]{6}-[0-9]+$ ]]; then
    echo "backup IDの形式が不正です: $backup_id" >&2
    return 1
  fi
}

tet2_validate_relative_path() {
  local relative=$1
  if [[ -z $relative ]] || [[ $relative == /* ]] \
    || [[ $relative =~ (^|/)\.\.(/|$) ]] \
    || [[ ! $relative =~ ^[A-Za-z0-9._/-]+$ ]]; then
    echo "relative pathが不正です: $relative" >&2
    return 1
  fi
}

tet2_write_mappings() {
  local output=$1
  : > "$output"
  while IFS= read -r -d '' tracked; do
    local relative=${tracked#web/}
    printf '%s\tweb\t%s\t%s\n' \
      "$TET2_REPO_ROOT/$tracked" "$relative" "$TET2_WEB_DEST/$relative" >> "$output"
  done < <(git -C "$TET2_REPO_ROOT" ls-files -z -- web)

  local relative
  for relative in "${TET2_BIN_FILES[@]}"; do
    printf '%s\tbin\t%s\t%s\n' \
      "$TET2_REPO_ROOT/bin/$relative" "$relative" "$TET2_BIN_DEST/$relative" >> "$output"
  done
  tet2_append_tracked_mappings "$output" deploy/systemd systemd "$TET2_SYSTEMD_DEST"
  tet2_append_tracked_mappings "$output" deploy/cron cron "$TET2_CRON_DEST"
}

tet2_append_tracked_mappings() {
  local output=$1
  local source_dir=$2
  local category=$3
  local destination=$4
  while IFS= read -r -d '' tracked; do
    local relative=${tracked#${source_dir}/}
    printf '%s\t%s\t%s\t%s\n' \
      "$TET2_REPO_ROOT/$tracked" "$category" "$relative" "$destination/$relative" >> "$output"
  done < <(git -C "$TET2_REPO_ROOT" ls-files -z -- "$source_dir")
}

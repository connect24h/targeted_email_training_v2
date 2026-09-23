#!/usr/bin/env bash

TET2_BIN_FILES=(
  __BeaconMst.png
  create_beacon_files.py
  master.html
  master2.html
  master3.html
  master4.html
  master5.html
  qr_doc_gen.py
  replace_url.py
  requirements.txt
  send_email.py
  fix-maildir-perms.sh
  tet2-purge-campaigns.py
  tet2-worker.py
  training_config.py
)

# 新規実装をcommit前に合成環境へリハーサルする際も、既知の必須依存だけは落とさない。
# 任意の未追跡ファイルを自動配備対象に広げない。
TET2_WEB_EXTRA_FILES=(
  assets/context-help.js
  assets/campaign-workspace.js
  assets/campaign-editor-steps.js
  api/credential_capture.php
  api/credential_captures.php
  db/schema-credential-captures.sql
  db/schema-template-snapshots.sql
  lib/CredentialVault.php
  lib/CampaignPreflight.php
  lib/CampaignLaunchService.php
)

tet2_deploy_mode() {
  local category=$1
  local relative=$2
  case "$category" in
    bin)
      case "$relative" in
        *.py|*.sh) echo 0755 ;;
        *) echo 0644 ;;
      esac
      ;;
    web|systemd|cron) echo 0644 ;;
    *) echo "deploy categoryが不正です: $category" >&2; return 1 ;;
  esac
}

tet2_scope_includes() {
  local category=$1
  local relative=$2
  case "${TET2_DEPLOY_SCOPE:-all}" in
    all) return 0 ;;
    campaign-safety)
      case "$category/$relative" in
        web/api/campaign_launch.php|web/db/migrate.php|web/db/MigrationRunner.php|\
        web/db/schema-template-snapshots.sql|web/lib/CampaignLauncher.php|web/lib/CampaignPreflight.php|\
        web/lib/CampaignLaunchService.php|web/lib/PipelineRunner.php|web/lib/Scheduler.php|\
        bin/__BeaconMst.png|bin/create_beacon_files.py|bin/send_email.py|bin/tet2-worker.py) return 0 ;;
        *) return 1 ;;
      esac
      ;;
    *) echo "deploy scopeが不正です: ${TET2_DEPLOY_SCOPE:-}" >&2; return 1 ;;
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
    tet2_scope_includes web "$relative" || continue
    printf '%s\tweb\t%s\t%s\n' \
      "$TET2_REPO_ROOT/$tracked" "$relative" "$TET2_WEB_DEST/$relative" >> "$output"
  done < <(git -C "$TET2_REPO_ROOT" ls-files -z -- web)

  local relative
  for relative in "${TET2_WEB_EXTRA_FILES[@]}"; do
    tet2_scope_includes web "$relative" || continue
    if git -C "$TET2_REPO_ROOT" ls-files --error-unmatch -- "web/$relative" >/dev/null 2>&1; then
      continue
    fi
    if [[ ! -f $TET2_REPO_ROOT/web/$relative ]]; then
      echo "必須Webファイルが見つかりません: $relative" >&2
      return 1
    fi
    printf '%s\tweb\t%s\t%s\n' \
      "$TET2_REPO_ROOT/web/$relative" "$relative" "$TET2_WEB_DEST/$relative" >> "$output"
  done
  for relative in "${TET2_BIN_FILES[@]}"; do
    tet2_scope_includes bin "$relative" || continue
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
    tet2_scope_includes "$category" "$relative" || continue
    printf '%s\t%s\t%s\t%s\n' \
      "$TET2_REPO_ROOT/$tracked" "$category" "$relative" "$destination/$relative" >> "$output"
  done < <(git -C "$TET2_REPO_ROOT" ls-files -z -- "$source_dir")
}

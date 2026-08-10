#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
deploy_script="$repo_root/deploy/tet2-deploy.sh"
rollback_script="$repo_root/deploy/tet2-rollback.sh"
prune_script="$repo_root/deploy/tet2-prune-backups.sh"
test_root=$(mktemp -d)
trap 'rm -rf "$test_root"' EXIT

web_dest="$test_root/web"
bin_dest="$test_root/bin"
systemd_dest="$test_root/systemd"
cron_dest="$test_root/cron"
backup_root="$test_root/backups"
db_path="$test_root/tet2.sqlite"
backup_id="20260808T000000-4242"

mkdir -p "$web_dest" "$bin_dest" "$systemd_dest" "$cron_dest"
printf 'old-index\n' > "$web_dest/index.html"
printf 'local-config\n' > "$bin_dest/config.ini"
printf 'old-unit\n' > "$systemd_dest/tet2-worker.service"
printf 'old-cron\n' > "$cron_dest/tet2-purge-campaigns"
sqlite3 "$db_path" "CREATE TABLE state(value TEXT); INSERT INTO state VALUES('before');"

common_args=(
  "--web-dest=$web_dest"
  "--bin-dest=$bin_dest"
  "--systemd-dest=$systemd_dest"
  "--cron-dest=$cron_dest"
  "--backup-root=$backup_root"
  "--db-path=$db_path"
)

"$deploy_script" "${common_args[@]}" > /dev/null
grep -qx 'old-index' "$web_dest/index.html"
test ! -e "$backup_root"

"$deploy_script" --apply "--backup-id=$backup_id" "${common_args[@]}" > /dev/null
cmp "$repo_root/web/index.html" "$web_dest/index.html"
cmp "$repo_root/bin/create_beacon_files.py" "$bin_dest/create_beacon_files.py"
test "$(stat -c '%a' "$bin_dest/__BeaconMst.png")" = '644'
test "$(stat -c '%a' "$bin_dest/create_beacon_files.py")" = '755'
test "$(stat -c '%U:%G' "$bin_dest/__BeaconMst.png")" = 'root:root'
grep -qx 'local-config' "$bin_dest/config.ini"
test -f "$backup_root/$backup_id/files.tsv"
test -f "$backup_root/$backup_id/db/tet2.sqlite"

sqlite3 "$db_path" "UPDATE state SET value='after';"
"$rollback_script" --restore-db "--backup-id=$backup_id" "${common_args[@]}" > /dev/null
cmp "$repo_root/web/index.html" "$web_dest/index.html"
test "$(sqlite3 "$db_path" 'SELECT value FROM state')" = 'after'

"$rollback_script" --apply --restore-db --db-offline-confirmed \
  "--backup-id=$backup_id" "${common_args[@]}" > /dev/null
grep -qx 'old-index' "$web_dest/index.html"
grep -qx 'old-unit' "$systemd_dest/tet2-worker.service"
grep -qx 'old-cron' "$cron_dest/tet2-purge-campaigns"
grep -qx 'local-config' "$bin_dest/config.ini"
test ! -e "$web_dest/api/auth.php"
test -f "$backup_root/$backup_id/rollback-removed/web/api/auth.php"
test "$(sqlite3 "$db_path" 'SELECT value FROM state')" = 'before'

printf 'corrupted\n' > "$backup_root/$backup_id/files/systemd/tet2-worker.service"
printf 'rollback-must-be-atomic\n' > "$web_dest/index.html"
if "$rollback_script" --apply "--backup-id=$backup_id" "${common_args[@]}" > /dev/null 2>&1; then
  echo 'corrupted backupを拒否しませんでした' >&2
  exit 1
fi
grep -qx 'rollback-must-be-atomic' "$web_dest/index.html"
printf 'old-unit\n' > "$backup_root/$backup_id/files/systemd/tet2-worker.service"

printf 'web\t../../escape\tMISSING\t-\n' >> "$backup_root/$backup_id/files.tsv"
if "$rollback_script" "--backup-id=$backup_id" "${common_args[@]}" > /dev/null 2>&1; then
  echo 'path traversalを含むmanifestを拒否しませんでした' >&2
  exit 1
fi

mkdir -p "$backup_root/20250101T000000-1" "$backup_root/20260101T000000-2"
"$prune_script" --keep-count=1 --min-age-days=0 "--backup-root=$backup_root" > /dev/null
test -d "$backup_root/20250101T000000-1"
test ! -e "$backup_root/.trash"
"$prune_script" --apply --keep-count=1 --min-age-days=0 \
  "--backup-root=$backup_root" > /dev/null
test -d "$backup_root/$backup_id"
test -d "$backup_root/.trash/20250101T000000-1"
test -d "$backup_root/.trash/20260101T000000-2"

echo 'ALL TESTS PASSED'

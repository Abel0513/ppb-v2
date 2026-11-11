#!/usr/bin/env bash
# PPB V2 read-only prerequisites. No migrations, app commands or network calls.
set -euo pipefail

live="${PPB_LIVE_ROOT:-/www/wwwroot/ppbaos.com}"
base="${PPB_DEPLOY_ROOT:-/www/ppb-v2-deploy}"

fail() { printf 'STOP=%s\n' "$1" >&2; exit 1; }
for p in "$live" "$base" "$base/backups" "$base/scripts"; do
  [[ -d "$p" && ! -L "$p" ]] || fail "MISSING_OR_SYMLINK_DIRECTORY:$p"
done
for f in artisan .env composer.json composer.lock public/index.php vendor/autoload.php; do
  [[ -f "$live/$f" && ! -L "$live/$f" ]] || fail "MISSING_OR_SYMLINK_LIVE_FILE:$f"
done
[[ -d "$live/storage" && ! -L "$live/storage" ]] || fail 'STORAGE_MISSING_OR_SYMLINK'
for p in "$base" "$base/backups" "$base/scripts"; do
  [[ "$(stat -c %a "$p")" == 700 ]] || fail "PRIVATE_MODE_REQUIRED:$p"
done
baseline=''
while IFS= read -r -d '' candidate; do
  # A baseline marker was created by an earlier local snapshot step.
  candidate_dir="$(dirname "$candidate")"
  [[ -f "$candidate_dir/.env" && -f "$candidate_dir/artisan" ]] || continue
  baseline="$candidate_dir"
  break
done < <(find "$base/backups" -mindepth 2 -maxdepth 2 -type f -name .snapshot-complete -print0)
[[ -n "$baseline" ]] || fail 'NO_VERIFIED_BASELINE_SNAPSHOT'

printf 'LIVE_ROOT=%s\n' "$live"
printf 'DEPLOY_ROOT=%s\n' "$base"
echo 'PRIVATE_DIRECTORIES=PASS'
echo 'BASELINE_SNAPSHOT=FOUND'
echo 'LIVE_APPLICATION_MODIFIED=NO'
echo 'DATABASE_MODIFIED=NO'
echo 'RESULT=PPB_V2_LIVE_GUARD_PASS'

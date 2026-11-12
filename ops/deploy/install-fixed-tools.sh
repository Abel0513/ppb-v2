#!/usr/bin/env bash
# One-time installation of FIXED PPB V2 deploy tools only; no live website writes.
set -euo pipefail
umask 077

stop() { printf 'STOP=%s\n' "$1" >&2; exit 1; }

[[ "$(id -u)" == 0 ]] || stop 'MUST_RUN_AS_ROOT'
root=/www/ppb-v2-deploy
dest="$root/scripts"
[[ -d "$root" && ! -L "$root" && -d "$dest" && ! -L "$dest" ]] || stop 'PRIVATE_WORKSPACE_MISSING'
[[ "$(stat -c %a "$root")" == 700 && "$(stat -c %a "$dest")" == 700 ]] || stop 'PRIVATE_MODE_MISMATCH'

src="$(cd -- "$(dirname -- "$0")" && pwd -P)"
[[ "$src" != "$dest" ]] || stop 'REFUSE_SELF_REINSTALL'
for file in SHA256SUMS check-release.py stage-release.py controlled-deploy.py; do
  [[ -f "$src/$file" && ! -L "$src/$file" ]] || stop "MISSING_OR_SYMLINK_FILE:$file"
done
(cd "$src" && sha256sum -c SHA256SUMS)

for file in check-release.py stage-release.py controlled-deploy.py; do
  target="$dest/$file"
  [[ ! -L "$target" ]] || stop "DESTINATION_SYMLINK:$file"
  if [[ -e "$target" ]]; then
    [[ -f "$target" ]] || stop "DESTINATION_NOT_A_FILE:$file"
    cmp -s "$src/$file" "$target" || stop "EXISTING_SCRIPT_DIFFERS:$file"
  fi
done

for file in check-release.py stage-release.py controlled-deploy.py; do
  if [[ ! -e "$dest/$file" ]]; then
    install -o root -g root -m 600 "$src/$file" "$dest/$file"
  fi
done
for file in check-release.py stage-release.py controlled-deploy.py; do
  cmp -s "$src/$file" "$dest/$file" || stop "INSTALLED_CHECKSUM_DIFFERS:$file"
  [[ "$(stat -c %U:%G "$dest/$file")" == root:root ]] || stop "WRONG_OWNER:$file"
done

echo 'SCRIPTS_INSTALLED_IN_PRIVATE_WORKSPACE=YES'
echo 'LIVE_APPLICATION_MODIFIED=NO'
echo 'DATABASE_MODIFIED=NO'
echo 'RESULT=PPB_V2_FIXED_DEPLOY_TOOLS_INSTALLED'

#!/usr/bin/env bash
set -euo pipefail
EXPECTED_DOCUMENT_ROOT='/var/www/u3609206/data/www/telvora.ru'
EXPECTED_BACKUP_ROOT='/var/www/u3609206/data/backend-backups'
manifest="$(dirname "$0")/backend-manifest.txt"
payload=''; document_root=''; backup_root=''
while [ "$#" -gt 0 ]; do
  case "$1" in
    --payload) payload="$2"; shift 2;;
    --document-root) document_root="$2"; shift 2;;
    --backup-root) backup_root="$2"; shift 2;;
    --help|-h) echo 'deploy-backend.sh --payload DIR --document-root DIR --backup-root DIR'; exit 0;;
    *) echo 'unexpected argument' >&2; exit 2;;
  esac
done
test -n "$payload" && test -n "$document_root" && test -n "$backup_root"
test "$(realpath -m -- "$document_root")" = "$EXPECTED_DOCUMENT_ROOT"
test "$(realpath -m -- "$backup_root")" = "$EXPECTED_BACKUP_ROOT"
test -f "$manifest"; test -d "$payload"; test ! -L "$payload"
mapfile -t files < <(sed '/^[[:space:]]*#/d;/^[[:space:]]*$/d' "$manifest")
test "${#files[@]}" -gt 0
for relative in "${files[@]}"; do
  case "$relative" in /*|../*|*/../*|*\\*|*.php/*) echo 'unsafe manifest path' >&2; exit 1;; esac
  test "$relative" = "$(basename "$relative")"
  test -f "$payload/$relative"; test ! -L "$payload/$relative"
done
lint_dir="$(mktemp -d)"; trap 'rm -rf -- "$lint_dir"' EXIT
for relative in "${files[@]}"; do
  if ! php -l "$payload/$relative" >"$lint_dir/$relative.out" 2>&1; then
    echo "LINT_FAILED $relative" >&2; exit 1
  fi
done
mkdir -p -- "$backup_root"; chmod 700 "$backup_root"
stamp="$(date -u +%Y%m%dT%H%M%SZ)-$$"; backup="$backup_root/backend-$stamp"; mkdir -- "$backup"; chmod 700 "$backup"
replaced=()
rollback() {
  set +e
  for relative in "${replaced[@]}"; do
    destination="$document_root/$relative"; saved="$backup/$relative"; temporary="$destination.telvora-backend-rollback-$$"
    if [ -f "$saved" ]; then cp -p -- "$saved" "$temporary" && mv -f -- "$temporary" "$destination"; else rm -f -- "$destination"; fi
  done
}
trap rollback ERR
for relative in "${files[@]}"; do
  destination="$document_root/$relative"; test ! -L "$destination"
  if [ -e "$destination" ]; then test -f "$destination"; cp -p -- "$destination" "$backup/$relative"; fi
done
for relative in "${files[@]}"; do
  destination="$document_root/$relative"; temporary="$destination.telvora-backend-new-$$"
  cp -p -- "$payload/$relative" "$temporary"; chmod 640 "$temporary"; mv -f -- "$temporary" "$destination"; replaced+=("$relative")
done
for relative in "${files[@]}"; do
  php -l "$document_root/$relative" >/dev/null 2>&1 || { echo "POST_LINT_FAILED $relative" >&2; exit 1; }
done
trap - ERR
python3 - "$backup" <<'PY'
import json,sys
print(json.dumps({'status':'DEPLOYED','backup_reference':sys.argv[1]}))
PY

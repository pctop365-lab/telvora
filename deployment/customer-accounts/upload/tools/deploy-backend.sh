#!/usr/bin/env bash
set -euo pipefail

payload= manifest= document_root= backup_root=
while [ "$#" -gt 0 ]; do
  case "$1" in
    --payload) payload="$2"; shift 2;;
    --manifest) manifest="$2"; shift 2;;
    --document-root) document_root="$2"; shift 2;;
    --backup-root) backup_root="$2"; shift 2;;
    *) echo "Usage: deploy-backend.sh --payload DIR --manifest FILE --document-root DIR --backup-root DIR" >&2; exit 2;;
  esac
done
[ -d "$payload" ] && [ -f "$manifest" ] && [ -d "$document_root" ] && [ -d "$backup_root" ]
mapfile -t files < <(sed '/^[[:space:]]*#/d;/^[[:space:]]*$/d' "$manifest")
[ "${#files[@]}" -gt 0 ]
for relative in "${files[@]}"; do
  case "$relative" in /*|../*|*/../*|*\\*|*.php/*) echo "unsafe manifest path: $relative" >&2; exit 1;; esac
  [ "$relative" = "$(basename "$relative")" ] || { echo "manifest must contain flat filenames" >&2; exit 1; }
  [ -f "$payload/$relative" ] || { echo "missing payload file: $relative" >&2; exit 1; }
  php -l "$payload/$relative" >/dev/null
done
stamp="$(date -u +%Y%m%dT%H%M%SZ)-$$"
backup="$backup_root/customer-accounts-$stamp"
mkdir -m 700 "$backup"
rollback() {
  set +e
  for relative in "${files[@]}"; do
    destination="$document_root/$relative"
    saved="$backup/$relative"
    if [ -f "$saved" ]; then cp -p -- "$saved" "$destination"; else rm -f -- "$destination"; fi
  done
}
trap rollback ERR
for relative in "${files[@]}"; do
  destination="$document_root/$relative"
  if [ -e "$destination" ]; then
    [ -f "$destination" ] || { echo "destination is not a regular file: $relative" >&2; exit 1; }
    cp -p -- "$destination" "$backup/$relative"
  fi
done
for relative in "${files[@]}"; do
  destination="$document_root/$relative"
  temporary="$destination.telvora-customer-new-$$"
  cp -p -- "$payload/$relative" "$temporary"
  chmod 640 "$temporary"
  mv -f -- "$temporary" "$destination"
done
for relative in "${files[@]}"; do php -l "$document_root/$relative" >/dev/null; done
trap - ERR
python3 - "$backup" <<'PY'
import json, pathlib, sys
print(json.dumps({'status':'DEPLOYED','backup_reference':str(pathlib.Path(sys.argv[1]))}))
PY

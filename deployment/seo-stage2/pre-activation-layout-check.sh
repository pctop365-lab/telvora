#!/usr/bin/env bash
set -eu

# Read-only check. Run from the deployed DocumentRoot or pass its path.
root="${1:-.}"
forbidden=(
  catalog delivery services warranty returns support contacts requisites
  offer privacy personal-data-consent cookies
)
failed=0
for name in "${forbidden[@]}"; do
  if [ -d "$root/$name" ]; then
    printf 'FAIL forbidden public route directory exists: %s\n' "$root/$name" >&2
    failed=1
  fi
done

for required in index.html client.html 404.html robots.txt sitemap.xml _prerender; do
  if [ ! -e "$root/$required" ]; then
    printf 'FAIL required release path missing: %s\n' "$root/$required" >&2
    failed=1
  fi
done

if [ "$failed" -ne 0 ]; then exit 1; fi
printf 'pre-activation layout check: PASS (%s)\n' "$root"

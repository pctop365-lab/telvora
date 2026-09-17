#!/usr/bin/env bash
set -eu

# Read-only validator for an extracted seo-release package. It never touches
# the DocumentRoot; pass the package root (containing payload/) as argument.
root="${1:-.}"
PYTHON_BIN="${TELVORA_PYTHON:-python3}"
payload="$root/payload"
failed=0
fail() { printf 'FAIL %s\n' "$1" >&2; failed=1; }

for required in deployment-manifest.json snapshot.json checksums.sha256 production.htaccess payload; do
  [ -e "$root/$required" ] || fail "required package path missing: $required"
done

if [ "$failed" -eq 0 ]; then
  (cd "$root" && sha256sum -c checksums.sha256) || fail 'checksum validation failed'
fi

if command -v "$PYTHON_BIN" >/dev/null 2>&1 || [ -x "$PYTHON_BIN" ]; then
  "$PYTHON_BIN" - "$root" <<'PY' || failed=1
import json, pathlib, re, stat, sys
root = pathlib.Path(sys.argv[1]).resolve()
payload = root / 'payload'
manifest = json.loads((root / 'deployment-manifest.json').read_text(encoding='utf-8'))
snapshot = json.loads((root / 'snapshot.json').read_text(encoding='utf-8'))
if manifest.get('schemaVersion') != 1: raise SystemExit('FAIL unsupported manifest schema')
if manifest.get('snapshotHash') != snapshot.get('snapshotHash'): raise SystemExit('FAIL snapshot hash mismatch')
if manifest.get('routeCount') != snapshot.get('routeCount'): raise SystemExit('FAIL route count mismatch')
if manifest.get('sitemapUrlCount') != snapshot.get('sitemapUrlCount'): raise SystemExit('FAIL sitemap count mismatch')
allowed = {'index.html','client.html','404.html','robots.txt','sitemap.xml','favicon.ico','favicon.svg','favicon-32x32.png','favicon-48x48.png','apple-touch-icon.png','telvora-logo.svg','telvora-logo-dark.svg','telvora-logo-white.svg','telvora-mark.svg','assets','images','_prerender'}
actual = {p.name for p in payload.iterdir()}
if actual - allowed: raise SystemExit(f'FAIL unexpected payload paths: {sorted(actual - allowed)}')
for path in payload.rglob('*'):
    rel = path.relative_to(payload).as_posix()
    if path.is_symlink(): raise SystemExit(f'FAIL symlink: {rel}')
    if '..' in pathlib.PurePosixPath(rel).parts or rel.startswith('/') or '\\' in rel: raise SystemExit(f'FAIL unsafe path: {rel}')
    mode = path.lstat().st_mode
    if not (stat.S_ISREG(mode) or stat.S_ISDIR(mode)): raise SystemExit(f'FAIL unsupported file type: {rel}')
    if stat.S_ISREG(mode) and path.stat().st_size == 0: raise SystemExit(f'FAIL empty payload file: {rel}')
    if stat.S_ISREG(mode) and (rel.lower().endswith('.php') or re.search(r'(^|/)(uploads|pdf|vendor|runtime|logs?|locks?|telegram[^/]*|\.env|\.git)(/|$)', rel, re.I)): raise SystemExit(f'FAIL forbidden payload file: {rel}')
for route, internal in manifest.get('prerenderFiles', {}).items():
    target = payload / internal.lstrip('/')
    if not target.is_file(): raise SystemExit(f'FAIL missing prerender: {route}')
    if f'https://telvora.ru{route}' not in target.read_text(encoding='utf-8'): raise SystemExit(f'FAIL canonical missing: {route}')
if not (payload / '_prerender').is_dir(): raise SystemExit('FAIL _prerender missing')
if 'noindex' not in (payload / 'client.html').read_text(encoding='utf-8'): raise SystemExit('FAIL client.html noindex missing')
if 'noindex' not in (payload / '404.html').read_text(encoding='utf-8'): raise SystemExit('FAIL 404.html noindex missing')
for route in manifest.get('productRoutes', []):
    if 'index, follow' not in (payload / manifest['prerenderFiles'][route].lstrip('/')).read_text(encoding='utf-8'): raise SystemExit(f'FAIL product not indexable: {route}')
sitemap = (payload / 'sitemap.xml').read_text(encoding='utf-8')
locs = re.findall(r'<loc>(https://telvora\.ru[^<]*)</loc>', sitemap)
if len(locs) != manifest.get('sitemapUrlCount'): raise SystemExit('FAIL sitemap URL count')
if any('/_prerender/' in url or 'www.' in url for url in locs): raise SystemExit('FAIL non-public sitemap URL')
htaccess = (root / 'production.htaccess').read_text(encoding='utf-8')
if 'THE_REQUEST' not in htaccess or 'R=404,END' not in htaccess: raise SystemExit('FAIL internal namespace/404 rules')
if 'ErrorDocument 404 /404.html' not in htaccess: raise SystemExit('FAIL ErrorDocument rule')
for route in manifest.get('routes', []):
    expression = r'RewriteRule \^\$ index\.html' if route == '/' else r'RewriteRule \^' + re.escape(route.lstrip('/')) + r'\$'
    if not re.search(expression, htaccess): raise SystemExit(f'FAIL missing htaccess route: {route}')
print(f'pre-activation layout check: PASS ({manifest.get("routeCount")} routes)')
PY
else
  fail 'python3 is required for package validation'
fi

if [ "$failed" -ne 0 ]; then exit 1; fi

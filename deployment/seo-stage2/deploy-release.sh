#!/usr/bin/env bash
set -euo pipefail
archive=; staging_root=; document_root=; backup_root=; dry_run=0; rollback_dir=; failure_point=; PYTHON_BIN="${TELVORA_PYTHON:-python3}"
while [ "$#" -gt 0 ]; do
  case "$1" in
    --archive) archive="$2"; shift 2;; --staging-root) staging_root="$2"; shift 2;;
    --document-root) document_root="$2"; shift 2;; --backup-root) backup_root="$2"; shift 2;;
    --dry-run) dry_run=1; shift;; --rollback) rollback_dir="$2"; shift 2;;
    --failure-point) failure_point="$2"; shift 2;; --help|-h) echo 'deploy-release.sh --archive FILE --staging-root DIR --document-root DIR --backup-root DIR [--dry-run]'; exit 0;;
    *) echo "unknown argument: $1" >&2; exit 2;;
  esac
done
die(){ echo "deploy-release: FAIL: $*" >&2; exit 1; }
[ -z "$failure_point" ] || [ "${TELVORA_DEPLOY_TEST_MODE:-0}" = 1 ] || die '--failure-point is test-only (set TELVORA_DEPLOY_TEST_MODE=1)'
[ -n "$document_root" ] || die '--document-root required'; [ -n "$staging_root" ] || die '--staging-root required'; [ -n "$backup_root" ] || die '--backup-root required'; [ -d "$document_root" ] || die 'DocumentRoot does not exist'
[ "$document_root" != / ] || die 'DocumentRoot=/ forbidden'; [ "$staging_root" != "$document_root" ] || die 'staging equals DocumentRoot'; [ "$backup_root" != "$document_root" ] || die 'backup equals DocumentRoot'
if command -v realpath >/dev/null 2>&1 && [ "${TELVORA_NO_REALPATH:-0}" != 1 ]; then
  document_root="$(realpath -m "$document_root")"; staging_root="$(realpath -m "$staging_root")"; backup_root="$(realpath -m "$backup_root")"
else
  mkdir -p "$staging_root" "$backup_root"
  # Git Bash on Windows may expose the workspace as a non-writable /c mount;
  # retain relative fixture paths there. REG.RU/Linux uses realpath above.
fi
[ "$document_root" != / ] || die 'resolved DocumentRoot=/ forbidden'; [ "$staging_root" != "$document_root" ] || die 'resolved staging equals DocumentRoot'; [ "$backup_root" != "$document_root" ] || die 'resolved backup equals DocumentRoot'
mkdir -p "$staging_root" "$backup_root"
lock_file="${TELVORA_SEO_LOCK_FILE:-$(dirname "$staging_root")/.telvora-seo-deploy.lock}"; mkdir -p "$(dirname "$lock_file")"; exec 9>"$lock_file"
if command -v flock >/dev/null 2>&1; then flock -n 9 || die 'another deployment holds the lock'; fi
if [ -n "$rollback_dir" ]; then
  [ -f "$rollback_dir/backup-manifest.json" ] || die 'backup manifest missing'
  "$PYTHON_BIN" - "$rollback_dir" "$document_root" <<'PY'
import json,pathlib,shutil,sys
b=pathlib.Path(sys.argv[1]).resolve(); r=pathlib.Path(sys.argv[2]).resolve(); d=json.loads((b/'backup-manifest.json').read_text())
for x in d['files']:
 t=r/x['restoreTarget']; s=b/x['backupLocation'] if x.get('backupLocation') else None
 if x['existedBefore']:
  if not s or not s.is_file(): raise SystemExit('missing backup '+x['restoreTarget'])
  t.parent.mkdir(parents=True,exist_ok=True); shutil.copy2(s,t)
 elif t.exists() or t.is_symlink():
  if t.is_dir(): raise SystemExit('unexpected directory '+str(t))
  t.unlink()
for rel in sorted(d.get('createdDirectories', []), key=lambda x: (x.count('/'), x), reverse=True):
 p=r/rel
 if p.is_dir() and not any(p.iterdir()): p.rmdir()
print('rollback restored',len(d['files']),'managed files')
PY
  exit 0
fi
[ -n "$archive" ] || die '--archive required'; [ -f "$archive" ] || die 'archive missing'
if [ -n "${TELVORA_ARCHIVE_SHA256:-}" ]; then [ "$(sha256sum "$archive"|cut -d' ' -f1)" = "$TELVORA_ARCHIVE_SHA256" ] || die 'archive checksum mismatch'; fi
tar -tzf "$archive" >/dev/null || die 'invalid archive'
while IFS= read -r member; do member="${member#./}"; [ -z "$member" ] && continue; case "$member" in /*|../*|*/../*|..|[A-Za-z]:/*|*\\*) die "unsafe archive member: $member";; esac; done < <(tar -tzf "$archive")
if ! tar -tvzf "$archive"|awk 'substr($1,1,1) ~ /[lshcbp]/ {bad=1} END {exit bad}'; then die 'archive symlink or unsupported type'; fi
tmp="${staging_root%/}/.extract.$$"; [ ! -e "$tmp" ] || die 'temporary extraction path already exists'; mkdir -p "$tmp"; trap 'rm -rf "$tmp"' EXIT; tar -xzf "$archive" --no-same-owner -C "$tmp" || die 'extract failed'
[ -f "$tmp/deployment-manifest.json" ] || die 'manifest missing'; release_id="$("$PYTHON_BIN" - "$tmp/deployment-manifest.json" <<'PY'
import json,sys
print(json.load(open(sys.argv[1]))['releaseId'])
PY
)"; case "$release_id" in ''|*/*|*..*) die 'unsafe release ID';; esac
stage="$staging_root/$release_id"; [ ! -e "$stage" ] || die 'staging release already exists'; mkdir -p "$stage"; cp -a "$tmp/." "$stage/"; rm -rf "$tmp"; trap - EXIT
[ -f "$stage/tools/pre-activation-layout-check.sh" ] || die 'packaged validator missing'; bash "$stage/tools/pre-activation-layout-check.sh" "$stage" || die 'immutable validator failed'
plan="$stage/activation-plan.json"
"$PYTHON_BIN" - "$stage" "$document_root" "$plan" <<'PY'
import hashlib,json,os,pathlib,sys
s,r,o=map(pathlib.Path,sys.argv[1:]); m=json.loads((s/'deployment-manifest.json').read_text()); allowed=set(m['managedFiles'])|{'.htaccess'}
root_resolved=r.resolve()
for p in allowed:
 if p.startswith('/') or '..' in pathlib.PurePosixPath(p).parts or p.endswith('.php') or p in {'public_contacts.json','telvora_secrets.php','.env'} or p.startswith(('uploads/','pdf/','telegram','runtime','logs','locks','vendor')): raise SystemExit('forbidden managed path '+p)
 target=(r/p).resolve()
 if target != root_resolved and root_resolved not in target.parents: raise SystemExit('destination escapes DocumentRoot '+p)
def src(p): return s/'production.htaccess' if p=='.htaccess' else s/'payload'/p
def dst(p): return r/p
def sha(p): return hashlib.sha256(p.read_bytes()).hexdigest()
a=pathlib.Path(os.environ.get('TELVORA_ACTIVE_RELEASE',str(s.parent/'active-release.json'))); old=json.loads(a.read_text()) if a.is_file() else None; previous=set(old.get('managedFiles',[])) if old else set()
if not old:
 previous|={p.name for p in r.iterdir() if p.is_file() and p.name in m['managedRootFiles']}; previous|={f'_prerender/{p.name}' for p in (r/'_prerender').glob('*') if p.is_file()} if (r/'_prerender').is_dir() else set()
new=sorted(allowed); add=[p for p in new if not dst(p).is_file()]; replace=[p for p in new if dst(p).is_file() and sha(dst(p))!=sha(src(p))]; unchanged=[p for p in new if dst(p).is_file() and sha(dst(p))==sha(src(p))]; remove=[p for p in sorted(previous|{'.htaccess'}) if p not in new and p.startswith('_prerender/') and dst(p).is_file()]
created_dirs=set()
for p in add+replace:
 q=dst(p).parent
 while q != r and not q.exists(): created_dirs.add(q.relative_to(r).as_posix()); q=q.parent
backup=[{'path':p,'existedBefore':dst(p).is_file(),'previousSha256':sha(dst(p)) if dst(p).is_file() else None,'action':'add' if p in add else ('replace' if p in replace else 'remove'),'restoreTarget':p,'backupLocation':f'files/{p}' if dst(p).is_file() else None} for p in sorted(set(add+replace+remove))]
json.dump({'schemaVersion':1,'releaseId':m['releaseId'],'commitSha':m['commitSha'],'snapshotHash':m['snapshotHash'],'add':add,'replace':replace,'remove':remove,'unchanged':unchanged,'createdDirectories':sorted(created_dirs),'backup':backup,'activationOrder':['assets/images','_prerender','client.html/404.html','index.html','robots.txt/sitemap.xml/other static','.htaccess']},open(o,'w'),indent=2); open(o,'a').write('\n')
PY
echo "activation plan: $plan"; cat "$plan"; [ "$dry_run" -eq 1 ] && exit 0
backup_dir="$backup_root/$(date -u +%Y%m%dT%H%M%SZ)-$release_id"; mkdir -p "$backup_dir/files"; cp "$plan" "$backup_dir/activation-plan.json"
"$PYTHON_BIN" - "$plan" "$document_root" "$backup_dir" <<'PY'
import json,pathlib,shutil,sys
p,r,b=map(pathlib.Path,sys.argv[1:]); d=json.loads(p.read_text()); records=[]
for x in d['backup']:
 t=r/x['restoreTarget']; s=b/x['backupLocation'] if x['backupLocation'] else None
 if t.is_file(): s.parent.mkdir(parents=True,exist_ok=True); shutil.copy2(t,s)
 records.append(x)
json.dump({'schemaVersion':1,'releaseId':d['releaseId'],'createdDirectories':d.get('createdDirectories',[]),'files':records},open(b/'backup-manifest.json','w'),indent=2); open(b/'backup-manifest.json','a').write('\n')
PY
activation_started=1
rollback(){ echo 'activation failed; rollback' >&2; "$0" --rollback "$backup_dir" --document-root "$document_root" --staging-root "$staging_root" --backup-root "$backup_root" || { echo "ROLLBACK FAILED: $backup_dir" >&2; exit 2; }; }
trap rollback ERR
activate(){ p="$1"; [ "$p" = .htaccess ] && src="$stage/production.htaccess" || src="$stage/payload/$p"; dest="$document_root/$p"; case "$p" in /*|../*|*/../*|uploads/*|pdf/*|telegram*|runtime*|logs*|locks*|vendor*|*.php|public_contacts.json|telvora_secrets.php|.env) die "forbidden destination $p";; esac; [ ! -L "$dest" ] || die "symlink destination $p"; mkdir -p "$(dirname "$dest")"; tmp="$dest.telvora-new-$release_id"; rm -f "$tmp"; cp "$src" "$tmp"; mv -f "$tmp" "$dest"; }
failpoint(){ [ "$failure_point" = "$1" ] && { echo "injected failure: $1" >&2; return 1; } || return 0; }
mapfile -t paths < <("$PYTHON_BIN" - "$plan" <<'PY'
import json,sys
x=json.load(open(sys.argv[1])); print('\n'.join(x['add']+x['replace']))
PY
)
for p in "${paths[@]}"; do p="${p%$'\r'}"; case "$p" in assets/*|images/*) activate "$p";; esac; done; failpoint assets
for p in "${paths[@]}"; do p="${p%$'\r'}"; case "$p" in _prerender/*) activate "$p";; esac; done; for p in "${paths[@]}"; do p="${p%$'\r'}"; case "$p" in client.html|404.html) activate "$p";; esac; done; failpoint prerender
for p in "${paths[@]}"; do p="${p%$'\r'}"; [ "$p" = index.html ] && activate "$p"; done; failpoint root
for p in "${paths[@]}"; do p="${p%$'\r'}"; case "$p" in robots.txt|sitemap.xml|favicon*|apple-touch-icon.png|telvora-logo*|telvora-logo-dark.svg|telvora-logo-white.svg|telvora-mark.svg) activate "$p";; esac; done
mapfile -t removes < <("$PYTHON_BIN" - "$plan" <<'PY'
import json,sys
print('\n'.join(json.load(open(sys.argv[1]))['remove']))
PY
); for p in "${removes[@]}"; do p="${p%$'\r'}"; [ -n "$p" ] && rm -f "$document_root/$p"; done
activate .htaccess; failpoint htaccess
"$PYTHON_BIN" - "$plan" "$document_root" "$staging_root/active-release.json" "$backup_dir" <<'PY'
import hashlib,json,pathlib,sys,datetime
p,r,a,b=map(pathlib.Path,sys.argv[1:]); d=json.loads(p.read_text()); managed=sorted(set(d['add']+d['replace']+d['unchanged'])|{'.htaccess'}); checks={x:hashlib.sha256((r/x).read_bytes()).hexdigest() for x in managed if (r/x).is_file()}; json.dump({'releaseId':d['releaseId'],'commit':d['commitSha'],'snapshotHash':d['snapshotHash'],'activatedAt':datetime.datetime.now(datetime.timezone.utc).isoformat(),'managedFiles':managed,'productionChecksums':checks,'backupReference':str(b)},open(a,'w'),indent=2); open(a,'a').write('\n')
PY
trap - ERR; echo "activation PASS: $release_id"

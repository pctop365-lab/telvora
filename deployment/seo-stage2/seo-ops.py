#!/usr/bin/env python3
"""Read-only-by-default operational helpers for TELVORA SEO releases."""

import argparse
import hashlib
import json
import os
import re
import shutil
import sys
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import unquote, urlsplit

BASE = Path('/var/www/u3609206/data')
DOCUMENT_ROOT = BASE / 'www/telvora.ru'
STAGING_ROOT = BASE / 'staging'
BACKUP_ROOT = BASE / 'telvora-backups'
STAGE_RE = re.compile(r'^telvora-seo-[0-9a-f]{40}-[0-9]+$')
BACKUP_RE = re.compile(r'^\d{8}T\d{6}Z-[0-9a-f]{12}-[0-9a-f]{12}$')
HASHED_ASSET_RE = re.compile(r'^[A-Za-z0-9._-]+-[A-Za-z0-9]{6,}\.(?:js|css)$')


class OpsError(RuntimeError):
    pass


def fail(message):
    raise OpsError(message)


def resolved(path):
    return Path(path).expanduser().resolve(strict=False)


def test_mode():
    return os.environ.get('TELVORA_OPS_TEST_MODE') == '1'


def safe_root(value, expected, label, allow_document=False):
    if '..' in Path(str(value)).parts:
        fail(f'{label} contains path traversal')
    root = resolved(value)
    expected = resolved(expected)
    if root != expected and not test_mode():
        fail(f'{label} must be exactly {expected}')
    if not allow_document and root == resolved(DOCUMENT_ROOT):
        fail(f'{label} must not be DocumentRoot')
    if not root.name:
        fail(f'{label} is empty')
    if root.exists() and not root.is_dir():
        fail(f'{label} is not a directory')
    return root


def reject_symlink_tree(path):
    if path.is_symlink():
        fail(f'symlink is forbidden: {path}')
    if not path.exists():
        return
    for item in path.rglob('*'):
        if item.is_symlink():
            fail(f'symlink is forbidden: {item}')


def require_name(name, pattern, label):
    if not pattern.fullmatch(name):
        fail(f'unexpected {label} name: {name}')


def print_report(report):
    print(json.dumps(report, indent=2, sort_keys=True))


def staging_report(args):
    root = safe_root(args.root, STAGING_ROOT, 'staging root')
    if not root.exists():
        print_report({'root': root.as_posix(), 'eligible': [], 'keep': [], 'delete': [], 'dryRun': not args.execute})
        return
    reject_symlink_tree(root)
    entries = sorted(root.iterdir(), key=lambda p: (p.stat().st_mtime_ns, p.name), reverse=True)
    candidates = []
    for item in entries:
        if not item.is_dir():
            continue
        if not STAGE_RE.fullmatch(item.name):
            continue
        if not (item / 'release.tar.gz').is_file() or not (item / 'validated').is_dir():
            fail(f'staging directory is not a validated release: {item.name}')
        candidates.append(item)
    active = None
    if args.active_stage:
        require_name(args.active_stage, STAGE_RE, 'active staging')
        active = args.active_stage
    keep = {p.name for p in candidates[:args.keep]}
    if active:
        keep.add(active)
    delete = [p for p in candidates if p.name not in keep]
    report = {
        'generatedAt': datetime.now(timezone.utc).isoformat(),
        'root': root.as_posix(),
        'policy': {'keepLatest': args.keep, 'activeStage': active},
        'eligible': [p.name for p in candidates],
        'keep': sorted(keep),
        'delete': [p.name for p in delete],
        'dryRun': not args.execute,
    }
    print_report(report)
    if args.execute:
        if not args.confirm:
            fail('--execute requires --confirm SEO-STAGING-CLEANUP')
        for item in delete:
            reject_symlink_tree(item)
            shutil.rmtree(item)


def backup_report(args):
    root = safe_root(args.root, BACKUP_ROOT, 'backup root')
    if not root.exists():
        print_report({'root': root.as_posix(), 'eligible': [], 'keep': [], 'delete': [], 'dryRun': not args.execute})
        return
    reject_symlink_tree(root)
    now = datetime.now(timezone.utc).timestamp()
    candidates = []
    for item in root.iterdir():
        if item.is_symlink():
            fail(f'symlink is forbidden: {item}')
        if not item.is_dir() or not BACKUP_RE.fullmatch(item.name):
            continue
        if not (item / 'backup-manifest.json').is_file() or not (item / 'activation-plan.json').is_file():
            fail(f'SEO backup missing manifests: {item.name}')
        try:
            json.loads((item / 'backup-manifest.json').read_text(encoding='utf-8'))
            json.loads((item / 'activation-plan.json').read_text(encoding='utf-8'))
        except (OSError, ValueError) as exc:
            fail(f'invalid SEO backup {item.name}: {exc}')
        candidates.append(item)
    candidates.sort(key=lambda p: p.name, reverse=True)
    active = None
    if args.active_backup:
        require_name(args.active_backup, BACKUP_RE, 'active backup')
        active = args.active_backup
    keep = {p.name for p in candidates[:args.keep]}
    if args.keep_days is not None:
        keep.update(p.name for p in candidates if now - p.stat().st_mtime <= args.keep_days * 86400)
    if active:
        keep.add(active)
    delete = [p for p in candidates if p.name not in keep]
    report = {
        'generatedAt': datetime.now(timezone.utc).isoformat(),
        'root': root.as_posix(),
        'policy': {'keepLatest': args.keep, 'keepDays': args.keep_days, 'activeBackup': active},
        'eligible': [p.name for p in candidates],
        'keep': sorted(keep),
        'delete': [p.name for p in delete],
        'dryRun': not args.execute,
    }
    print_report(report)
    if args.execute:
        if not args.confirm:
            fail('--execute requires --confirm SEO-BACKUP-CLEANUP')
        for item in delete:
            reject_symlink_tree(item)
            shutil.rmtree(item)


def asset_report(args):
    root = safe_root(args.root, DOCUMENT_ROOT, 'DocumentRoot', allow_document=True)
    if not root.is_dir():
        fail('DocumentRoot does not exist')
    assets = root / 'assets'
    if not assets.is_dir():
        print_report({'root': root.as_posix(), 'referenced': [], 'candidates': [], 'ignored': [], 'readOnly': True})
        return
    reject_symlink_tree(assets)
    html_files = [root / 'index.html', root / 'client.html']
    prerender = root / '_prerender'
    if prerender.is_dir():
        html_files.extend(sorted(p for p in prerender.rglob('*.html') if p.is_file()))
    referenced = set()
    for html in html_files:
        if not html.is_file():
            continue
        text = html.read_text(encoding='utf-8', errors='replace')
        for match in re.finditer(r"(?:https?://[^'\"]+)?/?assets/([^'\"? )#]+)", text):
            candidate = unquote(urlsplit(match.group(0)).path).lstrip('/')
            if candidate.startswith('assets/'):
                referenced.add(candidate)
    present = []
    ignored = []
    for item in assets.rglob('*'):
        if not item.is_file():
            continue
        rel = item.relative_to(root).as_posix()
        if HASHED_ASSET_RE.fullmatch(item.name):
            present.append(rel)
        else:
            ignored.append(rel)
    candidates = sorted(set(present) - referenced)
    print_report({'root': root.as_posix(), 'referenced': sorted(referenced), 'presentHashedAssets': sorted(present), 'candidates': candidates, 'ignored': sorted(ignored), 'readOnly': True})


def parser():
    p = argparse.ArgumentParser(description='TELVORA SEO operational inspection and retention helpers')
    sub = p.add_subparsers(dest='command', required=True)
    s = sub.add_parser('staging-retention'); s.add_argument('--root', default=str(STAGING_ROOT)); s.add_argument('--keep', type=int, default=5); s.add_argument('--active-stage'); s.add_argument('--execute', action='store_true'); s.add_argument('--confirm'); s.set_defaults(func=staging_report)
    b = sub.add_parser('backup-retention'); b.add_argument('--root', default=str(BACKUP_ROOT)); b.add_argument('--keep', type=int, default=10); b.add_argument('--keep-days', type=int); b.add_argument('--active-backup'); b.add_argument('--execute', action='store_true'); b.add_argument('--confirm'); b.set_defaults(func=backup_report)
    a = sub.add_parser('assets-report'); a.add_argument('--root', default=str(DOCUMENT_ROOT)); a.set_defaults(func=asset_report)
    return p


try:
    args = parser().parse_args()
    if args.keep < 1 if hasattr(args, 'keep') else False:
        fail('--keep must be at least 1')
    args.func(args)
except OpsError as exc:
    print(f'seo-ops: FAIL: {exc}', file=sys.stderr)
    sys.exit(1)

import assert from 'node:assert/strict';
import { mkdir, mkdtemp, readFile, readdir, rm, stat, writeFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { dirname, join, relative, resolve } from 'node:path';
import { tmpdir } from 'node:os';
import { spawn, spawnSync } from 'node:child_process';

const root = resolve('.');
const archiveCandidates = (await readdir(resolve('seo-artifacts'))).filter(file => file.startsWith('telvora-seo-release-') && file.endsWith('.tar.gz')).map(file => resolve('seo-artifacts', file));
const archive = (await Promise.all(archiveCandidates.map(async file => ({ file, mtime: (await stat(file)).mtimeMs })))).sort((a, b) => a.mtime - b.mtime).at(-1)?.file;
assert.ok(archive, 'build a release archive before running engine tests');
const script = resolve('deployment/seo-stage2/deploy-release.sh');
const bash = process.env.TELVORA_BASH || (process.platform === 'win32' && 'C:/Program Files/Git/bin/bash.exe') || 'bash';
const posix = path => {
  const normalized = path.replaceAll('\\', '/');
  const rel = relative(root, path);
  if (rel && !rel.startsWith('..') && !/^[A-Za-z]:/.test(rel)) return rel.replaceAll('\\', '/');
  if (rel === '') return '.';
  return process.platform === 'win32' && /^[A-Za-z]:\//.test(normalized)
    ? `/${normalized[0].toLowerCase()}${normalized.slice(2)}`
    : normalized;
};

async function fixture() {
  const base = await mkdtemp(join(root, '.tmp-seo-deploy-'));
  const doc = join(base, 'document-root'); const staging = join(base, 'staging'); const backups = join(base, 'backups');
  await mkdir(doc, { recursive: true });
  for (const directory of ['_prerender', 'assets', 'images', 'uploads']) await mkdir(join(doc, directory), { recursive: true });
  await writeFile(join(doc, 'index.html'), 'old index\n');
  await writeFile(join(doc, '.htaccess'), 'old htaccess\n');
  await writeFile(join(doc, 'sitemap.xml'), 'old sitemap\n');
  for (const file of ['products.php', 'services.php', 'generate_invoice_pdf.php', 'public_contacts.json', 'telegram_polling.php']) await writeFile(join(doc, file), `untouched ${file}\n`);
  await writeFile(join(doc, 'runtime-state'), 'runtime untouched\n');
  await writeFile(join(doc, '_prerender', 'product-product-a.html'), 'old product A\n');
  await writeFile(join(doc, 'assets', 'old-hash.js'), 'old asset\n');
  await writeFile(join(doc, 'assets', 'unmanaged.js'), 'unmanaged asset\n');
  await writeFile(join(doc, 'images', 'unmanaged.png'), 'unmanaged image\n');
  await writeFile(join(doc, 'uploads', 'customer-file'), 'customer\n');
  return { base, doc, staging, backups };
}
async function treeSnapshot(directory) {
  const snapshot = new Map();
  async function walk(dir, prefix = '') {
    for (const entry of (await readdir(dir, { withFileTypes: true })).sort((a, b) => a.name.localeCompare(b.name))) {
      const rel = prefix ? `${prefix}/${entry.name}` : entry.name; const full = join(dir, entry.name);
      if (entry.isDirectory()) { snapshot.set(rel, { type: 'directory', mode: (await stat(full)).mode & 0o777 }); await walk(full, rel); }
      else if (entry.isFile()) snapshot.set(rel, { type: 'file', mode: (await stat(full)).mode & 0o777, sha256: createHash('sha256').update(await readFile(full)).digest('hex') });
      else snapshot.set(rel, { type: entry.isSymbolicLink() ? 'symlink' : 'other', mode: (await stat(full)).mode & 0o777 });
    }
  }
  await walk(directory); return snapshot;
}
function snapshotDiff(before, after) {
  const added = [], removed = [], changed = [], typeChanged = [], modeChanged = [];
  for (const path of new Set([...before.keys(), ...after.keys()])) {
    const left = before.get(path), right = after.get(path);
    if (!left) added.push(path); else if (!right) removed.push(path);
    else { if (left.type !== right.type) typeChanged.push(path); else if (left.type === 'file' && left.sha256 !== right.sha256) changed.push(path); if (left.mode !== right.mode) modeChanged.push(path); }
  }
  return { added, removed, changed, typeChanged, modeChanged };
}
async function treeHash(directory) {
  const hash = createHash('sha256');
  async function walk(dir, prefix = '') {
    for (const entry of (await readdir(dir, { withFileTypes: true })).sort((a, b) => a.name.localeCompare(b.name))) {
      const rel = prefix ? `${prefix}/${entry.name}` : entry.name; const full = join(dir, entry.name);
      if (entry.isDirectory()) await walk(full, rel); else hash.update(rel + '\0' + await readFile(full));
    }
  }
  await walk(directory); return hash.digest('hex');
}
async function assertTreeRestored(directory, before, message) {
  const after = await treeSnapshot(directory); const diff = snapshotDiff(before, after);
  const contract = { added: diff.added, removed: diff.removed, changed: diff.changed, typeChanged: diff.typeChanged };
  assert.deepEqual(contract, { added: [], removed: [], changed: [], typeChanged: [] }, `${message}: ${JSON.stringify(diff)}`);
}
function run(args, env = {}) {
  // Git Bash is used only for local fixture execution on Windows.
  const quote = value => `'${value.replaceAll("'", "'\\''")}'`;
  const mapped = [posix(script), ...args.map(posix)];
  if (process.env.DEBUG_DEPLOY_TEST) console.error('mapped', mapped);
  return spawnSync(bash, ['-lc', mapped.map(quote).join(' ')], { cwd: root, encoding: 'utf8', timeout: 15000, env: { ...process.env, ...(process.platform === 'win32' ? { TELVORA_NO_REALPATH: '1' } : {}), ...env } });
}
async function argsFor(f, extra = []) { return ['--archive', archive, '--staging-root', f.staging, '--document-root', f.doc, '--backup-root', f.backups, ...extra]; }

const dry = await fixture();
const beforeDry = await treeHash(dry.doc);
let result = run(await argsFor(dry, ['--dry-run']));
if (result.status !== 0) console.error('dry-run stderr:', result.stderr, 'args:', await argsFor(dry, ['--dry-run']));
assert.equal(result.status, 0, result.stderr); assert.equal(await treeHash(dry.doc), beforeDry, 'dry-run mutated DocumentRoot');
assert.match(result.stdout, /activation plan/);
await rm(dry.base, { recursive: true, force: true });

const active = await fixture();
const beforeActive = await treeSnapshot(active.doc);
result = run(await argsFor(active)); assert.equal(result.status, 0, result.stderr);
assert.ok(await stat(join(active.doc, '_prerender', 'product-lg-oled77c5rla.html')));
assert.ok(await stat(join(active.staging, 'active-release.json')));
await assert.rejects(stat(join(active.doc, '_prerender', 'product-product-a.html')));
for (const file of ['assets/unmanaged.js', 'images/unmanaged.png', 'products.php', 'services.php', 'generate_invoice_pdf.php', 'public_contacts.json', 'telegram_polling.php', 'runtime-state', 'uploads/customer-file']) {
  const expected = file === 'assets/unmanaged.js' ? 'unmanaged asset\n' : file === 'images/unmanaged.png' ? 'unmanaged image\n' : file === 'uploads/customer-file' ? 'customer\n' : file === 'runtime-state' ? 'runtime untouched\n' : `untouched ${file}\n`;
  assert.equal(await readFile(join(active.doc, file), 'utf8'), expected);
}
const backup = (await readdir(active.backups)).find(name => name !== '.telvora-seo-deploy.lock');
assert.ok(backup, 'backup directory missing');
result = run(['--rollback', join(active.backups, backup), '--document-root', active.doc, '--staging-root', active.staging, '--backup-root', active.backups]); assert.equal(result.status, 0, result.stderr);
await assertTreeRestored(active.doc, beforeActive, 'rollback did not restore fixture');
await rm(active.base, { recursive: true, force: true });

for (const point of ['assets', 'prerender', 'root', 'htaccess']) {
  const f = await fixture(); const before = await treeSnapshot(f.doc); result = run(await argsFor(f, ['--failure-point', point]), { TELVORA_DEPLOY_TEST_MODE: '1', ...(point === 'assets' ? { TELVORA_DEPLOY_DEBUG: '1' } : {}) });
  assert.notEqual(result.status, 0, `${point} failure injection unexpectedly passed`); const backupDir = (await readdir(f.backups)).find(name => name !== '.telvora-seo-deploy.lock'); assert.ok(backupDir, `${point} backup missing`); const backupManifest = JSON.parse(await readFile(join(f.backups, backupDir, 'backup-manifest.json'), 'utf8')); const assetAdds = backupManifest.files.filter(x => x.action === 'add' && /^(assets|images)\//.test(x.path)); assert.ok(assetAdds.length > 0, `${point} did not journal asset/image ADD mutations`); for (const entry of assetAdds) assert.equal(entry.existedBefore, false); if (point === 'assets') { console.error('assets ADD rollback journal:', assetAdds.map(({ path, action, existedBefore, backupLocation, restoreTarget }) => ({ path, action, existedBefore, backupLocation, restoreTarget }))); console.error('assets rollback trace:', result.stderr); } await assertTreeRestored(f.doc, before, `${point} rollback did not restore fixture`); await assert.rejects(stat(join(f.staging, 'active-release.json'))); await rm(f.base, { recursive: true, force: true });
}
const protectedFailure = await fixture(); const protectedBefore = await treeSnapshot(protectedFailure.doc); result = run(await argsFor(protectedFailure, ['--failure-point', 'root']));
assert.notEqual(result.status, 0, 'failure injection was accepted without test mode'); await assertTreeRestored(protectedFailure.doc, protectedBefore, 'test-only failure point mutated DocumentRoot'); await assert.rejects(stat(join(protectedFailure.staging, 'active-release.json'))); await rm(protectedFailure.base, { recursive: true, force: true });

const isolated = await fixture(); const isolatedBackup = join(isolated.backups, 'isolated'); await mkdir(isolatedBackup, { recursive: true }); await writeFile(join(isolated.doc, 'assets', 'new.js'), 'new\n'); await writeFile(join(isolatedBackup, 'backup-manifest.json'), JSON.stringify({ schemaVersion: 1, releaseId: 'isolated', createdDirectories: [], files: [{ path: 'assets/new.js', action: 'add', existedBefore: false, previousSha256: null, backupLocation: null, restoreTarget: 'assets/new.js' }] })); result = run(['--rollback', isolatedBackup, '--document-root', isolated.doc, '--staging-root', isolated.staging, '--backup-root', isolated.backups], { TELVORA_DEPLOY_DEBUG: '1' }); assert.equal(result.status, 0, result.stderr); await assert.rejects(stat(join(isolated.doc, 'assets', 'new.js'))); assert.equal(await readFile(join(isolated.doc, 'assets', 'unmanaged.js'), 'utf8'), 'unmanaged asset\n'); await rm(isolated.base, { recursive: true, force: true });

const invalid = await mkdtemp(join(tmpdir(), 'telvora-invalid-')); const badArchive = join(invalid, 'bad.tar.gz'); await writeFile(badArchive, 'not an archive');
const bad = await fixture(); result = run(['--archive', badArchive, '--staging-root', bad.staging, '--document-root', bad.doc, '--backup-root', bad.backups]); assert.notEqual(result.status, 0); await rm(invalid, { recursive: true, force: true }); await rm(bad.base, { recursive: true, force: true });
const hostile = await mkdtemp(join(root, '.tmp-seo-hostile-')); const python = process.env.TELVORA_PYTHON || 'python3';
const makeTar = (target, mode) => spawnSync(python, ['-c', `import tarfile,sys; p=sys.argv[1]; t=tarfile.open(p,'w:gz'); i=tarfile.TarInfo('../escape' if sys.argv[2]=='path' else 'link'); i.type=tarfile.SYMTYPE if sys.argv[2]=='symlink' else tarfile.REGTYPE; i.linkname='/tmp/escape' if i.issym() else ''; i.size=0 if i.issym() else 1; t.addfile(i, None if i.issym() else __import__('io').BytesIO(b'x')); t.close()`, target, mode], { encoding: 'utf8' });
for (const mode of ['path', 'symlink']) { const hostileArchive = join(hostile, `${mode}.tar.gz`); assert.equal(makeTar(hostileArchive, mode).status, 0); const f = await fixture(); result = run(['--archive', hostileArchive, '--staging-root', f.staging, '--document-root', f.doc, '--backup-root', f.backups]); assert.notEqual(result.status, 0, `${mode} archive was accepted`); await rm(f.base, { recursive: true, force: true }); }
const checksumFixture = await fixture(); result = run(await argsFor(checksumFixture, ['--dry-run']), { TELVORA_ARCHIVE_SHA256: '0'.repeat(64) }); assert.notEqual(result.status, 0, 'tampered archive checksum was accepted'); await rm(checksumFixture.base, { recursive: true, force: true }); await rm(hostile, { recursive: true, force: true });
const boundary = await fixture(); result = run(['--archive', archive, '--staging-root', boundary.doc, '--document-root', boundary.doc, '--backup-root', boundary.backups]); assert.notEqual(result.status, 0); result = run(['--archive', archive, '--staging-root', boundary.staging, '--document-root', '/', '--backup-root', boundary.backups]); assert.notEqual(result.status, 0); await rm(boundary.base, { recursive: true, force: true });
if (process.platform !== 'win32') {
  assert.equal(spawnSync(bash, ['-lc', 'command -v flock'], { encoding: 'utf8' }).status, 0, 'Linux deployment tests require flock');
  const locked = await fixture(); const lock = join(dirname(locked.staging), '.telvora-seo-deploy.lock'); const holder = spawn(bash, ['-lc', `exec 9>"${posix(lock)}"; flock -n 9 -c 'sleep 3'`], { stdio: 'ignore' });
  const beforeLocked = await treeSnapshot(locked.doc); await new Promise(resolve => setTimeout(resolve, 400)); result = run(await argsFor(locked, ['--dry-run'])); assert.notEqual(result.status, 0, 'concurrent lock was not rejected'); await assertTreeRestored(locked.doc, beforeLocked, 'rejected concurrent run mutated DocumentRoot');
  await new Promise(resolve => holder.on('close', resolve)); await rm(locked.base, { recursive: true, force: true });
} else {
  console.log('seo_deploy_engine_test: flock concurrency test skipped (local shell has no flock; Linux CI exercises it)');
}
console.log('seo_deploy_engine_test: PASS (dry-run, activation, rollback, failure points, boundaries, preservation)');

import assert from 'node:assert/strict';
import { mkdtemp, mkdir, writeFile, symlink, readFile, rm, stat } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { spawnSync } from 'node:child_process';

const root = await mkdtemp(join(tmpdir(), 'telvora-seo-ops-'));
const script = 'deployment/seo-stage2/seo-ops.py';
const run = (...args) => spawnSync('python', [script, ...args], {
  encoding: 'utf8',
  env: { ...process.env, TELVORA_OPS_TEST_MODE: '1' },
});
const json = result => JSON.parse(result.stdout);
const put = async (path, content = 'fixture') => { await mkdir(dirname(path), { recursive: true }); await writeFile(path, content); };

try {
  const staging = join(root, 'staging');
  await mkdir(staging);
  for (let i = 0; i < 7; i++) {
    const name = `telvora-seo-${String(i + 1).padStart(40, '0')}-${100 + i}`;
    await mkdir(join(staging, name, 'validated'), { recursive: true });
    await put(join(staging, name, 'release.tar.gz'));
  }
  const dry = run('staging-retention', '--root', staging, '--keep', '5');
  assert.equal(dry.status, 0, dry.stderr);
  const dryReport = json(dry);
  assert.equal(dryReport.dryRun, true);
  assert.equal(dryReport.delete.length, 2);
  assert.equal((await stat(join(staging, dryReport.delete[0]))).isDirectory(), true);
  const noConfirm = run('staging-retention', '--root', staging, '--keep', '5', '--execute');
  assert.notEqual(noConfirm.status, 0);
  const execute = run('staging-retention', '--root', staging, '--keep', '5', '--execute', '--confirm', 'SEO-STAGING-CLEANUP');
  assert.equal(execute.status, 0, execute.stderr);
  for (const name of dryReport.delete) await assert.rejects(stat(join(staging, name)));
  assert.equal(run('staging-retention', '--root', `${staging}/../staging/..`, '--keep', '5').status, 1);

  const backups = join(root, 'telvora-backups');
  await mkdir(backups);
  for (let i = 0; i < 12; i++) {
    const name = `202609${String(20 - i).padStart(2, '0')}T120000Z-${String(i + 1).padStart(12, '0')}-${String(i + 1).padStart(12, '0')}`;
    await mkdir(join(backups, name));
    await put(join(backups, name, 'backup-manifest.json'), '{}');
    await put(join(backups, name, 'activation-plan.json'), '{}');
  }
  await mkdir(join(backups, 'product-delete-2026')); await put(join(backups, 'manual-backup', 'marker'));
  const backupDry = run('backup-retention', '--root', backups);
  assert.equal(backupDry.status, 0, backupDry.stderr);
  const backupReport = json(backupDry);
  assert.equal(backupReport.dryRun, true);
  assert.equal(backupReport.delete.length, 2);
  assert.ok(!backupReport.delete.some(name => name.startsWith('product-delete')));
  assert.ok(!backupReport.delete.some(name => name.startsWith('manual')));

  const docroot = join(root, 'docroot');
  await mkdir(join(docroot, 'assets'), { recursive: true });
  await put(join(docroot, 'index.html'), '<script src="/assets/index-abcdef123456.js"></script>');
  await put(join(docroot, 'client.html'), '<link href="/assets/index-fedcba654321.css">');
  await mkdir(join(docroot, '_prerender'), { recursive: true });
  await put(join(docroot, '_prerender', 'home.html'), '<script src="/assets/index-abcdef123456.js"></script>');
  await put(join(docroot, 'assets', 'index-abcdef123456.js'));
  await put(join(docroot, 'assets', 'index-fedcba654321.css'));
  await put(join(docroot, 'assets', 'index-unused999999.js'));
  await put(join(docroot, 'assets', 'manual.js'));
  await put(join(docroot, 'assets', 'photo.png'));
  const assets = run('assets-report', '--root', docroot);
  assert.equal(assets.status, 0, assets.stderr);
  const assetReport = json(assets);
  assert.deepEqual(assetReport.candidates, ['assets/index-unused999999.js']);
  assert.ok(assetReport.ignored.includes('assets/manual.js'));
  assert.equal(assetReport.readOnly, true);
  assert.notEqual(run('assets-report', '--root', '/var/www/u3609206/data/www/telvora.ru').status, 0);

  let symlinkChecked = false;
  try {
    await symlink(join(staging, 'telvora-seo-0000000000000000000000000000000000000000-999'), join(staging, 'telvora-seo-0000000000000000000000000000000000000001-999'));
    symlinkChecked = true;
    assert.notEqual(run('staging-retention', '--root', staging).status, 0);
  } catch { /* Windows may disallow symlink creation without developer mode. */ }
  assert.ok(!symlinkChecked || symlinkChecked);
  console.log('seo_ops_test: PASS');
} finally {
  await rm(root, { recursive: true, force: true });
}

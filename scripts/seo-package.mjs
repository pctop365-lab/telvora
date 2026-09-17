import { createHash } from 'node:crypto';
import { cp, mkdir, readFile, readdir, rm, stat, lstat, writeFile } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { posix, resolve } from 'node:path';
import { managedDirectories, managedRootFiles } from './seo-routing.mjs';
import { createDeterministicTarGz } from './seo-deterministic-tar.mjs';

const packageRoot = resolve('seo-release');
const payloadRoot = resolve(packageRoot, 'payload');
const artifactRoot = resolve('seo-artifacts');
const manifestSource = JSON.parse(await readFile(resolve(artifactRoot, 'routes.json'), 'utf8'));
const commitSha = process.env.GITHUB_SHA || (() => {
  try { return execFileSync('git', ['rev-parse', 'HEAD'], { encoding: 'utf8' }).trim(); } catch { return 'local'; }
})();
const buildTimestamp = process.env.SOURCE_DATE_EPOCH
  ? new Date(Number(process.env.SOURCE_DATE_EPOCH) * 1000).toISOString()
  : (() => {
    try { return execFileSync('git', ['show', '-s', '--format=%cI', commitSha], { encoding: 'utf8' }).trim(); }
    catch { return '1970-01-01T00:00:00.000Z'; }
  })();
const releaseId = `${commitSha.slice(0, 12)}-${manifestSource.snapshotHash.slice(0, 12)}`;

await rm(packageRoot, { recursive: true, force: true });
await mkdir(payloadRoot, { recursive: true });
for (const file of managedRootFiles) {
  await cp(resolve('dist', file), resolve(payloadRoot, file));
}
for (const directory of managedDirectories) {
  await cp(resolve('dist', directory), resolve(payloadRoot, directory), { recursive: true, dereference: false });
}
await cp(resolve(artifactRoot, 'production.htaccess'), resolve(packageRoot, 'production.htaccess'));
await mkdir(resolve(packageRoot, 'tools'), { recursive: true });
await cp(resolve('deployment/seo-stage2/pre-activation-layout-check.sh'), resolve(packageRoot, 'tools/pre-activation-layout-check.sh'));

const normalize = value => value.replaceAll('\\', '/').replace(/^\.?\//, '');
const walk = async (root, prefix = '') => {
  const result = [];
  for (const name of (await readdir(resolve(root, prefix), { withFileTypes: true })).sort((a, b) => a.name.localeCompare(b.name))) {
    const relative = normalize(posix.join(prefix, name.name));
    const full = resolve(root, relative);
    const info = await lstat(full);
    if (info.isSymbolicLink()) throw new Error(`Symlink is forbidden in package: ${relative}`);
    if (info.isDirectory()) result.push(...await walk(root, relative));
    else if (info.isFile()) result.push(relative);
    else throw new Error(`Unsupported file type in package: ${relative}`);
  }
  return result;
};
const payloadFiles = (await walk(payloadRoot)).map(file => `payload/${file}`);
const packageFiles = [...payloadFiles, 'production.htaccess', 'tools/pre-activation-layout-check.sh'];
const sha256 = async file => createHash('sha256').update(await readFile(file)).digest('hex');
const fileRecords = [];
for (const relative of packageFiles) fileRecords.push({ path: relative, sha256: await sha256(resolve(packageRoot, relative)) });
const managedFiles = [...managedRootFiles, ...managedDirectories.flatMap(directory => payloadFiles.filter(file => file === `payload/${directory}` || file.startsWith(`payload/${directory}/`)).map(file => file.slice('payload/'.length)))];
const snapshot = {
  snapshotHash: manifestSource.snapshotHash,
  activeProductRoutes: manifestSource.productRoutes,
  routes: manifestSource.routes,
  routeCount: manifestSource.routes.length,
  sitemapUrlCount: manifestSource.routes.length,
};
await writeFile(resolve(packageRoot, 'snapshot.json'), JSON.stringify(snapshot, null, 2) + '\n');
const manifest = {
  schemaVersion: 1,
  releaseId,
  commitSha,
  buildTimestamp,
  snapshotHash: manifestSource.snapshotHash,
  routeCount: manifestSource.routes.length,
  activeProductCount: manifestSource.productRoutes.length,
  sitemapUrlCount: manifestSource.routes.length,
  routes: manifestSource.routes,
  productRoutes: manifestSource.productRoutes,
  prerenderFiles: manifestSource.prerenderFiles,
  managedRootFiles,
  managedDirectories,
  managedFiles: managedFiles.sort(),
  fileInventory: fileRecords,
  generatedHtaccess: { path: 'production.htaccess', sha256: fileRecords.find(file => file.path === 'production.htaccess').sha256 },
};
await writeFile(resolve(packageRoot, 'deployment-manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
const metadataFiles = ['snapshot.json', 'deployment-manifest.json'];
const checksumLines = [];
for (const relative of [...packageFiles, ...metadataFiles].sort()) checksumLines.push(`${await sha256(resolve(packageRoot, relative))}  ${normalize(relative)}`);
await writeFile(resolve(packageRoot, 'checksums.sha256'), checksumLines.join('\n') + '\n');
await mkdir(artifactRoot, { recursive: true });
const archive = resolve(artifactRoot, `telvora-seo-release-${releaseId}.tar.gz`);
await rm(archive, { force: true });
await createDeterministicTarGz(packageRoot, archive);
const archiveEntries = execFileSync('tar', ['-tzf', archive], { encoding: 'utf8' }).split(/\r?\n/).filter(Boolean);
for (const entry of archiveEntries) {
  const normalized = entry.replaceAll('\\', '/').replace(/^\.\//, '');
  if (!normalized) continue;
  if (normalized.startsWith('/') || /^[A-Za-z]:\//.test(normalized) || normalized.split('/').includes('..')) {
    throw new Error(`Unsafe archive member: ${entry}`);
  }
}
console.log(`SEO package: ${packageRoot}`);
console.log(`Archive: ${archive}`);
console.log(`Release: ${releaseId}; ${fileRecords.length} deployable files; ${manifest.routeCount} routes; ${manifest.activeProductCount} products`);

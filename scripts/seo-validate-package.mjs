import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { access, lstat, readFile, readdir, stat } from 'node:fs/promises';
import { resolve, relative, posix } from 'node:path';

const root = resolve(process.argv[2] || 'seo-release');
const payload = resolve(root, 'payload');
const required = ['deployment-manifest.json', 'snapshot.json', 'checksums.sha256', 'production.htaccess', 'payload', 'tools/pre-activation-layout-check.sh'];
const forbiddenName = /(^|\/)(?:.*\.php|uploads|pdf|vendor|runtime|logs?|locks?|telegram[^/]*|public_contacts\.json|telvora_secrets\.php|\.env|\.git)(?:\/|$)/i;
const safeRelative = value => value && !value.startsWith('/') && !/^[A-Za-z]:[\\/]/.test(value) && !value.split('/').includes('..') && !value.includes('\0') && value === value.replaceAll('\\', '/');
for (const path of required) await access(resolve(root, path));
const manifest = JSON.parse(await readFile(resolve(root, 'deployment-manifest.json'), 'utf8'));
const snapshot = JSON.parse(await readFile(resolve(root, 'snapshot.json'), 'utf8'));
assert.deepEqual((await readdir(root)).sort(), ['checksums.sha256', 'deployment-manifest.json', 'payload', 'production.htaccess', 'snapshot.json', 'tools'].sort(), 'unexpected package top-level path');
assert.equal(manifest.schemaVersion, 1, 'unsupported deployment manifest schema');
assert.equal(manifest.snapshotHash, snapshot.snapshotHash, 'snapshot hash mismatch');
assert.equal(manifest.routeCount, snapshot.routeCount, 'route count mismatch');
assert.equal(manifest.sitemapUrlCount, snapshot.sitemapUrlCount, 'sitemap count mismatch');
const walk = async (directory, prefix = '') => {
  const result = [];
  for (const entry of (await readdir(resolve(directory, prefix), { withFileTypes: true })).sort((a, b) => a.name.localeCompare(b.name))) {
    const path = posix.join(prefix, entry.name).replaceAll('\\', '/');
    assert.ok(safeRelative(path), `unsafe path: ${path}`);
    const full = resolve(directory, path);
    const info = await lstat(full);
    assert.ok(!info.isSymbolicLink(), `symlink forbidden: ${path}`);
    assert.ok(info.isDirectory() || info.isFile(), `unsupported file type: ${path}`);
    if (info.isDirectory()) result.push(...await walk(directory, path)); else result.push(path);
  }
  return result;
};
const actualPayload = await walk(payload);
for (const path of actualPayload) assert.ok(!forbiddenName.test(path), `forbidden payload path: ${path}`);
assert.ok(actualPayload.every(path => !/\.php$/i.test(path)), 'PHP is forbidden in payload');
for (const path of actualPayload) assert.ok((await stat(resolve(payload, path))).size > 0, `empty deployable file: ${path}`);
for (const path of manifest.managedRootFiles) assert.ok(actualPayload.includes(path), `managed root file missing: ${path}`);
const secretPattern = /BEGIN (?:RSA|EC|OPENSSH) PRIVATE KEY|DB_PASSWORD|TELEGRAM_BOT_TOKEN|AWS_SECRET_ACCESS_KEY/i;
for (const path of [...actualPayload, 'production.htaccess', 'tools/pre-activation-layout-check.sh', 'deployment-manifest.json', 'snapshot.json']) {
  if (/\.(?:html?|json|xml|txt|svg|css|js|htaccess)$/i.test(path)) {
    const file = actualPayload.includes(path) ? resolve(payload, path) : resolve(root, path);
    assert.doesNotMatch(await readFile(file, 'utf8'), secretPattern, `secret marker in ${path}`);
  }
}
const actualPackage = [...actualPayload.map(path => `payload/${path}`), 'production.htaccess', 'tools/pre-activation-layout-check.sh', 'snapshot.json', 'deployment-manifest.json'].sort();
const checksumLines = (await readFile(resolve(root, 'checksums.sha256'), 'utf8')).trim().split(/\r?\n/).filter(Boolean);
const checksums = new Map();
for (const line of checksumLines) {
  const match = line.match(/^([a-f0-9]{64})  (.+)$/);
  assert.ok(match && safeRelative(match?.[2]), `invalid checksum line: ${line}`);
  assert.ok(!checksums.has(match[2]), `duplicate checksum: ${match[2]}`);
  checksums.set(match[2], match[1]);
}
assert.deepEqual([...checksums.keys()].sort(), actualPackage, 'checksum inventory differs from package files');
for (const [path, expected] of checksums) {
  const actual = createHash('sha256').update(await readFile(resolve(root, path))).digest('hex');
  assert.equal(actual, expected, `checksum mismatch: ${path}`);
}
const expectedInventory = new Set(manifest.fileInventory.map(record => record.path));
assert.deepEqual([...expectedInventory].sort(), [...checksums.keys()].filter(path => path.startsWith('payload/') || path === 'production.htaccess' || path.startsWith('tools/')).sort(), 'manifest inventory differs');
const htaccess = await readFile(resolve(root, 'production.htaccess'), 'utf8');
assert.match(htaccess, /HTTP:X-Forwarded-Proto/);
assert.match(htaccess, /THE_REQUEST/);
assert.match(htaccess, /R=404,END/);
assert.match(htaccess, /ErrorDocument 404 \/404\.html/);
for (const route of snapshot.routes) {
  const internal = route === '/' ? 'payload/index.html' : `payload${manifest.prerenderFiles[route]}`;
  await access(resolve(root, internal));
  if (route === '/') assert.ok(htaccess.includes('RewriteRule ^$ index.html'), 'missing htaccess route: /');
  else assert.ok(htaccess.includes(`RewriteRule ^${route.slice(1)}$ `), `missing htaccess route: ${route}`);
}
assert.match(await readFile(resolve(payload, 'client.html'), 'utf8'), /noindex/);
assert.match(await readFile(resolve(payload, '404.html'), 'utf8'), /noindex/);
const sitemap = await readFile(resolve(payload, 'sitemap.xml'), 'utf8');
const intentProducts = new Set(), intentJobs = new Set();
for (const intent of manifest.publicationIntents || []) {
  assert.ok(Number.isSafeInteger(intent.product_id) && intent.product_id > 0 && Number.isSafeInteger(intent.job_id) && intent.job_id > 0);
  assert.ok(Number.isSafeInteger(intent.revision) && intent.revision >= 0);
  assert.ok(!intentProducts.has(intent.product_id) && !intentJobs.has(intent.job_id), 'duplicate publication intent');
  intentProducts.add(intent.product_id); intentJobs.add(intent.job_id);
  assert.ok(['publish','unpublish'].includes(intent.operation));
  assert.ok(['queued','running'].includes(intent.job_status));
  assert.match(intent.path, /^\/catalog\/(?:oled|qled|led|8k)\/[a-z0-9]+(?:-[a-z0-9]+)*$/);
  assert.equal(intent.path.split('/').pop(),intent.slug);
  assert.equal(manifest.productRoutes.includes(intent.path),intent.operation === 'publish', 'intent differs from packaged routes');
  if (intent.operation === 'publish') {
    const html = await readFile(resolve(payload,manifest.prerenderFiles[intent.path].slice(1)),'utf8');
    const match = html.match(/<script[^>]*id="telvora-prerender"[^>]*>(.*?)<\/script>/s);
    assert.ok(match, 'missing prerender product');
    const data=JSON.parse(match[1]);
    assert.equal(String(data.products[0].id),String(intent.product_id));
    assert.equal(data.products[0].slug,intent.slug);
  }
}
assert.equal((sitemap.match(/<loc>https:\/\/telvora\.ru[^<]*<\/loc>/g) || []).length, manifest.sitemapUrlCount);
for (const route of manifest.managedFiles.filter(path => path.startsWith('_prerender/'))) await access(resolve(payload, route));
console.log(`seo-validate-package: PASS (${checksums.size} checksummed files, ${manifest.routeCount} routes)`);

function escapeRegex(value) { return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

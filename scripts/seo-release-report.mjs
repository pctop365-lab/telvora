import { createHash } from 'node:crypto';
import { access, readFile, readdir, writeFile } from 'node:fs/promises';

const args = process.argv.slice(2);
const readArg = (name) => {
  const index = args.indexOf(name);
  return index >= 0 ? args[index + 1] : undefined;
};
const baselinePath = readArg('--baseline') || process.env.SEO_BASELINE;
const outputPath = readArg('--output') || 'seo-artifacts/seo-release-report.json';

const manifest = JSON.parse(await readFile('seo-artifacts/routes.json', 'utf8'));
const routeFile = (route) => route === '/' ? 'dist/index.html' : `dist${manifest.prerenderFiles[route]}`;
const sha256 = async (file) => createHash('sha256').update(await readFile(file)).digest('hex');

const routeHashes = {};
for (const route of manifest.routes) {
  const file = routeFile(route);
  await access(file);
  routeHashes[route] = await sha256(file);
}

const assets = (await readdir('dist/assets')).map((file) => `dist/assets/${file}`).sort();
const prerenderInventory = Object.entries(manifest.prerenderFiles).map(([route, file]) => ({ route, file: `dist${file}`, sha256: routeHashes[route] })).sort((a, b) => a.route.localeCompare(b.route));
const sitemap = await readFile('dist/sitemap.xml', 'utf8');
const sitemapUrlCount = [...sitemap.matchAll(/<loc>https:\/\/telvora\.ru(?:\/[^<]*)?<\/loc>/g)].length;

let baseline = null;
if (baselinePath) {
  try {
    baseline = JSON.parse(await readFile(baselinePath, 'utf8'));
  } catch (error) {
    if (error.code !== 'ENOENT') throw error;
  }
}

const currentRoutes = [...manifest.routes].sort();
const previousRoutes = [...(baseline?.routes || Object.keys(baseline?.routeHashes || {}))].sort();
const currentSet = new Set(currentRoutes);
const previousSet = new Set(previousRoutes);
const addedRoutes = currentRoutes.filter((route) => !previousSet.has(route));
const removedRoutes = previousRoutes.filter((route) => !currentSet.has(route));
const changedRoutes = baseline?.routeHashes
  ? currentRoutes.filter((route) => previousSet.has(route) && baseline.routeHashes[route] && baseline.routeHashes[route] !== routeHashes[route])
  : [];

const report = {
  release: 'TELVORA_SEO_STAGE1_AND_STAGE2',
  generatedAt: new Date().toISOString(),
  baselineAvailable: Boolean(baseline),
  baselinePath: baseline ? baselinePath : null,
  snapshotHash: manifest.snapshotHash,
  snapshotChanged: Boolean(baseline?.snapshotHash && baseline.snapshotHash !== manifest.snapshotHash),
  activeProductCount: manifest.productRoutes.length,
  productRoutes: [...manifest.productRoutes].sort(),
  routes: currentRoutes,
  addedRoutes,
  removedRoutes,
  changedRoutes,
  sitemapUrlCount,
  prerenderInventory,
  staticInventory: ['dist/index.html', 'dist/client.html', 'dist/404.html', 'dist/robots.txt', 'dist/sitemap.xml', ...assets],
  routeHashes,
};

await writeFile(outputPath, JSON.stringify(report, null, 2) + '\n');
console.log('SEO release report');
console.log(`active products: ${report.activeProductCount}`);
console.log(`product routes: ${report.productRoutes.length ? report.productRoutes.join(', ') : '(none)'}`);
console.log(`added routes: ${report.addedRoutes.length ? report.addedRoutes.join(', ') : '(none)'}`);
console.log(`removed routes: ${report.removedRoutes.length ? report.removedRoutes.join(', ') : '(none)'}`);
console.log(`changed routes: ${report.changedRoutes.length ? report.changedRoutes.join(', ') : '(none)'}`);
console.log(`snapshot hash: ${report.snapshotHash}`);
console.log(`prerender files: ${report.prerenderInventory.length}`);
console.log(`sitemap URLs: ${report.sitemapUrlCount}`);
console.log(`baseline: ${report.baselineAvailable ? baselinePath : 'none (current inventory only)'}`);
console.log(`report: ${outputPath}`);

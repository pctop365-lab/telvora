import { readFile, readdir, writeFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
const { routes, snapshotHash } = JSON.parse(await readFile('seo-artifacts/routes.json', 'utf8'));
const { prerenderFiles } = JSON.parse(await readFile('seo-artifacts/routes.json', 'utf8'));
const files = ['dist/index.html', ...Object.values(prerenderFiles).map(p => 'dist' + p),
  'dist/client.html', 'dist/404.html', 'dist/robots.txt', 'dist/sitemap.xml',
  ...(await readdir('dist/assets')).map(f => 'dist/assets/' + f),
  'seo-artifacts/routes.nginx.conf', 'seo-artifacts/routes.apache.conf'];
const hashes = {};
for (const file of files) hashes[file] = createHash('sha256').update(await readFile(file)).digest('hex');
await writeFile('seo-artifacts/release-inventory.json', JSON.stringify({ release: 'TELVORA_SEO_STAGE1_AND_STAGE2', snapshotHash, hashes }, null, 2) + '\n');
console.log(`Release inventory: ${files.length} files, including alternative server route includes`);

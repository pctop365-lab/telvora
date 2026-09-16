import assert from 'node:assert/strict';
import { access, readdir, readFile, stat } from 'node:fs/promises';

const manifest = JSON.parse(await readFile('seo-artifacts/routes.json', 'utf8'));
const html = await readFile('dist/index.html', 'utf8');
const snapshot = JSON.parse(html.match(/id="telvora-prerender" type="application\/json">(.*?)<\/script>/s)?.[1] || '{}');
const products = Array.isArray(snapshot.products) ? snapshot.products : [];
const expected = new Set(products.flatMap(product => Array.isArray(product.images) ? product.images : []).map(path => path.split('/').pop()));
assert.ok(manifest.productRoutes.length > 0, 'expected at least one active product');
for (const name of expected) {
  const file = `seo-artifacts/images/${name}`;
  await access(file);
  assert.ok((await stat(file)).size > 0, file);
}
assert.ok(expected.size > 0, 'active products must expose at least one image');
assert.deepEqual(new Set(await readdir('seo-artifacts/images')), expected, 'image cache must contain only current snapshot files');
console.log(`seo_images_test: PASS (${expected.size} generated product images)`);

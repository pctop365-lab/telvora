import assert from 'node:assert/strict';
import { access, readFile } from 'node:fs/promises';
import { generateProductionHtaccess } from '../scripts/seo-routing.mjs';
import { managedRootFiles } from '../scripts/seo-routing.mjs';
import { managedReleaseDiff } from '../scripts/seo-release-diff.mjs';

const brandLogos = ['brand-lg.svg', 'brand-samsung.svg', 'brand-sony.svg', 'brand-hisense.svg'];
const catalog = await readFile(new URL('../src/pages/CatalogPage.tsx', import.meta.url), 'utf8');
for (const logo of brandLogos) {
  assert.ok(catalog.includes(`/${logo}`), `catalog uses unexpected/missing brand logo reference: ${logo}`);
  assert.ok(managedRootFiles.includes(logo), `SEO package does not manage used brand logo: ${logo}`);
  const svg = await readFile(new URL(`../public/${logo}`, import.meta.url), 'utf8');
  assert.match(svg, /<svg(?:\s|>)/i, `local brand asset is not a valid SVG: ${logo}`);
  await access(new URL(`../dist/${logo}`, import.meta.url));
}

const base = {
  routes: ['/', '/catalog', '/catalog/oled', '/catalog/oled/product-a'],
  productRoutes: ['/catalog/oled/product-a'],
  prerenderFiles: {
    '/catalog': '/_prerender/catalog.html',
    '/catalog/oled': '/_prerender/catalog-oled.html',
    '/catalog/oled/product-a': '/_prerender/product-product-a.html',
  },
};
const generatedWithProduct = generateProductionHtaccess(base);
assert.match(generatedWithProduct, /RewriteRule \^catalog\/oled\/product-a\$ _prerender\/product-product-a\.html \[END\]/);
const withoutProduct = { ...base, routes: base.routes.slice(0, 3), productRoutes: [], prerenderFiles: Object.fromEntries(Object.entries(base.prerenderFiles).filter(([path]) => !path.includes('product-a'))) };
const generatedWithoutProduct = generateProductionHtaccess(withoutProduct);
assert.doesNotMatch(generatedWithoutProduct, /product-a/);
const diff = managedReleaseDiff(
  { managedFiles: ['_prerender/product-product-a.html', '_prerender/catalog.html'] },
  { managedFiles: ['_prerender/catalog.html', '_prerender/product-product-b.html'] },
);
assert.deepEqual(diff.add, ['_prerender/product-product-b.html']);
assert.deepEqual(diff.remove, ['_prerender/product-product-a.html']);
assert.deepEqual(diff.replace, ['_prerender/catalog.html']);
console.log('seo_package_test: PASS (dynamic routes, stale-file diff and required local brand assets)');

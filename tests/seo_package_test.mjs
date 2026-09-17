import assert from 'node:assert/strict';
import { generateProductionHtaccess } from '../scripts/seo-routing.mjs';
import { managedReleaseDiff } from '../scripts/seo-release-diff.mjs';

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
console.log('seo_package_test: PASS (dynamic route generation and stale-file diff)');

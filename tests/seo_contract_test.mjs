import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = path => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const robots = read('public/robots.txt');
const sitemap = read('public/sitemap.xml');
const index = read('index.html');
const seo = read('src/components/SeoMetadata.tsx');
const seoUtils = read('src/lib/seo.ts');
const product = read('src/components/ProductDetail.tsx');
const app = read('src/App.tsx');

assert.match(robots, /^User-agent: \*$/m);
assert.match(robots, /^Allow: \/$/m);
assert.match(robots, /^Sitemap: https:\/\/telvora\.ru\/sitemap\.xml$/m);
for (const path of ['/admin', '/api.php', '/manager.php', '/generate_invoice_pdf.php']) {
  assert.ok(robots.includes(`Disallow: ${path}`), `robots must disallow ${path}`);
}
for (const publicPath of ['/catalog', '/delivery', '/services', '/warranty', '/returns', '/contacts', '/requisites']) {
  assert.ok(!robots.split(/\r?\n/).includes(`Disallow: ${publicPath}`), `robots must not disallow ${publicPath}`);
}

assert.match(sitemap, /^<\?xml version="1\.0" encoding="UTF-8"\?>/);
const locations = [...sitemap.matchAll(/<loc>([^<]+)<\/loc>/g)].map(match => match[1]);
assert.equal(new Set(locations).size, locations.length, 'sitemap URLs must be unique');
for (const url of locations) assert.match(url, /^https:\/\/telvora\.ru\/(?!.*[?#])/);
for (const path of ['', 'catalog', 'delivery', 'services', 'warranty', 'returns', 'contacts', 'requisites']) {
  assert.ok(locations.includes(`https://telvora.ru/${path}`), `sitemap missing /${path}`);
}
for (const forbidden of ['/admin', '/checkout', '/order-success', '.php', '?']) {
  assert.ok(!locations.some(url => url.includes(forbidden)), `sitemap contains ${forbidden}`);
}

assert.equal((index.match(/rel="canonical"/g) || []).length, 1, 'raw HTML must have one fallback canonical');
assert.ok(index.includes('href="https://telvora.ru/"'));
assert.ok(!index.includes('localhost'));
assert.ok(!index.includes('http://telvora.ru'));
assert.ok(seoUtils.includes("const SITE_URL = 'https://telvora.ru'"));
assert.ok(seo.includes('og:title') && seo.includes('og:description') && seo.includes('og:url'));
assert.ok(seo.includes('twitter:title') && seo.includes('twitter:description'));
assert.ok(product.includes("'@type': 'Product'"));
assert.ok(product.includes("'@type': 'Offer'"));
assert.ok(product.includes("'@type': 'BreadcrumbList'"));
assert.ok(!product.includes('AggregateRating'));
assert.ok(!product.includes("'review'"));
assert.ok(app.includes('robots="noindex, follow"'));
assert.ok(app.includes('robots="noindex, nofollow"'));

console.log('seo_contract_test: PASS');

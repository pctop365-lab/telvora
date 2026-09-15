import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { chromium } from '@playwright/test';
const manifest = JSON.parse(await readFile('seo-artifacts/routes.json', 'utf8'));
const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
try {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  const titles = new Set(), descriptions = new Set();
  for (const path of manifest.routes) {
    const html = await readFile(path === '/' ? 'dist/index.html' : `dist${manifest.prerenderFiles[path]}`, 'utf8');
    await page.setContent(html);
    assert.equal(await page.locator('title').count(), 1, path);
    assert.equal(await page.locator('link[rel="canonical"]').count(), 1, path);
    assert.equal(await page.locator('link[rel="canonical"]').getAttribute('href'), 'https://telvora.ru' + path);
    assert.equal(await page.locator('meta[name="description"]').count(), 1);
    const description = await page.locator('meta[name="description"]').getAttribute('content');
    const title = await page.title();
    assert.ok(description && !descriptions.has(description), path); descriptions.add(description);
    assert.ok(title && !titles.has(title), path); titles.add(title);
    assert.equal(await page.locator('#root h1').count(), 1, path);
    assert.ok((await page.locator('#root main').first().innerText()).length > 100, path);
    assert.equal(await page.locator('meta[property="og:title"]').getAttribute('content'), title);
    assert.equal(await page.locator('meta[property="og:url"]').getAttribute('content'), 'https://telvora.ru' + path);
    assert.equal(await page.locator('meta[name="robots"]').getAttribute('content'), 'index, follow');
    const blocks = await page.locator('script[type="application/ld+json"]').allTextContents();
    const schemas = blocks.flatMap(JSON.parse);
    assert.equal(new Set(schemas.map(s => s['@type'])).size, schemas.length);
    if (path === '/') {
      const organization = schemas.find(s => s['@type'] === 'Organization');
      assert.ok(organization);
      assert.deepEqual(organization.contactPoint.map(point => point.telephone), ['+79262020119', '+79031894342']);
    }
    if (manifest.productRoutes.includes(path)) {
      const product = schemas.find(s => s['@type'] === 'Product');
      const data = JSON.parse(await page.locator('#telvora-prerender').textContent()).products[0];
      assert.equal(product.name, data.name);
      assert.equal(await page.locator('h1').innerText(), data.name);
      assert.equal(product.description, data.description);
      assert.ok((await page.locator('#root').innerText()).includes(data.description));
      assert.equal(product.url, 'https://telvora.ru' + path);
      assert.ok(product.image.length > 0);
      assert.equal(await page.locator('meta[property="og:image"]').getAttribute('content'), product.image[0]);
      for (const key of ['aggregateRating', 'review', 'gtin', 'gtin13', 'mpn']) assert.equal(product[key], undefined);
      if (product.offers) {
        assert.ok(Number.isFinite(product.offers.price) && product.offers.price > 0);
        assert.equal(product.offers.priceCurrency, 'RUB');
        assert.ok(['InStock', 'OutOfStock', 'PreOrder'].some(s => product.offers.availability === 'https://schema.org/' + s));
      }
      assert.equal(schemas.find(s => s['@type'] === 'BreadcrumbList').itemListElement.length, 4);
    }
    const canonical = await page.locator('link[rel="canonical"]').getAttribute('href');
    assert.ok(!/localhost|127\.0\.0\.1|www\.|xn--|телвора/.test(canonical));
  }
  const sitemap = await readFile('dist/sitemap.xml', 'utf8');
  assert.deepEqual([...sitemap.matchAll(/<loc>https:\/\/telvora.ru([^<]*)<\/loc>/g)].map(m => m[1]), manifest.routes);
  for (const file of ['client.html', '404.html']) {
    await page.setContent(await readFile('dist/' + file, 'utf8'));
    assert.match(await page.locator('meta[name="robots"]').getAttribute('content'), /noindex/);
    assert.equal(await page.locator('#telvora-prerender').count(), 0);
    assert.equal(await page.locator('script[type="application/ld+json"]').count(), 0);
    assert.equal(await page.locator('link[rel="canonical"]').count(), 0);
  }
  console.log(`Raw HTML / JSON-LD / sitemap: PASS (${manifest.routes.length} pages; JavaScript disabled)`);
} finally { await browser.close(); }

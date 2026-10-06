import { expect, test, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const fixtureRoot = path.resolve('.seo-build/image-optimization-fixture');
const fixture = JSON.parse(fs.readFileSync(path.join(fixtureRoot, 'fixture.json'), 'utf8'));
// Preserve the old network behavior as a fixture, independent of future Git HEAD:
// the main image and every eager thumbnail reference the original file.
const oldGallery = `<img src="${fixture.images[0]}" width="600" height="400"><div>${fixture.images.map((src: string) => `<img src="${src}" width="80" height="64">`).join('')}</div>`;

function fixtureProduct(fallbackOnly = false) {
  const metadata = JSON.parse(JSON.stringify(fixture.image_variants));
  if (fallbackOnly) for (const item of Object.values(metadata) as Array<{ sources: Array<{ type: string }> }>) item.sources = item.sources.filter(s => s.type !== 'image/webp');
  return { id: 1, slug: 'network-tv', name: 'Network TV', brand: 'Fixture', series: 'G', category: 'OLED',
    screen_size: '55', resolution: '4K', price: 100, image: fixture.images[0], images: fixture.images,
    image_variants: metadata, rating: 0, reviews: 0, description: 'Network fixture', specs: [], highlights: [], is_active: true,
    storefront_variants: [{ product_variant_id: 1, country: 'Россия', price: 100, is_active: true, availability: { product_variant_id: 1, status: 'in_stock', orderable: true } }] };
}

async function install(page: Page, fallbackOnly = false) {
  const broken: string[] = [];
  const responses: Array<{ url: string; bytes: number }> = [];
  const pending: Promise<void>[] = [];
  page.on('response', response => {
    if (response.request().resourceType() !== 'image' || !response.url().includes('/uploads/products/')) return;
    pending.push((async () => {
      if (response.status() !== 200) broken.push(response.url());
      responses.push({ url: new URL(response.url()).pathname, bytes: (await response.body()).length });
    })());
  });
  await page.route('**/*', async route => {
    const url = new URL(route.request().url());
    if (url.pathname === '/products.php') return route.fulfill({ json: { success: true, products: [fixtureProduct(fallbackOnly)] } });
    if (url.pathname.startsWith('/uploads/products/')) {
      const file = path.join(fixtureRoot, url.pathname);
      if (!file.startsWith(fixtureRoot + path.sep) || !fs.existsSync(file)) {
        broken.push(url.pathname); return route.fulfill({ status: 404, body: 'Missing fixture' });
      }
      return route.fulfill({ body: fs.readFileSync(file), contentType: file.endsWith('.webp') ? 'image/webp' : file.endsWith('.png') ? 'image/png' : 'image/jpeg' });
    }
    if (url.pathname.endsWith('.php') || !['localhost', '127.0.0.1'].includes(url.hostname)) return route.abort();
    return route.continue();
  });
  const settle = async () => {
    await page.waitForLoadState('networkidle');
    await Promise.all(pending);
    expect(broken).toEqual([]);
    return responses.slice();
  };
  return { responses, settle };
}

for (const viewport of [{ name: 'mobile', width: 390, height: 844, dpr: 3 }, { name: 'desktop', width: 1440, height: 1000, dpr: 1 }]) {
  test(`45 frames: actual image traffic before/after on ${viewport.name}`, async ({ browser }, testInfo) => {
    test.setTimeout(90000);
    const context = await browser.newContext({ viewport: { width: viewport.width, height: viewport.height }, deviceScaleFactor: viewport.dpr });
    const report: Record<string, unknown> = { viewport, source: fixture.input, frames: 45, originalBytes: fixture.originalBytes };
    try {
      for (const screen of ['catalog', 'product']) {
        const before = await context.newPage(); const old = await install(before);
        await before.route('**/__image_baseline', route => route.fulfill({ contentType: 'text/html', body: `<html><body>${screen === 'product' ? oldGallery : `<img width="400" height="300" src="${fixture.images[0]}">`}</body></html>` }));
        await before.goto('http://127.0.0.1:4178/__image_baseline');
        const beforeRows = await old.settle();
        expect(new Set(beforeRows.map(r => r.url)).size).toBe(screen === 'product' ? 45 : 1);
        const beforeBytes = beforeRows.reduce((sum, row) => sum + row.bytes, 0);
        await before.close();

        const page = await context.newPage(); const current = await install(page);
        await page.goto(`http://127.0.0.1:4178/${screen === 'catalog' ? 'catalog' : 'catalog/oled/network-tv'}`, { waitUntil: 'domcontentloaded' });
        const main = screen === 'catalog' ? page.getByAltText('Network TV', { exact: true }) : page.getByAltText('Network TV, изображение 1 из 45', { exact: true });
        await expect(main).toBeVisible();
        await expect(main).not.toHaveAttribute('loading', 'lazy');
        await expect(main).toHaveAttribute('width', String(fixture.metadata.width));
        await expect(main).toHaveAttribute('height', String(fixture.metadata.height));
        await expect(main).toHaveAttribute('srcset', /\d+w/);
        const rows = await current.settle();
        expect(rows.length).toBeGreaterThan(0);
        expect(rows.every(row => row.url.endsWith('.webp'))).toBe(true);
        for (const row of rows) {
          const match = row.url.match(/\.(\d+)x(\d+)\.(webp|png|jpg)$/);
          expect(match, row.url).not.toBeNull();
          const edge = Math.max(Number(match![1]), Number(match![2]));
          if (screen === 'catalog') { expect(row.url.startsWith(fixture.images[0] + '.')).toBe(true); expect(edge).toBeLessThanOrEqual(800); }
          else if (edge > 320) expect(row.url.startsWith(fixture.images[0] + '.')).toBe(true);
        }
        const afterBytes = rows.reduce((sum, row) => sum + row.bytes, 0);
        expect(afterBytes).toBeLessThan(beforeBytes);
        report[screen] = { beforeBytes, afterBytes, reductionPercent: +(100 * (1 - afterBytes / beforeBytes)).toFixed(2), initialRequests: rows.length, urls: rows };
        await page.screenshot({ path: testInfo.outputPath(`${screen}-${viewport.name}.png`) });
        fs.copyFileSync(testInfo.outputPath(`${screen}-${viewport.name}.png`), path.join(fixtureRoot, `${screen}-${viewport.name}.png`));
        if (screen === 'product') {
          await page.getByRole('button', { name: 'Показать изображение 45', exact: true }).click();
          const selected = page.getByAltText('Network TV, изображение 45 из 45', { exact: true });
          await expect(selected).toBeVisible();
          await expect.poll(() => selected.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)).toBe(true);
          await current.settle();
          const large = current.responses.filter(r => { const m = r.url.match(/\.(\d+)x(\d+)\./); return m && Math.max(+m[1], +m[2]) > 320; });
          expect(large.some(r => r.url.startsWith(fixture.images[44] + '.'))).toBe(true);
          expect(large.every(r => [fixture.images[0], fixture.images[44]].some(src => r.url.startsWith(src + '.')))).toBe(true);
          await page.getByRole('button', { name: /Увеличить изображение 45/ }).click();
          await expect(page.getByRole('dialog')).toBeVisible(); await current.settle();
        }
        const loadedImages = await page.locator('img').evaluateAll(images => images.filter(img => img.currentSrc.includes('/uploads/products/') && img.complete).every(img => img.naturalWidth > 0));
        expect(loadedImages).toBe(true);
        await page.close();
      }
      fs.writeFileSync(path.join(fixtureRoot, `network-${viewport.name}.json`), JSON.stringify(report, null, 2));
      console.log(JSON.stringify({ viewport: viewport.name, catalog: report.catalog, product: { ...(report.product as object), urls: undefined } }));
      await testInfo.attach('network-report', { body: JSON.stringify(report, null, 2), contentType: 'application/json' });
    } finally { await context.close(); }
  });
}

test('compatible fallback images decode without WebP candidates', async ({ page }) => {
  const api = await install(page, true);
  await page.goto('/catalog/oled/network-tv');
  const main = page.getByAltText('Network TV, изображение 1 из 45', { exact: true });
  await expect.poll(() => main.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)).toBe(true);
  const rows = await api.settle();
  expect(rows.length).toBeGreaterThan(0);
  expect(rows.every(row => !row.url.endsWith('.webp'))).toBe(true);
});

test('legacy single photo loads its original in catalog, detail and zoom', async ({ page }) => {
  const api = await install(page);
  const { images, image_variants, ...legacy } = fixtureProduct();
  await page.route('**/products.php**', route => route.fulfill({ json: { success: true, products: [legacy] } }));
  for (const path of ['/catalog', '/catalog/oled/network-tv']) {
    await page.goto(path);
    const main = page.locator(`img[src="${legacy.image}"]`).first();
    await expect.poll(() => main.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)).toBe(true);
    await expect(main).not.toHaveAttribute('srcset');
    if (path !== '/catalog') {
      await expect(page.getByLabel('Миниатюры товара')).toHaveCount(0);
      await page.getByRole('button', { name: /Увеличить изображение 1/ }).click();
      const zoom = page.getByRole('dialog').locator('img');
      await expect.poll(() => zoom.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)).toBe(true);
    }
    const rows = await api.settle();
    expect(rows.length).toBeGreaterThan(0);
    expect(rows.every(row => row.url === legacy.image)).toBe(true);
  }
});

test('a derivative that disappears after metadata loading falls back to the original', async ({ page }) => {
  await install(page);
  let failed = 0;
  await page.route('**/uploads/products/**', async route => {
    const url = new URL(route.request().url()).pathname;
    if (url !== fixture.images[0]) {
      failed++;
      return route.fulfill({ status: 404, body: 'Simulated stale metadata' });
    }
    return route.fulfill({ body: fs.readFileSync(path.join(fixtureRoot, url)), contentType: 'image/jpeg' });
  });
  await page.goto('/catalog');
  const main = page.getByAltText('Network TV', { exact: true });
  await expect.poll(() => main.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0 && new URL(img.currentSrc).pathname === img.getAttribute('src'))).toBe(true);
  await expect(main).toHaveAttribute('src', fixture.images[0]);
  await expect(main).not.toHaveAttribute('srcset');
  expect(failed).toBeGreaterThan(0);
});

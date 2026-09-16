import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
const manifest = JSON.parse(readFileSync('seo-artifacts/routes.json', 'utf8'));
const productPath = manifest.productRoutes[0];
const snapshot = (path: string) => JSON.parse(readFileSync(path === '/' ? 'dist/index.html' : `dist${manifest.prerenderFiles[path]}`, 'utf8').match(/id="telvora-prerender" type="application\/json">(.*?)<\/script>/s)![1]);
const products = snapshot('/').products;
const apiProducts = products.map((p: any) => ({
  ...p, is_active: true, screen_size: p.screenSize, old_price: p.oldPrice,
  storefront_variants: p.variants.map((v: any) => ({ ...v, product_variant_id: v.productVariantId, is_active: v.isActive, old_price: v.oldPrice, availability: { ...v.availability, product_variant_id: v.productVariantId, expected_arrival_at: v.availability.expectedArrivalAt } })),
}));
test.afterEach(async ({}, info) => { for (const error of info.errors) console.log(error.message); });
test.beforeEach(async ({ page }) => {
  // Freeze public responses to the exact release snapshot; no private API requests.
  await page.route('**/products.php**', route => route.fulfill({ json: { success: true, count: apiProducts.length, products: apiProducts } }));
  await page.route('**/services.php**', route => route.fulfill({ json: { success: true, services: snapshot('/services').services } }));
  await page.route('https://fonts.googleapis.com/**', route => route.fulfill({ contentType: 'text/css', body: '' }));
  await page.route('**/uploads/products/**', route => {
    const name = new URL(route.request().url()).pathname.split('/').pop();
    return route.fulfill({ body: readFileSync(`seo-artifacts/images/${name}`), contentType: 'image/webp' });
  });
});
for (const mobile of [false, true]) for (const theme of ['light', 'dark']) {
  test(`${mobile ? 'mobile' : 'desktop'} ${theme}: refresh, hydration, SPA, cart, gallery`, async ({ page }) => {
    test.setTimeout(60000);
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('console', m => { if (m.type() === 'error' || /hydration|did not match/i.test(m.text())) errors.push(m.text()); });
    await page.setViewportSize(mobile ? { width: 390, height: 844 } : { width: 1440, height: 1000 });
    await page.addInitScript(theme => localStorage.setItem('telvora-theme', theme), theme);
    for (const path of ['/', '/catalog', '/delivery', productPath]) {
      expect((await page.goto(path))!.status()).toBe(200);
      await expect(page.locator('html')).toHaveAttribute('data-react-ready', 'true');
      expect((await page.reload())!.status()).toBe(200);
      await expect(page.locator('html')).toHaveAttribute('data-react-ready', 'true');
      await expect(page.locator('html')).toHaveClass(theme);
      await expect(page.locator('h1')).toHaveCount(1);
      await expect(page.locator('link[rel="canonical"]')).toHaveCount(1);
      await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', `https://telvora.ru${path}`);
      await expect(page.locator('meta[name="description"]')).toHaveCount(1);
      const blocks = (await page.locator('script[type="application/ld+json"]').allTextContents()).flatMap(JSON.parse);
      expect(new Set(blocks.map(b => b['@type'])).size).toBe(blocks.length);
      expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
      await expect(page.locator('header').first()).toBeVisible();
      await expect(page.locator('footer')).toHaveCount(1);
    }
    await page.evaluate(() => (window as any).__spaMarker = true);
    await page.locator('footer a[href="/catalog"]').click();
    await expect(page).toHaveURL('/catalog');
    await page.locator(`main a[href="${productPath}"]`).first().click();
    await expect(page).toHaveURL(productPath);
    await page.locator('footer a[href="/delivery"]').click();
    await expect(page).toHaveURL('/delivery');
    await page.locator('a[aria-label="TELVORA — на главную"]').first().click();
    await expect(page).toHaveURL('/');
    expect(await page.evaluate(() => (window as any).__spaMarker)).toBe(true);
    await page.goto(productPath);
    await expect(page.locator('html')).toHaveAttribute('data-react-ready', 'true');
    await page.getByRole('button', { name: 'В корзину', exact: true }).click();
    await expect.poll(() => page.evaluate(() => JSON.parse(localStorage.getItem('telvora_cart') || '[]').length)).toBe(1);
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-react-ready', 'true');
    await expect.poll(() => page.evaluate(() => JSON.parse(localStorage.getItem('telvora_cart') || '[]')[0]?.quantity)).toBe(1);
    await page.getByRole('button', { name: /^Увеличить изображение/ }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.screenshot({ path: `seo-artifacts/${mobile ? 'mobile' : 'desktop'}-${theme}.png`, fullPage: true });
    await page.getByRole('button', { name: /^Включить .* тему$/ }).click();
    await expect(page.locator('html')).toHaveClass(theme === 'dark' ? 'light' : 'dark');
    expect(errors).toEqual([]);
  });
}
test('HTTP route contract, raw noindex and query preservation', async ({ request }) => {
  for (const path of manifest.routes) expect((await request.get(path)).status()).toBe(200);
  for (const path of ['/missing-seo-page', '/catalog/not-a-category', '/catalog/oled/not-active', '/catalog/qled/lg-oled77c5rla', '/admin/unknown', '/order-success/a/b', '/_prerender/catalog.html']) {
    const response = await request.get(path); expect(response.status()).toBe(404); expect(await response.text()).toContain('noindex');
  }
  for (const path of ['/checkout', '/admin', '/order-success/TLV-EXAMPLE', '/soundbars', '/accessories']) {
    const response = await request.get(path); expect(response.status()).toBe(200);
    const body = await response.text(); expect(body).toContain('noindex'); expect(body).not.toContain('telvora-prerender');
  }
  const redirect = await request.get('/catalog/?a=1&b=2', { maxRedirects: 0 });
  expect(redirect.status()).toBe(301); expect(redirect.headers().location).toBe('/catalog?a=1&b=2');
});
test('Header and Footer match Stage 1 pixels in both themes and viewports', async ({ page }) => {
  test.setTimeout(60000);
  for (const width of [390, 1440]) for (const theme of ['light', 'dark']) {
    await page.setViewportSize({ width, height: 1000 });
    await page.addInitScript(theme => localStorage.setItem('telvora-theme', theme), theme);
    const capture = async (baseline: boolean) => {
      await page.goto('/delivery' + (baseline ? '?baseline=1' : ''));
      await expect(page.locator('h1')).toBeVisible();
      await expect(page.locator('html')).toHaveClass(theme);
      await page.locator('footer').scrollIntoViewIfNeeded();
      const footer = await page.locator('footer').screenshot({ animations: 'disabled' });
      await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
      const header = await page.locator('header').first().screenshot({ animations: 'disabled' });
      return [header, footer].map(b => createHash('sha256').update(b).digest('hex'));
    };
    expect(await capture(false), `${width} ${theme}`).toEqual(await capture(true));
  }
});
test('fresh API price replaces snapshot Offer; deactivation removes Product', async ({ page }) => {
  await page.unroute('**/products.php**');
  const changed = apiProducts.map((p: any) => ({ ...p, storefront_variants: p.storefront_variants.map((v: any) => ({ ...v, price: 123456 })) }));
  await page.route('**/products.php**', route => route.fulfill({ json: { success: true, products: changed } }));
  await page.goto(productPath);
  await expect.poll(async () => (await page.locator('script[type="application/ld+json"]').allTextContents()).flatMap(JSON.parse).find(p => p['@type'] === 'Product')?.offers?.price).toBe(123456);
  await page.unroute('**/products.php**');
  await page.route('**/products.php**', route => route.fulfill({ json: { success: true, products: [] } }));
  await page.reload();
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
  await expect(page.locator('script[type="application/ld+json"]')).toHaveCount(0);
});

test('active product remains indexable after hydration when API revalidation fails', async ({ page }) => {
  await page.unroute('**/products.php**');
  await page.route('**/products.php**', route => route.abort());
  const raw = await page.request.get(productPath);
  const rawHtml = await raw.text();
  expect(raw.status()).toBe(200);
  expect(rawHtml.match(/<meta data-rh="true" name="robots" content="([^"]+)"/s)?.[1]).toBe('index, follow');
  expect(rawHtml.match(/<link data-rh="true" rel="canonical" href="([^"]+)"/s)?.[1]).toBe(`https://telvora.ru${productPath}`);
  const response = await page.goto(productPath);
  expect(response?.status()).toBe(200);
  await expect(page.locator('html')).toHaveAttribute('data-react-ready', 'true');
  await expect(page.locator('h1')).toHaveCount(1);
  await expect(page.locator('meta[name="robots"]')).toHaveCount(1);
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', 'index, follow');
  await expect(page.locator('meta[name="googlebot"]')).toHaveCount(0);
  await expect(page.locator('link[rel="canonical"]')).toHaveCount(1);
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', `https://telvora.ru${productPath}`);
});

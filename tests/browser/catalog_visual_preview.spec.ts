import { expect, test, type Page } from '@playwright/test';

const image = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="20" height="20"/%3E';
const item = (id: number, brand: string, series: string, category: string, resolution: string, screen_size: string) => ({
  id, slug: `preview-tv-${id}`, name: `${brand} ${series}`, brand, series, category, screen_size, resolution,
  price: 100000, image, images: [image], rating: 5, reviews: 0, description: 'TV', specs: [], highlights: [], is_active: true,
  storefront_variants: [{ product_variant_id: id, country: 'Р В Р’В Р вЂ™Р’В Р В Р’В Р РЋРІР‚СћР В Р Р‹Р В РЎвЂњР В Р Р‹Р В РЎвЂњР В Р’В Р РЋРІР‚ВР В Р Р‹Р В Р РЏ', price: 100000, is_active: true, availability: { product_variant_id: id, status: 'in_stock', orderable: true } }],
});

async function prepare(page: Page) {
  await page.route('**/products.php**', route => route.fulfill({ json: {
    success: true, count: 3,
    products: [
      item(1, 'LG', 'C5', 'OLED', '3840 Р В РІР‚СљР Р†Р вЂљРІР‚Сњ 2160 (4K UHD)', '55'),
      item(2, 'LG', 'G5', 'OLED', '3840 Р В РІР‚СљР Р†Р вЂљРІР‚Сњ 2160 (4K UHD)', '65'),
      item(3, 'Samsung', 'Q900', 'QLED', '7680 Р В РІР‚СљР Р†Р вЂљРІР‚Сњ 4320 (8K UHD)', '85'),
    ],
  } }));
  await page.addInitScript(() => { localStorage.clear(); localStorage.setItem('telvora-theme', 'light'); });
  await page.goto('/catalog');
  await page.locator("main button").first().click();
  await expect(page.locator("[role=dialog]")).toBeVisible();
}

test('catalog filters visual preview in light and dark themes', async ({ page }) => {
  await prepare(page);
  for (const theme of ['light', 'dark'] as const) {
    if (theme === 'dark') {
      await page.locator("button[aria-label]").first().evaluate((element) => (element as HTMLElement).click());
    }
    for (const width of [1280, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.screenshot({ path: `test-results/catalog-preview/catalog-${width}-${theme}.png`, fullPage: true });
    }
  }
});

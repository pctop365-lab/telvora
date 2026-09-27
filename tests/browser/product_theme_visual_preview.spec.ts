import { test } from '@playwright/test';

const pixel = (color: string) => `data:image/svg+xml,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="320" height="220"><rect width="320" height="220" fill="${color}"/></svg>`)}`;

const product = {
  id: 1,
  slug: 'theme-preview-tv',
  name: 'Theme Preview TV',
  brand: 'LG',
  series: 'C5',
  category: 'OLED',
  screen_size: '55',
  resolution: '4K',
  price: 100000,
  image: pixel('#d7dde5'),
  images: [pixel('#d7dde5'), pixel('#bcc8d8'), pixel('#a7b8ce')],
  rating: 5,
  reviews: 12,
  description: 'Телевизор для локального визуального превью темы и карточки товара.',
  specs: [{ label: 'Матрица', value: 'OLED' }, { label: 'Частота', value: '120 Гц' }],
  highlights: ['Яркий экран', 'Объёмный звук'],
  is_active: true,
  homepage_position: 1,
  storefront_variants: [{
    product_variant_id: 1,
    country: 'Россия',
    price: 100000,
    is_active: true,
    availability: { product_variant_id: 1, status: 'in_stock', orderable: true },
  }],
};

async function prepare(page: import('@playwright/test').Page) {
  await page.route('**/products.php**', route => route.fulfill({ json: { success: true, count: 1, products: [product] } }));
  await page.addInitScript(() => {
    localStorage.clear();
    localStorage.setItem('telvora-theme', 'light');
  });
}

async function switchToDark(page: import('@playwright/test').Page) {
  await page.locator('button[aria-label]').first().evaluate((element) => (element as HTMLElement).click());
  await page.waitForTimeout(650);
}

test('product and home theme visual previews', async ({ page }) => {
  await prepare(page);
  for (const theme of ['light', 'dark'] as const) {
    await page.goto('/catalog/oled/theme-preview-tv');
    await page.setViewportSize({ width: 1280, height: 1000 });
    if (theme === 'dark') await switchToDark(page);
    await page.locator('main button').nth(1).hover();
    await page.screenshot({ path: `test-results/product-theme-preview/product-1280-${theme}.png`, fullPage: true });
    await page.setViewportSize({ width: 390, height: 1000 });
    await page.screenshot({ path: `test-results/product-theme-preview/product-390-${theme}.png`, fullPage: true });
  }

  await page.setViewportSize({ width: 1280, height: 1000 });
  for (const theme of ['light', 'dark'] as const) {
    await page.goto('/');
    if (theme === 'dark') await switchToDark(page);
    const cinema = page.locator('#tech a[href="/catalog"]');
    await cinema.hover();
    await page.screenshot({ path: `test-results/product-theme-preview/home-1280-${theme}.png`, fullPage: true });
    await page.setViewportSize({ width: 390, height: 1000 });
    await cinema.hover();
    await page.screenshot({ path: `test-results/product-theme-preview/home-390-${theme}.png`, fullPage: true });
  }
});

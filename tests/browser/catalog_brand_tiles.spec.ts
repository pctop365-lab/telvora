import { expect, test } from '@playwright/test';

const image = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="20" height="20"/%3E';
const item = (id: number, brand: string, is_active = true) => ({
  id, slug: `brand-tv-${id}`, name: `${brand} TV ${id}`, brand, series: `Series ${id}`,
  category: 'OLED', screen_size: '55', resolution: '3840 × 2160 (4K UHD)', price: 100000 + id,
  image, images: [image], rating: 5, reviews: 0, description: 'TV', specs: [], highlights: [], is_active,
  storefront_variants: [{ product_variant_id: id, country: 'Россия', price: 100000 + id, is_active: true,
    availability: { product_variant_id: id, status: 'in_stock', orderable: true } }],
});

test.beforeEach(async ({ page }) => {
  await page.route('**/products.php**', route => route.fulfill({ json: {
    success: true,
    count: 6,
    products: [item(1, 'LG'), item(2, 'Samsung'), item(3, 'Sony'), item(4, 'TCL'), item(5, 'Hisense'), item(6, 'HiddenBrand', false)],
  } }));
});

for (const width of [1280, 390]) test(`brand tiles filter and reset consistently at ${width}px`, async ({ page }) => {
  await page.setViewportSize({ width, height: 900 });
  await page.addInitScript(() => { localStorage.clear(); localStorage.setItem('telvora-theme', 'light'); });
  await page.goto('/catalog');

  await expect(page.getByText('Найдено товаров: 5')).toBeVisible();
  const brands = page.getByRole('region', { name: 'Выбор бренда' });
  for (const name of ['LG', 'Samsung', 'Sony', 'TCL', 'Hisense']) {
    await expect(brands.getByRole('button', { name: `${name}, 1 товар` })).toBeVisible();
  }
  await expect(brands.getByRole('button', { name: /HiddenBrand/ })).toHaveCount(0);
  await expect(page.getByText('Найдено товаров: 5')).toBeVisible();

  await brands.getByRole('button', { name: 'Sony, 1 товар' }).click();
  await expect(page).toHaveURL(/\/catalog$/);
  await expect(brands.getByRole('button', { name: 'Sony, 1 товар' })).toHaveAttribute('aria-pressed', 'true');
  await expect(page.getByText('Найдено товаров: 1')).toBeVisible();

  await page.getByRole('button', { name: /Фильтры/ }).click();
  const dialog = page.getByRole('dialog', { name: 'Фильтры каталога' });
  await expect(dialog.getByRole('button', { name: 'Sony', exact: true })).toHaveClass(/bg-accent-500/);
  await dialog.getByRole('button', { name: 'Закрыть', exact: true }).click();
  await page.locator('main select').selectOption('price-asc');
  await expect(page.getByText('Найдено товаров: 1')).toBeVisible();
  await page.getByRole('button', { name: 'Сбросить все фильтры' }).click();
  await expect(brands.getByRole('button', { name: 'Sony, 1 товар' })).toHaveAttribute('aria-pressed', 'false');
  await expect(page.locator('main select')).toHaveValue('default');
  await expect(page.getByText('Найдено товаров: 5')).toBeVisible();
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.screenshot({ path: `test-results/catalog-brand-tiles-${width}.png`, fullPage: true });
});

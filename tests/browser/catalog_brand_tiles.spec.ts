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

for (const theme of ['light', 'dark'] as const) for (const width of [1280, 390]) test(`brand tiles filter and reset in ${theme} theme at ${width}px`, async ({ page }) => {
  await page.setViewportSize({ width, height: 900 });
  await page.addInitScript((initialTheme) => { localStorage.clear(); localStorage.setItem('telvora-theme', initialTheme); }, theme);
  await page.goto('/catalog');

  await expect(page.getByText('Найдено товаров: 5')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Каталог телевизоров', exact: true })).toBeVisible();
  await expect(page.getByText('Выберите бренд и настройте фильтры', { exact: true })).toBeVisible();
  if (theme === 'dark') await expect(page.locator('html')).toHaveClass(/dark/);
  else await expect(page.locator('html')).toHaveClass(/light/);
  const consent = page.getByRole('button', { name: 'Только необходимые', exact: true });
  if (await consent.count()) await consent.click();
  const brands = page.getByRole('region', { name: 'Выбор бренда' });
  const grid = brands.getByTestId('brand-tiles-grid');
  await expect.poll(() => grid.evaluate(element => getComputedStyle(element).gridTemplateColumns.split(' ').length)).toBe(width >= 1024 ? 4 : 2);
  for (const name of ['LG', 'Samsung', 'Sony', 'TCL', 'Hisense']) {
    await expect(brands.getByRole('button', { name: `${name}, 1 товар` })).toBeVisible();
  }
  for (const [name, path] of [['LG', '/brand-lg.svg'], ['Samsung', '/brand-samsung.svg'], ['Sony', '/brand-sony.svg'], ['Hisense', '/brand-hisense.svg']]) {
    const logo = brands.getByRole('img', { name: `${name} logo` });
    await expect(logo).toHaveAttribute('src', path);
    await expect.poll(() => logo.evaluate(element => (element as HTMLImageElement).naturalWidth)).toBeGreaterThan(0);
  }
  await expect(brands.getByRole('button', { name: /HiddenBrand/ })).toHaveCount(0);
  await expect(page.getByText('Найдено товаров: 5')).toBeVisible();

  await brands.getByRole('button', { name: 'LG, 1 товар' }).click();
  await expect(page).toHaveURL(/\/catalog$/);
  await expect(brands.getByRole('button', { name: 'LG, 1 товар' })).toHaveAttribute('aria-pressed', 'true');
  await expect(brands.getByRole('button', { name: 'LG, 1 товар' })).toHaveClass(/border-accent-500/);
  await expect(page.getByText('Найдено товаров: 1')).toBeVisible();
  await page.screenshot({ path: `test-results/catalog-brand-tiles-${width}-${theme}-selected.png`, fullPage: true });

  await page.getByRole('button', { name: /Фильтры/ }).click();
  const dialog = page.getByRole('dialog', { name: 'Фильтры каталога' });
  await expect(dialog.getByRole('button', { name: 'LG', exact: true })).toHaveClass(/bg-accent-500/);
  await dialog.getByRole('button', { name: 'Закрыть', exact: true }).click();
  await page.locator('main select').selectOption('price-asc');
  await expect(page.getByText('Найдено товаров: 1')).toBeVisible();
  await page.getByRole('button', { name: 'Сбросить все фильтры' }).click();
  await expect(brands.getByRole('button', { name: 'LG, 1 товар' })).toHaveAttribute('aria-pressed', 'false');
  await expect(page.locator('main select')).toHaveValue('default');
  await expect(page.getByText('Найдено товаров: 5')).toBeVisible();
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.screenshot({ path: `test-results/catalog-brand-tiles-${width}-${theme}.png`, fullPage: true });
});

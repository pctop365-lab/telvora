import { expect, test, type Page } from '@playwright/test';

async function boot(page: Page) {
  const calls: Record<string, unknown>[] = [];
  let refreshes = 0;
  let release: (() => void) | undefined;
  const pending = new Promise<void>((resolve) => { release = resolve; });
  const products = [false, true].map((active, index) => ({
    id: index + 1, slug: `local-${index}`, name: `LG Local ${index}`, brand: 'LG', series: 'Test',
    country: 'Россия', category: 'OLED', screen_size: '55', resolution: '4K', price: 10000, old_price: null,
    image: '', badge: null, rating: 0, reviews: 0, description: '', specs: [], highlights: [], variants: [],
    is_active: active, publication_status: active ? 'published' : 'draft', publication_revision: 0, created_at: '', updated_at: '',
  }));
  // All PHP requests are mocked; no request can reach the dev server's API proxy.
  await page.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('/manager.php')) {
      return route.fulfill({ json: { success: true, csrf_token: 'local-csrf', orders: [] } });
    }
    if (url.pathname.endsWith('/products.php')) {
      if (url.searchParams.get('action') === 'list') return route.fulfill({ json: { success: true, products: [] } });
      if (url.searchParams.get('action') === 'admin_list') {
        refreshes++;
        return route.fulfill({ json: { success: true, products } });
      }
      const body = route.request().postDataJSON();
      calls.push(body);
      expect(route.request().headers()['x-csrf-token']).toBe('local-csrf');
      if (body.action === 'bulk_publish_prepare') {
        return route.fulfill({ json: { success: true, preview_id: 'a'.repeat(64), eligible: 3, total: 5 } });
      }
      if (body.action === 'bulk_publish_confirm') {
        await pending;
        return route.fulfill({ json: { success: true, result: { published: 0, queued: 3, skipped: 2, errors: 0,
          rows: [{ id: 4, name: 'Missing price', status: 'skipped', reason: 'Нет опубликованной цены' }] } } });
      }
      return route.fulfill({ json: { success: true } });
    }
    if (url.pathname.endsWith('.php') || !['127.0.0.1', 'localhost'].includes(url.hostname)) return route.abort();
    return route.continue();
  });
  await page.goto('/admin');
  await page.getByPlaceholder('Введите пароль').fill('local');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await page.getByRole('button', { name: 'Товары', exact: true }).click();
  await expect(page.getByText('Найдено товаров: 2')).toBeVisible();
  return { calls, refreshes: () => refreshes, release: () => release!() };
}

test('bulk sends filters, uses server count, blocks double click and refreshes', async ({ page }) => {
  const api = await boot(page);
  await page.getByPlaceholder('Поиск товара...').fill('LG');
  await page.getByRole('button', { name: /^LG\s+2$/ }).click();
  await page.locator('select').filter({ has: page.locator('option[value="Все категории"]') }).selectOption('OLED');
  await page.locator('select').filter({ has: page.locator('option[value="Все страны"]') }).selectOption('Россия');
  page.once('dialog', async (dialog) => {
    expect(dialog.message()).toContain('К публикации готово: 3');
    await dialog.accept();
  });
  const refreshesBefore = api.refreshes();
  await page.getByRole('button', { name: 'Опубликовать все', exact: true }).evaluate((button: HTMLButtonElement) => { button.click(); button.click(); });
  await expect(page.getByRole('button', { name: 'Публикация', exact: true })).toBeDisabled();
  await expect.poll(() => api.calls.length).toBe(2);
  expect(api.calls[0]).toEqual({ action: 'bulk_publish_prepare', filters: { search: 'LG', brand: 'lg', category: 'OLED', country: 'Россия' } });
  expect(api.calls[1]).toEqual({ action: 'bulk_publish_confirm', preview_id: 'a'.repeat(64), confirm: true });
  api.release();
  await expect(page.getByRole('status')).toContainText('Передано на публикацию: 3 · Пропущено: 2 · Ошибки: 0');
  await page.getByText('Причины пропуска и ошибок', { exact: true }).click();
  await expect(page.getByText('Missing price (#4): Нет опубликованной цены')).toBeVisible();
  await expect.poll(() => api.refreshes()).toBeGreaterThan(refreshesBefore);
  await expect(page.getByRole('button', { name: 'Опубликовать', exact: true })).toBeEnabled();
  await expect(page.getByRole('button', { name: 'Скрыть', exact: true })).toBeEnabled();
});

test('cancel never confirms and individual publish/hide remain wired', async ({ page }) => {
  const api = await boot(page);
  page.once('dialog', (dialog) => dialog.dismiss());
  await page.getByRole('button', { name: 'Опубликовать все', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Опубликовать все', exact: true })).toBeEnabled();
  expect(api.calls.map((call) => call.action)).toEqual(['bulk_publish_prepare']);
  await page.getByRole('button', { name: 'Опубликовать', exact: true }).click();
  await expect.poll(() => api.calls.length).toBe(2);
  await page.getByRole('button', { name: 'Скрыть', exact: true }).click();
  await expect.poll(() => api.calls.length).toBe(3);
  expect(api.calls.slice(1)).toEqual([
    { action: 'request_publish', id: 1, expected_revision: 0 },
    { action: 'request_unpublish', id: 2, expected_revision: 0 },
  ]);
});

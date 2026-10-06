import { expect, test } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

test('45 images upload sequentially and retry retains completed files', async ({ page }) => {
  test.setTimeout(90000);
  const root = path.resolve('.seo-build/image-optimization-fixture');
  const fixture = JSON.parse(fs.readFileSync(path.join(root, 'fixture.json'), 'utf8'));
  const bytes = fs.readFileSync(path.join(root, fixture.url));
  let active = 0; let maximum = 0; let failedOnce = false;
  const successful: number[] = [];
  await page.route('**/*', async route => {
    const url = new URL(route.request().url());
    if (url.pathname === '/manager.php') return route.fulfill({ json: { success: true, csrf_token: 'fixture', orders: [] } });
    if (url.pathname === '/products.php') {
      if (route.request().method() !== 'POST') return route.fulfill({ json: { success: true, products: [] } });
      const body = route.request().postDataBuffer()!.toString('latin1');
      expect(body).toContain('upload_gallery');
      expect(body.match(/filename=/g)).toHaveLength(1);
      expect(route.request().headers()['x-csrf-token']).toBe('fixture');
      const index = Number(body.match(/filename="frame-(\d+)\.jpg"/)![1]);
      active++; maximum = Math.max(maximum, active);
      await new Promise(resolve => setTimeout(resolve, 10));
      active--;
      if (index === 3 && !failedOnce) {
        failedOnce = true;
        return route.fulfill({ status: 500, json: { success: false, message: 'Тестовая ошибка загрузки' } });
      }
      expect(successful).not.toContain(index); successful.push(index);
      const image = fixture.images[index];
      return route.fulfill({ json: { success: true, images: [image], image_variants: { [image]: fixture.image_variants[image] } } });
    }
    if (url.pathname.startsWith('/uploads/products/')) return route.fulfill({ body: fs.readFileSync(path.join(root, url.pathname)), contentType: url.pathname.endsWith('.webp') ? 'image/webp' : 'image/jpeg' });
    if (url.pathname.endsWith('.php') || !['127.0.0.1', 'localhost'].includes(url.hostname)) return route.abort();
    return route.continue();
  });
  await page.goto('/admin');
  await page.getByPlaceholder('Введите пароль').fill('fixture');
  await page.getByRole('button', { name: 'Войти', exact: true }).click();
  await page.getByRole('button', { name: 'Товары', exact: true }).click();
  await page.getByRole('button', { name: 'Добавить товар', exact: true }).click();
  await page.locator('input[type="file"][multiple]').setInputFiles(Array.from({ length: 45 }, (_, index) => ({ name: `frame-${index}.jpg`, mimeType: 'image/jpeg', buffer: bytes })));
  await page.getByRole('button', { name: 'Загрузить выбранные', exact: true }).click();
  await expect(page.getByText('Тестовая ошибка загрузки', { exact: true }).first()).toBeVisible();
  expect(successful).toEqual([0, 1, 2]);
  await expect(page.getByAltText(/^Превью \d+$/)).toHaveCount(3);
  await page.getByRole('button', { name: 'Загрузить выбранные', exact: true }).click();
  await expect(page.getByAltText(/^Превью \d+$/)).toHaveCount(45, { timeout: 30000 });
  await expect(page.getByRole('button', { name: 'Загрузить выбранные', exact: true })).toBeDisabled();
  expect(maximum).toBe(1);
  expect(successful).toEqual(Array.from({ length: 45 }, (_, i) => i));
});

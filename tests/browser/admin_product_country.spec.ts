import { expect, test } from '@playwright/test';

test('free country input survives create, edit and reload; filters use product countries', async ({ page }) => {
  const products: Record<string, unknown>[] = [{
    id: 1, name: 'Existing fixture', slug: 'existing-fixture', brand: 'Fixture', country: ' Китай ',
    category: 'OLED', price: 100, old_price: null, variants: [], specs: [], highlights: [],
    is_active: false, publication_status: 'draft', rating: 0, reviews: 0,
  }];
  const original = JSON.stringify(products[0]);
  const writes: Record<string, unknown>[] = [];
  await page.route('**/*', async route => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('/manager.php')) {
      return route.fulfill({ json: { success: true, csrf_token: 'fixture', orders: [] } });
    }
    if (url.pathname.endsWith('/products.php')) {
      if (route.request().method() === 'POST') {
        const body = route.request().postDataJSON();
        writes.push(body);
        expect(['add', 'update']).toContain(body.action);
        if (body.action === 'add') products.push({ ...body, id: 2, publication_status: 'draft' });
        else {
          expect(body.id).toBe(2);
          Object.assign(products[1], body);
        }
        return route.fulfill({ json: { success: true, id: 2 } });
      }
      return route.fulfill({ json: { success: true, products } });
    }
    if (url.pathname.endsWith('.php') || !['localhost', '127.0.0.1'].includes(url.hostname)) return route.abort();
    return route.continue();
  });
  const login = async () => {
    await page.getByPlaceholder('Введите пароль').fill('fixture');
    await page.getByRole('button', { name: 'Войти', exact: true }).click();
    await page.getByRole('button', { name: 'Товары', exact: true }).click();
  };
  const filter = page.locator('select').filter({ has: page.locator('option[value="Все страны"]') });
  const country = page.getByLabel('Страна', { exact: true });
  const row = page.getByRole('row').filter({ hasText: 'Country fixture' });
  await page.goto('/admin', { waitUntil: 'domcontentloaded' });
  await login();
  await expect(filter.locator('option')).toHaveText(['Все страны', 'Китай']);
  await filter.selectOption('Китай');
  await expect(page.getByRole('row').filter({ hasText: 'Existing fixture' })).toBeVisible();
  await filter.selectOption('Все страны');
  await page.getByRole('button', { name: 'Добавить товар', exact: true }).click();
  await expect(page.locator('#product-country-suggestions option')).toHaveAttribute('value', 'Китай');
  await country.fill('  Япония  ');
  await page.getByPlaceholder('Телевизор TELVORA OLED').fill('Country fixture');
  await page.getByPlaceholder('Например: Samsung').fill('Fixture');
  await page.locator('select.admin-input').filter({ has: page.locator('option[value="OLED"]') }).selectOption('OLED');
  await page.getByRole('button', { name: 'Добавить вариант', exact: true }).click();
  await page.getByPlaceholder('Например: США').fill('Малайзия');
  await page.getByRole('button', { name: 'Добавить товар', exact: true }).last().click();
  await expect(country).toHaveCount(0);
  expect(writes[0].country).toBe('Япония');
  const variants = JSON.stringify(products[1].variants);
  await expect(filter.locator('option')).toHaveText(['Все страны', 'Китай', 'Япония']);
  await filter.selectOption('Япония');
  await expect(row).toBeVisible();
  await page.reload({ waitUntil: 'domcontentloaded' });
  await login();
  await filter.selectOption('Япония');
  await row.getByTitle('Редактировать', { exact: true }).click();
  await expect(country).toHaveValue('Япония');
  await country.fill('  Новая страна  ');
  await page.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
  await expect(country).toHaveCount(0);
  expect(writes[1].country).toBe('Новая страна');
  await expect(filter).toHaveValue('Все страны');
  expect(writes[1]).not.toHaveProperty('variants');
  expect(writes[1]).not.toHaveProperty('price');
  await page.reload({ waitUntil: 'domcontentloaded' });
  await login();
  await expect(filter.locator('option')).toHaveText(['Все страны', 'Китай', 'Новая страна']);
  await row.getByTitle('Редактировать', { exact: true }).click();
  await expect(country).toHaveValue('Новая страна');
  await country.fill('   ');
  await page.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
  await expect(country).toHaveCount(0);
  expect(writes[2].country).toBeNull();
  await expect(filter.locator('option')).toHaveText(['Все страны', 'Китай']);
  expect(JSON.stringify(products[0])).toBe(original);
  expect(JSON.stringify(products[1].variants)).toBe(variants);
});

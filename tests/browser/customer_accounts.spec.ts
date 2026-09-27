import { expect, test, type Page } from '@playwright/test';
const fixtureCustomer = { id: 1, login: 'Fixture', full_name: 'Тестовый получатель', phone: '+79990000001', email: '', address: 'Тестовый адрес', email_verified_at: null, created_at: '2026-09-27' };
const cart = [{ id: '1__variant_1', productId: '1', productVariantId: 1, slug: 'fixture-tv', name: 'Тестовый телевизор', price: 100000, image: '', screenSize: '55', category: 'OLED', quantity: 1, assemblyCountry: 'Россия' }];
async function prepare(page: Page, signedIn = true, delayed = false) {
  let customer: typeof fixtureCustomer | null = signedIn ? { ...fixtureCustomer } : null;
  let release: () => void = () => {};
  const gate = new Promise<void>(resolve => { release = resolve; });
  const submitted: Record<string, unknown>[] = [];
  await page.route('**/*', async route => {
    const url = new URL(route.request().url());
    if (url.hostname !== '127.0.0.1' && url.hostname !== 'localhost') return route.abort();
    if (url.pathname === '/customer.php') {
      const payload = route.request().method() === 'POST' ? route.request().postDataJSON() : null;
      const action = payload?.action || url.searchParams.get('action') || 'session';
      if (action === 'session' && delayed) await gate;
      if (action === 'register' || action === 'login') customer = { ...fixtureCustomer };
      if (action === 'logout') customer = null;
      if (action === 'profile') customer = { ...fixtureCustomer, ...payload };
      return route.fulfill({ json: action === 'orders' ? { success: true, orders: [], page: 1, total: 0 } : { success: true, customer, csrf_token: 'fixture-csrf', recovery_available: false } });
    }
    if (url.pathname === '/api.php') {
      if (url.searchParams.get('action') === 'validate_cart') return route.fulfill({ json: { success: true, all_orderable: true, items: [{ product_id: 1, product_variant_id: 1, slug: 'fixture-tv', assembly_country: 'Россия', price: 100000, status: 'in_stock', orderable: true, expected_arrival_at: null, message: null }] } });
      submitted.push(route.request().postDataJSON());
      return route.fulfill({ json: { success: true, order_id: 1, order_number: 'TLV-FIXTURE', subtotal: 100000, services_total: 0, delivery: 0, delivery_status: 'confirmed', total: 100000, items: [{ product_id: 1, product_variant_id: 1, slug: 'fixture-tv', assembly_country: 'Россия', name: 'Тестовый телевизор', quantity: 1, price: 100000 }] } });
    }
    if (url.pathname.endsWith('.php')) return route.fulfill({ json: { success: true, products: [], services: [] } });
    return route.continue();
  });
  await page.addInitScript(items => { localStorage.clear(); localStorage.setItem('telvora_cart', JSON.stringify(items)); }, cart);
  return { release, submitted };
}

test('late profile fills empty fields but preserves edited and intentionally cleared fields', async ({ page }) => {
  const mock = await prepare(page, true, true);
  await page.goto('/checkout');
  await page.getByLabel('Имя и фамилия', { exact: true }).fill('Другой получатель');
  await page.getByLabel('Адрес доставки', { exact: true }).fill('Удалить');
  await page.getByLabel('Адрес доставки', { exact: true }).fill('');
  mock.release();
  await expect(page.getByLabel('Телефон', { exact: true })).toHaveValue(fixtureCustomer.phone);
  await expect(page.getByLabel('Имя и фамилия', { exact: true })).toHaveValue('Другой получатель');
  await expect(page.getByLabel('Адрес доставки', { exact: true })).toHaveValue('');
  await expect(page.getByLabel('Сохранить для следующих заказов')).not.toBeChecked();
});

for (const save of [false, true]) test(`checkout sends explicit save_profile=${save} and no customer_id`, async ({ page }) => {
  const mock = await prepare(page);
  await page.goto('/checkout');
  await expect(page.getByLabel('Имя и фамилия', { exact: true })).toHaveValue(fixtureCustomer.full_name);
  if (save) await page.getByLabel('Сохранить для следующих заказов').check();
  await page.locator('#personal-data-consent').check();
  await page.getByRole('button', { name: 'Разместить заказ' }).click();
  await expect(page).toHaveURL(/order-success/);
  expect(mock.submitted[0].save_profile).toBe(save);
  expect(mock.submitted[0]).not.toHaveProperty('customer_id');
  expect(mock.submitted[0].email).toBe('');
});

test('guest can checkout without registration or email', async ({ page }) => {
  const mock = await prepare(page, false);
  await page.goto('/checkout');
  await page.getByLabel('Имя и фамилия', { exact: true }).fill('Гость');
  await page.getByLabel('Телефон', { exact: true }).fill('+79990000003');
  await page.getByLabel('Адрес доставки', { exact: true }).fill('Гостевой адрес');
  await expect(page.getByLabel('Сохранить для следующих заказов')).toHaveCount(0);
  await page.locator('#personal-data-consent').check();
  await page.getByRole('button', { name: 'Разместить заказ' }).click();
  await expect(page).toHaveURL(/order-success/);
  expect(mock.submitted[0].save_profile).toBe(false);
});

for (const width of [320, 390, 1280]) test(`account registration/profile/logout and header at ${width}px`, async ({ page }) => {
  await prepare(page, false); await page.setViewportSize({ width, height: 900 });
  await page.goto('/account');
  await expect(page.getByRole('link', { name: 'Войти в аккаунт', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Регистрация', exact: true }).click();
  await page.getByLabel('Логин — никнейм или телефон').fill('Fixture');
  await page.getByLabel('Пароль', { exact: true }).fill('fixture-password');
  await page.getByLabel('Повтор пароля').fill('fixture-password');
  await page.getByRole('button', { name: 'Зарегистрироваться' }).click();
  await expect(page.getByRole('heading', { name: 'Мои заказы' })).toBeVisible();
  await page.getByLabel('ФИО', { exact: true }).fill('Новое имя');
  await page.getByRole('button', { name: 'Сохранить профиль' }).click();
  await expect(page.getByRole('status')).toHaveText('Профиль сохранён.');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  expect(await page.evaluate(() => Object.keys(localStorage).some(key => /password|token|session|customer/i.test(key)))).toBe(false);
  await page.getByRole('button', { name: 'Выйти', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Войти', exact: true })).toBeVisible();
});

import { expect, test, type Page } from '@playwright/test';

// Local-only visual fixture based on the customer_accounts browser fixture.
const fixtureCustomer = { id: 1, login: 'Fixture', full_name: 'Тестовый получатель', phone: '+79990000001', email: '', address: 'Тестовый адрес', email_verified_at: null, created_at: '2026-09-27' };
const fixtureOrder = {
  id: 101,
  order_number: 'TLV-FIXTURE-0101',
  customer_name: fixtureCustomer.full_name,
  phone: fixtureCustomer.phone,
  email: '',
  address: fixtureCustomer.address,
  created_at: '2026-09-27 18:42:00',
  status: 'Новый',
  total: 107000,
  items: [{ product_name: 'Тестовый телевизор OLED 55', quantity: 1, price: 100000 }],
  services: [{ service_name: 'Доставка по Москве', quantity: 1, total: 7000 }],
};

async function prepare(page: Page, signedIn = true) {
  await page.route('**/*', async route => {
    const url = new URL(route.request().url());
    if (url.hostname !== '127.0.0.1' && url.hostname !== 'localhost') return route.abort();
    if (url.pathname === '/customer.php') {
      const action = url.searchParams.get('action') || 'session';
      if (action === 'orders') return route.fulfill({ json: { success: true, orders: [fixtureOrder], page: 1, total: 1 } });
      return route.fulfill({ json: { success: true, customer: signedIn ? fixtureCustomer : null, csrf_token: 'fixture-csrf', recovery_available: false } });
    }
    if (url.pathname.endsWith('.php')) return route.fulfill({ json: { success: true, products: [], services: [] } });
    return route.continue();
  });
  await page.addInitScript(() => { localStorage.clear(); localStorage.setItem('telvora-theme', 'light'); });
  await page.goto('/account');
  await page.getByRole('heading', { name: signedIn ? 'Мои заказы' : 'Ваш кабинет покупателя' }).waitFor();
}

test('account visual preview: light and dark desktop/mobile', async ({ page }) => {
  await prepare(page);
  for (const theme of ['light', 'dark'] as const) {
    if (theme === 'dark') { await page.getByRole('button', { name: 'Включить тёмную тему' }).click(); await expect(page.getByRole('button', { name: 'Включить светлую тему' })).toBeVisible(); await page.waitForTimeout(650); }
    for (const width of [1280, 390]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.screenshot({ path: `test-results/account-preview/account-${width}-${theme}.png`, fullPage: true });
    }
  }

  const loginPage = await page.context().newPage();
  await prepare(loginPage, false);
  for (const theme of ['light', 'dark'] as const) {
    if (theme === 'dark') { await loginPage.getByRole('button', { name: 'Включить тёмную тему' }).click(); await expect(loginPage.getByRole('button', { name: 'Включить светлую тему' })).toBeVisible(); await loginPage.waitForTimeout(650); }
    for (const width of [1280, 390]) {
      await loginPage.setViewportSize({ width, height: 1000 });
      await loginPage.screenshot({ path: `test-results/account-preview/login-${width}-${theme}.png`, fullPage: true });
    }
  }
  await loginPage.close();
});

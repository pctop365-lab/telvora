import { expect, test, type Page, type Route } from '@playwright/test';

const product = { id: 5, slug: 'lg', name: 'LG Test', brand: 'LG', series: '', country: null, category: 'OLED', screen_size: '55', resolution: '4K', price: 0, old_price: null, image: '', badge: null, rating: 0, reviews: 0, description: '', specs: [], highlights: [], variants: [], is_active: true, created_at: '', updated_at: '' };
const variant = (id: number, country: string, price: number, active = true) => ({ product_variant_id: id, product_id: 5, variant_key: `key-${id}`, assembly_country: country, relational_is_active: active, legacy_is_active: active, published_price: price, old_price: null, identity_status: 'resolved', diagnostic_code: null, diagnostics: [], identity_ready: true, has_published_price: price > 0, references: { offers: 0, matches: 0, import_rows: 0, audit: 0, orders: 0 }, provenance_mismatch_count: 0 });

async function boot(page: Page, variants = [variant(5, 'Россия', 271400)]) {
  let list = variants;
  let mutationCount = 0;
  let mutationHandler: ((route: Route) => Promise<void>) | null = null;
  await page.route('https://**', route => route.abort());
  await page.route('**/manager.php**', async route => {
    const body = route.request().postDataJSON?.() || {};
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body.action === 'login' ? { success: true, csrf_token: 'test-csrf' } : { success: true, orders: [] }) });
  });
  await page.route('**/products.php**', async route => {
    const url = new URL(route.request().url());
    if (route.request().method() === 'GET' && url.searchParams.get('action') === 'admin_list') return route.fulfill({ json: { success: true, products: [product] } });
    if (route.request().method() === 'GET' && url.searchParams.get('action') === 'admin_variant_list') return route.fulfill({ json: { success: true, product_id: 5, variants: list, legacy_orphans: [], legacy_unresolved: [], diagnostics: [] } });
    mutationCount++;
    if (mutationHandler) return mutationHandler(route);
    const body = route.request().postDataJSON();
    if (body.action === 'variant_add') list = [...list, variant(6, body.assembly_country, 0)];
    if (body.action === 'variant_set_active') list = list.map(item => item.product_variant_id === body.product_variant_id ? { ...item, relational_is_active: body.is_active, legacy_is_active: body.is_active } : item);
    return route.fulfill({ json: { success: true } });
  });
  await page.goto('/admin', { waitUntil: 'commit' });
  await page.getByPlaceholder('Введите пароль').fill('local-test');
  await page.getByRole('button', { name: 'Войти' }).click();
  await page.getByRole('button', { name: 'Товары', exact: true }).click();
  await page.getByRole('button', { name: /Просмотреть варианты товара/ }).click();
  return { getList: () => list, setList: (next: typeof list) => { list = next; }, getMutationCount: () => mutationCount, setMutationHandler: (handler: typeof mutationHandler) => { mutationHandler = handler; } };
}

test('add draft requires server refresh and double submit is blocked', async ({ page }) => {
  const api = await boot(page);
  await page.getByPlaceholder('Например, Россия').fill('Китай');
  await page.getByRole('button', { name: 'Добавить вариант' }).dblclick();
  await expect(page.getByRole('heading', { name: 'Китай', exact: true })).toBeVisible();
  await expect(page.getByText(/Черновик: identity согласована/)).toBeVisible();
  await expect(page.getByRole('dialog').getByText('Цена не опубликована', { exact: true })).toBeVisible();
  expect(api.getMutationCount()).toBe(1);
  await expect(page.getByPlaceholder('Например, Россия')).toHaveCount(1);
  await expect(page.getByText(/Редактировать цену|Редактировать identity/)).toHaveCount(0);
});

test('disable confirmation cancel and 409 are fail-closed', async ({ page }) => {
  const api = await boot(page);
  page.once('dialog', dialog => dialog.dismiss());
  await page.getByRole('button', { name: 'Отключить вариант' }).click();
  expect(api.getMutationCount()).toBe(0);
  api.setMutationHandler(route => route.fulfill({ status: 409, json: { success: false, message: 'internal ignored' } }));
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'Отключить вариант' }).click();
  await expect(page.getByText(/нельзя отключить последний готовый вариант/)).toBeVisible();
  await expect(page.getByText('Активен', { exact: true }).first()).toBeVisible();
});

test('network uncertainty does not retry mutation and refresh failure does not optimize state', async ({ page }) => {
  const api = await boot(page);
  api.setMutationHandler(route => route.abort('failed'));
  await page.getByPlaceholder('Например, Россия').fill('Корея');
  await page.getByRole('button', { name: 'Добавить вариант' }).click();
  await expect(page.getByText(/Результат запроса неизвестен/)).toBeVisible();
  expect(api.getMutationCount()).toBe(1);
  await expect(page.getByText('Корея', { exact: true })).toHaveCount(0);
});

test('closing pending panel ignores late success', async ({ page }) => {
  const api = await boot(page);
  let release!: () => void;
  const barrier = new Promise<void>(resolve => { release = resolve; });
  api.setMutationHandler(async route => { await barrier; await route.fulfill({ json: { success: true } }); });
  await page.getByPlaceholder('Например, Россия').fill('Польша');
  await page.getByRole('button', { name: 'Добавить вариант' }).click();
  await page.getByRole('button', { name: 'Закрыть просмотр вариантов' }).click();
  release();
  await expect(page.getByRole('dialog')).toHaveCount(0);
});

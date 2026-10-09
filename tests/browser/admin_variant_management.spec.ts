import { expect, test, type Page, type Route } from '@playwright/test';

test('edit form renames supplier-linked variant, reopens it and confirms safe removal', async ({ page }) => {
  const linked = { ...variant(5, 'Уточняется', 80000), references: { offers: 1, matches: 1, import_rows: 1, audit: 1, orders: 1 } };
  const api = await boot(page, [linked, variant(6, 'Китай', 100000)]);
  await page.getByRole('button', { name: 'Закрыть просмотр вариантов' }).click();
  await page.locator('button').filter({ has: page.locator('svg.lucide-pencil') }).first().click();
  await page.getByRole('textbox', { name: 'Название варианта #5', exact: true }).fill('Япония');
  await page.getByRole('button', { name: 'Сохранить название', exact: true }).first().click();
  await expect(page.getByText('Название сохранено. ID варианта и связь с поставщиком сохранены.')).toBeVisible();
  expect(api.getList()[0].product_variant_id).toBe(5);
  expect(api.getList()[0].references).toEqual(linked.references);
  await page.getByRole('button', { name: 'Сохранить изменения', exact: true }).click();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await page.locator('button').filter({ has: page.locator('svg.lucide-pencil') }).first().click();
  await expect(page.getByRole('textbox', { name: 'Название варианта #5', exact: true })).toHaveValue('Япония');
  const count = api.getMutationCount();
  page.once('dialog', dialog => dialog.dismiss());
  await page.getByRole('button', { name: 'Удалить вариант', exact: true }).first().click();
  expect(api.getMutationCount()).toBe(count);
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'Удалить вариант', exact: true }).first().click();
  await expect(page.getByText(/Вариант удалён из продажи через отключение/)).toBeVisible();
  expect(api.getList()[0].references).toEqual(linked.references);
  expect(api.getList()[0].relational_is_active).toBe(false);
  await page.getByRole('button', { name: 'Отмена', exact: true }).click();
  await page.getByRole('button', { name: /Просмотреть варианты товара/ }).click();
  await expect(page.getByRole('heading', { name: 'Япония', exact: true })).toBeVisible();
  await expect(page.getByRole('article').filter({ has: page.getByRole('heading', { name: 'Япония', exact: true }) }).getByRole('button', { name: 'Удалить вариант', exact: true })).toBeDisabled();
  await page.goto('/catalog');
  await expect(page.getByRole('link', { name: /LG Test.*100\s000/ })).toBeVisible();
  await expect(page.getByText('80 000 ₽', { exact: true })).toHaveCount(0);
  await page.goto('/catalog/oled/lg');
  await expect(page.getByRole('combobox')).toHaveValue('Китай');
  await expect(page.getByRole('option', { name: /Япония/ })).toHaveCount(0);
  await expect(page.getByText('100 000 ₽', { exact: true }).first()).toBeVisible();
});

test('renamed label appears in storefront and unlinked variant removal refreshes options', async ({ page }) => {
  const api = await boot(page, [variant(5, 'Уточняется', 271400), variant(6, 'Китай', 300000)]);
  const first = page.getByRole('article').first();
  await first.getByLabel('Название варианта').fill('Япония');
  await first.getByRole('button', { name: 'Сохранить название' }).click();
  await expect(page.getByRole('heading', { name: 'Япония', exact: true })).toBeVisible();
  await page.goto('/catalog/oled/lg');
  await expect(page.getByRole('option', { name: /Япония/ })).toHaveCount(1);
  await expect(page.getByRole('combobox')).toHaveValue('Япония');
  await page.goto('/admin');
  await page.getByPlaceholder('Введите пароль').fill('local-test');
  await page.getByRole('button', { name: 'Войти' }).click();
  await page.getByRole('button', { name: 'Товары', exact: true }).click();
  await page.getByRole('button', { name: /Просмотреть варианты товара/ }).click();
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('article').filter({ has: page.getByRole('heading', { name: 'Китай', exact: true }) }).getByRole('button', { name: 'Удалить вариант', exact: true }).click();
  await expect(page.getByText(/Вариант удалён из продажи через отключение/)).toBeVisible();
  expect(api.getList()[1].relational_is_active).toBe(false);
});


const product = { id: 5, slug: 'lg', name: 'LG Test', brand: 'LG', series: '', country: null, category: 'OLED', screen_size: '55', resolution: '4K', price: 0, old_price: null, image: '', badge: null, rating: 0, reviews: 0, description: '', specs: [], highlights: [], variants: [], is_active: true, created_at: '', updated_at: '' };
const variant = (id: number, country: string, price: number, active = true) => ({ product_variant_id: id, product_id: 5, variant_key: `key-${id}`, assembly_country: country, relational_is_active: active, legacy_is_active: active, published_price: price, old_price: null, automatic_price: price, automatic_old_price: null, price_source: 'automatic' as const, minimum_purchase_price: 120000, identity_status: 'resolved', diagnostic_code: null, diagnostics: [], identity_ready: true, has_published_price: price > 0, references: { offers: 0, matches: 0, import_rows: 0, audit: 0, orders: 0 }, provenance_mismatch_count: 0 });

async function boot(page: Page, variants = [variant(5, 'Россия', 271400)]) {
  let list: Array<ReturnType<typeof variant> & { price_source: 'automatic' | 'manual' }> = variants;
  let mutationCount = 0;
  let mutationHandler: ((route: Route) => Promise<void>) | null = null;
  await page.route('https://**', route => route.abort());
  await page.route('**/manager.php**', async route => {
    const body = route.request().postDataJSON?.() || {};
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body.action === 'login' ? { success: true, csrf_token: 'test-csrf' } : { success: true, orders: [] }) });
  });
  await page.route('**/products.php**', async route => {
    const url = new URL(route.request().url());
    const currentProduct = { ...product, variants: list.map(item => ({ country: item.assembly_country, price: item.published_price, old_price: item.old_price, is_active: item.relational_is_active })), storefront_variants: list.filter(item => item.relational_is_active && item.has_published_price).map(item => ({ product_variant_id: item.product_variant_id, country: item.assembly_country, display_name: item.assembly_country, price: item.published_price, old_price: item.old_price, is_active: true, availability: { product_variant_id: item.product_variant_id, status: 'in_stock', orderable: true, expected_arrival_at: null } })) };
    if (route.request().method() === 'GET' && url.searchParams.get('action') === 'admin_list') return route.fulfill({ json: { success: true, products: [currentProduct] } });
    if (route.request().method() === 'GET' && url.searchParams.get('action') === 'list') return route.fulfill({ json: { success: true, products: [currentProduct] } });
    if (route.request().method() === 'GET' && url.searchParams.get('action') === 'admin_variant_list') return route.fulfill({ json: { success: true, product_id: 5, variants: list, legacy_orphans: [], legacy_unresolved: [], diagnostics: [] } });
    mutationCount++;
    if (mutationHandler) return mutationHandler(route);
    const body = route.request().postDataJSON();
    if (body.action === 'variant_add') list = [...list, variant(6, body.assembly_country, 0)];
    if (body.action === 'variant_rename') list = list.map(item => item.product_variant_id === body.product_variant_id ? { ...item, assembly_country: body.name } : item);
    if (body.action === 'variant_archive') list = list.map(item => item.product_variant_id === body.product_variant_id ? { ...item, relational_is_active: false, legacy_is_active: false } : item);
    if (body.action === 'variant_set_active') list = list.map(item => item.product_variant_id === body.product_variant_id ? { ...item, relational_is_active: body.is_active, legacy_is_active: body.is_active } : item);
    if (body.action === 'variant_price_set_manual') list = list.map(item => item.product_variant_id === body.product_variant_id ? { ...item, published_price: Number(body.price), old_price: body.old_price === null ? null : Number(body.old_price), price_source: 'manual' as const, has_published_price: true } : item);
    if (body.action === 'variant_price_set_automatic') list = list.map(item => item.product_variant_id === body.product_variant_id ? { ...item, published_price: item.automatic_price, old_price: item.automatic_old_price, price_source: 'automatic' as const, has_published_price: Number(item.automatic_price) > 0 } : item);
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

test('manual price is server-refreshed, survives automatic updates and returns to automatic with confirmation', async ({ page }) => {
  const api = await boot(page);
  await page.getByLabel('Ручная цена').fill('250000');
  await page.getByLabel('Старая цена (необязательно)').fill('270000');
  await page.getByRole('button', { name: 'Сохранить ручную цену' }).dblclick();
  await expect(page.getByText('Источник цены: Ручная')).toBeVisible();
  expect(api.getMutationCount()).toBe(1);

  api.setList(api.getList().map(item => ({ ...item, automatic_price: 300000 })));
  page.once('dialog', dialog => dialog.dismiss());
  await page.getByRole('button', { name: 'Вернуть автоматическую цену' }).click();
  expect(api.getMutationCount()).toBe(1);

  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', { name: 'Вернуть автоматическую цену' }).click();
  await expect(page.getByText('Источник цены: Автоматическая')).toBeVisible();
  await expect(page.getByRole('article').getByText('300 000 ₽', { exact: true })).toBeVisible();
  expect(api.getMutationCount()).toBe(2);
});

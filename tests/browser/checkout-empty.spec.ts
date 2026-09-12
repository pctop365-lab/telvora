import { expect, test } from '@playwright/test';

const validationUrl = /api\.php\?action=validate_cart/;

for (const { name, viewport, theme } of [
  { name: 'desktop light', viewport: { width: 1280, height: 900 }, theme: 'light' },
  { name: 'desktop dark', viewport: { width: 1280, height: 900 }, theme: 'dark' },
  { name: 'mobile light', viewport: { width: 390, height: 844 }, theme: 'light' },
  { name: 'mobile dark', viewport: { width: 390, height: 844 }, theme: 'dark' },
]) {
  test(`empty checkout skips cart validation on ${name}`, async ({ page }) => {
    const consoleErrors: string[] = [];
    let validationRequests = 0;

    page.on('console', message => {
      if (message.type() === 'error') consoleErrors.push(message.text());
    });
    page.on('request', request => {
      if (validationUrl.test(request.url())) validationRequests += 1;
    });

    await page.setViewportSize(viewport);
    await page.addInitScript(value => {
      localStorage.clear();
      localStorage.setItem('telvora-theme', value);
    }, theme);

    const response = await page.goto('/checkout');
    expect(response?.status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'Корзина пуста' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'В каталог' })).toHaveAttribute('href', '/catalog');

    await page.reload();
    await expect(page.getByRole('heading', { name: 'Корзина пуста' })).toBeVisible();
    expect(validationRequests).toBe(0);
    expect(consoleErrors).toEqual([]);
  });
}

test('populated checkout still validates its cart', async ({ page }) => {
  const item = {
    id: '10__variant_20', productId: '10', productVariantId: 20,
    slug: 'test-tv', name: 'Тестовый телевизор', price: 90000,
    image: '', screenSize: '55″', category: 'OLED', quantity: 1,
    assemblyCountry: 'Россия',
  };
  let validationRequests = 0;

  await page.addInitScript(value => {
    localStorage.clear();
    localStorage.setItem('telvora_cart', JSON.stringify([value]));
  }, item);
  await page.route(validationUrl, async route => {
    validationRequests += 1;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        all_orderable: true,
        items: [{
          product_id: 10, product_variant_id: 20, slug: 'test-tv',
          assembly_country: 'Россия', price: 90000, status: 'in_stock',
          orderable: true, expected_arrival_at: null, message: null,
        }],
      }),
    });
  });

  await page.goto('/checkout');
  await expect(page.getByRole('heading', { name: 'Оформление заказа' })).toBeVisible();
  await expect.poll(() => validationRequests).toBeGreaterThan(0);
  await expect(page.getByRole('button', { name: 'Разместить заказ' })).toBeEnabled();

  const completedValidations = validationRequests;
  await page.getByRole('button', { name: 'Корзина' }).click();
  await page.getByRole('button', { name: 'Убрать' }).click();
  await expect(page.getByRole('heading', { name: 'Корзина пуста', level: 1 })).toBeVisible();
  expect(validationRequests).toBe(completedValidations);
});

test('checkout with a product and service keeps the existing validation flow', async ({ page }) => {
  const item = {
    id: '10__variant_20', productId: '10', productVariantId: 20,
    slug: 'test-tv', name: 'Тестовый телевизор', price: 90000,
    image: '', screenSize: '55″', category: 'OLED', quantity: 1,
    assemblyCountry: 'Россия',
  };
  const service = {
    id: '1__10__variant_20', serviceId: 1, serviceKey: 'wall-mount-43-55',
    category: 'mounting', name: 'Монтаж телевизора на стену', price: 7000,
    quantity: 1, targetCartItemId: item.id, televisionName: item.name, screenSize: 55,
  };
  let validationRequests = 0;

  await page.addInitScript(({ cartItem, cartService }) => {
    localStorage.clear();
    localStorage.setItem('telvora_cart', JSON.stringify([cartItem]));
    localStorage.setItem('telvora_cart_services', JSON.stringify([cartService]));
  }, { cartItem: item, cartService: service });
  await page.route(validationUrl, async route => {
    validationRequests += 1;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        all_orderable: true,
        items: [{
          product_id: 10, product_variant_id: 20, slug: 'test-tv',
          assembly_country: 'Россия', price: 90000, status: 'in_stock',
          orderable: true, expected_arrival_at: null, message: null,
        }],
      }),
    });
  });

  await page.goto('/checkout');
  await expect(page.getByText('Монтаж телевизора на стену')).toHaveCount(1);
  await expect.poll(() => validationRequests).toBeGreaterThan(0);
  await expect(page.getByRole('button', { name: 'Разместить заказ' })).toBeEnabled();
});

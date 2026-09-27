import { expect, test } from '@playwright/test';

test('support order tracking validates format before the API and reports a missing order', async ({ page }) => {
  let requestCount = 0;
  await page.route('**/manager.php?action=track_order', async route => {
    requestCount += 1;
    const payload = route.request().postDataJSON() as { order_number: string; phone: string };
    if (payload.order_number === 'TLV-20260928-0008' && payload.phone === '+79990000001') {
      await route.fulfill({ json: { success: true, order: { id: 8, status: 'В обработке' } } });
      return;
    }
    await route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'Заказ не найден или данные не совпадают' }) });
  });

  await page.goto('/support');
  const tracking = page.locator('#order-tracking');
  const orderInput = tracking.locator('input[placeholder="TLV-20260831-0040"]');
  const phoneInput = tracking.locator('input[type="tel"]');
  const submit = tracking.locator('button').first();

  await orderInput.fill('TLV-20260928-008');
  await phoneInput.fill('+79990000001');
  await submit.click();
  await expect(page.getByRole('alert')).toHaveText('Введите номер заказа в формате TLV-ГГГГММДД-0000');
  expect(requestCount).toBe(0);

  await orderInput.fill('TLV-20260928-0008');
  await phoneInput.fill('+79990000001');
  await submit.click();
  await expect(tracking).toContainText('В обработке');
  expect(requestCount).toBe(1);

  await orderInput.fill('TLV-20990101-9999');
  await submit.click();
  await expect(page.getByRole('alert')).toContainText('не найден');
  expect(requestCount).toBe(2);
});

test('support order tracking rejects an invalid phone format before the API', async ({ page }) => {
  let requestCount = 0;
  await page.route('**/manager.php?action=track_order', async route => {
    requestCount += 1;
    await route.fulfill({ json: { success: true, order: { id: 8, status: 'В обработке' } } });
  });
  await page.goto('/support');
  const tracking = page.locator('#order-tracking');
  await tracking.locator('input[placeholder="TLV-20260831-0040"]').fill('TLV-20260928-0008');
  await tracking.locator('input[type="tel"]').fill('7999000000');
  await tracking.locator('button').first().click();
  await expect(page.getByRole('alert')).toHaveText('Введите телефон в формате +7XXXXXXXXXX');
  expect(requestCount).toBe(0);
});

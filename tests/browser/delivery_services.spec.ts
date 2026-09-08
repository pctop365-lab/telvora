import { expect, test } from '@playwright/test';

test('delivery and services routes keep legacy direct routes', async ({ page }) => {
  await page.goto('/delivery');
  await expect(page.getByRole('heading', { name: 'Доставка', exact: true })).toBeVisible();
  await expect(page.getByText('43–55″')).toBeVisible();
  await expect(page.getByText('25 000 ₽')).toBeVisible();
  await page.goto('/services');
  await expect(page.getByRole('heading', { name: 'Сервисные услуги' })).toBeVisible();
  await expect(page.getByText('Стоимость уточняется')).toHaveCount(4);
  await page.goto('/soundbars');
  await expect(page.getByRole('heading', { name: 'Саундбары' })).toBeVisible();
  await page.goto('/accessories');
  await expect(page.getByRole('heading', { name: 'Аксессуары' })).toBeVisible();
});

test('mobile header exposes the new links', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/');
  await page.getByRole('button', { name: 'Открыть меню' }).click();
  await expect(page.getByRole('button', { name: 'Доставка', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Сервисные услуги' }).click();
  await expect(page).toHaveURL(/\/services$/);
});

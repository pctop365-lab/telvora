import { test, expect } from '@playwright/test';

const pages = [
  ['/requisites', 'Реквизиты TELVORA'], ['/offer', 'Публичная оферта'], ['/privacy', 'Политика обработки персональных данных'],
  ['/personal-data-consent', 'Согласие на обработку персональных данных'], ['/cookies', 'Cookies и локальное хранилище'],
  ['/returns', 'Возврат товара'], ['/warranty', 'Гарантия'], ['/delivery', 'Доставка'],
] as const;

for (const theme of ['light', 'dark'] as const) {
  for (const viewport of [{ name: 'mobile', width: 390, height: 844 }, { name: 'desktop', width: 1440, height: 1000 }]) {
    test(`legal pages ${theme} ${viewport.name}`, async ({ page }) => {
      const errors: string[] = [];
      page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
      page.on('pageerror', error => errors.push(error.message));
      await page.setViewportSize(viewport);
      await page.addInitScript(value => localStorage.setItem('telvora-theme', value), theme);
      for (const [path, heading] of pages) {
        await page.goto(path);
        await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible();
        await expect(page.locator('link[data-rh="true"][rel="canonical"]')).toHaveAttribute('href', `https://telvora.ru${path}`);
      }
      expect(errors).toEqual([]);
    });
  }
}

test('footer legal navigation and consent defaults', async ({ page }) => {
  await page.goto('/');
  for (const name of ['Публичная оферта','Политика обработки персональных данных','Согласие на обработку персональных данных','Политика Cookies','Возврат товара','Гарантия','Доставка','Реквизиты']) await expect(page.getByRole('link', { name, exact: true }).last()).toBeVisible();
  await page.goto('/contacts');
  await expect(page.locator('#callback-consent')).not.toBeChecked();
});

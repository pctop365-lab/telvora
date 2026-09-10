import { expect, test } from '@playwright/test';

const removedContent = [
  'Премиальный сервис',
  'Курьерская доставка',
  '1 год гарантии',
  'Профессиональная установка',
  '14 дней на возврат',
  'Гибкая оплата',
  'Поддержка 24/7',
  'Важная информация',
];

for (const theme of ['light', 'dark'] as const) {
  for (const viewport of [{ width: 1280, height: 900 }, { width: 390, height: 844 }]) {
    test(`homepage without service promo in ${theme} at ${viewport.width}px`, async ({ page }) => {
      const errors: string[] = [];
      page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
      page.on('pageerror', error => errors.push(error.message));
      await page.route('**/products.php?action=list', route => route.fulfill({ json: { success: true, products: [] } }));
      await page.setViewportSize(viewport);
      await page.addInitScript(value => localStorage.setItem('telvora-theme', value), theme);
      await page.goto('/');

      for (const text of removedContent) await expect(page.getByText(text, { exact: true })).toHaveCount(0);
      await expect(page.getByText('Популярные модели', { exact: true })).toBeVisible();
      await expect(page.locator('a[href*="t.me/"]').first()).toBeVisible();
      expect(await page.evaluate(() => {
        const main = document.querySelector('main');
        const footer = document.querySelector('footer');
        return {
          overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
          gap: main && footer ? Math.round(footer.getBoundingClientRect().top - main.getBoundingClientRect().bottom) : null,
          lastSectionId: main?.querySelector('section:last-of-type')?.id,
        };
      })).toEqual({ overflow: false, gap: 0, lastSectionId: 'tech' });
      expect(errors).toEqual([]);
    });
  }
}

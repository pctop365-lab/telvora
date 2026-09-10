import { expect, test } from '@playwright/test';

for (const theme of ['light', 'dark'] as const) {
  for (const viewport of [{ width: 1280, height: 900, mode: 'desktop' }, { width: 390, height: 844, mode: 'mobile' }]) {
    test(`hero copy in ${theme} ${viewport.mode}`, async ({ page }) => {
      const errors: string[] = [];
      page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
      page.on('pageerror', error => errors.push(error.message));
      await page.route('**/products.php?action=list', route => route.fulfill({ json: { success: true, products: [] } }));
      await page.setViewportSize(viewport);
      await page.addInitScript(value => localStorage.setItem('telvora-theme', value), theme);
      await page.goto('/');

      await expect(page.getByText('Телевизоры TELVORA', { exact: true })).toBeVisible();
      const heading = page.getByRole('heading', { level: 1, name: 'Телевизор, который подходит именно вам' });
      await expect(heading).toBeVisible();
      await expect(page.getByText('Подберём диагональ, технологию и модель под вашу комнату и бюджет. Доставка и профессиональная установка.', { exact: true })).toBeVisible();
      await expect(page.getByRole('link', { name: 'Смотреть каталог' })).toHaveAttribute('href', '/catalog');
      await expect(page.getByRole('link', { name: 'Узнать о технологиях' })).toHaveAttribute('href', '#tech');
      const hero = page.locator('section').first();
      for (const name of ['Подобрать телевизор', 'Доставка и оплата', 'Сервисные услуги']) await expect(hero.getByRole('link', { name: new RegExp(name) })).toBeVisible();

      const metrics = await heading.evaluate(element => {
        const style = getComputedStyle(element);
        const lineHeight = Number.parseFloat(style.lineHeight);
        const rect = element.getBoundingClientRect();
        const headerBottom = document.querySelector('header')?.getBoundingClientRect().bottom ?? 0;
        return { lines: Math.round(rect.height / lineHeight), top: rect.top, headerBottom, clipped: rect.bottom > window.innerHeight };
      });
      expect(metrics.lines).toBeLessThanOrEqual(viewport.mode === 'desktop' ? 3 : 4);
      expect(metrics.top).toBeGreaterThanOrEqual(metrics.headerBottom);
      expect(metrics.clipped).toBe(false);
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(viewport.width);
      expect(errors).toEqual([]);
      console.log(`HERO_LINES ${theme} ${viewport.mode}: ${metrics.lines}`);
      test.info().annotations.push({ type: 'heading-lines', description: `${metrics.lines}` });
    });
  }
}

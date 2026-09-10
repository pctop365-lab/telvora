import { expect, test } from '@playwright/test';

for (const theme of ['light', 'dark'] as const) {
  for (const viewport of [{ width: 1280, height: 900 }, { width: 390, height: 844 }]) {
    test(`simplified logo in ${theme} theme at ${viewport.width}px`, async ({ page }) => {
      const errors: string[] = [];
      page.on('console', (message) => {
        if (message.type() === 'error') errors.push(message.text());
      });
      page.on('pageerror', (error) => errors.push(error.message));

      await page.setViewportSize(viewport);
      await page.route('**/products.php?action=list', (route) => route.fulfill({ json: { success: true, products: [] } }));
      await page.addInitScript((value) => localStorage.setItem('telvora-theme', value), theme);
      await page.goto('/');

      const headerLogo = page.locator('header a[aria-label="TELVORA — на главную"] img');
      const footerLogo = page.locator('footer a[aria-label="TELVORA — на главную"] img');
      await expect(headerLogo).toBeVisible();
      await expect(headerLogo).toHaveAttribute('src', '/telvora-mark.svg');
      await expect(footerLogo).toHaveAttribute('src', '/telvora-mark.svg');

      expect(await headerLogo.evaluate((image) => ({ width: image.clientWidth, height: image.clientHeight }))).toEqual({ width: 48, height: 36 });
      expect(await footerLogo.evaluate((image) => ({ width: image.clientWidth, height: image.clientHeight }))).toEqual({ width: 80, height: 60 });
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(viewport.width);

      await footerLogo.scrollIntoViewIfNeeded();
      await expect(footerLogo).toBeVisible();
      expect(errors).toEqual([]);
    });
  }
}

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

      const headerLogo = page.locator('header a[aria-label="TELVORA — на главную"] .lucide-tv');
      const footerLogo = page.locator('footer a[aria-label="TELVORA — на главную"] .lucide-tv');
      await expect(headerLogo).toBeVisible();
      await expect(footerLogo).toBeAttached();

      expect(await headerLogo.evaluate((icon) => ({ width: icon.clientWidth, height: icon.clientHeight }))).toEqual({ width: 20, height: 20 });
      expect(await footerLogo.evaluate((icon) => ({ width: icon.clientWidth, height: icon.clientHeight }))).toEqual({ width: 28, height: 28 });
      await expect(page.locator('header a[aria-label="TELVORA — на главную"]')).not.toContainText('TELVORA');
      await expect(page.locator('footer a[aria-label="TELVORA — на главную"]')).not.toContainText('TELVORA');
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(viewport.width);

      await footerLogo.scrollIntoViewIfNeeded();
      await expect(footerLogo).toBeVisible();
      expect(errors).toEqual([]);
    });
  }
}

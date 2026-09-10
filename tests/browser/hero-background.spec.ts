import { expect, test } from '@playwright/test';

for (const theme of ['light', 'dark'] as const) {
  for (const viewport of [{ width: 1280, height: 900, mode: 'desktop' }, { width: 390, height: 844, mode: 'mobile' }]) {
    test(`local cinema hero in ${theme} ${viewport.mode}`, async ({ page }) => {
      const errors: string[] = [];
      page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
      page.on('pageerror', error => errors.push(error.message));
      await page.route('**/products.php?action=list', route => route.fulfill({ json: { success: true, products: [] } }));
      await page.setViewportSize(viewport);
      await page.addInitScript(value => localStorage.setItem('telvora-theme', value), theme);
      await page.goto('/');

      const hero = page.locator('main section').first();
      const image = hero.locator('img').first();
      await expect(image).toHaveAttribute('src', '/images/telvora-hero-cinema.png');
      await expect(image).toHaveJSProperty('complete', true);
      expect(await image.evaluate(img => ({
        naturalWidth: (img as HTMLImageElement).naturalWidth,
        naturalHeight: (img as HTMLImageElement).naturalHeight,
        fit: getComputedStyle(img).objectFit,
        position: getComputedStyle(img).objectPosition,
      }))).toEqual({ naturalWidth: 1672, naturalHeight: 941, fit: 'cover', position: viewport.mode === 'desktop' ? '100% 50%' : '72% 50%' });
      await expect(hero.getByRole('heading', { level: 1 })).toBeVisible();
      for (const href of ['/catalog', '/support', '/delivery', '/services']) await expect(hero.locator(`a[href="${href}"]`)).toBeVisible();
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(viewport.width);
      expect(errors).toEqual([]);
    });
  }
}

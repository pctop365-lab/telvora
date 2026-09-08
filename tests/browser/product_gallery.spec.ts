import { expect, test } from '@playwright/test';

const pixel = (color: string) => `data:image/svg+xml,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="80" height="60"><rect width="80" height="60" fill="${color}"/></svg>`)}`;

test.beforeEach(async ({ page }) => {
  await page.route('**/products.php**', route => route.fulfill({ json: { success:true, products:[{
    id:1, slug:'gallery-tv', name:'Gallery TV', series:'G', category:'OLED', screen_size:'55', resolution:'4K', price:100,
    image:pixel('red'), images:[pixel('red'),pixel('blue'),pixel('green')], rating:5, reviews:1, description:'TV', specs:[], highlights:[], is_active:true,
    storefront_variants:[{product_variant_id:1,country:'Россия',price:100,is_active:true,availability:{product_variant_id:1,status:'in_stock',orderable:true}}]
  }] } }));
});

test('gallery supports thumbnails, arrows, zoom and keyboard', async ({ page }) => {
  await page.goto('/catalog/oled/gallery-tv');
  const next=page.getByRole('button',{name:'Следующее изображение'}).first();
  await expect(next).toBeVisible(); await next.click();
  await expect(page.getByAltText(/изображение 2 из 3/)).toBeVisible();
  await page.getByRole('button',{name:/Увеличить изображение 2/}).click();
  await expect(page.getByRole('dialog')).toBeVisible();
  await page.keyboard.press('ArrowRight'); await expect(page.getByAltText(/увеличенное изображение 3/)).toBeVisible();
  await page.keyboard.press('Escape'); await expect(page.getByRole('dialog')).toHaveCount(0);
});

test('legacy single image has no redundant controls', async ({ page }) => {
  await page.route('**/products.php**', route => route.fulfill({ json:{success:true,products:[{id:2,slug:'legacy-tv',name:'Legacy TV',series:'L',category:'OLED',screen_size:'55',resolution:'4K',price:100,image:pixel('black'),rating:0,reviews:0,description:'TV',specs:[],highlights:[],is_active:true,storefront_variants:[]}]}}));
  await page.goto('/catalog/oled/legacy-tv');
  await expect(page.getByAltText(/изображение 1 из 1/)).toBeVisible();
  await expect(page.getByRole('button',{name:'Следующее изображение'})).toHaveCount(0);
  await expect(page.getByLabel('Миниатюры товара')).toHaveCount(0);
});

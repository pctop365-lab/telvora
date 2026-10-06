import { expect, test } from '@playwright/test';
const image='data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="20" height="20"/%3E';
const item=(id:number,brand:string,series:string,category:string,resolution:string,screen_size:string)=>({id,slug:`tv-${id}`,name:`${brand} ${series}`,brand,series,category,screen_size,resolution,price:100000,image,images:[image],rating:5,reviews:0,description:'TV',specs:[],highlights:[],is_active:true,storefront_variants:[{product_variant_id:id,country:'Россия',price:100000,is_active:true,availability:{product_variant_id:id,status:'in_stock',orderable:true}}]});
test.beforeEach(async({page})=>{await page.route('**/products.php**',route=>route.fulfill({json:{success:true,count:3,products:[item(1,'LG','C5','OLED','3840 × 2160 (4K UHD)','55'),item(2,'LG','G5','OLED','3840 × 2160 (4K UHD)','65'),item(3,'Samsung','Q900','QLED','7680 × 4320 (8K UHD)','85')]}}));});

for (const width of [1280, 390]) test(`catalog without model buttons retains filters at ${width}px`,async({page})=>{
  await page.setViewportSize({width,height:900});
  await page.goto('/catalog');
  await page.getByRole('button',{name:'Только необходимые',exact:true}).click();
  await expect(page.getByText('Найдено товаров: 3')).toBeVisible();
  await expect(page.getByText('Модельные ряды',{exact:true})).toHaveCount(0);
  await expect(page.getByRole('button',{name:/Все модели|LG C5|LG G5|Samsung Q900/})).toHaveCount(0);
  await expect(page.locator('main select')).toHaveValue('default');
  await page.locator('main select').selectOption('price-asc');
  await expect(page.getByText('Найдено товаров: 3')).toBeVisible();
  await page.screenshot({path:`test-results/catalog-without-models-${width}.png`,fullPage:true});
  await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.getByRole('button',{name:/Фильтры/}).click();
  await page.getByRole('button',{name:'LG',exact:true}).click();
  await page.getByRole('button',{name:'85',exact:true}).click();
  await expect(page.getByText('По выбранным условиям ничего не найдено.')).toBeVisible();
  const dialog=page.getByRole('dialog',{name:'Фильтры каталога'}); await dialog.getByRole('button',{name:'Сбросить',exact:true}).click(); await dialog.getByRole('button',{name:'Закрыть',exact:true}).click();
  await expect(page.getByText('Найдено товаров: 3')).toBeVisible();
  await page.goto('/televisions');
  await expect(page.getByText('Найдено товаров: 3')).toBeVisible();
  await expect(page.getByRole('button',{name:/Все модели|Samsung Q900/})).toHaveCount(0);
});

test('legacy 8k route selects resolution rather than technology',async({page})=>{
  await page.goto('/catalog/8k'); await expect(page.getByText('Найдено товаров: 1')).toBeVisible();
  await expect(page.getByRole('button',{name:/Samsung Q900/})).toBeVisible();
  await page.getByRole('button',{name:/Фильтры/}).click();
  await expect(page.getByRole('button',{name:'7680 × 4320 (8K UHD)'})).toHaveClass(/bg-accent-500/);
  await expect(page.getByRole('heading',{name:'Технология экрана'})).toBeVisible();
});

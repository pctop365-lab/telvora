import { expect, test } from '@playwright/test';

const catalog={success:true,services:[
  {id:1,service_key:'wall-mount-43-55',category:'mounting',name:'Монтаж телевизора на стену',description:'Монтаж на подготовленное место.',min_screen_size:43,max_screen_size:55,price:7000,is_active:true,sort_order:1,requires_tv:true},
  {id:2,service_key:'tv-setup',category:'other',name:'Настройка телевизора',description:'Стоимость определяется после уточнения.',min_screen_size:null,max_screen_size:null,price:null,is_active:true,sort_order:2,requires_tv:true},
]};
const television={id:'10__variant_20',slug:'test-tv',name:'Тестовый телевизор',price:90000,image:'',screenSize:'55″',category:'OLED',quantity:1,assemblyCountry:'Россия',productId:'10',productVariantId:20};

test.beforeEach(async({page})=>{
  await page.route('**/services.php',route=>route.fulfill({json:catalog}));
  await page.addInitScript(item=>localStorage.setItem('telvora_cart',JSON.stringify([item])),television);
});

for(const viewport of [{width:1280,height:900},{width:390,height:844}])test(`services, cart and checkout at ${viewport.width}px`,async({page})=>{
  await page.setViewportSize(viewport);
  await page.goto('/services');
  await expect(page.getByRole('heading',{name:'Сервисные услуги'})).toBeVisible();
  await expect(page.getByText('Стоимость уточняется')).toBeVisible();
  await page.getByRole('button',{name:/Добавить для 55/}).click();
  await page.getByRole('button',{name:'Корзина'}).click();
  await expect(page.getByRole('button',{name:'Удалить Монтаж телевизора на стену'})).toBeVisible();
  await expect(page.getByText('97 000 ₽')).toBeVisible();
  await page.getByRole('button',{name:'Оформить заказ'}).click();
  await expect(page).toHaveURL(/\/checkout$/);
  await expect(page.getByText('Монтаж телевизора на стену')).toHaveCount(1);
});

test('service can be removed without removing its television',async({page})=>{
  await page.goto('/services');
  await page.getByRole('button',{name:/Добавить для 55/}).click();
  await page.getByRole('button',{name:'Корзина'}).click();
  await page.getByRole('button',{name:'Удалить Монтаж телевизора на стену'}).click();
  await expect(page.getByRole('button',{name:'Удалить Монтаж телевизора на стену'})).toHaveCount(0);
  await expect(page.getByText('Тестовый телевизор')).toBeVisible();
});

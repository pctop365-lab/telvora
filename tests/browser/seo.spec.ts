import { expect, test } from '@playwright/test';

const product = {
  id: 5,
  slug: 'lg-oled77c5rla',
  name: 'Телевизор LG OLED77C5RLA 77" OLED evo 4K Smart TV (2025)',
  brand: 'LG',
  series: 'OLED C5',
  category: 'OLED',
  screen_size: '77″',
  resolution: '4K',
  price: 399990,
  image: '/images/test-product.webp',
  images: ['/images/test-product.webp'],
  description: 'OLED-телевизор с разрешением 4K и функциями Smart TV.',
  specs: [],
  highlights: [],
  is_active: true,
  storefront_variants: [{
    product_variant_id: 15,
    country: 'Россия',
    price: 399990,
    is_active: true,
    availability: { product_variant_id: 15, status: 'in_stock', orderable: true, expected_arrival_at: null },
  }],
};

test.beforeEach(async ({ page }) => {
  await page.route('**/products.php**', route => route.fulfill({ json: { success: true, count: 1, products: [product] } }));
});

for (const entry of [
  { path: '/', title: 'TELVORA — телевизоры с доставкой и установкой', canonical: '/' },
  { path: '/catalog', title: 'Телевизоры — каталог TELVORA', canonical: '/catalog' },
  { path: '/delivery', title: 'Доставка и оплата — TELVORA', canonical: '/delivery' },
  { path: '/services', title: 'Сервисные услуги — TELVORA', canonical: '/services' },
  { path: '/contacts', title: 'Контакты TELVORA', canonical: '/contacts' },
  { path: '/requisites', title: 'Реквизиты TELVORA', canonical: '/requisites' },
]) {
  test(`unique metadata for ${entry.path}`, async ({ page }) => {
    await page.goto(entry.path);
    await expect(page).toHaveTitle(entry.title);
    await expect(page.locator('meta[name="description"]')).toHaveCount(1);
    await expect(page.locator('link[rel="canonical"]')).toHaveCount(1);
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', `https://telvora.ru${entry.canonical}`);
    await expect(page.locator('meta[property="og:url"]')).toHaveAttribute('content', `https://telvora.ru${entry.canonical}`);
    await expect(page.locator('h1')).toHaveCount(1);
  });
}

test('product has factual Product, Offer and breadcrumb structured data', async ({ page }) => {
  await page.goto('/catalog/oled/lg-oled77c5rla');
  await expect(page.getByRole('heading', { level: 1, name: product.name })).toBeVisible();
  await expect(page).toHaveTitle(`${product.name} — купить в TELVORA`);
  await expect(page.locator('link[rel="canonical"]')).toHaveCount(1);
  const blocks = await page.locator('script[type="application/ld+json"]').evaluateAll(nodes => nodes.flatMap(node => JSON.parse(node.textContent || '[]')));
  const productSchema = blocks.find(item => item['@type'] === 'Product');
  const breadcrumbs = blocks.find(item => item['@type'] === 'BreadcrumbList');
  expect(productSchema.name).toBe(product.name);
  expect(productSchema.offers.price).toBe(399990);
  expect(productSchema.offers.priceCurrency).toBe('RUB');
  expect(productSchema.offers.availability).toBe('https://schema.org/InStock');
  expect(productSchema.AggregateRating).toBeUndefined();
  expect(breadcrumbs.itemListElement).toHaveLength(4);
});

test('home exposes factual Organization structured data', async ({ page }) => {
  await page.goto('/');
  const blocks = await page.locator('script[type="application/ld+json"]').evaluateAll(nodes => nodes.flatMap(node => JSON.parse(node.textContent || '[]')));
  const organization = blocks.find(item => item['@type'] === 'Organization');
  expect(organization).toMatchObject({
    name: 'TELVORA',
    legalName: 'Индивидуальный предприниматель Помякшев Иван Владимирович',
    url: 'https://telvora.ru/',
    telephone: '+7 (926) 202-01-19',
    email: 'telvora24@gmail.com',
  });
  expect(organization.contactPoint.map((item: { telephone: string }) => item.telephone)).toEqual([
    '+79262020119',
    '+79031894342',
  ]);
  expect(organization.address).toBeUndefined();
});

test('public phone links expose both TELVORA numbers', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('a[href="tel:+79262020119"]')).toHaveCount(2);
  await expect(page.locator('a[href="tel:+79031894342"]')).toHaveCount(2);
  await expect(page.getByText('+7 (903) 189-43-42').first()).toBeVisible();
});

test('inactive product is not exposed as an indexable product page', async ({ page }) => {
  await page.unroute('**/products.php**');
  await page.route('**/products.php**', route => route.fulfill({ json: { success: true, count: 1, products: [{ ...product, is_active: false }] } }));
  await page.goto('/catalog/oled/inactive-product');
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', 'noindex, follow');
  await expect(page.locator('script[type="application/ld+json"]')).toHaveCount(0);
});

test('indexed routes have unique titles and descriptions', async ({ page }) => {
  test.setTimeout(60_000);
  const paths = ['/', '/catalog', '/catalog/oled', '/catalog/qled', '/catalog/led', '/catalog/8k', '/catalog/oled/lg-oled77c5rla', '/delivery', '/services', '/warranty', '/returns', '/support', '/contacts', '/requisites', '/offer', '/privacy', '/personal-data-consent', '/cookies'];
  const titles: string[] = [];
  const descriptions: string[] = [];
  for (const path of paths) {
    await page.goto(path);
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', `https://telvora.ru${path}`);
    titles.push(await page.title());
    descriptions.push(await page.locator('meta[name="description"]').getAttribute('content') || '');
  }
  expect(new Set(titles).size, JSON.stringify(paths.map((path, index) => ({ path, title: titles[index] })))).toBe(paths.length);
  expect(new Set(descriptions).size, JSON.stringify(paths.map((path, index) => ({ path, description: descriptions[index] })))).toBe(paths.length);
  expect(descriptions.every(Boolean)).toBe(true);
});

for (const path of ['/checkout', '/order-success/TLV-TEST', '/admin', '/missing-seo-page']) {
  test(`${path} is noindex`, async ({ page }) => {
    await page.goto(path);
    await expect(page.locator('meta[name="robots"]')).toHaveCount(1);
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
  });
}

test('SEO pages stay stable on mobile dark mode without console errors', async ({ page }) => {
  const errors: string[] = [];
  page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.addInitScript(() => localStorage.setItem('telvora-theme', 'dark'));
  await page.goto('/catalog/oled/lg-oled77c5rla');
  expect(await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth)).toBe(false);
  expect(errors).toEqual([]);
});

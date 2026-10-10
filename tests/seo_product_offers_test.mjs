import assert from 'node:assert/strict';
import { buildProductOffers } from '../src/lib/productOffers.js';

const variant = (overrides = {}) => ({
  productVariantId: 1,
  country: 'Польша',
  displayName: 'Польша',
  price: 125900,
  oldPrice: 149900,
  isActive: true,
  availability: { status: 'in_stock', orderable: true },
  ...overrides,
});
const url = 'https://telvora.ru/catalog/oled/example';

const single = buildProductOffers([variant()], url);
assert.equal(single['@type'], 'Offer');
assert.equal(single.price, 125900, 'uses the effective buyer price, not old price');
assert.equal(single.priceCurrency, 'RUB');
assert.equal(single.availability, 'https://schema.org/InStock');
assert.equal(single.url, url);

const multi = buildProductOffers([
  variant({ productVariantId: 1, country: 'Индонезия', price: 127000 }),
  variant({ productVariantId: 2, country: 'Польша', price: 125900 }),
], url);
assert.deepEqual(multi.map(offer => offer.price), [127000, 125900], 'emits a separate offer for every active priced variant');
assert.deepEqual(multi.map(offer => offer.name), ['Индонезия', 'Польша']);

const stockCases = buildProductOffers([
  variant({ productVariantId: 1, country: 'Подтверждён', availability: { status: 'in_stock', orderable: true } }),
  variant({ productVariantId: 2, country: 'Остаток закончился', availability: { status: 'out_of_stock', orderable: false } }),
  variant({ productVariantId: 3, country: 'Ожидается', availability: { status: 'expected', orderable: false } }),
  variant({ productVariantId: 4, country: 'Неизвестно', availability: { status: 'unknown', orderable: false } }),
  variant({ productVariantId: 5, country: 'Противоречие', availability: { status: 'in_stock', orderable: false } }),
], url);
assert.equal(stockCases[0].availability, 'https://schema.org/InStock');
assert.equal(stockCases[1].availability, 'https://schema.org/OutOfStock');
for (const offer of stockCases.slice(2)) assert.equal('availability' in offer, false, `${offer.name} must not imply stock`);

assert.equal(buildProductOffers([
  variant({ productVariantId: 1, price: 0 }),
  variant({ productVariantId: 2, price: -10 }),
  variant({ productVariantId: 3, price: Number.NaN }),
  variant({ productVariantId: 4, isActive: false }),
], url), undefined, 'technical zero, invalid price, and inactive variants are excluded');
assert.equal(buildProductOffers([variant()], url, 'draft'), undefined, 'draft products have no offers');
assert.equal(buildProductOffers([variant()], url, 'publish_failed'), undefined, 'failed unpublished products have no offers');

console.log('Product Offer JSON-LD: PASS (effective prices, variant offers, conservative availability, draft and zero-price exclusion)');

import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const products = read('products.php');
const mutation = read('product_variant_mutation_service.php');
const storefront = read('storefront_cart_service.php');
const admin = read('src/pages/AdminPage.tsx');
const detail = read('src/components/ProductDetail.tsx');
const cart = read('src/store/cart.tsx');
const order = read('api.php');

assert.match(products, /manufacturer_part_number/);
assert.match(products, /'model_code'\s*=>/);
assert.match(products, /variant_model_update/);
assert.match(mutation, /function productVariantMutationModelCode/);
assert.match(mutation, /product_id = :product_id/);
assert.match(admin, /Модель варианта/);
assert.match(admin, /model_code/);
assert.match(detail, /formatVariantProductName/);
assert.match(detail, /product\.slug/);
assert.match(cart, /modelCode: variant\?\.modelCode/);
assert.match(storefront, /manufacturer_part_number/);
assert.match(storefront, /'model_code'/);
assert.match(order, /storefrontCartVariantName\(\$serverItem\)/);

function formatVariantProductName(name, modelCode) {
  const code = modelCode?.trim();
  if (!code) return name;
  const token = name.match(/\b(?=[A-Za-zА-Яа-я0-9-]*[A-Za-zА-Яа-я])(?=[A-Za-zА-Яа-я0-9-]*\d)[A-Za-zА-Яа-я0-9-]{5,}\b/)?.[0];
  return token ? name.replace(token, code) : name;
}

assert.equal(
  formatVariantProductName('Телевизор LG OLED48C6RLA 48" OLED evo 4K Smart TV (2026)', 'OLED48C6LA'),
  'Телевизор LG OLED48C6LA 48" OLED evo 4K Smart TV (2026)'
);
assert.equal(formatVariantProductName('Телевизор LG OLED48C6RLA', null), 'Телевизор LG OLED48C6RLA');
assert.equal(formatVariantProductName('Телевизор без кода', 'OLED48C6LA'), 'Телевизор без кода');

console.log('product_variant_model_code_contract_test: PASS');

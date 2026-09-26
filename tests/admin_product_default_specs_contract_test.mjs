import assert from 'node:assert/strict';
import fs from 'node:fs';

const source = fs.readFileSync(new URL('../src/pages/AdminPage.tsx', import.meta.url), 'utf8');
const defaultList = source.match(/const defaultProductSpecs: Spec\[\] = \[(.*?)\n\];/s)?.[1];

assert.ok(defaultList, 'default product specification list must exist');

const expected = [
  'Тип экрана',
  'Разрешение',
  'Частота обновления',
  'HDR',
  'Smart TV',
  'Операционная система',
  'Процессор',
  'Мощность звука',
  'HDMI',
  'USB',
  'Wi-Fi',
  'Bluetooth',
  'Размер без подставки',
  'Размер с подставкой',
  'Вес без подставки',
  'Вес с подставкой',
];

const labels = [...defaultList.matchAll(/label: '([^']+)'/g)].map((match) => match[1]);
assert.deepEqual(labels, expected, 'new product defaults must preserve the required order');
assert.ok(defaultList.match(/label: 'Размер без подставки', value: ''/));
assert.ok(defaultList.match(/label: 'Размер с подставкой', value: ''/));
assert.ok(defaultList.match(/label: 'Вес без подставки', value: ''/));
assert.ok(defaultList.match(/label: 'Вес с подставкой', value: ''/));

const addProduct = source.match(/const openAddProduct = \(\) => \{(.*?)\n  \};/s)?.[1];
assert.ok(addProduct?.includes('defaultProductSpecs.map((spec) => ({ ...spec }))'));

const editProduct = source.match(/const openEditProduct = \(product: AdminProduct\) => \{(.*?)\n  \};/s)?.[1];
assert.ok(editProduct, 'existing product edit path must remain present');
assert.ok(!editProduct.includes('defaultProductSpecs'), 'editing must not inject new default specs');

console.log('admin_product_default_specs_contract_test: PASS');

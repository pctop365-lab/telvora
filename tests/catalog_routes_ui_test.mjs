import assert from 'node:assert/strict'; import fs from 'node:fs';
const app=fs.readFileSync(new URL('../src/App.tsx',import.meta.url),'utf8'); const header=fs.readFileSync(new URL('../src/components/Header.tsx',import.meta.url),'utf8'); const catalog=fs.readFileSync(new URL('../src/pages/CatalogPage.tsx',import.meta.url),'utf8'); const category=fs.readFileSync(new URL('../src/pages/CategoryPage.tsx',import.meta.url),'utf8'); const card=fs.readFileSync(new URL('../src/components/ProductCard.tsx',import.meta.url),'utf8');
assert.match(app,/path="\/catalog"/); assert.match(app,/path="\/televisions"/); assert.match(app,/path="\/catalog\/:categorySlug\/:productSlug"/);
assert.match(header,/Телевизоры', to: '\/televisions'/); assert.match(card,/`\/catalog\/\$\{categorySlug\}\/\$\{product\.slug\}`/);
assert.match(category,/categorySlug === '8k'/); assert.match(catalog,/Технология экрана/); assert.match(catalog,/Разрешение/); assert.match(catalog,/Сбросить все фильтры/); assert.match(catalog,/По выбранным условиям ничего не найдено/); assert.match(catalog,/role="dialog"/);
console.log('PASS catalog routes, reset, empty state and mobile filter UI');

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const hero = readFileSync(new URL('../src/components/Hero.tsx', import.meta.url), 'utf8');
const content = readFileSync(new URL('../src/data/siteContent.ts', import.meta.url), 'utf8');

assert.match(content, /badge: 'Телевизоры TELVORA'/);
assert.match(content, /title: 'Телевизор, который подходит именно вам'/);
assert.match(content, /Подберём диагональ, технологию и модель под вашу комнату и бюджет\. Доставка и профессиональная установка\./);
assert.match(content, /image: '\/images\/telvora-hero-cinema\.png'/);
assert.doesNotMatch(content, /pexels-photo-28549934/);
assert.match(hero, /\{title\}/);
assert.match(hero, /object-\[72%_center\] sm:object-right/);
assert.match(hero, /dark-content relative min-h-screen/);
assert.doesNotMatch(hero, /titleParts|оживающая/);

for (const [title, subtitle, route] of [
  ['Подобрать телевизор', 'Поможем выбрать модель под ваш бюджет', '/support'],
  ['Доставка и оплата', 'Тарифы по Москве и отправка в регионы', '/delivery'],
  ['Сервисные услуги', 'Монтаж, настройка и пиксельтест', '/services'],
]) {
  assert.match(hero, new RegExp(`${title}[\\s\\S]+${subtitle}[\\s\\S]+${route}`));
}

assert.match(hero, /<Link[\s\S]+to=\{f\.to\}/);
assert.match(hero, /focus-visible:ring-2/);
assert.match(hero, /hover:-translate-y-0\.5/);
assert.match(hero, /Листайте вниз/);
assert.doesNotMatch(hero, /title: 'Курьерская доставка'/);
assert.doesNotMatch(hero, /title: 'Официальная гарантия'/);
assert.doesNotMatch(hero, /title: 'Установка за 1 день'/);

console.log('hero_links_test: PASS');

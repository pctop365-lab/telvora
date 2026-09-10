import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const techSection = readFileSync(new URL('../src/components/TechSection.tsx', import.meta.url), 'utf8');
const telegramBanner = readFileSync(new URL('../src/components/TelegramBanner.tsx', import.meta.url), 'utf8');

assert.match(techSection, /src="\/images\/tech-home-cinema\.png"[\s\S]+object-cover/);
assert.match(techSection, /src="\/images\/tech-design-lounge\.png"[\s\S]+object-cover/);
assert.match(techSection, /Кинотеатр у вас дома[\s\S]+Смотреть телевизоры →/);
assert.match(techSection, /Дизайн без границ[\s\S]+Смотреть модели →/);
assert.match(techSection, /to="\/support"[\s\S]+bg-accent-500\/10 blur-3xl[\s\S]+Поддержка/);
assert.doesNotMatch(techSection, /to="\/support"[\s\S]+<img/);
assert.doesNotMatch(telegramBanner, /<img|backgroundImage|url\(/);

console.log('tech_section_backgrounds_test: PASS');

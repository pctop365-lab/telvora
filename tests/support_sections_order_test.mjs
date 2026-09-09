import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const supportPage = readFileSync(new URL('../src/pages/SupportPage.tsx', import.meta.url), 'utf8');
const helpHeading = 'Нужна дополнительная помощь?';
const trackingHeading = 'Проверить заказ';
const helpHeadingMarkup = `<h2 className="font-display font-bold text-2xl">${helpHeading}</h2>`;
const trackingHeadingMarkup = `<h2 className="font-display font-bold text-2xl">${trackingHeading}</h2>`;

assert.equal(supportPage.split(helpHeadingMarkup).length - 1, 1, 'help block must appear exactly once');
assert.equal(supportPage.split(trackingHeadingMarkup).length - 1, 1, 'tracking block must appear exactly once');
assert.ok(supportPage.indexOf(helpHeadingMarkup) < supportPage.indexOf(trackingHeadingMarkup), 'help block must precede order tracking');
assert.match(supportPage, /id="order-tracking"/);
assert.match(supportPage, /onClick=\{trackOrder\}/);
assert.match(supportPage, /to="\/contacts#callback"/);

console.log('Support section order checks passed.');

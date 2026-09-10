import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const home = readFileSync('src/pages/HomePage.tsx', 'utf8');
const grid = readFileSync('src/components/ProductGrid.tsx', 'utf8');
assert.match(home, /useProducts\(\)/);
assert.match(home, /homepage_position !== null/);
assert.match(home, /homepage_position as number/);
assert.match(home, /products\.slice\(0, 9\)/);
assert.doesNotMatch(home, /products\.slice\(0, 3\)/);
assert.match(home, /<TelegramBanner \/>/);
assert.match(grid, /grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6/);
console.log('Home featured products limit and responsive grid contract: PASS');

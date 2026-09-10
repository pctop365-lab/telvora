import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const migration = readFileSync('database/migrations/20260910_012_homepage_positions.sql', 'utf8');
const preflight = readFileSync('database/migrations/preflight_20260910_012_homepage_positions.sql', 'utf8');
const products = readFileSync('products.php', 'utf8');
const admin = readFileSync('src/pages/AdminPage.tsx', 'utf8');
const home = readFileSync('src/pages/HomePage.tsx', 'utf8');

assert.match(migration, /homepage_position TINYINT UNSIGNED NULL/);
assert.match(migration, /UNIQUE KEY uq_products_homepage_position/);
assert.match(migration, /CHECK \(homepage_position IS NULL OR homepage_position BETWEEN 1 AND 9\)/);
assert.match(preflight, /COLUMN_TYPE = 'int unsigned'/);
assert.match(products, /function normalizeHomepagePosition\(mixed \$value\)/);
assert.match(products, /Позиция \{\$position\} уже занята другим товаром/);
assert.match(products, /homepage_position/);
assert.match(products, /WHERE is_active = 1/);
assert.match(admin, /Позиция на главной/);
assert.match(admin, /Array\.from\(\{ length: 9 \}/);
assert.match(admin, /homepage_position: productForm\.homepage_position === '' \? null : Number/);
assert.match(home, /useProducts\(\)/);
assert.match(home, /homepage_position !== null/);
assert.match(home, /\.sort\(\(a, b\) => \(a\.homepage_position as number\)/);
assert.doesNotMatch(home, /sort: 'rating'/);
console.log('Homepage position backend, migration, Admin and HomePage contract: PASS');

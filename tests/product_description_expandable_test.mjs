import assert from 'node:assert/strict';
import fs from 'node:fs';

const source = fs.readFileSync(new URL('../src/components/ProductDetail.tsx', import.meta.url), 'utf8');

assert.match(source, /data-testid="product-description"/);
assert.match(source, /data-testid="product-description-toggle"/);
assert.match(source, /descriptionHasOverflow/);
assert.match(source, /descriptionExpanded \? 'Свернуть' : 'Показать полностью'/);
assert.match(source, /scrollHeight > collapsedHeight \+ 1/);
assert.match(source, /window\.matchMedia\('\(min-width: 640px\)'\)/);
assert.match(source, /from-white dark:from-graphite-900/);
assert.match(source, /whitespace-pre-line/);
assert.match(source, /transition-\[max-height\] duration-300/);

console.log('PASS expandable product description contract');

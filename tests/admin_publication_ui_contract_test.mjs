import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const page = await readFile(new URL('../src/pages/AdminPage.tsx', import.meta.url), 'utf8');

assert.match(page, /action: operation/);
assert.match(page, /request_publish/);
assert.match(page, /request_unpublish/);
assert.match(page, /expected_revision: product\.publication_revision/);
assert.match(page, /publication_status/);
assert.match(page, /publication_revision: number/);
assert.match(page, /pending_publish/);
assert.match(page, /pending_unpublish/);
assert.match(page, /disabled=\{product\.publication_status === 'pending_publish'/);
assert.match(page, /response\.status === 409[\s\S]*loadProducts\(\)/);
assert.match(page, /setInterval\(\(\) => \{ void loadProducts\(\); \}, 10000\)/);
const toggle = page.slice(page.indexOf('const toggleProductStatus'), page.indexOf('const deleteProduct'));
assert.doesNotMatch(toggle, /action:\s*['"]update['"]/);
assert.doesNotMatch(toggle, /is_active:\s*newStatus/);
console.log('PASS admin publication UI contract');

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const header = readFileSync(new URL('../src/components/Header.tsx', import.meta.url), 'utf8');

assert.match(header, /import \{ publicContacts \} from '@\/data\/publicContacts'/);
assert.match(header, /publicContacts\.phones\.map/);
assert.match(header, /tel:\$\{phone\.href\}/);
assert.match(header, /href=\{publicContacts\.ordersMailto\}/);
assert.match(header, /href=\{publicContacts\.supportMailto\}/);
assert.match(header, /hidden lg:flex items-center gap-5/);
assert.match(header, /MOBILE CONTACTS/);
assert.match(header, /fixed top-0 left-0 right-0 z-50/);

console.log('Header contact strip checks passed.');

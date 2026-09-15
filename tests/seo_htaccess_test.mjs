import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
const htaccess = await readFile('deployment/seo-stage2/production.htaccess.final', 'utf8');
const apache = await readFile('seo-artifacts/routes.apache.conf', 'utf8');
const required = [
  'Options -MultiViews',
  'HTTP:X-Forwarded-Proto',
  'HTTP_HOST',
  'https://telvora.ru%{REQUEST_URI}',
  'RewriteRule ^catalog$ _prerender/catalog.html [END]',
  'RewriteRule ^catalog/oled$ _prerender/catalog-oled.html [END]',
  'RewriteRule ^catalog/oled/lg-oled77c5rla$ _prerender/product-lg-oled77c5rla.html [END]',
  'RewriteRule ^(?:checkout|admin|soundbars|accessories)$ client.html [END]',
  'RewriteRule ^order-success/[^/]+$ client.html [END]',
  'RewriteCond %{REQUEST_FILENAME} -f [OR]',
  'RewriteCond %{REQUEST_FILENAME} -d',
  'RewriteCond %{THE_REQUEST}',
  'RewriteRule ^ - [R=404,END]',
  'ErrorDocument 404 /404.html',
];
for (const rule of required) assert.ok(htaccess.includes(rule), `missing final .htaccess rule: ${rule}`);
assert.ok(apache.includes('_prerender/catalog.html'));
assert.ok(apache.includes('THE_REQUEST'));
assert.ok(apache.includes('R=404,END'));
assert.ok(!htaccess.includes('catalog/index.html'));
assert.ok(!htaccess.includes('delivery/index.html'));
console.log('seo_htaccess_test: PASS (flat internal routing, no DirectorySlash conflict, real 404)');

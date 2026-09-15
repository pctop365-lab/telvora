import assert from 'node:assert/strict';
import { readFile, stat } from 'node:fs/promises';
const manifest = JSON.parse(await readFile('seo-artifacts/routes.json', 'utf8'));
const forbidden = ['/catalog', '/delivery', '/services', '/warranty', '/returns', '/support', '/contacts', '/requisites', '/offer', '/privacy', '/personal-data-consent', '/cookies'];
for (const route of forbidden) {
  await assert.rejects(stat(`dist${route}`), { code: 'ENOENT' }, `public route directory must not exist: ${route}`);
}
for (const [route, internal] of Object.entries(manifest.prerenderFiles)) {
  assert.ok(internal.startsWith('/_prerender/'), `${route} must use internal namespace`);
  await stat(`dist${internal}`);
  assert.equal((await readFile(`dist${internal}`, 'utf8')).includes(`https://telvora.ru${route}`), true, `${route} canonical missing`);
}
for (const route of manifest.productRoutes) {
  await assert.rejects(stat(`dist${route}`), { code: 'ENOENT' }, `product public directory must not exist: ${route}`);
}
assert.equal(await stat('dist/_prerender').then(() => true), true);
console.log(`seo_physical_routes_test: PASS (${manifest.routes.length} public routes use flat internal prerender files)`);

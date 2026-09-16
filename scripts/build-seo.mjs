import { build } from 'vite';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { publicRoutes, clientRoutes } from './seo-routes.mjs';
import { downloadProductImages } from './seo-product-images.mjs';

// Only anonymous, read-only public endpoints. Never load PHP config or database secrets.
async function getPublic(url) {
  const response = await fetch(url, { redirect: 'error', signal: AbortSignal.timeout(30000), headers: { Accept: 'application/json' } });
  if (!response.ok) throw new Error(`Public API HTTP ${response.status}`);
  const data = await response.json();
  if (data.success !== true) throw new Error('Public API reported failure');
  return data;
}
const [catalog, services] = await Promise.all([
  getPublic('https://telvora.ru/products.php?action=list'),
  getPublic('https://telvora.ru/services.php'),
]);
if (!Array.isArray(catalog.products) || catalog.count !== catalog.products.length) throw new Error('Incomplete product response');
if (!Array.isArray(services.services)) throw new Error('Invalid service response');
await build();
await build({ build: { ssr: 'src/entry-prerender.tsx', outDir: '.seo-build', emptyOutDir: true } });
const { render, normalizeProduct, getCategorySlugForProduct } = await import('../.seo-build/entry-prerender.js');
const active = catalog.products.filter(p => p.is_active === true || p.is_active === 1);
// Normalization is a public-field allowlist; raw backend objects never go into HTML.
const products = active.map(normalizeProduct).sort((a, b) => a.slug.localeCompare(b.slug, 'en'));
const productRoutes = products.map(p => {
  const category = getCategorySlugForProduct(p);
  if (!['oled', 'qled', 'led', '8k'].includes(category) || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(p.slug)) throw new Error('Unsupported public product route');
  if (!p.name.trim() || !p.description.trim() || !p.image) throw new Error(`Incomplete public SEO content: ${p.slug}`);
  return `/catalog/${category}/${p.slug}`;
});
if (new Set(productRoutes).size !== products.length) throw new Error('Duplicate product routes');
const serviceFields = ['id', 'service_key', 'category', 'name', 'description', 'min_screen_size', 'max_screen_size', 'price', 'is_active', 'sort_order', 'requires_tv'];
const serviceSnapshot = services.services.filter(s => s.is_active === true || s.is_active === 1).map(s => Object.fromEntries(serviceFields.map(k => [k, s[k]])));
const routes = [...publicRoutes, ...productRoutes];
const template = (await readFile('dist/index.html', 'utf8'))
  .replace(/<title>[\s\S]*?<\/title>/g, '')
  .replace(/<(?:meta|link)\b[^>]*data-rh="true"[^>]*>/g, '');
const json = value => JSON.stringify(value).replace(/</g, '\\u003c');
// Refuse non-JSON numbers rather than hydrate a null in place of NaN/Infinity.
JSON.stringify({ products, services: serviceSnapshot }, (_key, value) => {
  if (typeof value === 'number' && !Number.isFinite(value)) throw Error('Invalid numeric public data');
  return value;
});
const themeBootstrap = `<script>(()=>{let dark=false;try{dark=localStorage.getItem('telvora-theme')==='dark'}catch{}document.documentElement.className=dark?'dark':'light';document.documentElement.style.colorScheme=dark?'dark':'light';const t=document.getElementById('telvora-dark');if(dark)document.getElementById('root').replaceChildren(t.content);t.remove()})()</script>`;
const sizes = {};
const prerenderFileFor = path => {
  if (path === '/') return 'index.html';
  const product = productRoutes.find(route => route === path);
  if (product) return `product-${product.split('/').pop()}.html`;
  return `${path.slice(1).replaceAll('/', '-')}.html`;
};
await mkdir('dist/_prerender', { recursive: true });
for (const path of routes) {
  const data = { path, products: path.startsWith('/catalog/') && productRoutes.includes(path) ? products.filter(p => path.endsWith('/' + p.slug)) : path === '/' || path.startsWith('/catalog') ? products : [], services: path === '/services' ? serviceSnapshot : [], theme: 'light' };
  const light = render(path, data);
  const dark = render(path, { ...data, theme: 'dark' });
  const html = template.replace('</head>', `${light.head}</head>`).replace('<div id="root"></div>',
    `<div id="root">${light.body}</div><template id="telvora-dark">${dark.body}</template>${themeBootstrap}<script id="telvora-prerender" type="application/json">${json(data)}</script>`);
  const file = path === '/' ? 'dist/index.html' : `dist/_prerender/${prerenderFileFor(path)}`;
  await writeFile(file, html);
  sizes[path] = Buffer.byteLength(html);
}
// Private routes get only an empty noindex shell. No order/admin components execute at build time.
const shell = template.replace('</head>', '<title>TELVORA</title><meta name="robots" content="noindex, nofollow" data-rh="true"></head>');
await writeFile('dist/client.html', shell);
await writeFile('dist/404.html', shell.replace('<div id="root"></div>', '<div id="root"><h1>Страница не найдена</h1><p>Запрошенная страница не существует.</p><a href="/catalog">В каталог</a></div>'));
await writeFile('dist/sitemap.xml', `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${routes.map(p => `  <url><loc>https://telvora.ru${p}</loc></url>`).join('\n')}\n</urlset>\n`);
await mkdir('seo-artifacts', { recursive: true });
const snapshotHash = createHash('sha256').update(json({ products, services: serviceSnapshot })).digest('hex');
const prerenderFiles = Object.fromEntries(routes.filter(p => p !== '/').map(p => [p, `/_prerender/${prerenderFileFor(p)}`]));
await writeFile('seo-artifacts/routes.json', JSON.stringify({ routes, productRoutes, prerenderFiles, sizes, snapshotHash }, null, 2));
await downloadProductImages(products);
const nginx = routes.map(p => `location = ${p} { try_files ${p === '/' ? '/index.html' : prerenderFiles[p]} =404; }${p === '/' ? '' : `\nlocation = ${p}/ { return 301 https://telvora.ru${p}$is_args$args; }`}`).join('\n');
await writeFile('seo-artifacts/routes.nginx.conf', `${nginx}\n${clientRoutes.map(p => `location = ${p} { try_files /client.html =404; }`).join('\n')}\nlocation ~ ^/order-success/[^/]+$ { try_files /client.html =404; }\nlocation = /televisions { return 301 https://telvora.ru/catalog$is_args$args; }\nlocation ^~ /_prerender/ { return 404; }\nlocation / { return 404; }\nerror_page 404 /404.html;\nlocation = /404.html { internal; }\nlocation = /client.html { internal; }\n`);
const escape = s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
await writeFile('seo-artifacts/routes.apache.conf', `Options -MultiViews\nRewriteEngine On\nRewriteRule ^televisions/?$ https://telvora.ru/catalog [R=301,L,NE]\n${routes.filter(p => p !== '/').map(p => `RewriteRule ^${escape(p.slice(1))}/$ https://telvora.ru${p} [R=301,L,NE]`).join('\n')}\nRewriteCond %{THE_REQUEST} \\s/+_prerender(?:[/\\s?]) [NC]\nRewriteRule ^_prerender(?:/|$) - [R=404,END]\n${routes.map(p => `RewriteRule ^${p === '/' ? '$ index.html' : escape(p.slice(1)) + '$ ' + (p === '/' ? 'index.html' : prerenderFiles[p].slice(1))} [END]`).join('\n')}\nRewriteRule ^(?:${clientRoutes.map(p => escape(p.slice(1))).join('|')}|order-success/[^/]+)$ client.html [END]\nRewriteCond %{REQUEST_FILENAME} -f [OR]\nRewriteCond %{REQUEST_FILENAME} -d\nRewriteRule ^ - [END]\nRewriteRule ^ - [R=404,END]\nErrorDocument 404 /404.html\n`);
console.log(`SEO build: ${routes.length} routes, ${products.length} active products; snapshot ${snapshotHash}`);
await import('./seo-release-inventory.mjs');

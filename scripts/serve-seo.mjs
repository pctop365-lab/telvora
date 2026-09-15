// Local validation server mirrors the generated route allowlist, not a production server.
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { resolve, extname } from 'node:path';
import { isClientRoute } from './seo-routes.mjs';
const { routes, prerenderFiles } = JSON.parse(await readFile('seo-artifacts/routes.json', 'utf8'));
const mime = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.xml': 'application/xml', '.svg': 'image/svg+xml', '.png': 'image/png', '.webp': 'image/webp', '.avif': 'image/avif', '.ico': 'image/x-icon', '.txt': 'text/plain' };
createServer(async (req, res) => {
  const url = new URL(req.url, 'http://127.0.0.1');
  const path = url.pathname;
  const normalized = path.replace(/\/$/, '') || '/';
  if (path === '/televisions' || path === '/televisions/' || path !== normalized && (routes.includes(normalized) || isClientRoute(normalized))) {
    res.writeHead(301, { Location: (path.startsWith('/televisions') ? '/catalog' : normalized) + url.search }); res.end(); return;
  }
  let file, status = 200;
  if (routes.includes(path)) file = path === '/' ? 'index.html' : prerenderFiles[path].slice(1);
  else if (isClientRoute(path)) file = 'client.html';
  else if (/^\/(assets|images)\//.test(path) || /^\/(?:favicon[^/]*|apple-touch-icon.png|telvora-logo.svg|robots.txt|sitemap.xml)$/.test(path)) file = path.slice(1);
  else { file = '404.html'; status = 404; }
  try {
    const absolute = resolve('dist', file);
    if (!absolute.startsWith(resolve('dist') + '\\') && !absolute.startsWith(resolve('dist') + '/')) throw Error('Invalid path');
    const data = url.searchParams.has('baseline') && routes.includes(path)
      ? await readFile('seo-artifacts/baseline/dist/index.html')
      : await readFile(absolute).catch(async error => {
        if (path.startsWith('/assets/')) return readFile(resolve('seo-artifacts/baseline/dist', file));
        throw error;
      });
    res.writeHead(status, { 'Content-Type': mime[extname(file)] || 'application/octet-stream' }); res.end(data);
  } catch { res.writeHead(404); res.end('Not found'); }
}).listen(4179, '127.0.0.1', () => console.log('SEO validation server http://127.0.0.1:4179'));

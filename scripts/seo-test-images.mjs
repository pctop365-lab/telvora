// Read-only download of the real gallery, kept outside deployment output.
import { readFile, writeFile, mkdir } from 'node:fs/promises';
const html = await readFile('dist/index.html', 'utf8');
const { products } = JSON.parse(html.match(/id="telvora-prerender" type="application\/json">(.*?)<\/script>/s)[1]);
await mkdir('seo-artifacts/images', { recursive: true });
for (const path of new Set(products.flatMap(p => p.images))) {
  if (!/^\/uploads\/products\/[a-zA-Z0-9_.-]+$/.test(path)) throw Error('Unexpected image path');
  const response = await fetch('https://telvora.ru' + path, { redirect: 'error', signal: AbortSignal.timeout(30000) });
  if (!response.ok) throw Error(`Image HTTP ${response.status}`);
  await writeFile('seo-artifacts/images/' + path.split('/').pop(), Buffer.from(await response.arrayBuffer()));
}
console.log('Real public gallery cached for local browser tests');

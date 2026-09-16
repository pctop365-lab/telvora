// Read-only download of the real gallery, kept outside deployment output.
import { readFile } from 'node:fs/promises';
import { downloadProductImages } from './seo-product-images.mjs';
const html = await readFile('dist/index.html', 'utf8');
const { products } = JSON.parse(html.match(/id="telvora-prerender" type="application\/json">(.*?)<\/script>/s)[1]);
await downloadProductImages(products);

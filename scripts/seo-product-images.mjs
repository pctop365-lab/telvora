import { mkdir, rm, writeFile } from 'node:fs/promises';

const imageName = imagePath => {
  if (!/^\/uploads\/products\/[a-zA-Z0-9_.-]+$/.test(imagePath)) {
    throw new Error(`Unexpected public product image path: ${imagePath}`);
  }
  return imagePath.split('/').pop();
};

// Product gallery files are generated from the public API snapshot on every SEO build.
// The cache is deliberately replaced so a clean build cannot retain stale images.
export async function downloadProductImages(products, { baseUrl = 'https://telvora.ru' } = {}) {
  const destinations = new Map();
  for (const product of products) {
    for (const imagePath of Array.isArray(product.images) ? product.images : []) {
      const name = imageName(imagePath);
      const previous = destinations.get(name);
      if (previous && previous !== imagePath) {
        throw new Error(`Product image filename collision: ${previous} and ${imagePath}`);
      }
      destinations.set(name, imagePath);
    }
  }

  await rm('seo-artifacts/images', { recursive: true, force: true });
  await mkdir('seo-artifacts/images', { recursive: true });
  for (const [name, imagePath] of destinations) {
    const response = await fetch(baseUrl + imagePath, {
      redirect: 'error',
      signal: AbortSignal.timeout(30000),
      headers: { Accept: 'image/avif,image/webp,image/*' },
    });
    if (!response.ok) throw new Error(`Product image HTTP ${response.status}: ${imagePath}`);
    const bytes = Buffer.from(await response.arrayBuffer());
    if (bytes.length === 0) throw new Error(`Product image is empty: ${imagePath}`);
    await writeFile(`seo-artifacts/images/${name}`, bytes);
  }
  console.log(`Generated ${destinations.size} public product image files for SEO tests`);
}

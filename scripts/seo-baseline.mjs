// Rebuild the user's completed Stage 1 commit for local size/visual comparisons only.
import { execFileSync } from 'node:child_process';
import { mkdir, writeFile, readdir, stat } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { build } from 'vite';
const commit = 'dc6e0510f8942474eba32da97488b3cbd51d296c';
const files = execFileSync('git', ['ls-tree', '-r', '--name-only', commit, 'src', 'index.html', 'public_contacts.json', 'package.json', 'vite.config.ts', 'tailwind.config.js', 'postcss.config.js', 'tsconfig.app.json'], { encoding: 'utf8' }).trim().split('\n');
for (const file of files) {
  const target = resolve('seo-artifacts/baseline', file);
  await mkdir(dirname(target), { recursive: true });
  await writeFile(target, execFileSync('git', ['show', `${commit}:${file}`], { maxBuffer: 10 * 1024 * 1024 }));
}
await build({ root: resolve('seo-artifacts/baseline'), configFile: resolve('seo-artifacts/baseline/vite.config.ts'), publicDir: false });
const sizes = {};
for (const file of ['index.html', ...(await readdir('seo-artifacts/baseline/dist/assets')).map(f => 'assets/' + f)]) sizes[file] = (await stat('seo-artifacts/baseline/dist/' + file)).size;
await writeFile('seo-artifacts/baseline-sizes.json', JSON.stringify(sizes, null, 2));
console.log(sizes);

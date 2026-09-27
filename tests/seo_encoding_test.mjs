import { readFile, readdir } from 'node:fs/promises';
import { join, relative } from 'node:path';

const root = 'dist';
const mojibake = /Р[А-Яа-яЁё]Р|С[Ѓ-џ][РС]|вЂ/g;

async function htmlFiles(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const files = [];
  for (const entry of entries) {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) files.push(...await htmlFiles(path));
    else if (entry.isFile() && entry.name.endsWith('.html')) files.push(path);
  }
  return files;
}

const files = await htmlFiles(root);
if (!files.length) throw new Error('SEO encoding test: no generated HTML files found');
const failures = [];
for (const file of files) {
  const html = await readFile(file, 'utf8');
  if (!/<meta\s+charset=["']UTF-8["']/i.test(html)) failures.push(`${relative('.', file)}: missing UTF-8 meta charset`);
  const matches = [...html.matchAll(mojibake)].slice(0, 3).map(match => match[0]);
  if (matches.length) failures.push(`${relative('.', file)}: mojibake signatures ${matches.join(', ')}`);
}
if (failures.length) {
  console.error(failures.join('\n'));
  process.exit(1);
}
console.log(`seo_encoding_test: PASS (${files.length} generated HTML files are UTF-8 and contain no mojibake signatures)`);

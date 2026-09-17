import { gzipSync } from 'node:zlib';
import { lstat, readFile, readdir } from 'node:fs/promises';
import { posix, resolve } from 'node:path';
import { writeFile } from 'node:fs/promises';

function field(buffer, offset, length, value, numeric = false) {
  const text = numeric ? `${Math.floor(value).toString(8)}\0` : String(value);
  buffer.write(text.slice(0, length).padEnd(length, '\0'), offset, length, 'ascii');
}

function header(name, size, directory) {
  const buffer = Buffer.alloc(512, 0);
  field(buffer, 0, 100, name);
  field(buffer, 100, 8, directory ? 0o755 : 0o644, true);
  field(buffer, 108, 8, 0, true);
  field(buffer, 116, 8, 0, true);
  field(buffer, 124, 12, directory ? 0 : size, true);
  field(buffer, 136, 12, 0, true);
  buffer.fill(0x20, 148, 156);
  buffer[156] = directory ? 0x35 : 0x30;
  buffer.write('ustar\0', 257, 6, 'ascii');
  buffer.write('00', 263, 2, 'ascii');
  field(buffer, 265, 32, 'root');
  field(buffer, 297, 32, 'root');
  const checksum = [...buffer].reduce((sum, byte) => sum + byte, 0);
  buffer.write(`${checksum.toString(8).padStart(6, '0')} \0`, 148, 8, 'ascii');
  return buffer;
}

async function entries(root, prefix = '') {
  const output = [];
  for (const entry of (await readdir(resolve(root, prefix), { withFileTypes: true })).sort((a, b) => a.name.localeCompare(b.name))) {
    const relative = posix.join(prefix, entry.name).replaceAll('\\', '/');
    const info = await lstat(resolve(root, relative));
    if (info.isSymbolicLink()) throw new Error(`Symlink is forbidden in archive: ${relative}`);
    if (info.isDirectory()) {
      output.push({ relative: `${relative}/`, directory: true });
      output.push(...await entries(root, relative));
    } else if (info.isFile()) output.push({ relative, directory: false });
    else throw new Error(`Unsupported archive file type: ${relative}`);
  }
  return output;
}

export async function createDeterministicTarGz(root, output) {
  const archiveEntries = await entries(root);
  const chunks = [];
  for (const entry of archiveEntries) {
    const data = entry.directory ? Buffer.alloc(0) : await readFile(resolve(root, entry.relative));
    chunks.push(header(entry.relative, data.length, entry.directory), data);
    const padding = (512 - (data.length % 512)) % 512;
    if (padding) chunks.push(Buffer.alloc(padding));
  }
  chunks.push(Buffer.alloc(1024));
  const compressed = gzipSync(Buffer.concat(chunks), { level: 9, mtime: 0 });
  // Normalize gzip OS marker for identical output across Node platforms.
  compressed[9] = 3;
  await writeFile(output, compressed);
}

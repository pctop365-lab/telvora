import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const build = await readFile(new URL('../scripts/build-seo.mjs', import.meta.url), 'utf8');
const batch = await readFile(new URL('../seo_publication_batch_service.php', import.meta.url), 'utf8');
const snapshot = await readFile(new URL('../seo_publication_snapshot_service.php', import.meta.url), 'utf8');
const worker = await readFile(new URL('../seo_publication_worker.php', import.meta.url), 'utf8');

assert.match(batch, /LIMIT \{\$limit\}/);
assert.match(batch, /ORDER BY j\.product_id ASC, j\.id ASC/);
assert.match(batch, /status = 'queued'/);
assert.match(batch, /status = 'running'/);
assert.match(snapshot, /publication_status = 'published'/);
assert.match(snapshot, /publication_status = 'pending_publish'/);
assert.match(snapshot, /publication_status = 'unpublish_failed' AND is_active = 1/);
assert.match(snapshot, /productActivationValidateCandidate/);
assert.match(snapshot, /snapshot_hash/);
assert.match(worker, /\$argv\[1\].*prepare/s);
assert.match(worker, /NO_WORK/);
assert.match(build, /TELVORA_SEO_SNAPSHOT_FILE/);
assert.match(build, /Invalid SEO snapshot file/);
assert.match(build, /publication_status === 'pending_publish'/);
assert.match(build, /https:\/\/telvora\.ru\/products\.php\?action=list/);

console.log('PASS SEO publication Phase 2A contract');

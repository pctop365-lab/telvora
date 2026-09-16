import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

const run = promisify(execFile);
const temp = await mkdtemp(join(tmpdir(), 'telvora-seo-report-'));
try {
  const currentOutput = join(temp, 'current.json');
  await run(process.execPath, ['scripts/seo-release-report.mjs', '--output', currentOutput]);
  const current = JSON.parse(await readFile(currentOutput, 'utf8'));
  assert.equal(current.baselineAvailable, false);
  assert.equal(current.activeProductCount, current.productRoutes.length);
  assert.equal(current.sitemapUrlCount, current.routes.length);
  assert.ok(current.prerenderInventory.length > 0);

  const firstRoute = current.routes.find((route) => route !== '/');
  const baseline = {
    snapshotHash: 'previous-snapshot',
    routes: [firstRoute, '/removed-old-route'],
    routeHashes: { [firstRoute]: 'previous-file-hash', '/removed-old-route': 'old-hash' },
  };
  const baselinePath = join(temp, 'baseline.json');
  const diffOutput = join(temp, 'diff.json');
  await writeFile(baselinePath, JSON.stringify(baseline));
  await run(process.execPath, ['scripts/seo-release-report.mjs', '--baseline', baselinePath, '--output', diffOutput]);
  const diff = JSON.parse(await readFile(diffOutput, 'utf8'));
  assert.equal(diff.baselineAvailable, true);
  assert.ok(diff.addedRoutes.includes('/'));
  assert.ok(diff.removedRoutes.includes('/removed-old-route'));
  assert.ok(diff.changedRoutes.includes(firstRoute));
  assert.equal(diff.snapshotChanged, true);
  console.log('seo_release_report_test: PASS');
} finally {
  await rm(temp, { recursive: true, force: true });
}

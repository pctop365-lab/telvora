import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import YAML from 'yaml';

const files = ['.github/workflows/seo-release.yml', '.github/workflows/seo-staging-transport.yml', '.github/workflows/seo-production-deploy.yml'];
for (const file of files) {
  const text = await readFile(file, 'utf8');
  const workflow = YAML.parse(text);
  assert.ok(workflow.jobs, `${file}: jobs missing`);
  assert.doesNotMatch(text, /runs-on:\s+ubuntu-latest/);
  assert.match(text, /runs-on:\s+ubuntu-24\.04/);
  assert.doesNotMatch(text, /actions\/(?:checkout|setup-node)@v4/);
  assert.match(text, /actions\/checkout@v5/);
  assert.match(text, /actions\/setup-node@v5/);
  assert.doesNotMatch(text, /StrictHostKeyChecking=no|ssh-keyscan/);
  for (const job of Object.values(workflow.jobs)) assert.ok(job['runs-on'], `${file}: job has no runner`);
}
const production = await readFile('.github/workflows/seo-production-deploy.yml', 'utf8');
assert.match(production, /GITHUB_RUN_ID/);
assert.match(production, /STAGE_ID/);
assert.match(production, /Backup path/);
assert.match(production, /PRODUCTION ACTIVATION/);
const staging = await readFile('.github/workflows/seo-staging-transport.yml', 'utf8');
assert.match(staging, /Staging ID/);
console.log('seo_workflow_maintenance_test: PASS');

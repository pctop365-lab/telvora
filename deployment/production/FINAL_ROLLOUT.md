# TELVORA final production rollout runbook

This is an operator runbook. It does not execute a migration or deployment.
Use a disposable Ubuntu runner for `final-preflight.sh` first. Do not place
production secrets in GitHub build jobs; SSH and DB commands below run only on
the protected production host.

## Ordered rollout

1. Verify `git rev-parse HEAD` is `976cff2687bda60a94966e9cd2d2cebf23c0910c`,
   the tree contains only the known untracked cleanup files, and the protected
   `seo-production` environment is approved by its reviewer.
2. Run `deployment/production/final-preflight.sh` on Ubuntu. It lints the
   explicit PHP manifest, backend script and migration helper, runs existing
   contract/build checks, and confirms `mysqldump` exists. Stop on failure.
3. On production, verify PHP CLI, `mysqldump`, the canonical DocumentRoot
   `/var/www/u3609206/data/www/telvora.ru`, and private writable directories
   `/var/www/u3609206/data/telvora-db-backups` and
   `/var/www/u3609206/data/backend-backups`.
4. Copy `deployment/production/backup-database.php` and its runtime-config
   dependency to a private directory outside DocumentRoot. Run
   `php backup-database.php --preflight`, then `php backup-database.php --backup`.
   Record JSON `backup_reference`, `sha256`, and `size`; verify the file exists
   and is non-empty.
5. Only after that backup succeeds, copy the exact migration and runner to a
   private directory and run `php apply-seo-publication-migration.php --apply`.
   The runner accepts only the hard-coded migration and SHA-256
   `59829574ea7a605a4a70c77dbbe4255a3d141c72ddb33e006a23ad8340f5e56c6`.
6. Verify publication columns/index, jobs table, unique intent key, FK, active
   and inactive backfill, and unchanged `is_active` counts. If verification
   fails, stop and use the recorded DB backup only through operator recovery.
7. Run manual `Backend Production Deploy`. It uses only the explicit backend
   manifest and `deploy-backend.sh`; record the backend backup reference. The
   SEO deploy engine remains static-only and still rejects PHP.
8. Run the existing protected SEO production preflight/activation workflow for
   current main. Record static backup, checksum, plan, smoke, rollback and
   Yandex results.
9. Read-only smoke checks: public products list, authenticated admin list,
   home/catalog, existing LG C6 route, and admin login. Do not start a canary
   until all checks pass.

## Canary sequence

For one safe test product, record id/slug/is_active/status/revision. Call
`request_publish` with the current revision, verify pending state and one queued
job, manually run `SEO Autopublish`, then verify completed job, published state,
unchanged revision, API visibility, route/canonical, prerender and sitemap.
Repeat with `request_unpublish`, verifying pending unpublish first and then
draft/inactive/API and sitemap removal. Finally queue 2–3 safe intents without a
run and execute exactly one autopublish run; verify one batch, snapshot, package
and deploy and all jobs completed.

## Failure handling

Migration verification failure stops backend/static deployment. Backend failure
uses the PHP deploy script's exact-file backup rollback; do not automatically
restore the database. Static failure uses the existing SEO engine rollback; the
backend remains installed and no canary starts. If static activation succeeds
but batch completion fails, preserve the working release and handle the explicit
`DEPLOY_SUCCEEDED_FINALIZATION_FAILED` operator state; do not start a second
deploy blindly.

## Schedule gate

Keep `seo-autopublish.yml` manual-only through both canaries and the 2–3 product
batch. Only after those results are recorded should an approved change add a
UTC five-minute cron while retaining `workflow_dispatch` and
`cancel-in-progress: false`.

# TELVORA SEO operations runbook

This runbook describes the controlled SEO release process. It does not grant
production access and it does not replace the protected GitHub environment.

## Normal staging validation

Run **SEO Staging Transport** manually. It builds the requested commit,
creates the immutable package, uploads it below
`/var/www/u3609206/data/staging/`, verifies the archive checksum and runs the
package validator. The staging workflow does not invoke activation.

## Production preflight and approval

Run **SEO Production Deploy** with a full release SHA and the exact
confirmation `DEPLOY TELVORA SEO PRODUCTION`. The preflight job builds the
same immutable release, performs a remote dry-run, and publishes ADD,
REPLACE, REMOVE and activation-order details in its Step Summary. Review that
summary. Only the `seo-production` environment approval can make the
activation job eligible.

## Successful deployment verification

The activation job uses `deployment/seo-stage2/deploy-release.sh`, creates a
backup below `/var/www/u3609206/data/telvora-backups/`, activates `.htaccess`
last, verifies the Yandex file and runs read-only HTTP smoke tests. The
summary records the release SHA, package checksum, staging ID, backup name,
activation, smoke and rollback results.

## Finding the backup for a release

Use the backup name recorded in the activation Step Summary. Backups have the
engine format `YYYYMMDDTHHMMSSZ-<12-hex-commit>-<12-hex-snapshot>`. Do not
guess a backup or use the newest directory implicitly.

## Manual rollback

From the exact staged engine directory, use the recorded backup directory and
the canonical target paths:

```bash
bash /var/www/u3609206/data/staging/<stage-id>/deploy-release.sh \
  --rollback /var/www/u3609206/data/telvora-backups/<exact-backup> \
  --document-root /var/www/u3609206/data/www/telvora.ru \
  --staging-root /var/www/u3609206/data/staging/<stage-id>/rollback \
  --backup-root /var/www/u3609206/data/telvora-backups
```

The engine validates the backup manifest, uses the production lock and
restores only recorded managed files. Never substitute a different backup.

## If smoke tests fail

The protected workflow invokes the engine rollback with the exact backup. If
that rollback fails, stop further releases, preserve the logs and use the
manual command above after checking the lock and backup manifest. Do not edit
`.htaccess` or delete release files by hand while a deployment is running.

## Retention and asset inspection

The following commands are dry-run by default and are not part of production
activation:

```bash
python3 deployment/seo-stage2/seo-ops.py staging-retention \
  --root /var/www/u3609206/data/staging --keep 5
python3 deployment/seo-stage2/seo-ops.py backup-retention \
  --root /var/www/u3609206/data/telvora-backups --keep 10
python3 deployment/seo-stage2/seo-ops.py assets-report \
  --root /var/www/u3609206/data/www/telvora.ru
```

Deletion requires an explicit `--execute` and the matching confirmation. No
cleanup is enabled by the GitHub workflows in Phase 2.5. The asset report is
read-only and lists only unreferenced hashed JS/CSS candidates.

## Never delete or overwrite

The SEO package owns only its allowlisted static files, `assets/`, `images/`,
`_prerender/` and generated `.htaccess`. Preserve PHP/backend files,
`uploads/`, PDF and Telegram runtime files, secrets/configuration,
`public_contacts.json`, customer/order data, unrelated backups and the Yandex
verification file. Never use `rm -rf` on DocumentRoot, `rsync --delete`,
`TRUNCATE`, or wildcard deletion.

## Emergency stop and lock handling

Only one production deployment may run because the engine locks
`dirname(DocumentRoot)/.telvora-seo-deploy.lock` with `flock`. Do not remove
the lock while a process is active. If a process is interrupted, inspect the
process and logs, then use the exact backup and rollback command after the
lock is released.

## SSH key rotation

Create a replacement key through the hosting provider, add its public key,
update `TELVORA_SSH_PRIVATE_KEY` and the pinned
`TELVORA_SSH_KNOWN_HOSTS` in the appropriate GitHub environment, run a
staging transport verification, and revoke the old key only after that check
passes. Never disable host-key checking or use `ssh-keyscan` in the workflow.

## Non-secret deployment record

For each approved run retain the GitHub run URL and Step Summary values:
requested/actual release SHA, package SHA-256, run ID, staging ID, backup
name, activation result, smoke result and rollback result. This is a file or
ticket record only; it introduces no database dependency.

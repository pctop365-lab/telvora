# SEO publication finalization

Manual deployment previously activated HTML but never completed queued publication jobs. Both workflows now bind exact private publication intents to the immutable archive and call the existing SSH-authorized CLI worker only after activation and successful smoke checks.

## Proof and transaction

1. Manual capture uses a read-only consistent database snapshot; autopublish captures the claimed running batch. Each intent contains product ID, job ID, operation, revision, slug, captured product fingerprint/timestamps and task metadata. The build resolves the route from the same production normalization rules.
2. The package manifest contains the exact intents, commit SHA and snapshot hash. Archive SHA-256 binds the manifest. Private identities are not included in public HTML.
3. Finalization holds the same server flock as the deploy engine. It checks archive/extracted manifest equality, active release identity, complete managed inventory and production file hashes. Every publish route must have the exact product identity, sitemap entry and HTTP 200 body hash; unpublish requires absence and HTTP 404. A newer active record rejects old evidence.
4. Within one transaction, lock products first and all their jobs. Require captured revision, task identity/state, product contents and timestamps; reject newer or competing tasks. Recheck production evidence before writing.
5. Update only publication state and exact jobs, and insert the audit atomically. Preserve revision, job attempt count and started_at. Any mismatch or audit failure rolls back everything. A repeated identical completion is a no-op authenticated by the existing audit.
6. Build/upload/activation/smoke failure cannot call finalization. If finalization fails after successful deployment, keep the live release and retry finalization with the same immutable evidence; do not redeploy merely to update statuses.

## Rollout order (not executed)

Pause scheduling new SEO runs during rollout. Do not cancel a running publication batch.
1. Deploy the approved backend manifest, including both new services before products.php and the updated worker/snapshot service. This does not modify product data.
2. Stage the approved backend PHP files at /var/www/u3609206/data/staging/seo-finalization-migration/ (flat root, preserving their names). Also stage deployment/production/apply-seo-finalization-migration.php and database/migrations/20261009_015_seo_publication_finalization_audit.sql below that root. The staged runtime_config.php resolves the existing /var/www/u3609206/data/telvora_runtime secrets. Do not publish the SQL in DocumentRoot.
3. After separate approval, run the additive migration on the server:
   php /var/www/u3609206/data/staging/seo-finalization-migration/deployment/production/apply-seo-finalization-migration.php --apply
   This creates only the audit table; it does not reconcile products or jobs.
4. Upload the private reviewed plan to /var/www/u3609206/data/seo-runtime/reconciliation-reviewed-plan.json, owner-readable only. Verify its SHA-256 equals the pin below. The diagnostic folder and reviewed plan must not be committed.
5. Make the updated workflows available only after backend and audit schema are ready. Run reconciliation dry-run. Expect DRY_RUN, verified_jobs=54, changed_jobs=0, would_change_jobs=54 if evidence is still current.
6. Apply only after review and explicit authorization. Expect COMPLETED/54; repeat returns ALREADY_COMPLETED/0. Review the audit and admin statuses. Resume scheduled publication.
No additional SEO deployment is necessary for the existing 54 pages. Future manual and automatic releases use the new finalizer.

The allowlist is fixed to product 14/job22, products16–67/jobs23–74, product68/job21; publish revision1 only. Old manifests without intents are accepted only for this pinned reconciliation plan. A newer release, edited product, changed job, missing page, wrong hash, or any other mismatch makes the entire command fail without writes. Do not bypass the guard; obtain fresh diagnostic evidence.

## Exact server commands (Bash, existing SSH account)

Initialize arguments:
```bash
stage=/var/www/u3609206/data/staging/telvora-seo-5d2b3a5f208c4242fca5542f4b9492cb31bc5615-37535859650
reconcile_args=(
  --plan=/var/www/u3609206/data/seo-runtime/reconciliation-reviewed-plan.json
  --plan-sha256=737cbbcc66433e7521a1d5303b8d6a51c2795fd190ca028b9c5270eefdbad7e1
  --active-record="$stage/engine-live/active-release.json"
  --archive="$stage/release.tar.gz"
  --package-root="$stage/validated"
  --release-sha=5d2b3a5f208c4242fca5542f4b9492cb31bc5615
  --package-sha256=8be69438d298847e69bcdd31261642ce808869465ddb414a5e6089328780eed0
  --snapshot-hash=4fdeee9872635a0adbaa403a1df647c149f37d77adeea8fe817548099ef0a107
  --smoke-result=PASS
)
```

Default dry-run (no data or audit writes):
```bash
php /var/www/u3609206/data/www/telvora.ru/seo_publication_worker.php reconcile "${reconcile_args[@]}"
```

Separate apply command, only after approval:
```bash
php /var/www/u3609206/data/www/telvora.ru/seo_publication_worker.php reconcile "${reconcile_args[@]}" --apply=yes
```

Even dry-run requires the deployed backend and audit table. Commands are intentionally pinned to the diagnosed release; they must fail if production has changed.

## Validation

PHP state/API contracts, MySQL concurrency, snapshot capture, phase2a/phase2b fixtures and release integration cover success, idempotency, revision/new-task conflict, archive/release/snapshot/hash mismatch, failed smoke, partial package, dry-run, unpublish and audit rollback. Typecheck, workflow contracts and Bash syntax, SEO intent mapping, build/package validation and raw/encoding/physical/image/htaccess/deploy tests must pass. Local Windows deploy tests skip Linux flock concurrency; run that existing test on Linux CI.

## Exact commit allowlist

Use explicit file paths, never git add . or git add -A:
```text
.github/workflows/seo-autopublish.yml
.github/workflows/seo-production-deploy.yml
.gitignore
deployment/backend/backend-manifest.txt
deployment/production/final-preflight.sh
package.json
products.php
scripts/build-seo.mjs
scripts/seo-package.mjs
scripts/seo-validate-package.mjs
seo_publication_snapshot_service.php
seo_publication_worker.php
tests/product_variant_model_code_contract_test.mjs
tests/seo_deploy_engine_test.mjs
tests/seo_publication_mysql_concurrency_test.php
tests/seo_publication_phase2a_mysql_smoke_test.php
tests/seo_publication_phase2b_e2e_fixture.php
tests/seo_publication_phase2c_contract_test.mjs
database/migrations/20261009_015_seo_publication_finalization_audit.sql
deployment/production/apply-seo-finalization-migration.php
scripts/prepare-seo-reconciliation.mjs
seo_publication_release_service.php
storefront_product_service.php
tests/seo_publication_capture_test.php
tests/seo_publication_intents_test.mjs
tests/seo_publication_release_contract_test.mjs
tests/seo_publication_release_mysql_test.php
deployment/production/SEO_PUBLICATION_FINALIZATION.md
```
Exclude variant_model_backend_diff.txt, existing unrelated untracked files, diagnostic evidence and generated build/package outputs.

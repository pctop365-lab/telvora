# SEO Stage 2 deployment-readiness package

## Phase 0/1 build-only automation

The repository contains `.github/workflows/seo-release.yml`. It is intentionally a build-only workflow: it never connects to REG.RU, never deploys files and requires no GitHub Secrets.

Run it manually in GitHub: **Actions → SEO Release (build only) → Run workflow**. The workflow checks out the exact commit, installs Node 20 dependencies, fetches only the two public read-only endpoints used by `build:seo`, runs the SEO build and contract/raw/physical/htaccess/hydration tests, scans release inputs for obvious secrets, and uploads an immutable artifact named with the commit SHA and run number.

The artifact can be downloaded from the completed workflow run. It contains `dist/`, the proposed `.htaccess`, the pre-activation layout check, generated route manifests and release metadata. It is preparation for a later controlled deployment; downloading it does not change production.

`npm run seo:report` writes `seo-artifacts/seo-release-report.json` and prints active product count, product routes, added/removed/changed routes, snapshot hash, prerender inventory and sitemap URL count. Pass `--baseline <previous-report.json>` (or set `SEO_BASELINE`) to obtain a route/hash diff. Without a baseline it reports the current inventory and does not fail.

The workflow intentionally has no enabled schedule in Phase 1. A schedule can be added later as a fallback after the build-only process is trusted.

## Phase 2.1 local release package

Phase 2.1 remains build-only. It does not connect to REG.RU and does not deploy.

Run:

```text
npm run seo:package
npm run seo:validate-package
```

The package is written to `seo-release/` and archived as
`seo-artifacts/telvora-seo-release-<release-id>.tar.gz`. Its structure is:

```text
payload/
  index.html, client.html, 404.html
  robots.txt, sitemap.xml
  favicon/logo files
  assets/
  images/
  _prerender/
production.htaccess
tools/pre-activation-layout-check.sh
deployment-manifest.json
snapshot.json
checksums.sha256
```

The generated `production.htaccess` is rebuilt from the same route inventory as
`build:seo`. It is not the static template
`deployment/seo-stage2/production.htaccess.final`. New active products are
therefore added automatically and removed products disappear from the generated
route rules.

The production allowlist is limited to the listed static root files,
`assets/`, `images/`, `_prerender/` and the generated `.htaccess`. PHP,
`uploads/`, PDF, Telegram, runtime, logs, secrets, database configuration,
customer/order data and unrelated files are forbidden. The test-only
`seo-artifacts/images/` cache is never packaged.

`deployment-manifest.json` records the schema, release ID, commit, timestamp,
snapshot hash, route/product counts, route-to-prerender mapping, managed paths
and SHA-256 inventory. `checksums.sha256` is sorted and covers every package
file except itself. The validator rejects checksum changes, missing/extra files,
symlinks, unsafe paths, PHP or other forbidden content, missing prerenders,
incorrect sitemap metadata and incomplete noindex/indexability markers.

The manifest's `managedFiles` list is sufficient for a future deployment to
calculate add/replace/remove sets. Deactivated product prerenders must be
removed during a future controlled activation; Phase 2.1 performs no deletion
on production.

The package carries its own `tools/pre-activation-layout-check.sh` outside
`payload/`. Its checksum is part of the package integrity set, so a future
deployment validates the exact tool shipped with the immutable release rather
than an arbitrary script from a newer checkout. The tool is never copied to
DocumentRoot.

The GitHub Actions build-only workflow now runs package creation and validation
and uploads only the immutable package archive plus the release report. It still
never uses SSH, credentials or production deployment.

This directory is preparation only. It does not contain a complete vhost and must not replace an ISPmanager/REG.RU server configuration.

## Current local evidence

- Production edge is nginx; REG.RU/ISPmanager has confirmed Apache 2.4.37 behind it in FastCGI (Apache) mode.
- Root-owned nginx vhost files remain unreadable to the hosting user. This package therefore targets the user-owned Apache DocumentRoot `.htaccess` layer and does not assume permission to edit nginx.
- The existing production behavior observed before this package was a global SPA fallback: valid and unknown frontend paths returned the same root HTML with HTTP 200.
- The production `.htaccess` is the supported routing insertion point. The root-owned nginx vhost still must not be replaced; preserve its existing upstream and security behavior.

## Files

- `nginx-edge-redirects.fragment.conf`: insert only the two edge `location /` redirects into existing HTTP and HTTPS-www vhosts. Preserve ACME and TLS directives.
- `nginx-canonical-routing.fragment.conf`: insertion point for the generated route include in the existing canonical HTTPS vhost.
- `../../seo-artifacts/routes.nginx.conf`: generated known-route/client-shell/404 rules.
- `../../seo-artifacts/routes.apache.conf`: conditional Apache alternative only; do not use until Apache and `.htaccess` are confirmed.
- `production.htaccess.final`: complete proposed user-owned `.htaccess`, including the existing canonical redirect, route allowlist, client shells and real 404.
- `post-deploy-verification.ps1`: read-only curl matrix; set `$BaseUrl` only for a validation endpoint and never add credentials.
- `pre-activation-layout-check.sh`: read-only DocumentRoot check; run it after upload and before replacing `.htaccess`.

## Required server read before any change

1. Confirm the actual DocumentRoot and retain the provider nginx upstream; do not replace its root-owned vhost.
2. Capture current `.htaccess`, index, robots, sitemap and assets backups/hashes.
3. Confirm PHP/API/FastCGI, upload restrictions, ACME challenge, admin/security denies and static asset behavior remain outside the new fallback.
4. Confirm Apache 2.4 `mod_rewrite`, `AllowOverride FileInfo` and `ErrorDocument` support for the DocumentRoot.
5. Read the current `.htaccess`, calculate its SHA-256, upload `_prerender` and static files, run `pre-activation-layout-check.sh`, then replace `.htaccess` only after the check passes.
6. Verify the www certificate is valid and covers both `telvora.ru` and `www.telvora.ru`.

## Safe insertion rules

The final `.htaccess` must be installed in the user-owned DocumentRoot only after the preserved PHP/API/security behavior has been reviewed. It must not replace the nginx vhost. Public prerender HTML is stored flat under `/_prerender/`; no `dist/catalog/`, `dist/delivery/`, product-route directory or other public route directory may be uploaded. This avoids REG.RU/Apache `DirectorySlash` redirects before `.htaccess` can serve the intended slashless route.

The internal namespace is blocked for direct requests with `THE_REQUEST`, while internal rewrites from public URLs remain allowed. Every prerendered file retains its public canonical URL.

The HTTP and HTTPS-www fragments preserve path and query through `$request_uri`. The canonical server must continue serving PHP/API endpoints and secured uploads normally.

## Route contract

Known prerender routes are generated from the build snapshot: `/`, `/catalog`, `/catalog/oled`, `/catalog/qled`, `/catalog/led`, `/catalog/8k`, `/delivery`, `/services`, `/warranty`, `/returns`, `/support`, `/contacts`, `/requisites`, `/offer`, `/privacy`, `/personal-data-consent`, `/cookies`, plus every active `/catalog/<category>/<product-slug>` route in the current build.

Client-only routes are `/checkout`, `/admin`, `/soundbars`, `/accessories`, and `/order-success/<id>`. They serve `client.html` with `noindex` and no order/customer snapshot.

Unknown routes and direct `/_prerender/*` requests must serve `404.html` with actual HTTP status 404. A successful body with status 200 is a failed deployment even if the body visually says “not found”.

## Rollback

Before deployment, save the current `.htaccess`, DocumentRoot index/sitemap/robots/assets and their hashes. Verify that no forbidden public route directories exist after upload. Upload `_prerender/` and normal static assets first; activate `.htaccess` last. If smoke tests fail, restore the saved `.htaccess` first, then restore the previous static release atomically. Re-test canonical redirects, PHP/API, uploads, ACME, known routes, direct `/_prerender/*` and unknown 404 before reopening traffic.

## Phase 2.2A local staging engine

`deployment/seo-stage2/deploy-release.sh` is a transport-free Linux/Bash engine for a future controlled activation. It accepts an immutable package archive and explicit `--staging-root`, `--document-root` and `--backup-root` paths; it never assumes REG.RU paths and has no SSH/SFTP logic.

```text
deploy-release.sh --archive RELEASE.tar.gz \
  --staging-root BASE/staging/telvora-seo \
  --document-root DOCUMENT_ROOT \
  --backup-root BASE/backups/telvora-seo --dry-run
```

The engine extracts to `staging-root/<release-id>/`, runs the validator shipped inside that exact package, and writes `activation-plan.json`. Dry-run performs no DocumentRoot mutation. A local-fixture activation creates a timestamped backup containing only affected managed files, activates assets and prerenders before HTML and `.htaccess` last, and writes `active-release.json` outside DocumentRoot. The managed allowlist comes from the package manifest; PHP/backend files, uploads, runtime data, Telegram files and secrets are rejected or preserved. Removed files are limited to the previously owned `_prerender/` namespace.

On activation failure the engine restores the backup manifest and removes only newly created managed files. It records any parent directories created by the release and removes them only when still empty. `--rollback BACKUP_DIR` repeats that restoration without touching unmanaged content. A `flock` lock outside DocumentRoot rejects concurrent activation on Linux. Failure injection (`--failure-point assets`, `prerender`, `root`, or `htaccess`) exists for temporary-fixture tests only. This phase intentionally does not upload, connect to REG.RU, create secrets, or perform production activation.

Failure injection is rejected unless `TELVORA_DEPLOY_TEST_MODE=1` is set. The
Ubuntu build-only workflow runs the engine fixture suite and both Bash syntax
checks before uploading the immutable artifact; it still performs no network
transport or production activation.

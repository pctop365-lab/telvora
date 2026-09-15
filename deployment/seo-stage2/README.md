# SEO Stage 2 deployment-readiness package

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

## Required server read before any change

1. Confirm the actual DocumentRoot and retain the provider nginx upstream; do not replace its root-owned vhost.
2. Capture current `.htaccess`, index, robots, sitemap and assets backups/hashes.
3. Confirm PHP/API/FastCGI, upload restrictions, ACME challenge, admin/security denies and static asset behavior remain outside the new fallback.
4. Confirm Apache 2.4 `mod_rewrite`, `AllowOverride FileInfo` and `ErrorDocument` support for the DocumentRoot.
5. Read the current `.htaccess`, calculate its SHA-256, then replace it only after the static files are uploaded.
6. Verify the www certificate is valid and covers both `telvora.ru` and `www.telvora.ru`.

## Safe insertion rules

The final `.htaccess` must be installed in the user-owned DocumentRoot only after the preserved PHP/API/security behavior has been reviewed. It must not replace the nginx vhost. The final catch-all returns 404 and is reached only after known routes, client shells and real files/directories.

The HTTP and HTTPS-www fragments preserve path and query through `$request_uri`. The canonical server must continue serving PHP/API endpoints and secured uploads normally.

## Route contract

Known prerender routes are generated from the build snapshot: `/`, `/catalog`, `/catalog/oled`, `/catalog/qled`, `/catalog/led`, `/catalog/8k`, `/delivery`, `/services`, `/warranty`, `/returns`, `/support`, `/contacts`, `/requisites`, `/offer`, `/privacy`, `/personal-data-consent`, `/cookies`, plus every active `/catalog/<category>/<product-slug>` route in the current build.

Client-only routes are `/checkout`, `/admin`, `/soundbars`, `/accessories`, and `/order-success/<id>`. They serve `client.html` with `noindex` and no order/customer snapshot.

Unknown routes must serve `404.html` with actual HTTP status 404. A successful body with status 200 is a failed deployment even if the body visually says “not found”.

## Rollback

Before deployment, save the current vhost fragments, `.htaccess` (if active), DocumentRoot index/sitemap/robots/assets and their hashes. If smoke tests fail, restore the saved server fragment first, then restore the previous static release atomically. Re-test canonical redirects, PHP/API, uploads, ACME, known routes and unknown 404 before reopening traffic.

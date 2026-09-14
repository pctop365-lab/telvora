# TELVORA SEO release: Stage 1 + Stage 2

Deployment and push are intentionally not performed in this task. Deploy only from the final clean commit after an independent production backup and hash check.

## Release contents

Stage 1 technical SEO foundation remains included: canonical `https://telvora.ru`, robots and sitemap policy, factual Organization/Product/Offer/Breadcrumb structured data, and noindex rules for checkout, order-success, admin, inactive products and unknown client states.

Stage 2 adds deterministic selective prerendering and an allowlisted HTTP route policy. `npm run build:seo` fetches only anonymous public `products.php?action=list` and `services.php` JSON, keeps active products, builds the React SSR snapshot, writes route HTML, sitemap, `client.html`, `404.html`, and the generated route includes.

## Prerender route set

`/`, `/catalog`, `/catalog/oled`, `/catalog/qled`, `/catalog/led`, `/catalog/8k`, `/delivery`, `/services`, `/warranty`, `/returns`, `/support`, `/contacts`, `/requisites`, `/offer`, `/privacy`, `/personal-data-consent`, `/cookies`, plus every active product route derived from the public API. The current build discovered `/catalog/oled/lg-oled77c5rla`.

Checkout, admin, soundbars, accessories and `/order-success/<id>` receive a noindex client shell with no customer or order snapshot. They are excluded from the sitemap.

## Product freshness and sitemap

The build API response is an immutable release snapshot. It contains only public allowlisted product fields and no credentials. Every production build must rerun `npm run build:seo`; an inactive product then disappears from both the generated route set and sitemap, and a newly active product is added. Until that build is deployed, the previous static release remains stale by design. The build fails on an incomplete API response instead of publishing an empty or unsafe catalog.

## Server changes to deploy manually

Use the generated `seo-artifacts/routes.nginx.conf` in the existing canonical HTTPS vhost, or `seo-artifacts/routes.apache.conf` in the existing Apache document root. Do not install both route handlers. The exact host examples are in [deployment/seo-stage2/nginx-hosts.conf.example](deployment/seo-stage2/nginx-hosts.conf.example) and [deployment/seo-stage2/apache-hosts.conf.example](deployment/seo-stage2/apache-hosts.conf.example).

At the edge, redirect `http://telvora.ru/*`, `http://www.telvora.ru/*`, and `https://www.telvora.ru/*` with 301 to `https://telvora.ru/*`, preserving path and query string. The HTTPS www redirect requires a valid provider certificate covering `www.telvora.ru`; if REG.RU does not provide that certificate or DNS/vhost, this is the remaining infrastructure blocker and must be resolved there. The Russian redirect-domain configuration is untouched.

The canonical host must serve only the generated known route files, private noindex shells, static assets, and backend/security exceptions. Unknown paths return `/404.html` with HTTP status 404. Do not retain the old catch-all SPA rewrite or an `error_page 404 =200` rule. Preserve existing PHP, upload, ACME and security locations.

## Verification

Required commands:

```text
npm run typecheck
node tests/seo_contract_test.mjs
npm run build:seo
npm run test:seo:raw
npx playwright test --config playwright.seo.config.ts
npx playwright test tests/browser/seo.spec.ts
git diff --check
```

The local route server validates 200 for every generated route, 404 for unknown routes, and noindex 200 shells for private client routes. The browser suite covers direct refresh, SPA navigation, cart restoration, product gallery, mobile/desktop, light/dark, hydration, duplicate metadata/JSON-LD and console errors.

## Release evidence

Current successful build: 18 prerender routes, 1 active product, snapshot `b054f40644069946655f263d3ac3e80ef8202b5f2cd3b1f61769a32294cccc6a`, release inventory 26 files. The inventory is generated at `seo-artifacts/release-inventory.json` and is intentionally ignored from source control as build output; regenerate it for the commit being deployed.

The existing hero PNG size warning remains a separate optimization item. No business, legal, pricing, checkout, Telegram, PDF, DB schema, admin or visual design changes are part of this release.

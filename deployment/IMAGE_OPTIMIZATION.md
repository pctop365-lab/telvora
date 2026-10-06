# Product image optimization — local verification, 2026-10-05

No production files, products, prices or variant countries were changed. No commit,
push or deployment was performed. Existing originals retain their URLs.

## Resumed verification — 2026-10-06

Existing working-tree changes were retained. All local checks passed: image-service
encoding/EXIF/transparency/quality checks, 45 real multipart uploads and SQLite
gallery persistence, gallery unit tests, frontend/HTTP/conversion/deployment
contracts, TypeScript, PHP syntax, `git diff --check`, and the production Vite build.
All eight browser tests passed (six existing scenarios plus two added scenarios).
Network byte measurements below were reproduced exactly.

The added tests verify actual decoding of a legacy original without gallery or
derivative metadata in catalog, detail and zoom, and recovery to the original when
a derivative returns a deliberately simulated 404. Normal fixture requests had no
broken URLs. No implementation failure was found during this resumed verification.

The first browser launch was blocked by filesystem sandbox access to Vite's parent
directory; rerunning with permission succeeded. Build warnings about Browserslist
data and a JavaScript chunk over 500 kB are non-blocking. Production web-PHP limits
and live CDN/network behavior remain unverified; the checks use local fixtures.

Files belonging to this change (explicit commit scope; do not stage all untracked files):

- `product_image_service.php`, `product_gallery_service.php`, `products.php`,
  `seo_publication_snapshot_service.php`
- `src/components/ProductImage.tsx`, `src/components/ProductCard.tsx`,
  `src/components/ProductDetail.tsx`, `src/components/ProductGallery.tsx`,
  `src/components/ProductGrid.tsx`, `src/pages/AdminPage.tsx`,
  `src/services/productService.ts`, `src/types/index.ts`
- `tests/product_gallery_frontend_test.mjs`, `tests/product_gallery_http_contract_test.mjs`,
  `tests/product_gallery_service_test.php`, `tests/product_image_service_test.php`,
  `tests/product_image_upload_test.php`, `tests/fixtures/product_image_upload_router.php`,
  `tests/browser/admin_image_upload.spec.ts`, `tests/browser/product_image_network.spec.ts`
- `deployment/backend/backend-manifest.txt`, `deployment/IMAGE_OPTIMIZATION.md`

Unrelated untracked files were left untouched: `deployment/hotfix-track-order.sh`,
`sales-accounting-preview/`, `scripts/PRODUCT_TEST_CLEANUP.md`,
`scripts/product_cleanup_common.php`, `scripts/product_test_cleanup.php`,
`scripts/product_test_diagnostic.php`, and `variant_model_backend_diff.txt`.
Generated fixtures/reports and `dist/` are not part of the commit scope.

## Behavior

- Up to 45 gallery images; the admin sends one file per request and retains
  completed uploads when a later request fails. Retry sends only remaining files.
- PHP creates WebP plus JPEG (JPEG originals) or PNG (PNG/WebP originals, retaining
  transparency). Long-edge limits: 160, 320, 480, 800, 1280, 1920 and 2560 pixels.
  A small original is never enlarged. EXIF orientation is applied before resizing.
- Original paths remain the database values. Derivatives and a JSON sidecar live
  alongside the original. Sidecar publication happens after successful encoding.
  Missing/incomplete metadata falls back to the original, including old single-photo products.
- Catalog uses only the first image, with candidates up to 800 pixels.
  The product page renders one selected large image, lazy thumbnails up to 320 pixels,
  and creates the zoom image only when opened. Main images are eager.
- API normalization and SEO publication snapshots carry derivative metadata;
  `srcset`, `sizes`, width and height are rendered in HTML. Removed a hidden legacy
  image in ProductDetail that was still requesting the original.

## Measured image response bodies

Local Chromium, cold pages, 45 distinct URLs containing copies of the same real
1100×730 TV photograph (`product_ffd493e5240822b143a39d74.jpg`, 149,696 bytes).
These are controlled fixture results, not measurements of the deployed site or
45 different customer photographs. The baseline reproduces the old gallery's eager
original-image requests; catalog baseline is its original-image request. Figures exclude API,
HTML, JS, CSS and HTTP headers. Local intercepted responses contain real encoded bytes.

| Screen | Before | After | Reduction |
|---|---:|---:|---:|
| Catalog, 390×844 / DPR 3 | 149,696 B | 58,662 B | 60.81% |
| Catalog, 1440×1000 / DPR 1 | 149,696 B | 25,108 B | 83.23% |
| Product, mobile | 6,736,320 B | 339,200 B | 94.96% |
| Product, desktop | 6,736,320 B | 148,362 B | 97.80% |

Initial catalog: one reduced image. Initial product: one selected large image plus
22 mobile / 25 desktop thumbnails (native lazy-loading includes nearby off-screen
thumbnails). No other large frame was fetched. Selecting frame 45 requests its large
version; zoom works. No broken requested URLs; fallback JPEG and legacy single-photo
navigation pass. Large WebP quality: PSNR 35.41 dB; visual comparison preserved text,
TV edges and fine color patterns without an obvious quality loss.

Reports/screenshots are generated under `.seo-build/image-optimization-fixture/`:
`network-mobile.json`, `network-desktop.json`, `catalog-*.png`, `product-*.png`,
`quality-reference.png` and the actual derivative files.

## Reproduce locally (PowerShell, from project root)

```powershell
$imageTestPhp = 'C:\Users\ASRock\Telvora-MySQL-Test\php\php.exe'
& $imageTestPhp -d extension=gd -d extension=exif tests/product_image_service_test.php seo-artifacts/images/product_ffd493e5240822b143a39d74.jpg
& $imageTestPhp -d extension=gd -d extension=exif -d extension=pdo_sqlite tests/product_image_upload_test.php
& $imageTestPhp tests/product_gallery_service_test.php
node tests/product_gallery_frontend_test.mjs
node tests/product_gallery_http_contract_test.mjs
node tests/product_image_conversion_test.mjs
node tests/backend_deployment_contract_test.mjs
node node_modules/@playwright/test/cli.js test tests/browser/admin_image_upload.spec.ts tests/browser/product_image_network.spec.ts tests/browser/product_gallery.spec.ts
npm.cmd run typecheck
git diff --check
```

The image-service fixture command accepts another JPEG/PNG/WebP path. Without a
path it uses the repository's public cinema image; byte measurements will differ.
PHP tests include real multipart uploads to a loopback-only fixture server and an
isolated in-memory SQLite gallery save/read round trip. They do not access MySQL
or authenticate to the production admin. Browser API calls are intercepted.

## Remaining production verification

- Deploy was not requested. Backend manifest includes `product_image_service.php`;
  deploy that together with the changed gallery/API/snapshot services, then publish
  a new frontend/SEO build through the usual process. Do not remove old uploads.
- CLI PHP on the server has GD/WebP, EXIF and Fileinfo. Verify the **web PHP pool**
  has these extensions and `upload_max_filesize >= 8M`, `post_max_size >= 10M`.
  CLI reported `upload_max_filesize=2M`; it does not establish the web pool's limit.
  Large 40-megapixel inputs also need sufficient memory (the encoder checks the
  available limit before decoding). Do not silently lower quality to bypass it.
- After publication, verify cache/CDN behavior and Network against actual product
  photos on the live site. Existing uploaded files are not backfilled automatically;
  their old URLs remain usable. No database migration is required.

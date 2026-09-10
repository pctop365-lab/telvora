# TELVORA manual Homepage positions release

Source commit: record the commit created with this release. Scope is homepage merchandising only; production deploy requires migration preflight and backup.

## 1. Database migration

Apply in order after migration 011:

1. `database/migrations/preflight_20260910_012_homepage_positions.sql`
2. `database/migrations/20260910_012_homepage_positions.sql`
3. `database/migrations/verify_20260910_012_homepage_positions.sql`

The migration adds `products.homepage_position TINYINT UNSIGNED NULL`, unique index `uq_products_homepage_position`, and CHECK `NULL OR BETWEEN 1 AND 9`. Existing rows are not populated.

## 2. Backend

Upload `products.php` after migration. It exposes `homepage_position` in public/admin lists, validates NULL/empty or integer 1..9, and returns a human-readable conflict for occupied positions.

## 3. Frontend upload order

Upload hashed assets first, then publish `dist/index.html` last. Do not remove old assets.

| Local file | Production path | SHA-256 |
| --- | --- | --- |
| `dist/assets/index-qcD62zhs.css` | `assets/index-qcD62zhs.css` | `52f2addf0d7e2f33f554a8ebd9db143655dc4de3be4e7c26a6a5e6d0da821cc1` |
| `dist/assets/index-cirgryML.js` | `assets/index-cirgryML.js` | `57a3c3ec50e0fd6761b4d3990d88cfddcc74616697ea9fe4735188b7c91c3d6a` |
| `dist/index.html` | `index.html` | `8542eb8ebec90f351fca39b4039f99aea83b135ccb1a1b0210dbf2d28c0c391f` |

## 4. Runtime files

- `products.php` (PHP backend)
- `database/migrations/20260910_012_homepage_positions.sql` (migration tooling, not webroot)

Do not deploy tests, secrets, unrelated PHP, API, Telegram bot, PDF, service catalog, delivery, or database dumps to webroot.

## Rollback

Restore the backed-up `products.php` and `index.html`. Do not automatically reverse migration 012 after positions may have been edited; assess data first.

# Service catalog deployment manifest

Status: local acceptance only. Do not deploy until the DRAFT tariff boundaries and prices in migration 011 are explicitly approved.

## Order

1. Back up the application database and deployed files.
2. Run `database/migrations/preflight_20260909_011_service_catalog.sql`; stop if it returns any `migration_blocker` row.
3. Run `database/migrations/20260909_011_service_catalog.sql` once.
4. Run `database/migrations/verify_20260909_011_service_catalog.sql`; the overlap query must return zero rows.
5. Deploy backend files, then the frontend build.
6. Perform read-only smoke checks for `/services`, Cart, Checkout, Admin order details, Telegram order card and invoice PDF.

## Backend files

- `api.php`
- `generate_invoice_pdf.php`
- `manager.php`
- `service_catalog_service.php`
- `services.php`
- `telegram_polling.php`

## Frontend source/build inputs

- `src/components/CartDrawer.tsx`
- `src/components/Header.tsx`
- `src/components/admin/ServiceCatalogAdmin.tsx`
- `src/pages/AdminPage.tsx`
- `src/pages/CheckoutPage.tsx`
- `src/pages/OrderSuccessPage.tsx`
- `src/pages/ServicesPage.tsx`
- `src/services/orderService.ts`
- `src/services/serviceCatalogService.ts`
- `src/store/cart.tsx`
- `src/types/index.ts`
- production contents of `dist/`

## Migration files

- `database/migrations/preflight_20260909_011_service_catalog.sql`
- `database/migrations/20260909_011_service_catalog.sql`
- `database/migrations/verify_20260909_011_service_catalog.sql`

Tests and this manifest are repository artifacts; they are not web-server runtime files.

# TELVORA legal/IP registration — deployment manifest

Deployment не выполнен. Push не выполнен.

## Scope

- Frontend build: развернуть содержимое локального `dist/` обычным действующим способом.
- Backend: заменить `generate_invoice_pdf.php` и разместить рядом `public_contacts.json`.
- Public assets: включить обновлённый `sitemap.xml`.
- Не менять БД, schema/migrations, товары, цены, supplier services, Telegram polling и secrets.

## Files

`public_contacts.json`, `generate_invoice_pdf.php`, `callback_request.php`, `public/sitemap.xml`, `src/App.tsx`, `src/components/Footer.tsx`, `src/components/LegalDocumentPage.tsx`, `src/components/LegalMetadata.tsx`, `src/data/publicContacts.ts`, `src/pages/ContactsPage.tsx`, `src/pages/RequisitesPage.tsx`, `src/pages/OfferPage.tsx`, `src/pages/PrivacyPage.tsx`, `src/pages/PersonalDataConsentPage.tsx`, `src/pages/CookiesPage.tsx`, `src/pages/ReturnsPage.tsx`, `src/pages/WarrantyPage.tsx`, `src/pages/DeliveryPage.tsx`, `src/pages/CheckoutPage.tsx`, `tests/legal_business_contract_test.mjs`, `tests/public_contacts_frontend_test.mjs`, `tests/callback_request_service_test.php`, `tests/invoice_contacts_pdf_fixture_test.php`, `tests/invoice_compact_layout_fixture_test.php`, `tests/browser/legal-pages.spec.ts` and `LEGAL_OPEN_ITEMS.md`.

## Pre-deploy gates

1. Подтвердить, что business config содержит утверждённые данные и не содержит secrets.
2. Выполнить `npm run typecheck`, `npm run build`, legal contract tests, PHP lint и PDF fixtures.
3. Проверить `/requisites`, Footer, Contacts, legal pages и Checkout на mobile/desktop в light/dark; console без ошибок.
4. Сделать резервную копию заменяемых production-файлов и сверить server paths для PHP/public config.

## Deploy order and smoke

1. Загрузить `public_contacts.json` и `generate_invoice_pdf.php` атомарно, затем frontend build/assets.
2. Проверить HTTP 200 и metadata для `/requisites`, `/offer`, `/privacy`, `/personal-data-consent`, `/cookies`, `/returns`, `/warranty`, `/delivery`.
3. Создать только тестовую заявку без оплаты и без изменения production БД, если для smoke имеется безопасный штатный режим; иначе пропустить и проверить на staging/local.
4. Сгенерировать накладную для заранее разрешённого тестового заказа и убедиться, что она не названа кассовым чеком, не содержит адреса и помещается ожидаемо.

## Rollback

Вернуть сохранённые frontend artifacts, `generate_invoice_pdf.php` и `public_contacts.json` одной согласованной версией. Миграции и rollback БД не требуются.

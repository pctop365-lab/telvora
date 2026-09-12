# TELVORA legal/IP registration — deployment manifest

Deployment не выполнен. Push не выполнен.

## Release identity

- Base legal commit: `0ad7c826df50c738aad3a8857c3d5f432de25f71`.
- Release HEAD: точный commit, содержащий эту версию manifest; перед deployment сверить `git rev-parse HEAD` с переданным release hash.
- Manifest и artifacts должны передаваться из одного чистого working tree.

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

## SHA-256

Сверять регистр независимо; значения рассчитаны после успешного production build:

```text
public_contacts.json                         55109146919F5BF9A9D7A291FCE4F8750CE683DBF3F11E4CC784713222ED4304
callback_request.php                         F481B7911FB705000AEBB3408A972F5E715B03C29F558D6EBE2CB6FDF3BFF375
generate_invoice_pdf.php                     EC8602B4D1FC3F3FA9EF87E71192F900A948CF911BC041442C9102BFA94B96C3
public/sitemap.xml                            EF4A8FF1343554AE9BDC1A12A822499DF66A9198E1BE6A58006DCBD71D0C4324
dist/index.html                               2F839A85FD7CEAE358452E05C3D98FE632084C8064B157E0098F5899304A8348
dist/assets/index-D4ag5NG-.css                F48E53DBFE9BA7FFE432862B213007B683B435E8980E5D4928ED526657328945
dist/assets/index-_h_IhUz8.js                 37B9E6014452E3256448CF07E550ABF1E2328C4440487CAFE6D1F6A2E6F056E0
```

## Rollback

Вернуть сохранённые frontend artifacts, `generate_invoice_pdf.php` и `public_contacts.json` одной согласованной версией. Миграции и rollback БД не требуются.

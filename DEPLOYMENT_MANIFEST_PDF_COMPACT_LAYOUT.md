# PDF compact layout deployment manifest

Release scope: presentation-only PDF change, including a centered 50mm footer logo with a 10mm top margin. No database migration, API, Telegram, frontend or order mutation.

## Deploy

1. Run PHP lint and all invoice fixtures.
2. Back up the production `generate_invoice_pdf.php` with a timestamp and SHA-256.
3. Atomically replace only `generate_invoice_pdf.php`.
4. Run production PHP lint and authenticated read-only PDF fixture/smoke.

## Runtime file

- `generate_invoice_pdf.php`

Tests and this manifest are repository-only artifacts and must not be copied into the public webroot.

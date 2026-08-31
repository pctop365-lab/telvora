# Supplier/variant infrastructure — stage 1

## Scope

Migration `20260831_001_supplier_variant_infrastructure.sql` creates only new tables. It contains no `ALTER TABLE products`, no catalog data, supplier data, credentials, price update, trigger, stored procedure, import execution or legacy JSON backfill.

The existing site continues to read and write `products`, including its current `variants` JSON. None of the current PHP or frontend files is changed by this stage.

## Tables and relations

```text
products (existing, unchanged)
  1 ── * product_variants
             * ── 1 variant_market_regions (nullable)
             * ── 1 variant_certification_supply_types (nullable)
             1 ── * supplier_offers * ── 1 suppliers

suppliers
  1 ── * supplier_import_profiles
  1 ── * supplier_import_jobs 1 ── * supplier_import_rows
  1 ── * supplier_product_matches

supplier_product_matches
  * ── 0..1 products
  * ── 0..1 product_variants

supplier_import_rows
  * ── 0..1 products
  * ── 0..1 product_variants
  * ── 0..1 supplier_product_matches
  1 ── 0..* supplier_offers (source traceability)

pricing_rules (inactive infrastructure; no production consumer)
```

One canonical model has any number of `product_variants` through `product_id`. Multiple suppliers can offer the same version because each `supplier_offers` row carries its own `supplier_id` and the shared `product_variant_id`.

## Type choices

- IDs for new high-growth/import tables are `BIGINT UNSIGNED`; the FK to existing `products.id` remains `INT UNSIGNED` so the types match exactly.
- Monetary values use `DECIMAL(15,2)`. Percent/confidence values use fixed-point `DECIMAL`; no money uses `FLOAT`.
- Currency is ISO-style `CHAR(3)`, but validation belongs to the future ingestion service.
- Statuses and strategies are `VARCHAR`, not SQL `ENUM`, so vocabulary can evolve without rebuilding tables.
- Market/region and certification/supply type are empty managed dictionaries with JSON aliases. No country, region, Rostest or European value is seeded.
- `assembly_country` is a nullable independent attribute. Unknown remains `NULL`; it is never populated from certification text.
- JSON is limited to sparse mappings/options/evidence where columns would be premature. Frequently filtered and joined facts have ordinary typed columns.
- Timestamps separate source time (`source_updated_at`), ingestion time (`imported_at`) and local audit time.
- Every table uses InnoDB, `utf8mb4` and `utf8mb4_unicode_ci`, matching the supplied current schema.

## Integrity and indexes

- All durable relations use foreign keys. Catalog, supplier, variant and offer deletes are `RESTRICT`; deleting a never-published import job may cascade only to its diagnostic rows.
- `product_variants(product_id, variant_key)` provides a stable, reviewed identity inside one canonical product. `variant_key` is deliberately not derived in SQL from nullable dimensions.
- Supplier codes and dictionary codes are unique.
- `supplier_import_rows.source_row_number` is unique inside a job. The explicit
  name avoids MySQL 8.0's reserved `ROW_NUMBER` identifier.
- Supplier SKU is unique per supplier where a SKU exists. MySQL permits multiple `NULL` values; rows without a trustworthy SKU require source-row identity and must not be title-deduplicated.
- Eligibility-oriented offer indexing begins with `product_variant_id`, followed by active/availability/price fields for future per-variant best-price queries.
- Matching/import review indexes lead with job/supplier/status fields used by preview and diagnostics.

The database cannot express the cross-table rule that `supplier_product_matches.product_id` must equal the product belonging to its optional `product_variant_id` without triggers. The future matching service must validate that pair transactionally. Offers avoid this ambiguity by referencing only the concrete variant; its canonical product is obtained through `product_variants.product_id`.

## Unresolved matches cannot affect purchasing

An unresolved supplier identity can exist in `supplier_product_matches` and `supplier_import_rows` with `product_variant_id = NULL` and a review-required status. `supplier_offers.product_variant_id` is `NOT NULL`, so unresolved rows cannot become price/availability offers by accident. The future importer must create/update an offer only after an approved match.

## Future best purchase selection

For one `product_variant_id`, the future pricing service will consider only offers whose supplier and offer are active, whose availability is eligible, whose source is fresh, and whose price/currency can be compared. It will choose the minimum normalized landed purchase cost inside that variant only. An offer from another version of the same product is never a candidate.

`pricing_rules` is inactive by default and has no production reader. A later stage may turn a selected purchase cost into a retail price using markup, minimum margin and rounding rules; this migration cannot change `products.price`.

## Future legacy JSON migration — plan only

Do not run this as part of stage 1.

1. Snapshot and validate every current `products.variants` JSON value.
2. For each JSON array element, create a deterministic backfill key containing the legacy product ID and array position, such as `legacy-json-<product-id>-<position>`. This prevents accidental merging when two legacy entries share a country.
3. Copy `country` only to `product_variants.assembly_country` after trimming. Never interpret it as market, region or certification/supply type.
4. Leave `market_region_id` and `certification_supply_type_id` as `NULL` unless separately confirmed by trusted evidence.
5. Set `classification_status` to a review-required value whenever the version dimensions are incomplete; store limited provenance in `classification_evidence` (legacy product ID, JSON position and migration batch), not the whole product record.
6. Map legacy `is_active` to variant activity, preserving missing `is_active` according to the current application behavior (currently treated as active).
7. Preserve legacy `price` and `old_price` in a dedicated future retail-price snapshot/backfill table or export ledger. Do not put them in `supplier_offers`: they have no supplier provenance and are not purchase prices.
8. Compare counts, countries, active flags and monetary snapshots before any consumer switches to the new tables.
9. Run a dual-read/shadow validation phase. Only later migrations may change frontend/cart/checkout behavior.
10. Keep `products.variants`, `products.price` and `products.old_price` untouched until the separate cutover is complete and reversible.

This process preserves all legacy values without inventing a Rostest/Europe classification and without collapsing distinct same-country entries.

## Application and rollback notes

No SQL should be run on production as part of repository preparation. Before a future deployment, test against a disposable database with the same MySQL/MariaDB version and an exact copy of the `products.id` definition.

If canonical migration `20260831_001_supplier_variant_infrastructure.sql`
stopped after creating the first seven infrastructure tables, do not rerun it.
For that exact partial state, run the read-only
`preflight_20260901_002_complete_supplier_variant_infrastructure.sql`; proceed
only when its violations result is empty. Then run
`20260901_002_complete_supplier_variant_infrastructure.sql`, followed by
`verify_20260901_002_complete_supplier_variant_infrastructure.sql`. Migration
002 creates only `supplier_import_rows`, `supplier_offers` and `pricing_rules`.

Because this migration is additive and production may later accumulate data in these tables, no automatic down migration is supplied. Rollback at this stage is simply leaving the unused tables in place. Any future removal must be a separately reviewed, explicitly destructive operation.

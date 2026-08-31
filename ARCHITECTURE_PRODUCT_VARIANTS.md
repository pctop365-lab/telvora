# TELVORA: canonical products, versions and supplier offers

## Decision

The current `products.variants` JSON must not become the foundation of supplier imports. It models a variant as assembly country plus a manually entered retail price. The target model must be relational:

`product -> product_variant -> supplier_offer`

A canonical product is the model itself. A product variant is a sellable version of that model. A supplier offer is one supplier's current commercial proposal for exactly one variant.

Rostest is never an assembly country. Assembly country, target market/region and certification/supply type are independent attributes.

## What exists now

The production code currently has:

- one `products` row per catalog card;
- product-level `country`, `price` and `old_price` fields;
- `variants` stored as JSON objects shaped as `country`, `price`, `old_price`, `is_active`;
- selection of a variant by `country` in the product page;
- a cart identity made from `product.id + country`;
- order submission containing `slug + assembly_country`;
- server-side price lookup by matching the submitted country inside the JSON;
- no supplier, supplier offer, price-import or AI-matching entities in the current schema.

This makes country an accidental variant ID. It cannot distinguish, for example, two Polish-built units intended for different markets or with different certification/supply types. It also makes retail price a property of the JSON variant instead of a result of offers and a pricing policy.

## Target entities

### 1. `products`: canonical product/model

One row represents the manufacturer model, for example `LG OLED65C5`, regardless of market version, certification or assembly country.

Suggested core fields:

| Field | Purpose |
|---|---|
| `id` | Stable internal ID |
| `brand_id` | Normalized manufacturer |
| `canonical_model` | Manufacturer model, preserving display spelling |
| `normalized_model` | Matching key, e.g. normalized `OLED65C5` |
| `name`, `slug` | Catalog presentation and route |
| catalog/spec fields | Shared characteristics that truly belong to the model |
| `is_active` | Catalog lifecycle |

Recommended uniqueness is based on manufacturer plus normalized model, not display name. A model alias table should keep supplier spellings and historical aliases without creating duplicate product cards.

Fields that differ between versions must not remain authoritative on `products`. Product-level displayed price may exist only as a derived/cache value such as "from price", never as purchase truth.

### 2. `product_variants`: variant/version of a canonical model

One row represents a confirmed sellable version of one product.

| Field | Purpose |
|---|---|
| `id` | Stable variant ID used by cart, order and offers |
| `product_id` | Required FK to `products.id` |
| `assembly_country_id` | Nullable FK to country dictionary |
| `market_region_id` | Nullable FK to managed market/region dictionary |
| `certification_supply_type_id` | Nullable FK to managed certification/supply-type dictionary |
| `manufacturer_part_number` | Optional version-specific MPN/SKU where confirmed |
| `display_name` | Optional curated buyer-facing version label |
| `status` | Lifecycle/match-readiness reference, not a hardcoded application enum |
| `is_active` | Whether it may be sold |
| `created_at`, `updated_at` | Audit fields |

Do not encode concrete region or certification values in a database enum yet. Use managed reference tables (for example, market/region terms and certification/supply-type terms) with stable IDs, code, localized label, aliases, active flag and audit metadata. This allows the real TELVORA vocabulary to emerge from confirmed source data.

`assembly_country_id`, `market_region_id` and `certification_supply_type_id` remain separate even when one appears correlated with another. Unknown values stay `NULL`; `NULL` does not mean Europe, Russia, Rostest or any default.

A variant should also have evidence records:

| Evidence field | Purpose |
|---|---|
| `product_variant_id` | Variant being supported |
| `attribute_name` | Which attribute is supported |
| `source_type`, `source_ref` | Price row, supplier document or trusted source |
| `raw_value` | Original wording |
| `normalized_value_ref` | Chosen dictionary value, if resolved |
| `confirmed_by`, `confirmed_at` | Human/system audit trail |

This prevents an AI inference from becoming an untraceable fact.

### 3. `supplier_offers`: one supplier's offer for one variant

| Field | Purpose |
|---|---|
| `id` | Offer ID |
| `supplier_id` | Required FK to supplier |
| `product_variant_id` | Required FK to the exact variant, never only to product |
| `supplier_sku` | Supplier's stable item identifier when available |
| `raw_title` | Original imported title |
| `purchase_price`, `currency_code` | Supplier acquisition price |
| `stock_status_id` | Managed stock-status term |
| `stock_quantity` | Nullable exact quantity if supplied |
| `min_order_quantity` | Optional commercial constraint |
| `valid_from`, `valid_until`, `last_seen_at` | Freshness window |
| `is_active` | Offer lifecycle |
| `source_import_row_id` | Traceability to original import |

The natural uniqueness should normally be supplier plus supplier SKU. Where a source has no SKU, keep a source-row identity/fingerprint; do not deduplicate merely by title.

Example:

```text
Product: LG OLED65C5
  Variant V1: market Europe; certification/supply type European version; assembly Poland
    Offer A: Supplier A; 142000; in stock
    Offer C: Supplier C; 139000; out of stock
  Variant V2: market Russia; certification/supply type Rostest; assembly unknown
    Offer B: Supplier B; 151000; in stock
```

The word `Rostest` is stored only in the confirmed certification/supply dimension in this example. It must not populate assembly country.

## Variant identity and duplicate prevention

Two variants belong to the same canonical product when their normalized model is the same. They are the same variant only when the confirmed version-defining attributes are compatible.

Rules:

1. A conflicting known value means a different variant. For example, confirmed Europe and confirmed Russia market versions do not merge.
2. An unknown value is not a wildcard that authorizes an automatic merge. It means insufficient evidence.
3. Identical confirmed market, certification/supply type, assembly country and version-specific MPN may be merged, subject to source quality and existing evidence.
4. Assembly country alone is not a sufficient version identity.
5. A new combination proposed by an import is created only through an approved matching decision, not silently by the AI.

Because nullable dimensions make a simple SQL composite unique key unsafe, store a reviewed normalized `identity_key` or enforce duplicate checks in a transactional domain service, backed by a uniqueness constraint for finalized variants. Draft/unresolved import rows remain outside the sellable variants table until resolved.

## Import and AI matching

Raw supplier input should first be persisted in `supplier_import_rows`. Matching produces a proposal, not an immediate catalog mutation.

The source spreadsheet position is stored as `source_row_number`, unique within
an import job. The reserved MySQL 8.0 identifier `ROW_NUMBER` is not used.

Suggested matching result fields:

- canonical-product candidate and confidence;
- product-variant candidate and confidence;
- separately extracted raw/normalized assembly country, market/region and certification/supply type;
- evidence spans showing the exact source text for every asserted dimension;
- proposed action;
- reason codes and matcher version;
- reviewer decision and audit timestamps.

Workflow:

```text
raw supplier row
  -> normalize brand/model
  -> match canonical product
  -> extract only explicitly supported version attributes
  -> compare with variants of that product
  -> propose one action
       MATCH_EXISTING_VARIANT
       PROPOSE_NEW_VARIANT_ON_EXISTING_PRODUCT
       PROPOSE_NEW_PRODUCT
       REQUIRES_MATCHING
  -> human/rule-approved decision
  -> upsert supplier offer against product_variant_id
```

The action labels above are conceptual; their final stored names should follow the project's future status vocabulary rather than being hardcoded prematurely.

Required behavior for `LG OLED65C5 Rostest`:

- if `LG + OLED65C5` matches the canonical product, do not create another product card;
- if a confirmed compatible Rostest variant exists, propose linking the offer to it;
- if no compatible variant exists, propose adding a Rostest variant to the existing product;
- only set Rostest when the price text or another trusted source explicitly confirms it;
- if the version cannot be established reliably, leave the offer/import row unresolved with buyer-invisible status "Требует сопоставления";
- never infer Europe/Russia/Rostest from supplier identity, price, language, currency, assembly country or absence of a label alone.

An unresolved supplier row must not affect stock, best purchase price, catalog price or checkout availability.

## Price calculation per version

Best purchase price is calculated independently for each `product_variant_id`:

```text
eligible offers for variant V =
  offers where product_variant_id = V
  and offer/supplier are active
  and offer is fresh and currently valid
  and stock is eligible under the stock policy
  and price/currency are valid

best_purchase_price(V) = minimum comparable landed purchase cost
```

If all prices are in one currency initially, comparable cost may equal `purchase_price`. Once currencies, delivery, supplier fees or tax treatment differ, compare normalized landed cost and retain all calculation inputs and timestamps.

In the example, Europe's best eligible purchase price is 142000 because the 139000 offer is out of stock; Rostest's is 151000. Neither price competes with the other variant.

Retail price is a separate calculation:

```text
retail_price(V) = pricing_policy(best_purchase_price(V), variant, category, channel, time)
```

Store or cache the computed result per variant with `calculated_at`, policy/version and source offer ID. Never overwrite supplier purchase price with retail price. If no eligible offer exists, the variant becomes unavailable or uses an explicitly approved fallback policy; the system must not borrow another variant's offer.

Catalog cards may display `from min(retail_price(V))` across active, purchasable variants, clearly as a derived teaser. Checkout must revalidate the selected variant and price server-side.

## Buyer selection and order integrity

The product page remains one canonical card/URL. It shows a version selector whose options are active, purchasable variants. Each option has a curated label assembled from confirmed attributes, for example:

```text
European version · assembly Poland — 169990 ₽
Rostest · assembly not specified — 179990 ₽
```

The UI should disclose separate fields (version/market, certification/supply type, assembly country) instead of presenting them all as a country. Unknown information is shown as not specified, not guessed.

Selection changes variant-specific price, availability, delivery promise, warranty/certification copy and add-to-cart target. The cart key must be `product_variant_id`, not country text. The order request should contain `product_variant_id` and quantity; product slug and displayed text are non-authoritative metadata at most.

On checkout the server:

1. loads the variant and canonical product by ID;
2. verifies that the variant is active and has an eligible offer/retail-price result;
3. recalculates the current sell price;
4. creates an order-item snapshot containing `product_id`, `product_variant_id`, selected/source offer where relevant, canonical name, confirmed version labels, assembly country and final price;
5. does not depend on mutable JSON or user-submitted country/price strings.

Historical order snapshots must remain readable even if dictionaries, offers or product presentation later change.

## Migration from current TELVORA variants

1. Add normalized tables without removing existing fields.
2. Create one canonical `products` row mapping to itself for every existing product.
3. Convert each current JSON variant into a `product_variants` row with assembly country only. Leave market and certification/supply type unknown; do not derive them.
4. Treat current JSON `price` as an initial retail-price snapshot/manual override, not as a supplier purchase offer because no supplier provenance exists.
5. Update read APIs and admin UI to use stable variant IDs and separate dimensions.
6. Update cart and order API from `assembly_country` to `product_variant_id`, with a short controlled compatibility window if existing carts must survive deployment.
7. Backfill order snapshot columns where possible; do not fabricate unavailable historical version facts.
8. After reconciliation and monitoring, stop writes to `products.variants`, product-level country and authoritative product-level price; remove legacy fields in a later migration.

## Non-negotiable invariants

- A supplier offer always references a concrete `product_variant_id`.
- A variant always references exactly one canonical `product_id`.
- Rostest is never stored or interpreted as assembly country.
- Unknown version attributes remain unknown.
- AI matching records proposals and evidence; it does not manufacture certification/market facts.
- Unresolved rows do not enter pricing or availability.
- Best purchase price and retail price are calculated per variant, never across variants of the same product.
- Cart and order identity use stable IDs, while order records preserve immutable customer-visible snapshots.

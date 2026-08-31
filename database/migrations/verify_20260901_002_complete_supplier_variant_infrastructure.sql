-- READ-ONLY verification after migration 002.
-- Safe result: the violations query and the non-empty-tables query both return
-- zero rows. The summary query must report 10 infrastructure tables and one
-- separately preserved legacy table.

SELECT CONCAT('missing table: ', expected.table_name) AS violation
FROM (
    SELECT 'suppliers' AS table_name
    UNION ALL SELECT 'variant_market_regions'
    UNION ALL SELECT 'variant_certification_supply_types'
    UNION ALL SELECT 'product_variants'
    UNION ALL SELECT 'supplier_import_profiles'
    UNION ALL SELECT 'supplier_import_jobs'
    UNION ALL SELECT 'supplier_product_matches'
    UNION ALL SELECT 'supplier_import_rows'
    UNION ALL SELECT 'supplier_offers'
    UNION ALL SELECT 'pricing_rules'
    UNION ALL SELECT 'product_variants_legacy'
) AS expected
LEFT JOIN information_schema.tables actual
    ON actual.table_schema = DATABASE()
   AND actual.table_name = expected.table_name
   AND actual.table_type = 'BASE TABLE'
WHERE actual.table_name IS NULL

UNION ALL

SELECT 'missing column: supplier_import_rows.source_row_number'
FROM (SELECT 1) AS guard
WHERE NOT EXISTS (
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'supplier_import_rows'
      AND column_name = 'source_row_number'
      AND column_type = 'int unsigned'
      AND is_nullable = 'NO'
)

UNION ALL

SELECT 'reserved legacy column exists: supplier_import_rows.row_number'
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'supplier_import_rows'
  AND column_name = 'row_number'

UNION ALL

SELECT CONCAT('missing or incompatible foreign key: ', expected.constraint_name)
FROM (
    SELECT 'fk_product_variants_canonical_product' AS constraint_name,
           'product_variants' AS table_name, 'product_id' AS column_name,
           'products' AS referenced_table_name, 'id' AS referenced_column_name
    UNION ALL SELECT 'fk_product_variants_market', 'product_variants',
        'market_region_id', 'variant_market_regions', 'id'
    UNION ALL SELECT 'fk_product_variants_cert_supply', 'product_variants',
        'certification_supply_type_id', 'variant_certification_supply_types', 'id'
    UNION ALL SELECT 'fk_supplier_import_profiles_supplier',
        'supplier_import_profiles', 'supplier_id', 'suppliers', 'id'
    UNION ALL SELECT 'fk_supplier_import_jobs_supplier',
        'supplier_import_jobs', 'supplier_id', 'suppliers', 'id'
    UNION ALL SELECT 'fk_supplier_import_jobs_profile',
        'supplier_import_jobs', 'import_profile_id', 'supplier_import_profiles', 'id'
    UNION ALL SELECT 'fk_supplier_product_matches_supplier',
        'supplier_product_matches', 'supplier_id', 'suppliers', 'id'
    UNION ALL SELECT 'fk_supplier_product_matches_product',
        'supplier_product_matches', 'product_id', 'products', 'id'
    UNION ALL SELECT 'fk_supplier_product_matches_variant',
        'supplier_product_matches', 'product_variant_id', 'product_variants', 'id'
    UNION ALL SELECT 'fk_supplier_import_rows_job', 'supplier_import_rows',
        'import_job_id', 'supplier_import_jobs', 'id'
    UNION ALL SELECT 'fk_supplier_import_rows_product', 'supplier_import_rows',
        'matched_product_id', 'products', 'id'
    UNION ALL SELECT 'fk_supplier_import_rows_variant', 'supplier_import_rows',
        'matched_product_variant_id', 'product_variants', 'id'
    UNION ALL SELECT 'fk_supplier_import_rows_match', 'supplier_import_rows',
        'match_id', 'supplier_product_matches', 'id'
    UNION ALL SELECT 'fk_supplier_offers_supplier', 'supplier_offers',
        'supplier_id', 'suppliers', 'id'
    UNION ALL SELECT 'fk_supplier_offers_variant', 'supplier_offers',
        'product_variant_id', 'product_variants', 'id'
    UNION ALL SELECT 'fk_supplier_offers_source_row', 'supplier_offers',
        'source_import_row_id', 'supplier_import_rows', 'id'
) AS expected
LEFT JOIN information_schema.key_column_usage actual
    ON actual.constraint_schema = DATABASE()
   AND actual.constraint_name = expected.constraint_name
   AND actual.table_name = expected.table_name
   AND actual.column_name = expected.column_name
   AND actual.referenced_table_name = expected.referenced_table_name
   AND actual.referenced_column_name = expected.referenced_column_name
WHERE actual.constraint_name IS NULL;

SELECT counts.table_name AS non_empty_table, counts.exact_rows
FROM (
    SELECT 'suppliers' AS table_name, COUNT(*) AS exact_rows FROM suppliers
    UNION ALL SELECT 'variant_market_regions', COUNT(*) FROM variant_market_regions
    UNION ALL SELECT 'variant_certification_supply_types', COUNT(*) FROM variant_certification_supply_types
    UNION ALL SELECT 'product_variants', COUNT(*) FROM product_variants
    UNION ALL SELECT 'supplier_import_profiles', COUNT(*) FROM supplier_import_profiles
    UNION ALL SELECT 'supplier_import_jobs', COUNT(*) FROM supplier_import_jobs
    UNION ALL SELECT 'supplier_product_matches', COUNT(*) FROM supplier_product_matches
    UNION ALL SELECT 'supplier_import_rows', COUNT(*) FROM supplier_import_rows
    UNION ALL SELECT 'supplier_offers', COUNT(*) FROM supplier_offers
    UNION ALL SELECT 'pricing_rules', COUNT(*) FROM pricing_rules
) AS counts
WHERE counts.exact_rows <> 0;

SELECT
    SUM(table_name <> 'product_variants_legacy') AS infrastructure_tables,
    SUM(table_name = 'product_variants_legacy') AS legacy_tables
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
      'suppliers',
      'variant_market_regions',
      'variant_certification_supply_types',
      'product_variants',
      'supplier_import_profiles',
      'supplier_import_jobs',
      'supplier_product_matches',
      'supplier_import_rows',
      'supplier_offers',
      'pricing_rules',
      'product_variants_legacy'
  );

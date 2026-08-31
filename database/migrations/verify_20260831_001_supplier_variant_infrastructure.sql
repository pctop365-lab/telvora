-- Read-only post-application verification for stage 1.
-- Run in the selected TELVORA database after applying the migration.
-- Every result set should report zero missing objects; the final products
-- result must show the existing table and does not modify it.

SELECT expected.table_name AS missing_table
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
) AS expected
LEFT JOIN information_schema.tables actual
    ON actual.table_schema = DATABASE()
   AND actual.table_name = expected.table_name
WHERE actual.table_name IS NULL;

SELECT expected.table_name, expected.column_name
FROM (
    SELECT 'product_variants' AS table_name, 'product_id' AS column_name
    UNION ALL SELECT 'product_variants', 'assembly_country'
    UNION ALL SELECT 'product_variants', 'market_region_id'
    UNION ALL SELECT 'product_variants', 'certification_supply_type_id'
    UNION ALL SELECT 'supplier_offers', 'supplier_id'
    UNION ALL SELECT 'supplier_offers', 'product_variant_id'
    UNION ALL SELECT 'supplier_offers', 'purchase_price'
    UNION ALL SELECT 'supplier_import_profiles', 'column_mapping'
    UNION ALL SELECT 'supplier_product_matches', 'product_variant_id'
    UNION ALL SELECT 'supplier_import_jobs', 'original_filename'
    UNION ALL SELECT 'supplier_import_rows', 'matched_product_variant_id'
    UNION ALL SELECT 'pricing_rules', 'markup_percent'
    UNION ALL SELECT 'pricing_rules', 'minimum_margin'
) AS expected
LEFT JOIN information_schema.columns actual
    ON actual.table_schema = DATABASE()
   AND actual.table_name = expected.table_name
   AND actual.column_name = expected.column_name
WHERE actual.column_name IS NULL;

SELECT expected.constraint_name AS missing_foreign_key
FROM (
    SELECT 'fk_product_variants_canonical_product' AS constraint_name
    UNION ALL SELECT 'fk_supplier_import_profiles_supplier'
    UNION ALL SELECT 'fk_supplier_import_jobs_supplier'
    UNION ALL SELECT 'fk_supplier_product_matches_supplier'
    UNION ALL SELECT 'fk_supplier_product_matches_product'
    UNION ALL SELECT 'fk_supplier_product_matches_variant'
    UNION ALL SELECT 'fk_supplier_import_rows_job'
    UNION ALL SELECT 'fk_supplier_import_rows_product'
    UNION ALL SELECT 'fk_supplier_import_rows_variant'
    UNION ALL SELECT 'fk_supplier_offers_supplier'
    UNION ALL SELECT 'fk_supplier_offers_variant'
) AS expected
LEFT JOIN information_schema.referential_constraints actual
    ON actual.constraint_schema = DATABASE()
   AND actual.constraint_name = expected.constraint_name
WHERE actual.constraint_name IS NULL;

SELECT table_name, engine, table_collation
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
      'pricing_rules'
  )
ORDER BY table_name;

SELECT table_name, column_type, character_set_name, collation_name
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'products'
  AND column_name = 'id';

-- READ-ONLY preflight for the interrupted TELVORA stage-1 migration.
-- Safe result: this query returns zero rows. Do not run migration 002 if any
-- row is returned. No DDL or data-changing statement is used here.

SELECT CONCAT('missing required table: ', expected.table_name) AS violation
FROM (
    SELECT 'suppliers' AS table_name
    UNION ALL SELECT 'variant_market_regions'
    UNION ALL SELECT 'variant_certification_supply_types'
    UNION ALL SELECT 'product_variants'
    UNION ALL SELECT 'supplier_import_profiles'
    UNION ALL SELECT 'supplier_import_jobs'
    UNION ALL SELECT 'supplier_product_matches'
    UNION ALL SELECT 'product_variants_legacy'
) AS expected
LEFT JOIN information_schema.tables actual
    ON actual.table_schema = DATABASE()
   AND actual.table_name = expected.table_name
   AND actual.table_type = 'BASE TABLE'
WHERE actual.table_name IS NULL

UNION ALL

SELECT CONCAT('resume target already exists: ', actual.table_name) AS violation
FROM information_schema.tables actual
WHERE actual.table_schema = DATABASE()
  AND actual.table_name IN (
      'supplier_import_rows',
      'supplier_offers',
      'pricing_rules'
  )

UNION ALL

SELECT CONCAT('wrong engine/collation: ', actual.table_name) AS violation
FROM information_schema.tables actual
WHERE actual.table_schema = DATABASE()
  AND actual.table_name IN (
      'suppliers',
      'variant_market_regions',
      'variant_certification_supply_types',
      'product_variants',
      'supplier_import_profiles',
      'supplier_import_jobs',
      'supplier_product_matches'
  )
  AND (actual.engine <> 'InnoDB'
       OR actual.table_collation <> 'utf8mb4_unicode_ci')

UNION ALL

SELECT CONCAT('missing or incompatible key column: ', expected.table_name,
              '.', expected.column_name) AS violation
FROM (
    SELECT 'suppliers' AS table_name, 'id' AS column_name,
           'bigint unsigned' AS column_type, 'PRI' AS column_key
    UNION ALL SELECT 'variant_market_regions', 'id', 'bigint unsigned', 'PRI'
    UNION ALL SELECT 'variant_certification_supply_types', 'id', 'bigint unsigned', 'PRI'
    UNION ALL SELECT 'product_variants', 'id', 'bigint unsigned', 'PRI'
    UNION ALL SELECT 'product_variants', 'product_id', 'int unsigned', ''
    UNION ALL SELECT 'supplier_import_profiles', 'id', 'bigint unsigned', 'PRI'
    UNION ALL SELECT 'supplier_import_profiles', 'supplier_id', 'bigint unsigned', ''
    UNION ALL SELECT 'supplier_import_jobs', 'id', 'bigint unsigned', 'PRI'
    UNION ALL SELECT 'supplier_import_jobs', 'supplier_id', 'bigint unsigned', ''
    UNION ALL SELECT 'supplier_product_matches', 'id', 'bigint unsigned', 'PRI'
    UNION ALL SELECT 'supplier_product_matches', 'supplier_id', 'bigint unsigned', ''
    UNION ALL SELECT 'supplier_product_matches', 'product_id', 'int unsigned', ''
    UNION ALL SELECT 'supplier_product_matches', 'product_variant_id', 'bigint unsigned', ''
) AS expected
LEFT JOIN information_schema.columns actual
    ON actual.table_schema = DATABASE()
   AND actual.table_name = expected.table_name
   AND actual.column_name = expected.column_name
WHERE actual.column_name IS NULL
   OR actual.column_type <> expected.column_type
   OR (expected.column_key = 'PRI' AND actual.column_key <> 'PRI')

UNION ALL

SELECT CONCAT('missing or incompatible foreign key: ', expected.constraint_name)
       AS violation
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
) AS expected
LEFT JOIN information_schema.key_column_usage actual
    ON actual.constraint_schema = DATABASE()
   AND actual.constraint_name = expected.constraint_name
   AND actual.table_name = expected.table_name
   AND actual.column_name = expected.column_name
   AND actual.referenced_table_name = expected.referenced_table_name
   AND actual.referenced_column_name = expected.referenced_column_name
WHERE actual.constraint_name IS NULL;

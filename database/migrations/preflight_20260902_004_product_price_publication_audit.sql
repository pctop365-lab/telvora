-- READ-ONLY preflight for migration 004. Every returned row is a blocker.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SELECT 'product_price_publication_audit already exists' AS migration_blocker
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'product_price_publication_audit';

SELECT CONCAT(expected.table_name, '.', expected.column_name, ' has unexpected type') AS schema_blocker
FROM (
    SELECT 'products' AS table_name, 'id' AS column_name, 'int unsigned' AS expected_type
    UNION ALL SELECT 'product_variants', 'id', 'bigint unsigned'
    UNION ALL SELECT 'suppliers', 'id', 'bigint unsigned'
    UNION ALL SELECT 'supplier_offers', 'id', 'bigint unsigned'
    UNION ALL SELECT 'pricing_rules', 'id', 'bigint unsigned'
    UNION ALL SELECT 'supplier_import_rows', 'id', 'bigint unsigned'
    UNION ALL SELECT 'supplier_import_jobs', 'id', 'bigint unsigned'
) expected
LEFT JOIN information_schema.columns actual
  ON actual.table_schema = DATABASE()
 AND actual.table_name = expected.table_name
 AND actual.column_name = expected.column_name
WHERE actual.column_name IS NULL OR actual.column_type <> expected.expected_type;

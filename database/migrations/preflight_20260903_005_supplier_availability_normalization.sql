-- READ-ONLY preflight for migration 005. Every returned row is a blocker.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SELECT 'supplier_availability_mappings already exists' AS migration_blocker
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'supplier_availability_mappings';

SELECT 'supplier_import_profiles.arrival_date_format already exists' AS migration_blocker
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'supplier_import_profiles'
  AND column_name = 'arrival_date_format';

SELECT CONCAT(expected.table_name, '.', expected.column_name, ' has unexpected type') AS schema_blocker
FROM (
    SELECT 'supplier_import_profiles' AS table_name, 'id' AS column_name, 'bigint unsigned' AS expected_type
    UNION ALL SELECT 'supplier_import_profiles', 'parser_options', 'json'
    UNION ALL SELECT 'supplier_offers', 'availability_status', 'varchar(50)'
    UNION ALL SELECT 'supplier_offers', 'stock_quantity', 'int unsigned'
    UNION ALL SELECT 'supplier_offers', 'expected_arrival_at', 'datetime'
) expected
LEFT JOIN information_schema.columns actual
  ON actual.table_schema = DATABASE()
 AND actual.table_name = expected.table_name
 AND actual.column_name = expected.column_name
WHERE actual.column_name IS NULL OR actual.column_type <> expected.expected_type;

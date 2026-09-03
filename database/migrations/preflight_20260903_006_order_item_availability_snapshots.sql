-- READ-ONLY preflight for migration 006. Every returned row is a blocker.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SELECT 'order_items table is missing or is not InnoDB' AS migration_blocker
WHERE NOT EXISTS (
    SELECT 1 FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'order_items' AND engine = 'InnoDB'
);

SELECT CONCAT(expected.column_name, ' is missing or has unexpected type/nullability') AS schema_blocker
FROM (
    SELECT 'order_id' AS column_name, 'NO' AS expected_nullable, 'integer' AS expected_family
    UNION ALL SELECT 'product_name', 'NO', 'text'
    UNION ALL SELECT 'quantity', 'NO', 'integer'
    UNION ALL SELECT 'price', 'NO', 'decimal'
) expected
LEFT JOIN information_schema.columns actual
  ON actual.table_schema = DATABASE() AND actual.table_name = 'order_items'
 AND actual.column_name = expected.column_name
WHERE actual.column_name IS NULL OR actual.is_nullable <> expected.expected_nullable
   OR (expected.expected_family = 'integer' AND actual.data_type NOT IN ('tinyint','smallint','mediumint','int','bigint'))
   OR (expected.expected_family = 'text' AND actual.data_type NOT IN ('varchar','char','text','mediumtext','longtext'))
   OR (expected.expected_family = 'decimal' AND actual.data_type NOT IN ('decimal','numeric'));

SELECT CONCAT('order_items.', column_name, ' already exists') AS migration_blocker
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'order_items'
  AND column_name IN ('product_id', 'product_variant_id', 'supplier_offer_id_at_order',
                      'availability_status_at_order', 'expected_arrival_at_order');

SELECT CONCAT('index ', index_name, ' already exists') AS migration_blocker
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name = 'order_items'
  AND index_name IN ('idx_order_items_product_variant', 'idx_order_items_supplier_offer_snapshot');

SELECT CONCAT(expected.table_name, '.', expected.column_name, ' has unexpected referenced type') AS schema_blocker
FROM (
    SELECT 'products' AS table_name, 'id' AS column_name, 'int unsigned' AS expected_type
    UNION ALL SELECT 'product_variants', 'id', 'bigint unsigned'
    UNION ALL SELECT 'supplier_offers', 'id', 'bigint unsigned'
) expected
LEFT JOIN information_schema.columns actual
  ON actual.table_schema = DATABASE() AND actual.table_name = expected.table_name
 AND actual.column_name = expected.column_name
WHERE actual.column_name IS NULL OR actual.column_type <> expected.expected_type;

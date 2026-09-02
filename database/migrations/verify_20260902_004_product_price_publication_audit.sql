-- READ-ONLY verification for migration 004. Expected: one table row,
-- zero schema_problem rows, zero write-capable audit triggers.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SELECT table_name, engine, table_collation
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'product_price_publication_audit';

SELECT expected.column_name AS schema_problem
FROM (
    SELECT 'id' AS column_name, 'bigint unsigned' AS column_type, 'NO' AS is_nullable
    UNION ALL SELECT 'product_id', 'int unsigned', 'NO'
    UNION ALL SELECT 'product_variant_id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'supplier_id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'supplier_offer_id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'pricing_rule_id', 'bigint unsigned', 'NO'
    UNION ALL SELECT 'old_live_price', 'decimal(12,2)', 'NO'
    UNION ALL SELECT 'new_live_price', 'decimal(12,2)', 'NO'
    UNION ALL SELECT 'purchase_price', 'decimal(15,2)', 'NO'
    UNION ALL SELECT 'currency_code', 'char(3)', 'NO'
    UNION ALL SELECT 'created_at', 'timestamp', 'NO'
) expected
LEFT JOIN information_schema.columns actual
  ON actual.table_schema = DATABASE()
 AND actual.table_name = 'product_price_publication_audit'
 AND actual.column_name = expected.column_name
WHERE actual.column_name IS NULL
   OR actual.column_type <> expected.column_type
   OR actual.is_nullable <> expected.is_nullable;

SELECT trigger_name AS audit_trigger_problem
FROM information_schema.triggers
WHERE trigger_schema = DATABASE()
  AND event_object_table = 'product_price_publication_audit';

SELECT COUNT(*) AS audit_rows
FROM product_price_publication_audit;

-- Read-only verification immediately after the PHP collision runner 000 and
-- before supplier infrastructure migration 001. Every *_problem result set
-- must be empty. The final legacy row count must be 0.

SELECT 'product_variants still exists after 000' AS table_problem
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'product_variants';

SELECT 'product_variants_legacy is missing after 000' AS legacy_table_problem
WHERE NOT EXISTS (
    SELECT 1
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'product_variants_legacy'
      AND table_type = 'BASE TABLE'
      AND engine = 'InnoDB'
      AND table_collation = 'utf8mb4_0900_ai_ci'
);

SELECT expected.column_name AS missing_or_changed_legacy_column
FROM (
    SELECT 1 AS ordinal_position, 'id' AS column_name, 'int unsigned' AS column_type, 'NO' AS is_nullable
    UNION ALL SELECT 2, 'product_id', 'int unsigned', 'NO'
    UNION ALL SELECT 3, 'name', 'varchar(255)', 'NO'
    UNION ALL SELECT 4, 'country', 'varchar(100)', 'NO'
    UNION ALL SELECT 5, 'price', 'decimal(12,2)', 'NO'
    UNION ALL SELECT 6, 'old_price', 'decimal(12,2)', 'YES'
    UNION ALL SELECT 7, 'is_active', 'tinyint(1)', 'NO'
    UNION ALL SELECT 8, 'created_at', 'timestamp', 'NO'
    UNION ALL SELECT 9, 'updated_at', 'timestamp', 'NO'
) AS expected
LEFT JOIN information_schema.columns actual
  ON actual.table_schema = DATABASE()
 AND actual.table_name = 'product_variants_legacy'
 AND actual.ordinal_position = expected.ordinal_position
 AND actual.column_name = expected.column_name
 AND actual.column_type = expected.column_type
 AND actual.is_nullable = expected.is_nullable
WHERE actual.column_name IS NULL;

SELECT 'unexpected legacy column count' AS legacy_column_problem
WHERE (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'product_variants_legacy'
) <> 9;

SELECT expected.index_name AS missing_or_changed_legacy_index
FROM (
    SELECT 'PRIMARY' AS index_name, 'id' AS column_name, 0 AS non_unique
    UNION ALL SELECT 'idx_product_id', 'product_id', 1
    UNION ALL SELECT 'idx_country', 'country', 1
) AS expected
LEFT JOIN information_schema.statistics actual
  ON actual.table_schema = DATABASE()
 AND actual.table_name = 'product_variants_legacy'
 AND actual.index_name = expected.index_name
 AND actual.column_name = expected.column_name
 AND actual.seq_in_index = 1
 AND actual.non_unique = expected.non_unique
WHERE actual.index_name IS NULL;

SELECT 'legacy product foreign key missing or changed' AS legacy_fk_problem
WHERE NOT EXISTS (
    SELECT 1
    FROM information_schema.key_column_usage kcu
    JOIN information_schema.referential_constraints rc
      ON rc.constraint_schema = kcu.constraint_schema
     AND rc.constraint_name = kcu.constraint_name
    WHERE kcu.table_schema = DATABASE()
      AND kcu.table_name = 'product_variants_legacy'
      AND kcu.constraint_name = 'fk_product_variants_product'
      AND kcu.column_name = 'product_id'
      AND kcu.referenced_table_schema = DATABASE()
      AND kcu.referenced_table_name = 'products'
      AND kcu.referenced_column_name = 'id'
      AND rc.update_rule = 'CASCADE'
      AND rc.delete_rule = 'CASCADE'
);

SELECT COUNT(*) AS legacy_row_count
FROM product_variants_legacy;

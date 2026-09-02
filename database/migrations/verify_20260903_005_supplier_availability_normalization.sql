-- READ-ONLY verification for migration 005.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SELECT table_name, engine, table_collation
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'supplier_availability_mappings';

SELECT column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND ((table_name = 'supplier_import_profiles' AND column_name = 'arrival_date_format')
    OR (table_name = 'supplier_availability_mappings'
        AND column_name IN ('id', 'import_profile_id', 'raw_value', 'raw_value_hash',
                            'collation_weight_hash', 'normalized_status', 'is_active')))
ORDER BY table_name, ordinal_position;

SELECT constraint_name, delete_rule, update_rule
FROM information_schema.referential_constraints
WHERE constraint_schema = DATABASE()
  AND table_name = 'supplier_availability_mappings';

SELECT COUNT(*) AS mapping_rows FROM supplier_availability_mappings;

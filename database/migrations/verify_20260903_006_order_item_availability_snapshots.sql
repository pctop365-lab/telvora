-- READ-ONLY verification for migration 006.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SELECT column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'order_items'
  AND column_name IN ('product_id', 'product_variant_id', 'supplier_offer_id_at_order',
                      'availability_status_at_order', 'expected_arrival_at_order')
ORDER BY ordinal_position;

SELECT index_name, seq_in_index, column_name, non_unique
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name = 'order_items'
  AND index_name IN ('idx_order_items_product_variant', 'idx_order_items_supplier_offer_snapshot')
ORDER BY index_name, seq_in_index;

SELECT COUNT(*) AS invalid_snapshot_status_rows
FROM order_items
WHERE availability_status_at_order IS NOT NULL
  AND availability_status_at_order NOT IN ('in_stock', 'out_of_stock', 'expected', 'unknown');

SELECT COUNT(*) AS legacy_rows_without_stage11_snapshot
FROM order_items
WHERE product_id IS NULL AND product_variant_id IS NULL
  AND supplier_offer_id_at_order IS NULL AND availability_status_at_order IS NULL
  AND expected_arrival_at_order IS NULL;

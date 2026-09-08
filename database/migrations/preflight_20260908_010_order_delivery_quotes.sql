SELECT 'orders table is missing or is not InnoDB' AS migration_blocker
WHERE NOT EXISTS (
  SELECT 1 FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'orders' AND engine = 'InnoDB'
);

SELECT CONCAT('orders.', column_name, ' already exists') AS migration_blocker
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'orders'
  AND column_name IN ('subtotal','delivery_price','delivery_quote_status','delivery_details');

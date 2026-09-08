SELECT column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'orders'
  AND column_name IN ('subtotal','delivery_price','delivery_quote_status','delivery_details')
ORDER BY ordinal_position;

SELECT COUNT(*) AS invalid_delivery_quote_statuses
FROM orders
WHERE delivery_quote_status IS NOT NULL
  AND delivery_quote_status NOT IN ('confirmed','pending','legacy');

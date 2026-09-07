-- Must return no rows before applying migration 008.
SELECT 'missing_product_variants' AS blocker
WHERE NOT EXISTS (
    SELECT 1 FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'product_variants'
);

SELECT 'price_override_table_already_exists' AS blocker
WHERE EXISTS (
    SELECT 1 FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'product_variant_price_overrides'
);

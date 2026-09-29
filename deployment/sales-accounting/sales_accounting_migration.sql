-- Apply before deploying api.php, manager.php and sales_export.php.
-- Existing orders cannot be assigned a historical purchase price retroactively.
CREATE TABLE IF NOT EXISTS order_item_purchase_snapshots (
    order_item_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    supplier_offer_id BIGINT UNSIGNED NOT NULL,
    supplier_name VARCHAR(255) NOT NULL,
    supplier_sku VARCHAR(255) NULL,
    purchase_price DECIMAL(15, 2) NOT NULL,
    currency_code VARCHAR(3) NOT NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE orders
    ADD COLUMN completed_at DATETIME NULL DEFAULT NULL;

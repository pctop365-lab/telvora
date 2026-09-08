ALTER TABLE orders
    ADD COLUMN subtotal DECIMAL(12,2) NULL AFTER comment,
    ADD COLUMN delivery_price DECIMAL(12,2) NULL AFTER subtotal,
    ADD COLUMN delivery_quote_status VARCHAR(32) NULL AFTER delivery_price,
    ADD COLUMN delivery_details TEXT NULL AFTER delivery_quote_status;

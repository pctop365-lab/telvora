-- Stage 11: additive identity and availability snapshots for new order items.
-- Run only after the read-only preflight returns no blockers.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE order_items
    ADD COLUMN product_id INT UNSIGNED NULL AFTER order_id,
    ADD COLUMN product_variant_id BIGINT UNSIGNED NULL AFTER product_id,
    ADD COLUMN supplier_offer_id_at_order BIGINT UNSIGNED NULL AFTER product_variant_id,
    ADD COLUMN availability_status_at_order VARCHAR(50) NULL AFTER supplier_offer_id_at_order,
    ADD COLUMN expected_arrival_at_order DATETIME NULL AFTER availability_status_at_order,
    ADD KEY idx_order_items_product_variant (product_variant_id),
    ADD KEY idx_order_items_supplier_offer_snapshot (supplier_offer_id_at_order);

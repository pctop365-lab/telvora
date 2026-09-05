-- Stage 12F-A: nullable product brand foundation for existing catalog compatibility.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE products
    ADD COLUMN brand VARCHAR(100) NULL AFTER name,
    ADD KEY idx_products_brand (brand);

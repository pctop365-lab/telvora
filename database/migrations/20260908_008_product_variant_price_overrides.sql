SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE product_variant_price_overrides (
    product_variant_id BIGINT UNSIGNED NOT NULL,
    manual_price DECIMAL(12,2) NOT NULL,
    manual_old_price DECIMAL(12,2) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (product_variant_id),
    KEY idx_variant_price_overrides_active (is_active, product_variant_id),
    CONSTRAINT fk_variant_price_overrides_variant
        FOREIGN KEY (product_variant_id) REFERENCES product_variants (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_variant_price_override_price
        CHECK (manual_price > 0),
    CONSTRAINT chk_variant_price_override_old_price
        CHECK (manual_old_price IS NULL OR manual_old_price > 0),
    CONSTRAINT chk_variant_price_override_active
        CHECK (is_active IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

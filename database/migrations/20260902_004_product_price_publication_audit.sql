-- Append-only audit infrastructure for explicit supplier Candidate publication.
-- Run only after preflight_20260902_004_product_price_publication_audit.sql
-- returns no rows. This migration does not modify product or pricing data.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE product_price_publication_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    product_variant_id BIGINT UNSIGNED NOT NULL,
    supplier_id BIGINT UNSIGNED NOT NULL,
    supplier_offer_id BIGINT UNSIGNED NOT NULL,
    supplier_sku VARCHAR(191) DEFAULT NULL,
    pricing_rule_id BIGINT UNSIGNED NOT NULL,
    source_import_row_id BIGINT UNSIGNED DEFAULT NULL,
    source_import_job_id BIGINT UNSIGNED DEFAULT NULL,
    variant_key VARCHAR(191) NOT NULL,
    assembly_country VARCHAR(100) NOT NULL,
    old_live_price DECIMAL(12,2) NOT NULL,
    new_live_price DECIMAL(12,2) NOT NULL,
    purchase_price DECIMAL(15,2) NOT NULL,
    currency_code CHAR(3) NOT NULL,
    margin_amount DECIMAL(15,2) NOT NULL,
    margin_percent DECIMAL(9,4) NOT NULL,
    source_type VARCHAR(50) NOT NULL,
    admin_actor VARCHAR(100) NOT NULL,
    admin_comment VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_price_pub_audit_product_created (product_id, created_at, id),
    KEY idx_price_pub_audit_variant_created (product_variant_id, created_at, id),
    KEY idx_price_pub_audit_offer (supplier_offer_id),
    KEY idx_price_pub_audit_rule (pricing_rule_id),
    KEY idx_price_pub_audit_source_row (source_import_row_id),
    KEY idx_price_pub_audit_source_job (source_import_job_id),
    CONSTRAINT fk_price_pub_audit_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_price_pub_audit_variant
        FOREIGN KEY (product_variant_id) REFERENCES product_variants (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_price_pub_audit_supplier
        FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_price_pub_audit_offer
        FOREIGN KEY (supplier_offer_id) REFERENCES supplier_offers (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_price_pub_audit_rule
        FOREIGN KEY (pricing_rule_id) REFERENCES pricing_rules (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_price_pub_audit_source_row
        FOREIGN KEY (source_import_row_id) REFERENCES supplier_import_rows (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_price_pub_audit_source_job
        FOREIGN KEY (source_import_job_id) REFERENCES supplier_import_jobs (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

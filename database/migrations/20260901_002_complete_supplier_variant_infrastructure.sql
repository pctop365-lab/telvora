-- TELVORA supplier/variant infrastructure: resume the interrupted stage 1.
-- Run only after preflight_20260901_002_complete_supplier_variant_infrastructure.sql
-- reports no violations. This migration intentionally creates only the three
-- tables that were not created when canonical migration 001 stopped.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE supplier_import_rows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    import_job_id BIGINT UNSIGNED NOT NULL,
    source_row_number INT UNSIGNED NOT NULL,
    supplier_sku VARCHAR(191) DEFAULT NULL,
    raw_product_name VARCHAR(500) DEFAULT NULL,
    normalized_product_name VARCHAR(500) DEFAULT NULL,
    normalized_model VARCHAR(255) DEFAULT NULL,
    purchase_price DECIMAL(15,2) DEFAULT NULL,
    currency_code CHAR(3) DEFAULT NULL,
    raw_availability VARCHAR(255) DEFAULT NULL,
    normalized_availability VARCHAR(50) DEFAULT NULL,
    raw_arrival_info VARCHAR(255) DEFAULT NULL,
    detected_assembly_country VARCHAR(100) DEFAULT NULL,
    detected_market_region VARCHAR(255) DEFAULT NULL,
    detected_certification_supply_type VARCHAR(255) DEFAULT NULL,
    variant_detection_evidence JSON DEFAULT NULL,
    matched_product_id INT UNSIGNED DEFAULT NULL,
    matched_product_variant_id BIGINT UNSIGNED DEFAULT NULL,
    match_id BIGINT UNSIGNED DEFAULT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'requires_matching',
    review_reason VARCHAR(1000) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_supplier_import_rows_job_row
        (import_job_id, source_row_number),
    KEY idx_supplier_import_rows_job_status (import_job_id, status),
    KEY idx_supplier_import_rows_sku (supplier_sku),
    KEY idx_supplier_import_rows_model (normalized_model),
    KEY idx_supplier_import_rows_product (matched_product_id),
    KEY idx_supplier_import_rows_variant (matched_product_variant_id),
    KEY idx_supplier_import_rows_match (match_id),
    CONSTRAINT fk_supplier_import_rows_job
        FOREIGN KEY (import_job_id) REFERENCES supplier_import_jobs (id)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT fk_supplier_import_rows_product
        FOREIGN KEY (matched_product_id) REFERENCES products (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_supplier_import_rows_variant
        FOREIGN KEY (matched_product_variant_id) REFERENCES product_variants (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_supplier_import_rows_match
        FOREIGN KEY (match_id) REFERENCES supplier_product_matches (id)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_offers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id BIGINT UNSIGNED NOT NULL,
    product_variant_id BIGINT UNSIGNED NOT NULL,
    supplier_sku VARCHAR(191) DEFAULT NULL,
    supplier_product_name VARCHAR(500) NOT NULL,
    purchase_price DECIMAL(15,2) NOT NULL,
    currency_code CHAR(3) NOT NULL,
    availability_status VARCHAR(50) NOT NULL,
    stock_quantity INT UNSIGNED DEFAULT NULL,
    expected_arrival_at DATETIME DEFAULT NULL,
    delivery_info VARCHAR(500) DEFAULT NULL,
    source_import_row_id BIGINT UNSIGNED DEFAULT NULL,
    source_updated_at DATETIME DEFAULT NULL,
    imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_supplier_offers_sku (supplier_id, supplier_sku),
    KEY idx_supplier_offers_variant_eligibility
        (product_variant_id, is_active, availability_status, purchase_price),
    KEY idx_supplier_offers_supplier_active (supplier_id, is_active),
    KEY idx_supplier_offers_source_row (source_import_row_id),
    KEY idx_supplier_offers_freshness (source_updated_at, imported_at),
    CONSTRAINT fk_supplier_offers_supplier
        FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_supplier_offers_variant
        FOREIGN KEY (product_variant_id) REFERENCES product_variants (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_supplier_offers_source_row
        FOREIGN KEY (source_import_row_id) REFERENCES supplier_import_rows (id)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pricing_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    priority INT NOT NULL DEFAULT 100,
    category_scope VARCHAR(100) DEFAULT NULL,
    purchase_price_min DECIMAL(15,2) DEFAULT NULL,
    purchase_price_max DECIMAL(15,2) DEFAULT NULL,
    markup_percent DECIMAL(9,4) DEFAULT NULL,
    minimum_margin DECIMAL(15,2) DEFAULT NULL,
    rounding_strategy VARCHAR(50) DEFAULT NULL,
    rounding_parameters JSON DEFAULT NULL,
    additional_scope JSON DEFAULT NULL,
    valid_from DATETIME DEFAULT NULL,
    valid_until DATETIME DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_pricing_rules_name (name),
    KEY idx_pricing_rules_selection
        (is_active, priority, category_scope, purchase_price_min, purchase_price_max),
    KEY idx_pricing_rules_validity (valid_from, valid_until)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

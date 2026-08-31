-- TELVORA supplier/variant architecture: infrastructure stage 1.
-- MySQL/InnoDB, additive only. This migration does not alter or seed products.
-- It is safe to apply separately from the current catalog application because
-- no existing application table reads from these tables.
-- Prerequisite on the current TELVORA production schema:
-- run_20260831_000_resolve_legacy_product_variants.php must complete first.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppliers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    internal_code VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_suppliers_internal_code (internal_code),
    KEY idx_suppliers_active_name (is_active, name)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
-- Managed dictionaries are intentionally empty. Concrete market and
-- certification/supply values are data, not hardcoded SQL enums.
CREATE TABLE IF NOT EXISTS variant_market_regions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(100) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    aliases JSON DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_variant_market_regions_code (code),
    KEY idx_variant_market_regions_active_name (is_active, display_name)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS variant_certification_supply_types (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(100) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    aliases JSON DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_variant_cert_supply_types_code (code),
    KEY idx_variant_cert_supply_types_active_name (is_active, display_name)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- Deliberately no IF NOT EXISTS: an unexpected pre-existing table must stop
-- this migration instead of being accepted with an incompatible definition.
CREATE TABLE product_variants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    variant_key VARCHAR(191) NOT NULL,
    assembly_country VARCHAR(100) DEFAULT NULL,
    market_region_id BIGINT UNSIGNED DEFAULT NULL,
    certification_supply_type_id BIGINT UNSIGNED DEFAULT NULL,
    manufacturer_part_number VARCHAR(191) DEFAULT NULL,
    display_name VARCHAR(255) DEFAULT NULL,
    classification_status VARCHAR(50) NOT NULL DEFAULT 'requires_classification',
    classification_evidence JSON DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_product_variants_product_key (product_id, variant_key),
    KEY idx_product_variants_product_active (product_id, is_active),
    KEY idx_product_variants_market (market_region_id),
    KEY idx_product_variants_cert_supply (certification_supply_type_id),
    KEY idx_product_variants_mpn (manufacturer_part_number),
    KEY idx_product_variants_classification (classification_status),
    -- The legacy table retains fk_product_variants_product after rename, so
    -- the new FK uses a distinct schema-wide constraint name.
    CONSTRAINT fk_product_variants_canonical_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_product_variants_market
        FOREIGN KEY (market_region_id) REFERENCES variant_market_regions (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_product_variants_cert_supply
        FOREIGN KEY (certification_supply_type_id)
        REFERENCES variant_certification_supply_types (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_import_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    sheet_name VARCHAR(255) DEFAULT NULL,
    header_row_number INT UNSIGNED NOT NULL DEFAULT 1,
    sku_column VARCHAR(50) DEFAULT NULL,
    product_name_column VARCHAR(50) DEFAULT NULL,
    purchase_price_column VARCHAR(50) DEFAULT NULL,
    stock_column VARCHAR(50) DEFAULT NULL,
    arrival_column VARCHAR(50) DEFAULT NULL,
    variant_region_column VARCHAR(50) DEFAULT NULL,
    column_mapping JSON DEFAULT NULL,
    parser_options JSON DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_supplier_import_profiles_name (supplier_id, name),
    KEY idx_supplier_import_profiles_active (supplier_id, is_active),
    CONSTRAINT fk_supplier_import_profiles_supplier
        FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_import_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id BIGINT UNSIGNED NOT NULL,
    import_profile_id BIGINT UNSIGNED DEFAULT NULL,
    original_filename VARCHAR(255) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    rows_total INT UNSIGNED NOT NULL DEFAULT 0,
    rows_matched INT UNSIGNED NOT NULL DEFAULT 0,
    rows_unmatched INT UNSIGNED NOT NULL DEFAULT 0,
    rows_errors INT UNSIGNED NOT NULL DEFAULT 0,
    error_summary VARCHAR(1000) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP NULL DEFAULT NULL,

    PRIMARY KEY (id),
    KEY idx_supplier_import_jobs_supplier_created (supplier_id, created_at),
    KEY idx_supplier_import_jobs_status_created (status, created_at),
    KEY idx_supplier_import_jobs_profile (import_profile_id),
    CONSTRAINT fk_supplier_import_jobs_supplier
        FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_supplier_import_jobs_profile
        FOREIGN KEY (import_profile_id) REFERENCES supplier_import_profiles (id)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_product_matches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_id BIGINT UNSIGNED NOT NULL,
    supplier_sku VARCHAR(191) DEFAULT NULL,
    normalized_model VARCHAR(255) DEFAULT NULL,
    product_id INT UNSIGNED DEFAULT NULL,
    product_variant_id BIGINT UNSIGNED DEFAULT NULL,
    match_method VARCHAR(50) NOT NULL,
    confidence DECIMAL(5,4) DEFAULT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'requires_matching',
    variant_confirmation_source VARCHAR(100) DEFAULT NULL,
    variant_confirmation_evidence JSON DEFAULT NULL,
    reviewed_by VARCHAR(191) DEFAULT NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_supplier_product_matches_sku (supplier_id, supplier_sku),
    KEY idx_supplier_product_matches_model (supplier_id, normalized_model),
    KEY idx_supplier_product_matches_product (product_id),
    KEY idx_supplier_product_matches_variant (product_variant_id),
    KEY idx_supplier_product_matches_review (status, is_active),
    CONSTRAINT fk_supplier_product_matches_supplier
        FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_supplier_product_matches_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_supplier_product_matches_variant
        FOREIGN KEY (product_variant_id) REFERENCES product_variants (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_import_rows (
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
    UNIQUE KEY uq_supplier_import_rows_job_row (import_job_id, source_row_number),
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

CREATE TABLE IF NOT EXISTS supplier_offers (
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

CREATE TABLE IF NOT EXISTS pricing_rules (
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

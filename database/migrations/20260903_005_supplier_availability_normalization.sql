-- Profile-specific exact supplier availability normalization infrastructure.
-- Run only after the read-only preflight returns no rows. No data is seeded or rewritten.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE supplier_import_profiles
    ADD COLUMN arrival_date_format VARCHAR(20) DEFAULT NULL AFTER parser_options;

CREATE TABLE supplier_availability_mappings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    import_profile_id BIGINT UNSIGNED NOT NULL,
    raw_value VARCHAR(191) NOT NULL,
    raw_value_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    collation_weight_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    normalized_status VARCHAR(50) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_supplier_availability_exact (import_profile_id, raw_value_hash),
    UNIQUE KEY uq_supplier_availability_collation (import_profile_id, collation_weight_hash),
    KEY idx_supplier_availability_lookup (import_profile_id, is_active),
    CONSTRAINT fk_supplier_availability_profile
        FOREIGN KEY (import_profile_id) REFERENCES supplier_import_profiles (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

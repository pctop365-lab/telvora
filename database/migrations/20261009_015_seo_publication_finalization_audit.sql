-- Additive audit only. No product or publication job changes.
CREATE TABLE IF NOT EXISTS seo_publication_finalization_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    evidence_key CHAR(64) NOT NULL,
    source VARCHAR(32) NOT NULL,
    release_sha CHAR(40) NOT NULL,
    package_sha256 CHAR(64) NOT NULL,
    snapshot_hash CHAR(64) NOT NULL,
    audit_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_seo_finalization_evidence (evidence_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SEO publication state/job substrate (Phase 1A).
-- Review and execute only against an explicitly migrated disposable schema first.
-- This migration does not inspect or alter the legacy variants representation.

ALTER TABLE products
    ADD COLUMN publication_status VARCHAR(32) NULL AFTER is_active,
    ADD COLUMN publication_revision BIGINT UNSIGNED NULL DEFAULT 0 AFTER publication_status;

UPDATE products
SET publication_status = CASE
    WHEN is_active = 1 THEN 'published'
    ELSE 'draft'
END,
publication_revision = COALESCE(publication_revision, 0)
WHERE publication_status IS NULL;

ALTER TABLE products
    MODIFY COLUMN publication_status VARCHAR(32) NOT NULL DEFAULT 'draft',
    MODIFY COLUMN publication_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ADD KEY idx_products_publication (publication_status, is_active);

CREATE TABLE seo_publication_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    operation VARCHAR(16) NOT NULL,
    requested_revision BIGINT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'queued',
    batch_id CHAR(36) NOT NULL,
    snapshot_hash CHAR(64) DEFAULT NULL,
    release_sha CHAR(40) DEFAULT NULL,
    package_sha256 CHAR(64) DEFAULT NULL,
    backup_reference VARCHAR(512) DEFAULT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL DEFAULT NULL,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_seo_publication_job_intent (product_id, requested_revision, operation),
    KEY idx_seo_jobs_product_revision (product_id, requested_revision),
    KEY idx_seo_jobs_status_batch (status, batch_id),
    CONSTRAINT chk_seo_publication_job_operation CHECK (operation IN ('publish', 'unpublish')),
    CONSTRAINT chk_seo_publication_job_status CHECK (status IN ('queued', 'running', 'completed', 'failed', 'superseded')),
    CONSTRAINT fk_seo_publication_job_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

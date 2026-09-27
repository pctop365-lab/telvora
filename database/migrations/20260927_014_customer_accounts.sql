-- Apply once, before deploying the customer PHP/frontend files. No legacy backfill.
CREATE TABLE customers (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 login VARCHAR(64) NOT NULL,
 login_normalized VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 password_hash VARCHAR(255) NOT NULL,
 full_name VARCHAR(200) NOT NULL DEFAULT '',
 phone VARCHAR(32) NOT NULL DEFAULT '',
 email VARCHAR(254) NOT NULL DEFAULT '',
 email_verified_at DATETIME NULL,
 address VARCHAR(1000) NOT NULL DEFAULT '',
 auth_version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_customer_login (login_normalized)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders
 ADD COLUMN customer_id BIGINT UNSIGNED NULL,
 ADD KEY idx_orders_customer (customer_id, id),
 ADD CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT;

CREATE TABLE customer_auth_limits (
 bucket CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 attempts INT UNSIGNED NOT NULL,
 expires_at BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB;

CREATE TABLE customer_tokens (
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 customer_id BIGINT UNSIGNED NOT NULL,
 purpose ENUM('verify','reset') NOT NULL,
 email VARCHAR(254) NOT NULL,
 expires_at DATETIME NOT NULL,
 KEY idx_customer_tokens_owner (customer_id, purpose),
 CONSTRAINT fk_customer_token FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

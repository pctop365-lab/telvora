CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    series VARCHAR(100) NOT NULL,
    category VARCHAR(20) NOT NULL,
    screen_size VARCHAR(50) NOT NULL,
    resolution VARCHAR(100) NOT NULL,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    old_price DECIMAL(12,2) DEFAULT NULL,
    image TEXT NOT NULL,
    badge VARCHAR(100) DEFAULT NULL,
    rating DECIMAL(3,2) NOT NULL DEFAULT 0,
    reviews INT UNSIGNED NOT NULL DEFAULT 0,
    description TEXT NOT NULL,
    specs JSON NOT NULL,
    highlights JSON NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_products_slug (slug),
    KEY idx_products_category (category),
    KEY idx_products_active (is_active)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

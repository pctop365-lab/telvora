ALTER TABLE products
    ADD COLUMN homepage_position TINYINT UNSIGNED NULL AFTER is_active,
    ADD UNIQUE KEY uq_products_homepage_position (homepage_position),
    ADD CONSTRAINT chk_products_homepage_position
        CHECK (homepage_position IS NULL OR homepage_position BETWEEN 1 AND 9);

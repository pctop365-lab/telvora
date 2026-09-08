SELECT COUNT(*) AS products_without_id FROM products WHERE id IS NULL;
SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_images';

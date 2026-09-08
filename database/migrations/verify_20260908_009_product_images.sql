SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_images';
SELECT INDEX_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_images' ORDER BY INDEX_NAME;
SELECT COUNT(*) AS invalid_rows FROM product_images WHERE position >= 10 OR image_path = '';

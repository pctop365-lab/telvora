SELECT
    CASE
        WHEN COUNT(*) = 1 THEN 'PASS: products exists with INT UNSIGNED primary key'
        ELSE 'FAIL: unexpected products primary key definition'
    END AS result
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'products'
  AND COLUMN_NAME = 'id'
  AND COLUMN_TYPE = 'int unsigned'
  AND COLUMN_KEY = 'PRI';

SELECT CASE WHEN COUNT(*) = 0 THEN 'PASS: homepage_position absent and migration is safe' ELSE 'FAIL: homepage_position already exists; stop' END AS result
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'homepage_position';

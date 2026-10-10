<?php

declare(strict_types=1);

require_once __DIR__ . '/runtime_config.php';
require_once __DIR__ . '/storefront_product_service.php';
require_once __DIR__ . '/product_gallery_service.php';
require_once __DIR__ . '/price_ru_feed_service.php';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=300, stale-while-revalidate=60');
try {
    $secretsFile = telvoraSecretsFile();
    if (!is_file($secretsFile) || !is_readable($secretsFile)) throw new RuntimeException('Feed temporarily unavailable');
    $secrets = require $secretsFile;
    foreach (['db_host', 'db_name', 'db_user', 'db_password'] as $key) {
        if (!is_string($secrets[$key] ?? null) || $secrets[$key] === '') throw new RuntimeException('Feed temporarily unavailable');
    }
    $pdo = new PDO(
        'mysql:host=' . $secrets['db_host'] . ';dbname=' . $secrets['db_name'] . ';charset=utf8mb4',
        $secrets['db_user'], $secrets['db_password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $stmt = $pdo->query("SELECT id, slug, name, brand, category, screen_size, description, variants, image, is_active
                         FROM products WHERE is_active = 1 ORDER BY id DESC");
    $products = productGalleryAttach($pdo, attachStorefrontVariants($pdo, $stmt->fetchAll()));
    echo priceRuFeedXml($products);
} catch (Throwable $error) {
    error_log('Price.ru feed generation failed: ' . $error->getMessage());
    http_response_code(503);
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<error>Feed temporarily unavailable</error>\n";
}

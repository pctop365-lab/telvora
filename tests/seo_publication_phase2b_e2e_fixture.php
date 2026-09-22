<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/seo_publication_job_service.php';

function phase2bE2ePdo(): PDO
{
    $host = getenv('TELVORA_TEST_DB_HOST');
    $name = getenv('TELVORA_TEST_DB_NAME');
    if (!in_array($host, ['127.0.0.1', 'localhost'], true) || $name !== 'telvora_phase2b_test') throw new RuntimeException('Refusing non-disposable Phase 2B database');
    return new PDO('mysql:host=' . $host . ';port=' . (int)getenv('TELVORA_TEST_DB_PORT') . ';dbname=' . $name . ';charset=utf8mb4', (string)getenv('TELVORA_TEST_DB_USER'), (string)getenv('TELVORA_TEST_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function phase2bE2eSql(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) throw new RuntimeException('Cannot read Phase 2B migration');
    foreach (preg_split('/;\s*(?:\r?\n|\z)/', $sql) ?: [] as $statement) {
        $statement = trim((string)preg_replace('/\A(?:\s*--[^\r\n]*(?:\r?\n|\z))+/', '', $statement));
        if ($statement !== '') $pdo->exec($statement);
    }
}

$pdo = phase2bE2ePdo();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['seo_publication_jobs', 'product_variant_price_overrides', 'product_variants', 'service_catalog', 'products'] as $table) $pdo->exec("DROP TABLE IF EXISTS `$table`");
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("CREATE TABLE products (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT, slug VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL,
    series VARCHAR(100) NOT NULL, category VARCHAR(20) NOT NULL, screen_size VARCHAR(50) NOT NULL,
    resolution VARCHAR(100) NOT NULL, price DECIMAL(12,2) NOT NULL DEFAULT 0, old_price DECIMAL(12,2) NULL,
    image TEXT NOT NULL, badge VARCHAR(100) NULL, rating DECIMAL(3,2) NOT NULL DEFAULT 0,
    reviews INT UNSIGNED NOT NULL DEFAULT 0, description TEXT NOT NULL, specs JSON NOT NULL,
    highlights JSON NOT NULL, variants JSON NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_products_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
phase2bE2eSql($pdo, dirname(__DIR__) . '/database/migrations/20260831_001_supplier_variant_infrastructure.sql');
phase2bE2eSql($pdo, dirname(__DIR__) . '/database/migrations/20260908_008_product_variant_price_overrides.sql');
$pdo->exec("CREATE TABLE service_catalog (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT, service_key VARCHAR(100) NOT NULL, category VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL, description TEXT NOT NULL, min_screen_size INT NULL, max_screen_size INT NULL,
    price DECIMAL(12,2) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0,
    requires_tv TINYINT(1) NOT NULL DEFAULT 1, metadata JSON NULL, PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$insert = $pdo->prepare("INSERT INTO products (id, slug, name, series, category, screen_size, resolution, price, image, description, specs, highlights, variants, is_active) VALUES (:id, :slug, :name, 'Phase 2B', 'OLED', '55', '4K', 100000, '/uploads/products/phase2b-test.png', 'Phase 2B fixture product', '{}', '[]', :variants, :active)");
$legacy = json_encode([['country' => 'Russia', 'price' => 100000, 'old_price' => null, 'is_active' => true]], JSON_THROW_ON_ERROR);
foreach ([[1, 'phase2b-pending-unpublish-a', 'Pending unpublish A', 1], [2, 'phase2b-pending-unpublish-b', 'Pending unpublish B', 1], [3, 'phase2b-draft', 'Draft product', 0], [4, 'phase2b-published', 'Published product', 1], [5, 'phase2b-pending-publish', 'Pending publish product', 0]] as [$id, $slug, $name, $active]) $insert->execute([':id' => $id, ':slug' => $slug, ':name' => $name, ':variants' => $legacy, ':active' => $active]);
phase2bE2eSql($pdo, dirname(__DIR__) . '/database/migrations/20260922_013_seo_publication_state.sql');
$pdo->exec("UPDATE products SET publication_status = CASE WHEN id IN (1,2) THEN 'pending_unpublish' WHEN id = 4 THEN 'published' WHEN id = 5 THEN 'pending_publish' ELSE 'draft' END, publication_revision = 1");
$variant = $pdo->prepare("INSERT INTO product_variants (id, product_id, variant_key, assembly_country, display_name, is_active) VALUES (:id, 5, :key, 'Russia', 'Russia', 1)");
$variant->execute([':id' => 5, ':key' => 'legacy-country-sha256-' . hash('sha256', 'Russia')]);
$job = $pdo->prepare("INSERT INTO seo_publication_jobs (product_id, operation, requested_revision, status, batch_id) VALUES (:product, :operation, 1, 'queued', :batch)");
$job->execute([':product' => 1, ':operation' => 'unpublish', ':batch' => seoPublicationNewBatchId()]);
$job->execute([':product' => 2, ':operation' => 'unpublish', ':batch' => seoPublicationNewBatchId()]);
$job->execute([':product' => 5, ':operation' => 'publish', ':batch' => seoPublicationNewBatchId()]);
echo "Phase 2B fixture ready\n";

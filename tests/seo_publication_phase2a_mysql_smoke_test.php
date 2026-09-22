<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/seo_publication_batch_service.php';
require_once dirname(__DIR__) . '/seo_publication_snapshot_service.php';

function phase2aSmokeAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("FAIL $message");
    echo "PASS $message\n";
}

function phase2aSmokePdo(): PDO
{
    $keys = ['TELVORA_TEST_DB_HOST', 'TELVORA_TEST_DB_PORT', 'TELVORA_TEST_DB_NAME', 'TELVORA_TEST_DB_USER', 'TELVORA_TEST_DB_PASSWORD'];
    foreach ($keys as $key) if (!is_string(getenv($key)) || trim((string)getenv($key)) === '') throw new RuntimeException("Missing test-only variable $key");
    $host = trim((string)getenv('TELVORA_TEST_DB_HOST'));
    $name = trim((string)getenv('TELVORA_TEST_DB_NAME'));
    if (!in_array($host, ['127.0.0.1', 'localhost'], true) || $name !== 'telvora_phase2a_test') throw new RuntimeException('Refusing non-disposable database configuration');
    return new PDO('mysql:host=' . $host . ';port=' . (int)getenv('TELVORA_TEST_DB_PORT') . ';dbname=' . $name . ';charset=utf8mb4', (string)getenv('TELVORA_TEST_DB_USER'), (string)getenv('TELVORA_TEST_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

function phase2aSmokeSqlFile(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) throw new RuntimeException('Cannot read migration');
    foreach (preg_split('/;\s*(?:\r?\n|\z)/', $sql) ?: [] as $statement) {
        $statement = trim((string)preg_replace('/\A(?:\s*--[^\r\n]*(?:\r?\n|\z))+/', '', $statement));
        if ($statement !== '') $pdo->exec($statement);
    }
}

$pdo = phase2aSmokePdo();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['seo_publication_jobs', 'service_catalog', 'products'] as $table) $pdo->exec("DROP TABLE IF EXISTS `$table`");
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
$pdo->exec("CREATE TABLE service_catalog (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT, service_key VARCHAR(100) NOT NULL, category VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL, description TEXT NOT NULL, min_screen_size INT NULL, max_screen_size INT NULL,
    price DECIMAL(12,2) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0,
    requires_tv TINYINT(1) NOT NULL DEFAULT 1, metadata JSON NULL, PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$insert = $pdo->prepare("INSERT INTO products (id, slug, name, series, category, screen_size, resolution, price, image, description, specs, highlights, variants, is_active) VALUES (:id, :slug, :name, 'Test', 'oled', '55', '4K', 100000, '/test.png', 'Smoke product', '{}', '[]', '[]', :active)");
foreach ([[1, 'pending-unpublish-a', 'Pending unpublish A', 1], [2, 'pending-unpublish-b', 'Pending unpublish B', 1], [3, 'draft-product', 'Draft product', 0], [4, 'published-product', 'Published product', 1]] as [$id, $slug, $name, $active]) $insert->execute([':id' => $id, ':slug' => $slug, ':name' => $name, ':active' => $active]);
phase2aSmokeSqlFile($pdo, dirname(__DIR__) . '/database/migrations/20260922_013_seo_publication_state.sql');
$pdo->exec("UPDATE products SET publication_status = CASE WHEN id IN (1,2) THEN 'pending_unpublish' WHEN id = 4 THEN 'published' ELSE 'draft' END, publication_revision = 1");
$job = $pdo->prepare("INSERT INTO seo_publication_jobs (product_id, operation, requested_revision, status, batch_id) VALUES (:product, 'unpublish', 1, 'queued', :batch)");
$job->execute([':product' => 1, ':batch' => seoPublicationNewBatchId()]);
$job->execute([':product' => 2, ':batch' => seoPublicationNewBatchId()]);
$pdo->prepare("INSERT INTO seo_publication_jobs (product_id, operation, requested_revision, status, batch_id) VALUES (3, 'publish', 99, 'queued', :batch)")->execute([':batch' => seoPublicationNewBatchId()]);
$pdo->prepare("INSERT INTO seo_publication_jobs (product_id, operation, requested_revision, status, batch_id) VALUES (4, 'unpublish', 1, 'superseded', :batch)")->execute([':batch' => seoPublicationNewBatchId()]);
$claimed = seoPublicationClaimQueuedBatch($pdo, 100);
phase2aSmokeAssert($claimed['status'] === 'CLAIMED' && count($claimed['jobs']) === 2, 'two current queued jobs claim into one batch');
phase2aSmokeAssert(count(array_unique(array_column($claimed['jobs'], 'batch_id'))) === 1 && $claimed['batch_id'] !== '', 'claimed jobs share one batch_id');
phase2aSmokeAssert(array_column($claimed['jobs'], 'product_id') === [1, 2], 'claimed jobs use deterministic product ordering');
phase2aSmokeAssert((int)$pdo->query("SELECT COUNT(*) FROM seo_publication_jobs WHERE status = 'queued' AND product_id = 3")->fetchColumn() === 1, 'stale job is not claimed');
phase2aSmokeAssert((int)$pdo->query("SELECT COUNT(*) FROM seo_publication_jobs WHERE status = 'superseded'")->fetchColumn() === 1, 'superseded job is not claimed');
$snapshot = seoPublicationBuildDesiredSnapshot($pdo, $claimed['batch_id'], $claimed['jobs']);
$snapshotSlugs = array_column($snapshot['products'], 'slug');
phase2aSmokeAssert($snapshotSlugs === ['published-product'], 'desired snapshot includes published and excludes pending_unpublish/draft');
phase2aSmokeAssert((bool)preg_match('/\A[0-9a-f]{64}\z/', $snapshot['snapshot_hash']), 'snapshot hash is SHA-256');
$snapshotDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'telvora-phase2a-smoke-' . bin2hex(random_bytes(4));
$snapshotPath = seoPublicationWriteSnapshot($snapshot, $snapshotDir);
phase2aSmokeAssert(is_file($snapshotPath) && json_decode((string)file_get_contents($snapshotPath), true)['batch_id'] === $claimed['batch_id'], 'snapshot writes atomically to private temp directory');
$pdo->exec('DELETE FROM seo_publication_jobs');
$empty = seoPublicationClaimQueuedBatch($pdo, 100);
phase2aSmokeAssert($empty['status'] === 'NO_WORK' && $empty['jobs'] === [], 'empty queue returns NO_WORK');
@unlink($snapshotPath); @rmdir($snapshotDir);
echo "PASS SEO PUBLICATION PHASE 2A MYSQL SMOKE\n";

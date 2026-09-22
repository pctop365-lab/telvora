<?php

declare(strict_types=1);

require_once __DIR__ . '/seo_publication_service.php';
require_once __DIR__ . '/service_catalog_service.php';

function seoPublicationSnapshotJson(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
}

function seoPublicationSnapshotProduct(array $product): array
{
    foreach (['id', 'reviews', 'homepage_position'] as $key) if (array_key_exists($key, $product) && $product[$key] !== null) $product[$key] = (int)$product[$key];
    foreach (['price', 'old_price', 'rating'] as $key) if (array_key_exists($key, $product) && $product[$key] !== null) $product[$key] = (float)$product[$key];
    $product['is_active'] = (bool)($product['is_active'] ?? false);
    foreach (['specs', 'highlights', 'variants'] as $key) {
        if (is_string($product[$key] ?? null)) $product[$key] = json_decode($product[$key], true) ?: [];
        if (!is_array($product[$key] ?? null)) $product[$key] = [];
    }
    return $product;
}

function seoPublicationBuildDesiredSnapshot(PDO $pdo, string $batchId, array $jobs): array
{
    seoPublicationAssertBatchId($batchId);
    $products = $pdo->query("SELECT * FROM products
        WHERE publication_status = 'published'
           OR (publication_status = 'pending_publish')
           OR (publication_status = 'unpublish_failed' AND is_active = 1)
        ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    $pending = [];
    foreach ($jobs as $job) if ((string)$job['operation'] === 'publish') $pending[(int)$job['product_id']] = true;
    foreach ($products as $product) {
        if ((string)$product['publication_status'] === 'pending_publish' || isset($pending[(int)$product['id']])) {
            productActivationValidateCandidate($pdo, (int)$product['id']);
        }
    }

    $products = array_map('seoPublicationSnapshotProduct', $products);
    usort($products, static function (array $a, array $b): int {
        $slugOrder = strcmp((string)$a['slug'], (string)$b['slug']);
        return $slugOrder !== 0 ? $slugOrder : ((int)$a['id'] <=> (int)$b['id']);
    });
    $services = serviceCatalogList($pdo, false);
    $payload = ['products' => $products, 'services' => $services];
    $hash = hash('sha256', seoPublicationSnapshotJson($payload));
    return [
        'version' => 1,
        'generated_at' => gmdate('c'),
        'batch_id' => $batchId,
        'snapshot_hash' => $hash,
        'products' => $products,
        'services' => $services,
    ];
}

function seoPublicationWriteSnapshot(array $snapshot, string $directory): string
{
    if ($directory === '' || str_contains($directory, "\0")) throw new InvalidArgumentException('Invalid snapshot directory');
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Cannot create snapshot directory');
    $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $snapshot['batch_id'] . '.json';
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(4));
    $json = seoPublicationSnapshotJson($snapshot) . "\n";
    if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Cannot write SEO snapshot');
    }
    return $path;
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/runtime_config.php';
require_once __DIR__ . '/seo_publication_batch_service.php';
require_once __DIR__ . '/seo_publication_snapshot_service.php';

function seoPublicationWorkerPdo(): PDO
{
    $secrets = telvoraSecretsFile();
    if (!is_file($secrets) || !is_readable($secrets)) throw new RuntimeException('SEO worker database configuration unavailable');
    $config = require $secrets;
    $pdo = new PDO('mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4', $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    return $pdo;
}

if (($argv[1] ?? '') !== 'prepare') {
    fwrite(STDERR, "Usage: php seo_publication_worker.php prepare\n");
    exit(2);
}

try {
    $pdo = seoPublicationWorkerPdo();
    $claimed = seoPublicationClaimQueuedBatch($pdo, 100);
    if ($claimed['jobs'] === []) {
        echo json_encode(['status' => 'NO_WORK'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }
    try {
        $snapshot = seoPublicationBuildDesiredSnapshot($pdo, $claimed['batch_id'], $claimed['jobs']);
        $directory = getenv('TELVORA_SEO_SNAPSHOT_DIR');
        $directory = is_string($directory) && $directory !== '' ? $directory : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'telvora-seo-snapshots';
        $path = seoPublicationWriteSnapshot($snapshot, $directory);
        $pdo->prepare('UPDATE seo_publication_jobs SET snapshot_hash = :hash WHERE batch_id = :batch_id AND status = \'running\'')->execute([':hash' => $snapshot['snapshot_hash'], ':batch_id' => $claimed['batch_id']]);
        echo json_encode(['status' => 'PREPARED', 'batch_id' => $claimed['batch_id'], 'snapshot_file' => $path, 'snapshot_hash' => $snapshot['snapshot_hash'], 'job_ids' => array_map(static fn(array $j): int => (int)$j['id'], $claimed['jobs']), 'product_ids' => array_map(static fn(array $j): int => (int)$j['product_id'], $claimed['jobs'])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } catch (Throwable $error) {
        seoPublicationBatchFail($pdo, $claimed['batch_id'], $error->getMessage());
        throw $error;
    }
} catch (Throwable $error) {
    fwrite(STDERR, "SEO worker failed: " . $error->getMessage() . "\n");
    exit(1);
}

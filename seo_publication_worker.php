<?php

declare(strict_types=1);

require_once __DIR__ . '/runtime_config.php';
require_once __DIR__ . '/seo_publication_batch_service.php';
require_once __DIR__ . '/seo_publication_snapshot_service.php';

function seoPublicationWorkerPdo(): PDO
{
    $testHost = getenv('TELVORA_TEST_DB_HOST');
    $testName = getenv('TELVORA_TEST_DB_NAME');
    if (is_string($testHost) && $testHost !== '' || is_string($testName) && $testName !== '') {
        if (!in_array($testHost, ['127.0.0.1', 'localhost'], true) || $testName !== 'telvora_phase2b_test') throw new RuntimeException('Refusing non-disposable worker database');
        return new PDO('mysql:host=' . $testHost . ';port=' . (int)getenv('TELVORA_TEST_DB_PORT') . ';dbname=' . $testName . ';charset=utf8mb4', (string)getenv('TELVORA_TEST_DB_USER'), (string)getenv('TELVORA_TEST_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    $secrets = telvoraSecretsFile();
    if (!is_file($secrets) || !is_readable($secrets)) throw new RuntimeException('SEO worker database configuration unavailable');
    $config = require $secrets;
    $pdo = new PDO('mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4', $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    return $pdo;
}

function seoPublicationWorkerOptions(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 2) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new InvalidArgumentException('Worker options must use --name=value');
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        if ($name === '' || $value === '') throw new InvalidArgumentException('Worker option value is required');
        $options[$name] = $value;
    }
    return $options;
}

function seoPublicationWorkerRequired(array $options, string $name): string
{
    $value = $options[$name] ?? null;
    if (!is_string($value) || $value === '') throw new InvalidArgumentException("Missing --{$name}");
    return $value;
}

function seoPublicationWorkerAssertSha(string $value, int $length, string $name): string
{
    if (preg_match('/\A[0-9a-f]{' . $length . '}\z/i', $value) !== 1) throw new InvalidArgumentException("Invalid {$name}");
    return strtolower($value);
}

$command = $argv[1] ?? '';
if (!in_array($command, ['prepare', 'validate', 'complete', 'fail'], true)) {
    fwrite(STDERR, "Usage: php seo_publication_worker.php prepare|validate|complete|fail [options]\n");
    exit(2);
}

try {
    $pdo = seoPublicationWorkerPdo();
    $options = seoPublicationWorkerOptions($argv);
    if ($command === 'prepare') {
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
        exit(0);
    }

    $batchId = seoPublicationWorkerRequired($options, 'batch-id');
    seoPublicationAssertBatchId($batchId);
    $snapshotHash = seoPublicationWorkerRequired($options, 'snapshot-hash');
    $snapshotHash = seoPublicationWorkerAssertSha($snapshotHash, 64, 'snapshot hash');
    $jobs = seoPublicationValidateBatchStillCurrent($pdo, $batchId);
    if ($jobs === []) throw new SeoPublicationStateException(409, 'SEO batch has no jobs');
    foreach ($jobs as $job) {
        if (strtolower((string)($job['snapshot_hash'] ?? '')) !== $snapshotHash) throw new SeoPublicationStateException(409, 'SEO batch snapshot hash mismatch');
    }

    if ($command === 'validate') {
        echo json_encode(['status' => 'VALIDATED', 'batch_id' => $batchId, 'snapshot_hash' => $snapshotHash, 'job_ids' => array_map(static fn(array $j): int => (int)$j['id'], $jobs)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    if ($command === 'fail') {
        $message = seoPublicationWorkerRequired($options, 'message');
        seoPublicationBatchFail($pdo, $batchId, $message);
        echo json_encode(['status' => 'FAILED', 'batch_id' => $batchId, 'snapshot_hash' => $snapshotHash], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    $releaseSha = seoPublicationWorkerAssertSha(seoPublicationWorkerRequired($options, 'release-sha'), 40, 'release SHA');
    $packageSha = seoPublicationWorkerAssertSha(seoPublicationWorkerRequired($options, 'package-sha256'), 64, 'package SHA-256');
    $backupReference = seoPublicationWorkerRequired($options, 'backup-reference');
    $update = $pdo->prepare('UPDATE seo_publication_jobs SET release_sha = :release_sha, package_sha256 = :package_sha, backup_reference = :backup_reference WHERE id = :id AND batch_id = :batch_id AND status = \'running\'');
    foreach ($jobs as $job) {
        $update->execute([':release_sha' => $releaseSha, ':package_sha' => $packageSha, ':backup_reference' => $backupReference, ':id' => (int)$job['id'], ':batch_id' => $batchId]);
        if ($update->rowCount() !== 1) throw new SeoPublicationStateException(409, 'SEO job changed before completion');
    }
    $completed = seoPublicationBatchComplete($pdo, $batchId);
    echo json_encode(['status' => 'COMPLETED', 'batch_id' => $batchId, 'snapshot_hash' => $snapshotHash, 'release_sha' => $releaseSha, 'package_sha256' => $packageSha, 'backup_reference' => $backupReference, 'jobs' => $completed], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, "SEO worker failed: " . $error->getMessage() . "\n");
    exit(1);
}

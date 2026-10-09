<?php

declare(strict_types=1);

require_once __DIR__ . '/runtime_config.php';
require_once __DIR__ . '/seo_publication_batch_service.php';
require_once __DIR__ . '/seo_publication_snapshot_service.php';
require_once __DIR__ . '/seo_publication_release_service.php';

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

// CLI helpers may be reused by the migration tool without executing a command.
if (defined('TELVORA_PUBLICATION_WORKER_LIBRARY')) return;
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$command = $argv[1] ?? '';
$legacyCommands = ['prepare', 'validate', 'complete', 'fail'];
if (!in_array($command, array_merge($legacyCommands, ['capture', 'finalize-release', 'reconcile']), true)) {
    fwrite(STDERR, "Usage: php seo_publication_worker.php prepare|validate|complete|fail [options]\n");
    exit(2);
}

try {
    $pdo = seoPublicationWorkerPdo();
    $options = seoPublicationWorkerOptions($argv);
    if ($command === 'capture') {
        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            seoReleaseRequire((int)$pdo->query("SELECT COUNT(*) FROM seo_publication_jobs WHERE status = 'running'")->fetchColumn() === 0, 'A running publication batch already exists');
            $jobs = $pdo->query("SELECT j.* FROM seo_publication_jobs j JOIN products p ON p.id = j.product_id WHERE j.status = 'queued' AND j.requested_revision = p.publication_revision AND ((j.operation = 'publish' AND p.publication_status = 'pending_publish') OR (j.operation = 'unpublish' AND p.publication_status = 'pending_unpublish')) ORDER BY j.product_id,j.id")->fetchAll(PDO::FETCH_ASSOC);
            $snapshot = seoPublicationBuildDesiredSnapshot($pdo, seoPublicationNewBatchId(), $jobs);
            $snapshot['publication_intents'] = seoReleaseCaptureIntents($pdo, $jobs);
            $pdo->rollBack();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        // Stream the private snapshot over the existing pinned SSH connection.
        echo seoPublicationSnapshotJson($snapshot), "\n";
        exit(0);
    }
    if (in_array($command, ['complete', 'finalize-release', 'reconcile'], true)) {
        $apply = ($options['apply'] ?? 'no') === 'yes';
        seoReleaseRequire(!isset($options['apply']) || in_array($options['apply'], ['yes', 'no'], true), 'Invalid apply option');
        $root = '/var/www/u3609206/data';
        $document = $root . '/www/telvora.ru';
        $staging = $root . '/staging';
        $activeRecord = seoPublicationWorkerRequired($options, 'active-record');
        $archive = seoPublicationWorkerRequired($options, 'archive');
        $packageRoot = seoPublicationWorkerRequired($options, 'package-root');
        foreach ([$activeRecord, $archive, $packageRoot] as $path) {
            $canonical = realpath($path);
            seoReleaseRequire($canonical !== false && str_starts_with($canonical, $staging . '/'), 'Release evidence must be inside production staging');
        }
        $releaseOptions = ['source' => $command === 'reconcile' ? 'reconciliation' : 'release', 'release_sha' => seoPublicationWorkerAssertSha(seoPublicationWorkerRequired($options, 'release-sha'), 40, 'release SHA'), 'package_sha256' => seoPublicationWorkerAssertSha(seoPublicationWorkerRequired($options, 'package-sha256'), 64, 'package SHA'), 'snapshot_hash' => seoPublicationWorkerAssertSha(seoPublicationWorkerRequired($options, 'snapshot-hash'), 64, 'snapshot hash'), 'active_record' => $activeRecord, 'archive' => $archive, 'package_root' => $packageRoot, 'document_root' => $document, 'staging_root' => $staging, 'smoke_result' => seoPublicationWorkerRequired($options, 'smoke-result')];
        if ($command === 'reconcile') {
            $planText = file_get_contents(seoPublicationWorkerRequired($options, 'plan'));
            $planSha = seoPublicationWorkerAssertSha(seoPublicationWorkerRequired($options, 'plan-sha256'), 64, 'plan SHA');
            seoReleaseRequire(is_string($planText) && hash_equals($planSha, hash('sha256', $planText)), 'Reviewed plan SHA-256 mismatch');
            $plan = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $planText), true, 512, JSON_THROW_ON_ERROR);
            seoReleaseRequire(($plan['version'] ?? null) === 2 && ($plan['mode'] ?? '') === 'RECONCILIATION_ALLOWLIST' && count($plan['intents'] ?? []) === 54, 'Expected the reviewed 54-product reconciliation plan');
            foreach (['release_sha','package_sha256','snapshot_hash','active_record'] as $key) seoReleaseRequire(($plan[$key] ?? null) === $releaseOptions[$key], 'Reconciliation ' . $key . ' mismatch');
            $intents = $plan['intents'];
            $expectedPairs = [14 => 22, 68 => 21];
            foreach (range(16, 67) as $id) $expectedPairs[$id] = $id + 7;
            $seen = [];
            foreach ($intents as $i) {
                seoReleaseRequire($i['operation'] === 'publish' && $i['job_status'] === 'queued' && (int)$i['is_active'] === 1 && (int)$i['revision'] === 1, 'Reconciliation can only complete existing active publish intents');
                seoReleaseRequire(($expectedPairs[(int)$i['product_id']] ?? null) === (int)$i['job_id'] && !isset($seen[$i['product_id']]), 'Intent outside the diagnosed 54-product allowlist');
                $seen[$i['product_id']] = true;
            }
        } else {
            $manifest = seoReleaseJsonFile($packageRoot . '/deployment-manifest.json');
            $intents = $manifest['publicationIntents'] ?? null;
            seoReleaseRequire(is_array($intents), 'Package lacks captured publication intents');
            if ($command === 'complete') {
                $batch = seoPublicationWorkerRequired($options, 'batch-id');
                seoPublicationAssertBatchId($batch);
                foreach ($intents as $i) seoReleaseRequire($i['batch_id'] === $batch && $i['job_status'] === 'running', 'Complete batch differs from package intents');
            }
        }
        $lock = fopen($root . '/www/.telvora-seo-deploy.lock', 'c');
        seoReleaseRequire(is_resource($lock) && flock($lock, LOCK_EX | LOCK_NB), 'Another SEO activation/finalization holds the deployment lock');
        try {
            $verify = static fn() => seoReleaseVerifyEvidence($releaseOptions, $intents, 'seoReleasePublicGet');
            $result = seoReleaseFinalize($pdo, $releaseOptions, $intents, $apply, $verify);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
        exit(0);
    }
    if ($command === 'prepare') {
        $claimed = seoPublicationClaimQueuedBatch($pdo, 100);
        if ($claimed['jobs'] === []) {
            echo json_encode(['status' => 'NO_WORK'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            exit(0);
        }
        try {
            $pdo->exec('SET TRANSACTION READ ONLY');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            try {
                $currentJobs = seoPublicationBatchJobs($pdo, $claimed['batch_id']);
                $snapshot = seoPublicationBuildDesiredSnapshot($pdo, $claimed['batch_id'], $currentJobs);
                $snapshot['publication_intents'] = seoReleaseCaptureIntents($pdo, $currentJobs);
                $pdo->rollBack();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
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

    throw new RuntimeException('Publication command did not complete');
} catch (Throwable $error) {
    fwrite(STDERR, "SEO worker failed: " . $error->getMessage() . "\n");
    exit(1);
}

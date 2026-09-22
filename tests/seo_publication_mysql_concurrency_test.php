<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/seo_publication_service.php';

function phase1aAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("FAIL $message");
    echo "PASS $message\n";
}

function phase1aConfig(): array
{
    $required = ['TELVORA_TEST_DB_HOST', 'TELVORA_TEST_DB_PORT', 'TELVORA_TEST_DB_NAME', 'TELVORA_TEST_DB_USER', 'TELVORA_TEST_DB_PASSWORD'];
    foreach ($required as $key) {
        $value = getenv($key);
        if (!is_string($value) || trim($value) === '') throw new RuntimeException("SKIP: missing test-only environment variable $key");
    }
    $host = trim((string)getenv('TELVORA_TEST_DB_HOST'));
    $name = trim((string)getenv('TELVORA_TEST_DB_NAME'));
    $port = (int)getenv('TELVORA_TEST_DB_PORT');
    if (!in_array($host, ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Refusing non-loopback test database host');
    if ($name !== 'telvora_phase1a_test') throw new RuntimeException('Refusing non-test database name');
    if ($port < 1 || $port > 65535) throw new RuntimeException('Invalid test database port');
    return [$host, $port, $name, (string)getenv('TELVORA_TEST_DB_USER'), (string)getenv('TELVORA_TEST_DB_PASSWORD')];
}

function phase1aPdo(array $config): PDO
{
    [$host, $port, $name, $user, $password] = $config;
    return new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function phase1aRunSqlFile(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) throw new RuntimeException("Cannot read SQL file: $path");
    foreach (preg_split('/;\s*(?:\r?\n|\z)/', $sql) ?: [] as $statement) {
        $statement = trim((string)preg_replace('/\A(?:\s*--[^\r\n]*(?:\r?\n|\z))+/', '', $statement));
        if ($statement === '') continue;
        $pdo->exec($statement);
    }
}

function phase1aBootstrap(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['seo_publication_jobs', 'product_variant_price_overrides', 'product_variants', 'product_variants_legacy', 'supplier_import_rows', 'supplier_import_jobs', 'supplier_product_matches', 'supplier_offers', 'pricing_rules', 'variant_certification_supply_types', 'variant_market_regions', 'supplier_import_profiles', 'suppliers', 'products'] as $table) $pdo->exec("DROP TABLE IF EXISTS `$table`");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $pdo->exec(<<<'SQL'
CREATE TABLE products (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    series VARCHAR(100) NOT NULL,
    category VARCHAR(20) NOT NULL,
    screen_size VARCHAR(50) NOT NULL,
    resolution VARCHAR(100) NOT NULL,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    old_price DECIMAL(12,2) DEFAULT NULL,
    image TEXT NOT NULL,
    badge VARCHAR(100) DEFAULT NULL,
    rating DECIMAL(3,2) NOT NULL DEFAULT 0,
    reviews INT UNSIGNED NOT NULL DEFAULT 0,
    description TEXT NOT NULL,
    specs JSON NOT NULL,
    highlights JSON NOT NULL,
    variants JSON NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_products_slug (slug), KEY idx_products_category (category), KEY idx_products_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    phase1aRunSqlFile($pdo, dirname(__DIR__) . '/database/migrations/20260831_001_supplier_variant_infrastructure.sql');
    phase1aRunSqlFile($pdo, dirname(__DIR__) . '/database/migrations/20260908_008_product_variant_price_overrides.sql');
    $insert = $pdo->prepare("INSERT INTO products (id, slug, name, series, category, screen_size, resolution, price, image, description, specs, highlights, variants, is_active) VALUES (:id, :slug, :name, 'Test', 'oled', '55', '4K', 100000, '/test.png', 'CI fixture', '{}', '[]', :variants, :active)");
    $legacy = json_encode([['country' => 'Russia', 'price' => 100000, 'old_price' => null, 'is_active' => true]], JSON_THROW_ON_ERROR);
    $insert->execute([':id' => 1, ':slug' => 'phase1a-inactive', ':name' => 'Phase 1A inactive', ':variants' => $legacy, ':active' => 0]);
    $insert->execute([':id' => 2, ':slug' => 'phase1a-active', ':name' => 'Phase 1A active', ':variants' => $legacy, ':active' => 1]);
    $variant = $pdo->prepare('INSERT INTO product_variants (id, product_id, variant_key, assembly_country, display_name, is_active) VALUES (:id, :product, :key, :country, :name, 1)');
    $key = 'legacy-country-sha256-' . hash('sha256', 'Russia');
    $variant->execute([':id' => 1, ':product' => 1, ':key' => $key, ':country' => 'Russia', ':name' => 'Russia']);
    $variant->execute([':id' => 2, ':product' => 2, ':key' => $key, ':country' => 'Russia', ':name' => 'Russia']);
}

function phase1aFetch(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT id, is_active, publication_status, publication_revision FROM products WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!is_array($row)) throw new RuntimeException("Missing product $id");
    return $row;
}

function phase1aJob(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM seo_publication_jobs WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!is_array($row)) throw new RuntimeException("Missing job $id");
    return $row;
}

function phase1aInsertJob(PDO $pdo, int $product, int $revision, string $operation, string $status = 'queued'): int
{
    $stmt = $pdo->prepare('INSERT INTO seo_publication_jobs (product_id, operation, requested_revision, status, batch_id) VALUES (:product, :operation, :revision, :status, :batch)');
    $stmt->execute([':product' => $product, ':operation' => $operation, ':revision' => $revision, ':status' => $status, ':batch' => seoPublicationNewBatchId()]);
    return (int)$pdo->lastInsertId();
}

function phase1aColumn(PDO $pdo, string $table, string $column): array
{
    $stmt = $pdo->prepare('SELECT DATA_TYPE AS data_type, COLUMN_TYPE AS column_type,
        CHARACTER_MAXIMUM_LENGTH AS character_maximum_length, IS_NULLABLE AS is_nullable,
        COLUMN_DEFAULT AS column_default
        FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name');
    $stmt->execute([':table_name' => $table, ':column_name' => $column]);
    $row = $stmt->fetch();
    if (!is_array($row)) throw new RuntimeException("Missing column $table.$column");
    return $row;
}

function phase1aIndexColumns(PDO $pdo, string $table, string $index): array
{
    $stmt = $pdo->prepare('SELECT NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS seq_in_index,
        COLUMN_NAME AS column_name
        FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = :table_name AND index_name = :index_name
        ORDER BY seq_in_index');
    $stmt->execute([':table_name' => $table, ':index_name' => $index]);
    return $stmt->fetchAll();
}

function phase1aExpectRejected(callable $callback, string $message): void
{
    try { $callback(); } catch (Throwable $error) { phase1aAssert($error instanceof SeoPublicationStateException || $error instanceof PDOException, $message); return; }
    throw new RuntimeException("FAIL $message");
}

function phase1aWriteMarker(string $path, string $contents): void
{
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException("Could not write synchronization marker: $path");
    }
}

function phase1aChild(array $config, string $mode, string $dir, string $resultName = 'contender-result'): int
{
    try {
        $pdo = phase1aPdo($config);
        if ($mode === 'holder') {
            $pdo->beginTransaction();
            $locked = $pdo->query('SELECT id FROM products WHERE id = 1 FOR UPDATE')->fetchColumn();
            if ((int)$locked !== 1) throw new RuntimeException('Holder could not lock product 1');
            phase1aWriteMarker("$dir/holder-ready", (string)getmypid());
            while (!is_file("$dir/release")) usleep(50000);
            $pdo->commit();
            return 0;
        }
        while (!is_file("$dir/holder-ready")) usleep(50000);
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $result = seoPublicationRequestPublish($pdo, 1, 0);
        file_put_contents("$dir/$resultName", json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR));
    } catch (Throwable $error) {
        $marker = $mode === 'holder' ? "$dir/holder-error" : "$dir/$resultName";
        file_put_contents($marker, json_encode(['ok' => false, 'class' => get_class($error), 'message' => $error->getMessage()], JSON_THROW_ON_ERROR));
        if ($mode === 'holder') return 1;
    }
    return 0;
}

function phase1aStopProcess($process, array $pipes): void
{
    if (!is_resource($process)) return;
    $status = proc_get_status($process);
    if ($status['running']) {
        proc_terminate($process);
        $deadline = microtime(true) + 1;
        do {
            usleep(50000);
            $status = proc_get_status($process);
        } while ($status['running'] && microtime(true) < $deadline);
        if ($status['running']) proc_terminate($process, 9);
    }
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    @proc_close($process);
}

function phase1aProcessOutput(array $pipes): string
{
    $output = [];
    foreach ([1, 2] as $index) {
        if (!isset($pipes[$index]) || !is_resource($pipes[$index])) continue;
        stream_set_blocking($pipes[$index], false);
        $output[] = (string)stream_get_contents($pipes[$index]);
    }
    return implode('', $output);
}

function phase1aRunLockTest(array $config): void
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'telvora-phase1a-' . bin2hex(random_bytes(5));
    mkdir($dir, 0700, true);
    $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__);
    $env = array_merge($_ENV, ['TELVORA_TEST_DB_HOST' => $config[0], 'TELVORA_TEST_DB_PORT' => (string)$config[1], 'TELVORA_TEST_DB_NAME' => $config[2], 'TELVORA_TEST_DB_USER' => $config[3], 'TELVORA_TEST_DB_PASSWORD' => $config[4]]);
    $holder = $contender = $contender2 = null;
    $pipes = $pipes2 = $pipes3 = [];
    // Reserve bounded cleanup time so the whole section cannot exceed 30s.
    $deadline = microtime(true) + 24;
    try {
        $holder = proc_open("$command --holder " . escapeshellarg($dir), $descriptor, $pipes, dirname(__DIR__), $env);
        if (!is_resource($holder)) throw new RuntimeException('Could not start lock holder');
        $readyDeadline = microtime(true) + 5;
        while (!is_file("$dir/holder-ready") && !is_file("$dir/holder-error") && microtime(true) < $readyDeadline) {
            $status = proc_get_status($holder);
            if (!$status['running']) break;
            usleep(50000);
        }
        if (!is_file("$dir/holder-ready")) {
            $status = proc_get_status($holder);
            $detail = is_file("$dir/holder-error") ? (string)file_get_contents("$dir/holder-error") : 'no holder marker';
            throw new RuntimeException("Connection A did not acquire product lock: $detail; exit=" . (string)($status['exitcode'] ?? 'unknown') . '; output=' . phase1aProcessOutput($pipes));
        }
        $contender = proc_open("$command --contender " . escapeshellarg($dir) . ' contender-1', $descriptor, $pipes2, dirname(__DIR__), $env);
        $contender2 = proc_open("$command --contender " . escapeshellarg($dir) . ' contender-2', $descriptor, $pipes3, dirname(__DIR__), $env);
        if (!is_resource($contender) || !is_resource($contender2)) throw new RuntimeException('Could not start lock contender');
        usleep(1500000);
        phase1aAssert(!is_file("$dir/contender-1") && !is_file("$dir/contender-2"), 'connections B and C are blocked while product lock is held');
        phase1aWriteMarker("$dir/release", 'release');
        while ((!is_file("$dir/contender-1") || !is_file("$dir/contender-2") || proc_get_status($holder)['running'] || proc_get_status($contender)['running'] || proc_get_status($contender2)['running']) && microtime(true) < $deadline) usleep(50000);
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Concurrency processes exceeded 30-second deadline; output=' . phase1aProcessOutput($pipes) . phase1aProcessOutput($pipes2) . phase1aProcessOutput($pipes3));
        }
        $holderCode = proc_close($holder); $holder = null;
        $contenderCode = proc_close($contender); $contender = null;
        $contenderCode2 = proc_close($contender2); $contender2 = null;
        phase1aAssert(microtime(true) < $deadline, 'concurrency processes finish within 30 seconds');
        phase1aAssert($holderCode === 0 && $contenderCode === 0 && $contenderCode2 === 0, 'lock holder and contenders exit cleanly');
        phase1aAssert(is_file("$dir/contender-1") && is_file("$dir/contender-2"), 'both concurrent requests complete after lock release');
        $result1 = json_decode((string)file_get_contents("$dir/contender-1"), true, 512, JSON_THROW_ON_ERROR);
        $result2 = json_decode((string)file_get_contents("$dir/contender-2"), true, 512, JSON_THROW_ON_ERROR);
        phase1aAssert((($result1['ok'] ?? false) xor ($result2['ok'] ?? false)), 'duplicate request race has one winner and one stale/conflict result');
    } finally {
        phase1aWriteMarker("$dir/release", 'release');
        phase1aStopProcess($holder, $pipes);
        phase1aStopProcess($contender, $pipes2);
        phase1aStopProcess($contender2, $pipes3);
        @unlink("$dir/holder-ready"); @unlink("$dir/holder-error"); @unlink("$dir/release"); @unlink("$dir/contender-1"); @unlink("$dir/contender-2"); @rmdir($dir);
    }
}

function phase1aMain(): void
{
    $config = phase1aConfig();
    $pdo = phase1aPdo($config);
    phase1aBootstrap($pdo);
    $beforeA = (int)$pdo->query('SELECT is_active FROM products WHERE id = 1')->fetchColumn();
    $beforeB = (int)$pdo->query('SELECT is_active FROM products WHERE id = 2')->fetchColumn();
    phase1aRunSqlFile($pdo, dirname(__DIR__) . '/database/migrations/20260922_013_seo_publication_state.sql');
    $a = phase1aFetch($pdo, 1); $b = phase1aFetch($pdo, 2);
    phase1aAssert($a['publication_status'] === 'draft' && (int)$a['publication_revision'] === 0 && (int)$a['is_active'] === 0, 'migration backfills inactive product as draft');
    phase1aAssert($b['publication_status'] === 'published' && (int)$b['publication_revision'] === 0 && (int)$b['is_active'] === 1, 'migration backfills active product as published');
    phase1aAssert((int)$a['is_active'] === $beforeA && (int)$b['is_active'] === $beforeB, 'migration preserves is_active');
    $productsDdl = (string)$pdo->query('SHOW CREATE TABLE products')->fetchColumn(1);
    $jobsDdl = (string)$pdo->query('SHOW CREATE TABLE seo_publication_jobs')->fetchColumn(1);
    phase1aAssert($productsDdl !== '' && $jobsDdl !== '', 'SHOW CREATE TABLE returns non-empty DDL');
    $publicationStatus = phase1aColumn($pdo, 'products', 'publication_status');
    phase1aAssert($publicationStatus['data_type'] === 'varchar' && (int)$publicationStatus['character_maximum_length'] === 32, 'publication_status is VARCHAR(32)');
    phase1aAssert($publicationStatus['is_nullable'] === 'NO' && $publicationStatus['column_default'] === 'draft', 'publication_status is NOT NULL DEFAULT draft');
    $publicationRevision = phase1aColumn($pdo, 'products', 'publication_revision');
    phase1aAssert($publicationRevision['column_type'] === 'bigint unsigned', 'publication_revision is BIGINT UNSIGNED');
    phase1aAssert($publicationRevision['is_nullable'] === 'NO' && (string)$publicationRevision['column_default'] === '0', 'publication_revision is NOT NULL DEFAULT 0');
    $publicationIndex = phase1aIndexColumns($pdo, 'products', 'idx_products_publication');
    phase1aAssert(count($publicationIndex) === 2 && (int)$publicationIndex[0]['non_unique'] === 1 && $publicationIndex[0]['column_name'] === 'publication_status' && $publicationIndex[1]['column_name'] === 'is_active', 'idx_products_publication covers status and active state');
    $intentIndex = phase1aIndexColumns($pdo, 'seo_publication_jobs', 'uq_seo_publication_job_intent');
    phase1aAssert(count($intentIndex) === 3 && (int)$intentIndex[0]['non_unique'] === 0 && array_column($intentIndex, 'column_name') === ['product_id', 'requested_revision', 'operation'], 'unique intent index covers product/revision/operation');
    $fkStmt = $pdo->prepare('SELECT kcu.REFERENCED_TABLE_NAME AS referenced_table_name,
        kcu.REFERENCED_COLUMN_NAME AS referenced_column_name, rc.DELETE_RULE AS delete_rule
        FROM information_schema.key_column_usage kcu
        JOIN information_schema.referential_constraints rc
          ON rc.constraint_schema = kcu.constraint_schema AND rc.constraint_name = kcu.constraint_name
        WHERE kcu.constraint_schema = DATABASE() AND kcu.table_name = :table_name AND kcu.constraint_name = :constraint_name');
    $fkStmt->execute([':table_name' => 'seo_publication_jobs', ':constraint_name' => 'fk_seo_publication_job_product']);
    $fk = $fkStmt->fetch();
    phase1aAssert(is_array($fk) && $fk['referenced_table_name'] === 'products' && $fk['referenced_column_name'] === 'id' && $fk['delete_rule'] === 'RESTRICT', 'publication job product FK is ON DELETE RESTRICT');
    $checkStmt = $pdo->prepare('SELECT tc.CONSTRAINT_NAME AS constraint_name,
        cc.CHECK_CLAUSE AS check_clause
        FROM information_schema.table_constraints tc
        JOIN information_schema.check_constraints cc
          ON cc.constraint_schema = tc.constraint_schema AND cc.constraint_name = tc.constraint_name
        WHERE tc.constraint_schema = DATABASE() AND tc.table_name = :table_name AND tc.constraint_type = \'CHECK\'');
    $checkStmt->execute([':table_name' => 'seo_publication_jobs']);
    $checks = $checkStmt->fetchAll();
    $checkText = strtolower(implode(' ', array_map(static fn(array $row): string => (string)$row['constraint_name'] . ' ' . (string)$row['check_clause'], $checks)));
    phase1aAssert(str_contains($checkText, 'chk_seo_publication_job_operation') && str_contains($checkText, 'publish') && str_contains($checkText, 'unpublish'), 'operation CHECK metadata is present');
    phase1aAssert(str_contains($checkText, 'chk_seo_publication_job_status') && str_contains($checkText, 'queued') && str_contains($checkText, 'running') && str_contains($checkText, 'completed') && str_contains($checkText, 'failed') && str_contains($checkText, 'superseded'), 'status CHECK metadata is present');
    phase1aExpectRejected(static function () use ($pdo): void { $pdo->prepare("INSERT INTO seo_publication_jobs (product_id, operation, requested_revision, status, batch_id) VALUES (1, 'invalid', 1001, 'queued', :batch)")->execute([':batch' => seoPublicationNewBatchId()]); }, 'operation CHECK rejects invalid value');
    phase1aExpectRejected(static function () use ($pdo): void { $pdo->prepare("INSERT INTO seo_publication_jobs (product_id, operation, requested_revision, status, batch_id) VALUES (1, 'publish', 1002, 'invalid', :batch)")->execute([':batch' => seoPublicationNewBatchId()]); }, 'status CHECK rejects invalid value');
    $published = seoPublicationRequestPublish($pdo, 1, 0);
    phase1aAssert($published['result'] === 'queued' && (int)$published['product']['publication_revision'] === 1, 'request publish queues revision 1');
    phase1aAssert(phase1aFetch($pdo, 1)['publication_status'] === 'pending_publish', 'request publish enters pending_publish');
    $duplicate = seoPublicationRequestPublish($pdo, 1, 1);
    phase1aAssert($duplicate['result'] === 'idempotent' && (int)$duplicate['job']['id'] === (int)$published['job']['id'], 'duplicate publish is idempotent');
    $jobId = (int)$published['job']['id'];
    $pdo->prepare("UPDATE seo_publication_jobs SET status = 'running' WHERE id = :id")->execute([':id' => $jobId]);
    seoPublicationFinalizePublish($pdo, $jobId);
    phase1aAssert(phase1aFetch($pdo, 1)['publication_status'] === 'published', 'publish finalization completes');
    phase1aAssert((int)phase1aFetch($pdo, 1)['publication_revision'] === 1, 'publish finalization preserves revision');
    phase1aAssert(seoPublicationRequestPublish($pdo, 1, 1)['result'] === 'noop', 'published publish is no-op');
    $unpublish = seoPublicationRequestUnpublish($pdo, 1, 1);
    phase1aAssert($unpublish['result'] === 'queued' && phase1aFetch($pdo, 1)['publication_status'] === 'pending_unpublish', 'request unpublish queues');
    phase1aAssert(seoPublicationRequestUnpublish($pdo, 1, 2)['result'] === 'idempotent', 'duplicate unpublish is idempotent');
    $unpublishId = (int)$unpublish['job']['id'];
    $pdo->prepare("UPDATE seo_publication_jobs SET status = 'running' WHERE id = :id")->execute([':id' => $unpublishId]);
    seoPublicationFinalizeUnpublish($pdo, $unpublishId);
    phase1aAssert(phase1aFetch($pdo, 1)['publication_status'] === 'draft' && (int)phase1aFetch($pdo, 1)['is_active'] === 0, 'unpublish finalization deactivates');
    phase1aAssert((int)phase1aFetch($pdo, 1)['publication_revision'] === 2, 'unpublish finalization preserves revision');
    phase1aAssert(seoPublicationRequestUnpublish($pdo, 1, 2)['result'] === 'noop', 'draft unpublish is no-op');
    foreach (['queued', 'failed', 'completed', 'superseded'] as $status) {
        $revision = 120 + strlen($status);
        $pdo->exec("DELETE FROM seo_publication_jobs");
        $pdo->exec("UPDATE products SET is_active = 0, publication_status = 'pending_publish', publication_revision = $revision WHERE id = 1");
        $id = phase1aInsertJob($pdo, 1, $revision, 'publish', $status);
        $snapshot = phase1aFetch($pdo, 1);
        phase1aExpectRejected(static function () use ($pdo, $id): void { seoPublicationFinalizePublish($pdo, $id); }, "finalization rejects $status job");
        phase1aAssert(phase1aFetch($pdo, 1) === $snapshot, "finalization $status leaves product unchanged");
    }
    $pdo->exec('DELETE FROM seo_publication_jobs');
    $pdo->exec("UPDATE products SET is_active = 0, publication_status = 'pending_publish', publication_revision = 201 WHERE id = 1");
    $staleFinalize = phase1aInsertJob($pdo, 1, 200, 'publish', 'running');
    phase1aExpectRejected(static function () use ($pdo, $staleFinalize): void { seoPublicationFinalizePublish($pdo, $staleFinalize); }, 'finalization rejects stale revision');
    $pdo->exec('DELETE FROM seo_publication_jobs');
    $pdo->exec("UPDATE products SET is_active = 0, publication_status = 'draft', publication_revision = 2 WHERE id = 1");
    $pdo->prepare("INSERT INTO seo_publication_jobs (product_id, operation, requested_revision, status, batch_id) VALUES (1, 'publish', 99, 'completed', :batch)")->execute([':batch' => seoPublicationNewBatchId()]);
    phase1aExpectRejected(static function () use ($pdo): void { $pdo->prepare("INSERT INTO seo_publication_jobs (product_id, operation, requested_revision, status, batch_id) VALUES (1, 'publish', 99, 'queued', :batch)")->execute([':batch' => seoPublicationNewBatchId()]); }, 'unique intent rejects duplicate tuple');
    phase1aExpectRejected(static function () use ($pdo): void { $pdo->exec('DELETE FROM products WHERE id = 1'); }, 'product FK is ON DELETE RESTRICT');
    $pdo->exec("ALTER TABLE seo_publication_jobs ADD CONSTRAINT chk_phase1a_fail_batch CHECK (batch_id <> '00000000-0000-4000-8000-000000000000')");
    $failedBefore = phase1aFetch($pdo, 1);
    phase1aExpectRejected(static function () use ($pdo): void { seoPublicationRequestPublish($pdo, 1, 2, '00000000-0000-4000-8000-000000000000'); }, 'job insert failure is rejected');
    phase1aAssert(phase1aFetch($pdo, 1) === $failedBefore, 'product update and job insert roll back atomically');
    $pdo->exec('ALTER TABLE seo_publication_jobs DROP CHECK chk_phase1a_fail_batch');
    $rev = phase1aFetch($pdo, 1); $publishAgain = seoPublicationRequestPublish($pdo, 1, (int)$rev['publication_revision']);
    $reverse = seoPublicationRequestUnpublish($pdo, 1, (int)$publishAgain['product']['publication_revision']);
    phase1aAssert($reverse['result'] === 'reversed' && phase1aFetch($pdo, 1)['publication_status'] === 'draft', 'pending publish reversal supersedes job without new unpublish job');
    phase1aAssert(phase1aJob($pdo, (int)$publishAgain['job']['id'])['status'] === 'superseded', 'publish job is superseded');
    $revB = phase1aFetch($pdo, 2); $unpublishB = seoPublicationRequestUnpublish($pdo, 2, (int)$revB['publication_revision']);
    $reverseB = seoPublicationRequestPublish($pdo, 2, (int)$unpublishB['product']['publication_revision']);
    phase1aAssert($reverseB['result'] === 'reversed' && phase1aFetch($pdo, 2)['publication_status'] === 'published', 'pending unpublish reversal preserves published state');
    phase1aAssert(phase1aJob($pdo, (int)$unpublishB['job']['id'])['status'] === 'superseded', 'unpublish job is superseded');
    $pdo->exec('DELETE FROM seo_publication_jobs');
    $pdo->exec("UPDATE products SET is_active = 0, publication_status = 'pending_publish', publication_revision = 50 WHERE id = 1");
    $matching = phase1aInsertJob($pdo, 1, 50, 'publish', 'running'); seoPublicationMarkFailed($pdo, $matching, 'test failure');
    phase1aAssert(phase1aFetch($pdo, 1)['publication_status'] === 'publish_failed', 'running publish job marks exact product failed');
    phase1aAssert(phase1aJob($pdo, $matching)['status'] === 'failed', 'running publish job marks exact job failed');
    $pdo->exec("UPDATE products SET is_active = 1, publication_status = 'pending_unpublish', publication_revision = 60 WHERE id = 2");
    $matchingUnpublish = phase1aInsertJob($pdo, 2, 60, 'unpublish', 'running'); seoPublicationMarkFailed($pdo, $matchingUnpublish, 'test failure');
    phase1aAssert(phase1aFetch($pdo, 2)['publication_status'] === 'unpublish_failed' && (int)phase1aFetch($pdo, 2)['is_active'] === 1, 'running unpublish job preserves active product on failure');
    foreach (['queued', 'superseded', 'completed', 'failed'] as $statusIndex => $status) {
        // Keep each rejection fixture on its own deterministic intent generation.
        // These rows are independent scenarios and must not collide with jobs
        // created by the earlier request/finalization checks.
        $revision = 700 + $statusIndex; $pdo->exec("UPDATE products SET is_active = 0, publication_status = 'pending_publish', publication_revision = $revision WHERE id = 1");
        $id = phase1aInsertJob($pdo, 1, $revision, 'publish', $status); $snapshot = phase1aFetch($pdo, 1);
        phase1aExpectRejected(static function () use ($pdo, $id): void { seoPublicationMarkFailed($pdo, $id, 'must reject'); }, "status $status cannot mark failure");
        phase1aAssert(phase1aFetch($pdo, 1) === $snapshot, "status $status leaves product unchanged");
    }
    phase1aExpectRejected(static function () use ($pdo): void { seoPublicationMarkFailed($pdo, 999999, 'missing'); }, 'missing job cannot mark failure');
    $pdo->exec('DELETE FROM seo_publication_jobs'); $pdo->exec("UPDATE products SET is_active = 0, publication_status = 'draft', publication_revision = 0 WHERE id = 1");
    phase1aRunLockTest($config);
    phase1aAssert(count($pdo->query("SELECT id FROM seo_publication_jobs WHERE product_id = 1 AND requested_revision = 1")->fetchAll()) === 1, 'concurrent requests leave one intent job');
    echo "PASS REAL MYSQL PHASE 1A CONCURRENCY TEST\n";
}

$mode = $argv[1] ?? null;
if ($mode === '--holder' || $mode === '--contender') { phase1aChild(phase1aConfig(), $mode, $argv[2] ?? '', $argv[3] ?? 'contender-result'); exit(0); }
try { phase1aMain(); } catch (RuntimeException $error) {
    if (str_starts_with($error->getMessage(), 'SKIP:')) { echo $error->getMessage() . "\n"; exit(0); }
    throw $error;
}

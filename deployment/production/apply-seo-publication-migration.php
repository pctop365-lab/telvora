<?php

declare(strict_types=1);
const SEO_PUBLICATION_MIGRATION_SHA256 = '7bd21e3a906b4562d7bb04a04e3ddbea55e353690cc0fabb759aacaea04000f7';
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI_ONLY\n"); exit(2); }
$mode = $argv[1] ?? '';
if (!in_array($mode, ['--preflight', '--apply'], true)) { fwrite(STDERR, "Usage: php apply-seo-publication-migration.php --preflight|--apply\n"); exit(2); }
$configPath = getenv('TELVORA_RUNTIME_CONFIG');
$configPath = is_string($configPath) && $configPath !== '' ? $configPath : dirname(__DIR__, 2) . '/runtime_config.php';
$migration = dirname(__DIR__, 2) . '/database/migrations/20260922_013_seo_publication_state.sql';
if (!is_file($configPath) || !is_readable($configPath) || !is_file($migration)) { fwrite(STDERR, "CONFIG_OR_MIGRATION_UNAVAILABLE\n"); exit(1); }
if (hash_file('sha256', $migration) !== SEO_PUBLICATION_MIGRATION_SHA256) { fwrite(STDERR, "MIGRATION_HASH_MISMATCH\n"); exit(1); }
require_once $configPath;
$secrets = telvoraSecretsFile();
if (!is_file($secrets) || !is_readable($secrets)) { fwrite(STDERR, "DB_CONFIG_UNAVAILABLE\n"); exit(1); }
$config = require $secrets;
$host = (string)($config['db_host'] ?? ''); $port = (int)($config['db_port'] ?? 3306); $name = (string)($config['db_name'] ?? ''); $user = (string)($config['db_user'] ?? ''); $password = (string)($config['db_password'] ?? '');
if ($host === '' || $name === '' || $user === '' || $password === '') { fwrite(STDERR, "DB_CONFIG_INVALID\n"); exit(1); }
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->query('SELECT 1');

function seoPublicationSchemaState(PDO $pdo, string $database): string
{
    $column = $pdo->prepare("SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_DEFAULT\n        FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = 'products'\n        AND COLUMN_NAME IN ('publication_status', 'publication_revision')");
    $column->execute(['schema' => $database]);
    $columns = [];
    foreach ($column->fetchAll(PDO::FETCH_ASSOC) as $row) { $columns[(string)$row['COLUMN_NAME']] = $row; }
    $jobs = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = 'seo_publication_jobs'");
    $jobs->execute(['schema' => $database]);
    $jobsPresent = (bool)$jobs->fetchColumn();
    $hasAny = $jobsPresent || $columns !== [];
    if (!$hasAny) { return 'absent'; }
    if (!isset($columns['publication_status'], $columns['publication_revision']) || !$jobsPresent) { return 'partial'; }
    $status = $columns['publication_status']; $revision = $columns['publication_revision'];
    if ($status['DATA_TYPE'] !== 'varchar' || (int)$status['CHARACTER_MAXIMUM_LENGTH'] !== 32 || $status['IS_NULLABLE'] !== 'NO' || (string)$status['COLUMN_DEFAULT'] !== 'draft') { return 'partial'; }
    if ($revision['DATA_TYPE'] !== 'bigint' || stripos((string)$revision['COLUMN_TYPE'], 'bigint unsigned') !== 0 || $revision['IS_NULLABLE'] !== 'NO' || (string)$revision['COLUMN_DEFAULT'] !== '0') { return 'partial'; }
    $index = $pdo->prepare("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_products_publication' GROUP BY INDEX_NAME");
    $index->execute(['schema' => $database]);
    if ($index->fetchColumn() !== 'publication_status,is_active') { return 'partial'; }
    $unique = $pdo->prepare("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = 'seo_publication_jobs' AND INDEX_NAME = 'uq_seo_publication_job_intent' GROUP BY INDEX_NAME");
    $unique->execute(['schema' => $database]);
    if ($unique->fetchColumn() !== 'product_id,requested_revision,operation') { return 'partial'; }
    $foreign = $pdo->prepare("SELECT REFERENCED_TABLE_NAME, DELETE_RULE, UPDATE_RULE FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = :schema AND TABLE_NAME = 'seo_publication_jobs' AND CONSTRAINT_NAME = 'fk_seo_publication_job_product'");
    $foreign->execute(['schema' => $database]);
    $fk = $foreign->fetch(PDO::FETCH_ASSOC);
    if (!$fk || $fk['REFERENCED_TABLE_NAME'] !== 'products' || $fk['DELETE_RULE'] !== 'RESTRICT' || $fk['UPDATE_RULE'] !== 'RESTRICT') { return 'partial'; }
    $checks = $pdo->prepare("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = :schema AND TABLE_NAME = 'seo_publication_jobs' AND CONSTRAINT_TYPE = 'CHECK'");
    $checks->execute(['schema' => $database]);
    $checkNames = array_map('strval', $checks->fetchAll(PDO::FETCH_COLUMN));
    if (!in_array('chk_seo_publication_job_operation', $checkNames, true) || !in_array('chk_seo_publication_job_status', $checkNames, true)) { return 'partial'; }
    $invalid = $pdo->query("SELECT COUNT(*) FROM products WHERE (is_active = 1 AND publication_status <> 'published') OR (is_active = 0 AND publication_status <> 'draft')")->fetchColumn();
    return (int)$invalid === 0 ? 'complete' : 'partial';
}

$schemaState = seoPublicationSchemaState($pdo, $name);
if ($schemaState === 'complete') { echo json_encode(['status' => 'ALREADY_APPLIED', 'sha256' => SEO_PUBLICATION_MIGRATION_SHA256], JSON_UNESCAPED_SLASHES) . "\n"; exit(0); }
if ($schemaState === 'partial') { fwrite(STDERR, "MIGRATION_SCHEMA_PARTIAL\n"); exit(1); }
if ($mode === '--preflight') { echo json_encode(['status' => 'PREFLIGHT_OK'], JSON_UNESCAPED_SLASHES) . "\n"; exit(0); }
$sql = file_get_contents($migration);
if (!is_string($sql)) { fwrite(STDERR, "MIGRATION_READ_FAILED\n"); exit(1); }
try {
    foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|\z)/', $sql) ?: [])) as $statement) {
        $statement = preg_replace('/\A(?:\s*--[^\r\n]*(?:\r?\n|\z))+/', '', $statement);
        if (trim((string)$statement) !== '') $pdo->exec((string)$statement);
    }
    if (seoPublicationSchemaState($pdo, $name) !== 'complete') { throw new RuntimeException('schema verification failed'); }
    echo json_encode(['status' => 'MIGRATION_APPLIED', 'sha256' => SEO_PUBLICATION_MIGRATION_SHA256], JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, "MIGRATION_FAILED\n"); exit(1);
}

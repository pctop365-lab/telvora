<?php

declare(strict_types=1);
const SEO_PUBLICATION_MIGRATION_SHA256 = '59829574ea7a605a4a70c77dbbe4255a3d141c72ddb33e006a23ad8340f5e56c6';
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
if ($mode === '--preflight') { echo json_encode(['status' => 'PREFLIGHT_OK'], JSON_UNESCAPED_SLASHES) . "\n"; exit(0); }
$sql = file_get_contents($migration);
if (!is_string($sql)) { fwrite(STDERR, "MIGRATION_READ_FAILED\n"); exit(1); }
try {
    $pdo->beginTransaction();
    foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|\z)/', $sql) ?: [])) as $statement) {
        $statement = preg_replace('/\A(?:\s*--[^\r\n]*(?:\r?\n|\z))+/', '', $statement);
        if (trim((string)$statement) !== '') $pdo->exec((string)$statement);
    }
    $pdo->commit();
    echo json_encode(['status' => 'MIGRATION_APPLIED', 'sha256' => SEO_PUBLICATION_MIGRATION_SHA256], JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "MIGRATION_FAILED\n"); exit(1);
}

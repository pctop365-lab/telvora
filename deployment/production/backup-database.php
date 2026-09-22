<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI_ONLY\n");
    exit(2);
}

$runtimeConfig = getenv('TELVORA_RUNTIME_CONFIG');
$runtimeConfig = is_string($runtimeConfig) && $runtimeConfig !== '' ? $runtimeConfig : dirname(__DIR__, 2) . '/runtime_config.php';
if (!is_file($runtimeConfig) || !is_readable($runtimeConfig)) { fwrite(STDERR, "RUNTIME_CONFIG_UNAVAILABLE\n"); exit(1); }
require_once $runtimeConfig;
$secretsFile = telvoraSecretsFile();
if (!is_file($secretsFile) || !is_readable($secretsFile)) { fwrite(STDERR, "DB_CONFIG_UNAVAILABLE\n"); exit(1); }
$config = require $secretsFile;
$host = (string)($config['db_host'] ?? '');
$port = (int)($config['db_port'] ?? 3306);
$database = (string)($config['db_name'] ?? '');
$user = (string)($config['db_user'] ?? '');
$password = (string)($config['db_password'] ?? '');
if ($host === '' || $database === '' || $user === '' || $password === '' || preg_match('/\A[a-zA-Z0-9_$-]+\z/', $database) !== 1) { fwrite(STDERR, "DB_CONFIG_INVALID\n"); exit(1); }
$backupRoot = getenv('TELVORA_DB_BACKUP_DIR');
$backupRoot = is_string($backupRoot) && $backupRoot !== '' ? $backupRoot : '/var/www/u3609206/data/telvora-db-backups';
$documentRoot = '/var/www/u3609206/data/www/telvora.ru';
$backupCanonical = realpath($backupRoot) ?: $backupRoot;
if ($backupCanonical === $documentRoot || str_starts_with($backupCanonical, $documentRoot . '/')) { fwrite(STDERR, "BACKUP_PATH_NOT_PRIVATE\n"); exit(1); }
if (!is_dir($backupRoot) && !mkdir($backupRoot, 0700, true) && !is_dir($backupRoot)) { fwrite(STDERR, "BACKUP_DIR_UNWRITABLE\n"); exit(1); }
chmod($backupRoot, 0700);
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->query('SELECT 1');
$which = [];
exec('command -v mysqldump 2>/dev/null', $which, $whichExit);
if ($whichExit !== 0 || $which === []) { echo json_encode(['status' => 'MYSQLDUMP_NOT_AVAILABLE'], JSON_UNESCAPED_SLASHES) . "\n"; exit(1); }
if (($argv[1] ?? '') === '--preflight') { echo json_encode(['status' => 'PREFLIGHT_OK'], JSON_UNESCAPED_SLASHES) . "\n"; exit(0); }
if (($argv[1] ?? '') !== '--backup') { fwrite(STDERR, "Usage: php backup-database.php --preflight|--backup\n"); exit(2); }
$reference = rtrim($backupRoot, '/\\') . '/telvora-db-' . gmdate('Ymd\THis\Z') . '.sql';
$temporary = $reference . '.tmp.' . bin2hex(random_bytes(4));
$command = 'mysqldump --single-transaction --routines --triggers --host=' . escapeshellarg($host) . ' --port=' . $port . ' --user=' . escapeshellarg($user) . ' ' . escapeshellarg($database);
$environment = array_merge($_ENV, ['MYSQL_PWD' => $password]);
$process = proc_open($command . ' > ' . escapeshellarg($temporary), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
if (!is_resource($process)) { fwrite(STDERR, "MYSQLDUMP_START_FAILED\n"); exit(1); }
fclose($pipes[1]); fclose($pipes[2]);
$exit = proc_close($process);
if ($exit !== 0 || !is_file($temporary)) { @unlink($temporary); fwrite(STDERR, "MYSQLDUMP_FAILED\n"); exit(1); }
chmod($temporary, 0600);
if (!rename($temporary, $reference)) { @unlink($temporary); fwrite(STDERR, "BACKUP_RENAME_FAILED\n"); exit(1); }
$size = filesize($reference);
$sha256 = hash_file('sha256', $reference);
echo json_encode(['status' => 'BACKUP_CREATED', 'backup_reference' => $reference, 'sha256' => $sha256, 'size' => $size], JSON_UNESCAPED_SLASHES) . "\n";

<?php

declare(strict_types=1);

/**
 * TELVORA collision migration 000.
 *
 * This CLI-only runner performs exactly one schema mutation:
 *
 *   RENAME TABLE product_variants TO product_variants_legacy
 *
 * It must run before 20260831_001_supplier_variant_infrastructure.sql.
 * Credentials are loaded from a private PHP file outside the repository.
 * No credential value or raw PDO error is printed.
 */

const TELVORA_SOURCE_TABLE = 'product_variants';
const TELVORA_LEGACY_TABLE = 'product_variants_legacy';

function fail(string $message): void
{
    throw new RuntimeException($message);
}

function parseArguments(array $argv): array
{
    $options = [
        'execute' => false,
        'expected_database' => '',
        'secrets_file' => '',
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--execute') {
            $options['execute'] = true;
            continue;
        }

        if (str_starts_with($argument, '--expected-database=')) {
            $options['expected_database'] = substr(
                $argument,
                strlen('--expected-database=')
            );
            continue;
        }

        if (str_starts_with($argument, '--secrets=')) {
            $options['secrets_file'] = substr(
                $argument,
                strlen('--secrets=')
            );
            continue;
        }

        fail('Unknown argument.');
    }

    if (!$options['execute']) {
        fail('Refusing to run without --execute.');
    }

    if (!preg_match('/^[A-Za-z0-9_$-]+$/D', $options['expected_database'])) {
        fail('A valid --expected-database value is required.');
    }

    return $options;
}

function resolveSecretsFile(string $argumentValue): string
{
    if ($argumentValue !== '') {
        return $argumentValue;
    }

    $environmentValue = getenv('TELVORA_SECRETS_FILE');
    if (is_string($environmentValue) && $environmentValue !== '') {
        return $environmentValue;
    }

    return dirname(__DIR__, 4) . '/telvora_runtime/telvora_secrets.php';
}

function loadPrivateDatabaseSecrets(string $file): array
{
    if (!is_file($file) || !is_readable($file)) {
        fail('Private secrets file is missing or unreadable.');
    }

    $secrets = require $file;
    if (!is_array($secrets)) {
        fail('Private secrets file has an invalid format.');
    }

    $required = ['db_host', 'db_name', 'db_user', 'db_password'];
    $result = [];

    foreach ($required as $key) {
        $value = $secrets[$key] ?? null;
        if (!is_string($value) || $value === '') {
            fail('A required private database setting is missing.');
        }
        $result[$key] = $value;
    }

    return $result;
}

function connectDatabase(array $secrets): PDO
{
    return new PDO(
        'mysql:host=' . $secrets['db_host'] .
            ';dbname=' . $secrets['db_name'] .
            ';charset=utf8mb4',
        $secrets['db_user'],
        $secrets['db_password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
        ]
    );
}

function scalar(PDO $pdo, string $sql, array $parameters = []): mixed
{
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchColumn();
}

function assertSelectedDatabase(PDO $pdo, string $expectedDatabase): void
{
    $selectedDatabase = scalar($pdo, 'SELECT DATABASE()');
    if (!is_string($selectedDatabase) || $selectedDatabase !== $expectedDatabase) {
        fail('Selected database does not match --expected-database.');
    }
}

function tableMetadata(PDO $pdo, string $table): ?array
{
    $statement = $pdo->prepare(
        'SELECT table_type, engine, table_collation
           FROM information_schema.tables
          WHERE table_schema = DATABASE()
            AND table_name = :table_name'
    );
    $statement->execute([':table_name' => $table]);
    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}

function expectedLegacyColumns(): array
{
    return [
        ['id', 'int unsigned', 'NO', null, 'auto_increment'],
        ['product_id', 'int unsigned', 'NO', null, ''],
        ['name', 'varchar(255)', 'NO', null, ''],
        ['country', 'varchar(100)', 'NO', null, ''],
        ['price', 'decimal(12,2)', 'NO', '0.00', ''],
        ['old_price', 'decimal(12,2)', 'YES', null, ''],
        ['is_active', 'tinyint(1)', 'NO', '1', ''],
        ['created_at', 'timestamp', 'NO', 'CURRENT_TIMESTAMP', 'DEFAULT_GENERATED'],
        [
            'updated_at',
            'timestamp',
            'NO',
            'CURRENT_TIMESTAMP',
            'DEFAULT_GENERATED on update CURRENT_TIMESTAMP',
        ],
    ];
}

function actualLegacyColumns(PDO $pdo): array
{
    $statement = $pdo->query(
        "SELECT column_name, column_type, is_nullable, column_default, extra
           FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = 'product_variants'
          ORDER BY ordinal_position"
    );

    return array_map(
        static fn(array $row): array => [
            (string)$row['column_name'],
            strtolower((string)$row['column_type']),
            (string)$row['is_nullable'],
            $row['column_default'] === null
                ? null
                : (string)$row['column_default'],
            (string)$row['extra'],
        ],
        $statement->fetchAll()
    );
}

function assertLegacyColumns(PDO $pdo): void
{
    if (actualLegacyColumns($pdo) !== expectedLegacyColumns()) {
        fail('Legacy columns, types, nullability, defaults, or order differ.');
    }
}

function assertLegacyIndexes(PDO $pdo): void
{
    $statement = $pdo->query(
        "SELECT index_name, column_name, non_unique, seq_in_index
           FROM information_schema.statistics
          WHERE table_schema = DATABASE()
            AND table_name = 'product_variants'
          ORDER BY index_name, seq_in_index"
    );

    $actual = array_map(
        static fn(array $row): array => [
            (string)$row['index_name'],
            (string)$row['column_name'],
            (int)$row['non_unique'],
            (int)$row['seq_in_index'],
        ],
        $statement->fetchAll()
    );

    $expected = [
        ['idx_country', 'country', 1, 1],
        ['idx_product_id', 'product_id', 1, 1],
        ['PRIMARY', 'id', 0, 1],
    ];

    if ($actual !== $expected) {
        fail('Legacy primary key or indexes differ.');
    }
}

function assertLegacyForeignKeys(PDO $pdo): void
{
    $statement = $pdo->query(
        "SELECT
             kcu.constraint_name,
             kcu.column_name,
             kcu.referenced_table_schema,
             kcu.referenced_table_name,
             kcu.referenced_column_name,
             rc.update_rule,
             rc.delete_rule
           FROM information_schema.key_column_usage kcu
           JOIN information_schema.referential_constraints rc
             ON rc.constraint_schema = kcu.constraint_schema
            AND rc.constraint_name = kcu.constraint_name
          WHERE kcu.table_schema = DATABASE()
            AND kcu.table_name = 'product_variants'
            AND kcu.referenced_table_name IS NOT NULL"
    );

    $foreignKeys = $statement->fetchAll();
    if (count($foreignKeys) !== 1) {
        fail('Legacy outgoing foreign key count differs.');
    }

    $foreignKey = $foreignKeys[0];
    $expectedDatabase = (string)scalar($pdo, 'SELECT DATABASE()');

    if (
        (string)$foreignKey['constraint_name'] !== 'fk_product_variants_product' ||
        (string)$foreignKey['column_name'] !== 'product_id' ||
        (string)$foreignKey['referenced_table_schema'] !== $expectedDatabase ||
        (string)$foreignKey['referenced_table_name'] !== 'products' ||
        (string)$foreignKey['referenced_column_name'] !== 'id' ||
        (string)$foreignKey['update_rule'] !== 'CASCADE' ||
        (string)$foreignKey['delete_rule'] !== 'CASCADE'
    ) {
        fail('Legacy product foreign key differs.');
    }
}

function assertNoDependencies(PDO $pdo): void
{
    $checks = [
        'incoming foreign keys' =>
            "SELECT COUNT(*)
               FROM information_schema.key_column_usage
              WHERE referenced_table_schema = DATABASE()
                AND referenced_table_name = 'product_variants'",
        'triggers' =>
            "SELECT COUNT(*)
               FROM information_schema.triggers
              WHERE trigger_schema = DATABASE()
                AND event_object_table = 'product_variants'",
        'views' =>
            "SELECT COUNT(*)
               FROM information_schema.view_table_usage
              WHERE table_schema = DATABASE()
                AND table_name = 'product_variants'",
        'routines' =>
            "SELECT COUNT(*)
               FROM information_schema.routines
              WHERE routine_schema = DATABASE()
                AND LOWER(COALESCE(routine_definition, ''))
                    LIKE '%product_variants%'",
        'events' =>
            "SELECT COUNT(*)
               FROM information_schema.events
              WHERE event_schema = DATABASE()
                AND LOWER(COALESCE(event_definition, ''))
                    LIKE '%product_variants%'",
    ];

    foreach ($checks as $label => $sql) {
        if ((int)scalar($pdo, $sql) !== 0) {
            fail('Unexpected database dependency: ' . $label . '.');
        }
    }
}

function showCreateLegacyTable(PDO $pdo): string
{
    $statement = $pdo->query('SHOW CREATE TABLE `product_variants`');
    $row = $statement->fetch(PDO::FETCH_NUM);

    if (!is_array($row) || !isset($row[1]) || !is_string($row[1])) {
        fail('SHOW CREATE TABLE did not return the expected result.');
    }

    return $row[1];
}

function assertLegacyPreflight(PDO $pdo): string
{
    $source = tableMetadata($pdo, TELVORA_SOURCE_TABLE);
    if ($source === null || (string)$source['table_type'] !== 'BASE TABLE') {
        fail('Source product_variants base table is missing.');
    }

    if (
        (string)$source['engine'] !== 'InnoDB' ||
        (string)$source['table_collation'] !== 'utf8mb4_0900_ai_ci'
    ) {
        fail('Legacy engine or collation differs.');
    }

    if (tableMetadata($pdo, TELVORA_LEGACY_TABLE) !== null) {
        fail('Target product_variants_legacy already exists.');
    }

    assertLegacyColumns($pdo);
    assertLegacyIndexes($pdo);
    assertLegacyForeignKeys($pdo);
    assertNoDependencies($pdo);

    return showCreateLegacyTable($pdo);
}

function assertCriticalConditionsUnderLock(
    PDO $pdo,
    string $preflightCreateSql
): void {
    if (tableMetadata($pdo, TELVORA_LEGACY_TABLE) !== null) {
        fail('Target product_variants_legacy appeared while acquiring lock.');
    }

    if (!hash_equals($preflightCreateSql, showCreateLegacyTable($pdo))) {
        fail('Legacy SHOW CREATE TABLE changed while acquiring lock.');
    }

    assertLegacyColumns($pdo);
    assertLegacyIndexes($pdo);
    assertLegacyForeignKeys($pdo);

    if ((int)scalar($pdo, 'SELECT COUNT(*) FROM `product_variants`') !== 0) {
        fail('Source product_variants is no longer empty.');
    }
}

function bestEffortUnlock(?PDO $pdo, bool &$locked): void
{
    if (!$pdo instanceof PDO || !$locked) {
        return;
    }

    try {
        $pdo->exec('UNLOCK TABLES');
        $locked = false;
    } catch (Throwable $ignored) {
        // Closing this non-persistent PDO connection releases session locks.
    }
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: CLI execution is required.\n");
    exit(1);
}

$pdo = null;
$locked = false;
$renamed = false;
$stage = 'argument validation';
$exitCode = 1;
$finalMessage = '';

try {
    $options = parseArguments($argv);
    $secretsFile = resolveSecretsFile($options['secrets_file']);

    $stage = 'private credential loading';
    $secrets = loadPrivateDatabaseSecrets($secretsFile);

    if ($secrets['db_name'] !== $options['expected_database']) {
        fail('Private db_name does not match --expected-database.');
    }

    $stage = 'database connection';
    $pdo = connectDatabase($secrets);
    assertSelectedDatabase($pdo, $options['expected_database']);

    $stage = 'read-only preflight';
    $preflightCreateSql = assertLegacyPreflight($pdo);

    // MySQL recommends autocommit=0 while explicit locks protect InnoDB.
    $pdo->exec('SET SESSION autocommit = 0');

    $stage = 'write lock acquisition';
    $pdo->exec('LOCK TABLES `product_variants` WRITE');
    $locked = true;

    $stage = 'locked critical recheck';
    assertCriticalConditionsUnderLock($pdo, $preflightCreateSql);

    $stage = 'atomic table rename';
    $pdo->exec(
        'RENAME TABLE `product_variants` TO `product_variants_legacy`'
    );
    $renamed = true;

    $stage = 'post-rename verification';
    if (
        tableMetadata($pdo, TELVORA_SOURCE_TABLE) !== null ||
        tableMetadata($pdo, TELVORA_LEGACY_TABLE) === null
    ) {
        fail('Post-rename table state is unexpected.');
    }

    bestEffortUnlock($pdo, $locked);
    $pdo->exec('SET SESSION autocommit = 1');

    $exitCode = 0;
    $finalMessage =
        "SUCCESS: product_variants renamed to product_variants_legacy.\n";
} catch (Throwable $error) {
    $outcome = $renamed
        ? 'RENAME OCCURRED; inspect with verification SQL before any retry.'
        : 'No rename occurred.';

    $finalMessage =
        'ERROR during ' . $stage . ': migration aborted. ' .
        $outcome . "\n";
} finally {
    bestEffortUnlock($pdo, $locked);

    if ($pdo instanceof PDO) {
        try {
            $pdo->exec('SET SESSION autocommit = 1');
        } catch (Throwable $ignored) {
            // Connection close remains the final lock-release fallback.
        }
    }

    $pdo = null;
}

fwrite($exitCode === 0 ? STDOUT : STDERR, $finalMessage);
exit($exitCode);

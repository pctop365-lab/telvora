<?php

declare(strict_types=1);

/**
 * TELVORA Stage 7 data backfill.
 *
 * Default and --preflight modes are read-only. Only --execute may perform
 * INSERT statements, and only into product_variants. --verify is read-only.
 */

const TELVORA_BACKFILL_STATUS = 'requires_classification';
const TELVORA_BACKFILL_KEY_PREFIX = 'legacy-country-sha256-';
const TELVORA_BACKFILL_COLLATION = 'utf8mb4_unicode_ci';

function backfillFail(string $message): never
{
    throw new RuntimeException($message);
}

function backfillParseArguments(array $argv): array
{
    $options = [
        'mode' => 'preflight',
        'expected_database' => '',
        'secrets_file' => '',
    ];
    $modeWasSpecified = false;

    foreach (array_slice($argv, 1) as $argument) {
        if (in_array($argument, ['--preflight', '--execute', '--verify'], true)) {
            if ($modeWasSpecified) {
                backfillFail('Specify at most one execution mode.');
            }
            $options['mode'] = substr($argument, 2);
            $modeWasSpecified = true;
            continue;
        }
        if (str_starts_with($argument, '--expected-database=')) {
            $options['expected_database'] = substr($argument, strlen('--expected-database='));
            continue;
        }
        if (str_starts_with($argument, '--secrets=')) {
            $options['secrets_file'] = substr($argument, strlen('--secrets='));
            continue;
        }
        backfillFail('Unknown argument.');
    }

    if (preg_match('/\A[A-Za-z0-9_$-]+\z/D', $options['expected_database']) !== 1) {
        backfillFail('A valid --expected-database value is required.');
    }
    return $options;
}

function backfillResolveSecretsFile(string $argumentValue): string
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

function backfillLoadSecrets(string $file): array
{
    if (!is_file($file) || !is_readable($file)) {
        backfillFail('Private secrets file is missing or unreadable.');
    }
    $secrets = require $file;
    if (!is_array($secrets)) {
        backfillFail('Private secrets file has an invalid format.');
    }
    $result = [];
    foreach (['db_host', 'db_name', 'db_user', 'db_password'] as $key) {
        $value = $secrets[$key] ?? null;
        if (!is_string($value) || $value === '') {
            backfillFail('A required private database setting is missing.');
        }
        $result[$key] = $value;
    }
    return $result;
}

function backfillConnect(array $secrets): PDO
{
    return new PDO(
        'mysql:host=' . $secrets['db_host'] .
            ';dbname=' . $secrets['db_name'] . ';charset=utf8mb4',
        $secrets['db_user'],
        $secrets['db_password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_CASE => PDO::CASE_LOWER,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
        ]
    );
}

function backfillScalar(PDO $pdo, string $sql, array $parameters = []): mixed
{
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchColumn();
}

function backfillAssertDatabase(PDO $pdo, string $expectedDatabase): void
{
    $actual = backfillScalar($pdo, 'SELECT DATABASE()');
    if (!is_string($actual) || !hash_equals($expectedDatabase, $actual)) {
        backfillFail('Selected database does not match --expected-database.');
    }
}

function backfillAssertSchema(PDO $pdo): void
{
    $table = $pdo->query("
        SELECT engine, table_collation
        FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'product_variants'
          AND table_type = 'BASE TABLE'
    ")->fetch();
    if (
        !is_array($table) ||
        (string)$table['engine'] !== 'InnoDB' ||
        (string)$table['table_collation'] !== TELVORA_BACKFILL_COLLATION
    ) {
        backfillFail('product_variants table metadata differs from Stage 1.');
    }
    $requiredColumns = [
        'id' => ['bigint unsigned', 'NO'],
        'product_id' => ['int unsigned', 'NO'],
        'variant_key' => ['varchar(191)', 'NO'],
        'assembly_country' => ['varchar(100)', 'YES'],
        'market_region_id' => ['bigint unsigned', 'YES'],
        'certification_supply_type_id' => ['bigint unsigned', 'YES'],
        'manufacturer_part_number' => ['varchar(191)', 'YES'],
        'display_name' => ['varchar(255)', 'YES'],
        'classification_status' => ['varchar(50)', 'NO'],
        'classification_evidence' => ['json', 'YES'],
        'is_active' => ['tinyint(1)', 'NO'],
        'created_at' => ['timestamp', 'NO'],
        'updated_at' => ['timestamp', 'NO'],
    ];
    $statement = $pdo->query("
        SELECT column_name, column_type, is_nullable, collation_name
        FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'product_variants'
    ");
    $actual = [];
    foreach ($statement->fetchAll() as $row) {
        $actual[(string)$row['column_name']] = $row;
    }
    if (count($actual) !== count($requiredColumns)) {
        backfillFail('product_variants column count differs from Stage 1.');
    }
    foreach ($requiredColumns as $name => [$type, $nullable]) {
        $column = $actual[$name] ?? null;
        if (
            !is_array($column) ||
            strtolower((string)$column['column_type']) !== $type ||
            (string)$column['is_nullable'] !== $nullable
        ) {
            backfillFail('product_variants schema differs from the expected Stage 1 schema.');
        }
    }
    foreach (['variant_key', 'assembly_country', 'classification_status'] as $columnName) {
        if (($actual[$columnName]['collation_name'] ?? null) !== TELVORA_BACKFILL_COLLATION) {
            backfillFail('product_variants text collation differs from the expected collation.');
        }
    }
    $uniqueColumns = $pdo->query("
        SELECT column_name
        FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'product_variants'
          AND index_name = 'uq_product_variants_product_key' AND non_unique = 0
        ORDER BY seq_in_index
    ")->fetchAll(PDO::FETCH_COLUMN);
    if ($uniqueColumns !== ['product_id', 'variant_key']) {
        backfillFail('product_variants unique identity differs.');
    }
    $foreignKey = $pdo->query("
        SELECT kcu.column_name, kcu.referenced_table_name,
               kcu.referenced_column_name, rc.update_rule, rc.delete_rule
        FROM information_schema.key_column_usage kcu
        INNER JOIN information_schema.referential_constraints rc
          ON rc.constraint_schema = kcu.constraint_schema
         AND rc.constraint_name = kcu.constraint_name
        WHERE kcu.table_schema = DATABASE()
          AND kcu.table_name = 'product_variants'
          AND kcu.constraint_name = 'fk_product_variants_canonical_product'
    ")->fetch();
    if (
        !is_array($foreignKey) ||
        $foreignKey !== [
            'column_name' => 'product_id',
            'referenced_table_name' => 'products',
            'referenced_column_name' => 'id',
            'update_rule' => 'RESTRICT',
            'delete_rule' => 'RESTRICT',
        ]
    ) {
        backfillFail('product_variants product foreign key differs from Stage 1.');
    }
    $productColumns = $pdo->query("
        SELECT column_name, column_type, is_nullable
        FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'products'
          AND column_name IN ('id', 'variants')
        ORDER BY column_name
    ")->fetchAll();
    if (count($productColumns) !== 2) {
        backfillFail('products.id or products.variants is unavailable.');
    }
}

function backfillCollationWeight(PDOStatement $statement, string $value): string
{
    $statement->execute([':value' => $value]);
    $weight = $statement->fetchColumn();
    if (!is_string($weight) || $weight === '') {
        backfillFail('Unable to calculate a database collation weight.');
    }
    return $weight;
}

function backfillVariantKey(string $country): string
{
    return TELVORA_BACKFILL_KEY_PREFIX . hash('sha256', $country);
}

function backfillValidateLegacyVariant(array $variant, int $productId, int $position): array
{
    if (array_is_list($variant)) {
        backfillFail("Legacy variant is not an object for product {$productId}.");
    }
    $keys = array_keys($variant);
    sort($keys, SORT_STRING);
    if ($keys !== ['country', 'is_active', 'old_price', 'price']) {
        backfillFail("Legacy variant shape is unsupported for product {$productId}.");
    }
    if (!is_string($variant['country']) || !mb_check_encoding($variant['country'], 'UTF-8')) {
        backfillFail("Legacy country is invalid for product {$productId}.");
    }
    $country = preg_replace('/\A\s+|\s+\z/u', '', $variant['country']);
    if (!is_string($country)) {
        backfillFail("Legacy country cannot be normalized for product {$productId}.");
    }
    if ($country === '' || mb_strlen($country, 'UTF-8') > 100) {
        backfillFail("Legacy country length is invalid for product {$productId}.");
    }
    $controlMatch = preg_match('/[\x00-\x1F\x7F]/u', $country);
    if ($controlMatch === false || $controlMatch === 1) {
        backfillFail("Legacy country contains invalid control characters for product {$productId}.");
    }
    if (!is_bool($variant['is_active'])) {
        backfillFail("Legacy is_active is invalid for product {$productId}.");
    }
    if (!is_int($variant['price']) && !is_float($variant['price'])) {
        backfillFail("Legacy price shape is invalid for product {$productId}.");
    }
    if (is_float($variant['price']) && !is_finite($variant['price'])) {
        backfillFail("Legacy price is not finite for product {$productId}.");
    }
    if (
        $variant['old_price'] !== null &&
        !is_int($variant['old_price']) &&
        !is_float($variant['old_price'])
    ) {
        backfillFail("Legacy old_price shape is invalid for product {$productId}.");
    }
    if (is_float($variant['old_price']) && !is_finite($variant['old_price'])) {
        backfillFail("Legacy old_price is not finite for product {$productId}.");
    }
    if ($position < 0) {
        backfillFail('Legacy variant position is invalid.');
    }
    return [
        'product_id' => $productId,
        'variant_key' => backfillVariantKey($country),
        'assembly_country' => $country,
        'is_active' => $variant['is_active'] ? 1 : 0,
    ];
}

function backfillReadExpected(PDO $pdo, bool $lock): array
{
    $sql = 'SELECT id, variants FROM products ORDER BY id' . ($lock ? ' FOR UPDATE' : '');
    $products = $pdo->query($sql)->fetchAll();
    $weightStatement = $pdo->prepare(
        'SELECT HEX(WEIGHT_STRING(CAST(:value AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci))'
    );
    $expected = [];
    $productCount = 0;
    $legacyVariantCount = 0;
    foreach ($products as $product) {
        $productId = filter_var($product['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($productId === false) {
            backfillFail('A product has an invalid identifier.');
        }
        $productCount++;
        $rawVariants = $product['variants'] ?? null;
        if ($rawVariants === null || $rawVariants === '') {
            $variants = [];
        } elseif (!is_string($rawVariants)) {
            backfillFail("Legacy variants storage is invalid for product {$productId}.");
        } else {
            try {
                $variants = json_decode($rawVariants, false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                backfillFail("Legacy variants JSON is invalid for product {$productId}.");
            }
        }
        if (!is_array($variants) || !array_is_list($variants)) {
            backfillFail("Legacy variants JSON is not an array for product {$productId}.");
        }
        $countryWeights = [];
        $keyWeights = [];
        foreach ($variants as $position => $variant) {
            if (!$variant instanceof stdClass) {
                backfillFail("Legacy variant is not an object for product {$productId}.");
            }
            $row = backfillValidateLegacyVariant(
                get_object_vars($variant),
                (int)$productId,
                $position
            );
            $countryWeight = backfillCollationWeight($weightStatement, $row['assembly_country']);
            if (isset($countryWeights[$countryWeight])) {
                backfillFail("Duplicate logical assembly country for product {$productId}.");
            }
            $countryWeights[$countryWeight] = true;
            $keyWeight = backfillCollationWeight($weightStatement, $row['variant_key']);
            if (isset($keyWeights[$keyWeight])) {
                backfillFail("Generated variant key collision for product {$productId}.");
            }
            $keyWeights[$keyWeight] = true;
            $expected[(int)$productId][] = $row + [
                '_country_weight' => $countryWeight,
                '_key_weight' => $keyWeight,
            ];
            $legacyVariantCount++;
        }
    }
    return [
        'products' => $productCount,
        'legacy_variants' => $legacyVariantCount,
        'expected' => $expected,
    ];
}

function backfillReadExisting(PDO $pdo, bool $lock): array
{
    $sql = "
        SELECT id, product_id, variant_key, assembly_country,
               market_region_id, certification_supply_type_id,
               manufacturer_part_number, display_name,
               classification_status, classification_evidence, is_active
        FROM product_variants ORDER BY product_id, id" . ($lock ? ' FOR UPDATE' : '');
    $rows = $pdo->query($sql)->fetchAll();
    $weightStatement = $pdo->prepare(
        'SELECT HEX(WEIGHT_STRING(CAST(:value AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci))'
    );
    $existing = [];
    foreach ($rows as $row) {
        $productId = (int)($row['product_id'] ?? 0);
        $id = (int)($row['id'] ?? 0);
        if ($productId < 1 || $id < 1 || !is_string($row['variant_key'])) {
            backfillFail('An existing relational variant is structurally invalid.');
        }
        $row['_key_weight'] = backfillCollationWeight($weightStatement, $row['variant_key']);
        $row['_country_weight'] = is_string($row['assembly_country']) && $row['assembly_country'] !== ''
            ? backfillCollationWeight($weightStatement, $row['assembly_country'])
            : null;
        $existing[$productId][] = $row;
    }
    return $existing;
}

function backfillRowsAreCompatible(array $expected, array $actual): bool
{
    return
        (int)$actual['product_id'] === $expected['product_id'] &&
        (string)$actual['variant_key'] === $expected['variant_key'] &&
        $actual['assembly_country'] === $expected['assembly_country'] &&
        $actual['market_region_id'] === null &&
        $actual['certification_supply_type_id'] === null &&
        $actual['manufacturer_part_number'] === null &&
        $actual['display_name'] === null &&
        $actual['classification_status'] === TELVORA_BACKFILL_STATUS &&
        $actual['classification_evidence'] === null &&
        (int)$actual['is_active'] === $expected['is_active'];
}

function backfillBuildPlan(array $dataset, array $existing, bool $requireComplete): array
{
    $missing = [];
    $compatible = 0;
    foreach ($dataset['expected'] as $productId => $expectedRows) {
        $actualRows = $existing[$productId] ?? [];
        foreach ($expectedRows as $expected) {
            $exact = null;
            foreach ($actualRows as $actual) {
                if ($actual['_key_weight'] === $expected['_key_weight']) {
                    if ($exact !== null) {
                        backfillFail("Multiple relational key matches for product {$productId}.");
                    }
                    $exact = $actual;
                } elseif (
                    $actual['_country_weight'] !== null &&
                    $actual['_country_weight'] === $expected['_country_weight']
                ) {
                    backfillFail("Relational assembly-country conflict for product {$productId}.");
                }
            }
            if ($exact === null) {
                if ($requireComplete) {
                    backfillFail("Expected relational variant is missing for product {$productId}.");
                }
                $missing[] = $expected;
                continue;
            }
            if (!backfillRowsAreCompatible($expected, $exact)) {
                backfillFail("Existing relational variant conflicts for product {$productId}.");
            }
            $compatible++;
        }
    }
    return ['missing' => $missing, 'compatible' => $compatible];
}

function backfillInsertMissing(PDO $pdo, array $missing): void
{
    $statement = $pdo->prepare("
        INSERT INTO product_variants (
            product_id, variant_key, assembly_country,
            market_region_id, certification_supply_type_id,
            manufacturer_part_number, display_name,
            classification_status, classification_evidence, is_active
        ) VALUES (
            :product_id, :variant_key, :assembly_country,
            NULL, NULL, NULL, NULL,
            :classification_status, NULL, :is_active
        )
    ");
    foreach ($missing as $row) {
        $statement->execute([
            ':product_id' => $row['product_id'],
            ':variant_key' => $row['variant_key'],
            ':assembly_country' => $row['assembly_country'],
            ':classification_status' => TELVORA_BACKFILL_STATUS,
            ':is_active' => $row['is_active'],
        ]);
    }
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: CLI execution is required.\n");
    exit(1);
}

$pdo = null;
$exitCode = 1;
$stage = 'argument validation';
$finalMessage = '';

try {
    $options = backfillParseArguments($argv);
    $secretsFile = backfillResolveSecretsFile($options['secrets_file']);
    $stage = 'private credential loading';
    $secrets = backfillLoadSecrets($secretsFile);
    if ($secrets['db_name'] !== $options['expected_database']) {
        backfillFail('Private db_name does not match --expected-database.');
    }
    $stage = 'database connection';
    $pdo = backfillConnect($secrets);
    backfillAssertDatabase($pdo, $options['expected_database']);
    $stage = 'schema verification';
    backfillAssertSchema($pdo);

    if ($options['mode'] === 'execute') {
        $stage = 'transaction start';
        $pdo->beginTransaction();
        $stage = 'locked authoritative preflight';
        $dataset = backfillReadExpected($pdo, true);
        $existing = backfillReadExisting($pdo, true);
        $plan = backfillBuildPlan($dataset, $existing, false);
        $stage = 'product_variants insert';
        backfillInsertMissing($pdo, $plan['missing']);
        $stage = 'locked post-insert verification';
        $verifiedExisting = backfillReadExisting($pdo, true);
        backfillBuildPlan($dataset, $verifiedExisting, true);
        $pdo->commit();
        $exitCode = 0;
        $finalMessage = sprintf(
            "EXECUTE: OK; products=%d; legacy_variants=%d; inserted=%d; existing_noop=%d\n",
            $dataset['products'],
            $dataset['legacy_variants'],
            count($plan['missing']),
            $plan['compatible']
        );
    } else {
        $stage = $options['mode'] === 'verify' ? 'read-only verification' : 'read-only preflight';
        $dataset = backfillReadExpected($pdo, false);
        $existing = backfillReadExisting($pdo, false);
        $plan = backfillBuildPlan($dataset, $existing, $options['mode'] === 'verify');
        $exitCode = 0;
        $finalMessage = sprintf(
            "%s: OK; products=%d; legacy_variants=%d; missing=%d; compatible=%d\n",
            strtoupper($options['mode']),
            $dataset['products'],
            $dataset['legacy_variants'],
            count($plan['missing']),
            $plan['compatible']
        );
    }
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $ignored) {
            // Closing the non-persistent connection is the final rollback fallback.
        }
    }
    $safeDetail = $error instanceof RuntimeException && !($error instanceof PDOException)
        ? ' ' . $error->getMessage()
        : '';
    $finalMessage = "ERROR during {$stage}: backfill aborted; no partial inserts committed.{$safeDetail}\n";
} finally {
    $pdo = null;
}

fwrite($exitCode === 0 ? STDOUT : STDERR, $finalMessage);
exit($exitCode);

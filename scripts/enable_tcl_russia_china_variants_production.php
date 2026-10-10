<?php

declare(strict_types=1);

$identityService = __DIR__ . '/product_variant_identity_service.php';
if (!is_file($identityService)) $identityService = dirname(__DIR__) . '/product_variant_identity_service.php';
require_once $identityService;

final class TclRussiaChinaBulkEnableException extends RuntimeException {}

const TCL_RUSSIA_CHINA_TARGET = 'Россия-Китай';

function tclRussiaChinaRows(PDO $pdo, string $table, string $column, int $variantId): array
{
    $allowed = [
        'product_variant_price_overrides' => 'product_variant_id',
        'supplier_product_matches' => 'product_variant_id',
        'supplier_offers' => 'product_variant_id',
        'supplier_import_rows' => 'matched_product_variant_id',
    ];
    if (($allowed[$table] ?? null) !== $column) {
        throw new LogicException('Unexpected snapshot table');
    }
    $order = $table === 'product_variant_price_overrides' ? 'product_variant_id' : 'id';
    $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE `$column` = :id ORDER BY `$order` FOR UPDATE");
    $stmt->execute([':id' => $variantId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function tclRussiaChinaComparable(array $row, array $excluded): array
{
    foreach ($excluded as $key) unset($row[$key]);
    return $row;
}

/** Validate the entire TCL target set first, then update and verify it atomically. */
function tclRussiaChinaBulkEnable(PDO $pdo): array
{
    $pdo->beginTransaction();
    try {
        $products = $pdo->query("SELECT * FROM products WHERE brand = 'TCL' ORDER BY id FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
        $productById = [];
        foreach ($products as $product) $productById[(int)$product['id']] = $product;

        $variantsByProduct = [];
        if ($productById !== []) {
            $ids = array_keys($productById);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT pv.* FROM product_variants pv WHERE pv.product_id IN ($placeholders) ORDER BY pv.product_id, pv.id FOR UPDATE");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $variant) {
                $variantsByProduct[(int)$variant['product_id']][] = $variant;
            }
        }

        $targets = [];
        foreach ($productById as $productId => $product) {
            if (($product['brand'] ?? null) !== 'TCL') throw new TclRussiaChinaBulkEnableException("Ambiguous brand value for product $productId");
            $raw = $product['variants'] ?? null;
            if (!is_string($raw)) throw new TclRussiaChinaBulkEnableException("Legacy variants unavailable for product $productId");
            try { $legacy = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
            catch (JsonException) { throw new TclRussiaChinaBulkEnableException("Invalid legacy JSON for product $productId"); }
            if (!is_array($legacy) || !array_is_list($legacy) || count($legacy) > 200) {
                throw new TclRussiaChinaBulkEnableException("Ambiguous legacy variant list for product $productId");
            }

            $legacyTargets = [];
            $weightStmt = $pdo->prepare('SELECT HEX(WEIGHT_STRING(CAST(:value AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci))');
            $seenWeights = [];
            foreach ($legacy as $index => $entry) {
                if (!is_array($entry)) throw new TclRussiaChinaBulkEnableException("Invalid legacy variant for product $productId");
                try { $identity = productVariantIdentityCountry($weightStmt, $entry, $productId, true); }
                catch (ProductVariantIdentityException) { throw new TclRussiaChinaBulkEnableException("Invalid legacy variant data for product $productId"); }
                if (isset($seenWeights[$identity['weight']])) throw new TclRussiaChinaBulkEnableException("Duplicate legacy variant identity for product $productId");
                $seenWeights[$identity['weight']] = true;
                if ($entry['country'] === TCL_RUSSIA_CHINA_TARGET) {
                    $legacyTargets[] = ['index' => $index, 'identity' => $identity];
                } elseif (trim($entry['country']) === TCL_RUSSIA_CHINA_TARGET) {
                    throw new TclRussiaChinaBulkEnableException("Non-exact legacy target name for product $productId");
                }
            }

            $relationalTargets = [];
            foreach ($variantsByProduct[$productId] ?? [] as $variant) {
                $country = $variant['assembly_country'] ?? null;
                if ($country === TCL_RUSSIA_CHINA_TARGET) {
                    $relationalTargets[] = $variant;
                } elseif (is_string($country) && trim($country) === TCL_RUSSIA_CHINA_TARGET) {
                    throw new TclRussiaChinaBulkEnableException("Non-exact relational target name for product $productId");
                }
            }

            if (count($legacyTargets) > 1 || count($relationalTargets) > 1) {
                throw new TclRussiaChinaBulkEnableException("Multiple target variants for product $productId");
            }
            if (count($legacyTargets) !== count($relationalTargets)) {
                throw new TclRussiaChinaBulkEnableException("Relational/legacy target mismatch for product $productId");
            }
            if ($legacyTargets === []) continue;

            $variant = $relationalTargets[0];
            try { $resolved = productVariantIdentityResolve($pdo, $product, $variant, true); }
            catch (ProductVariantIdentityException) {
                throw new TclRussiaChinaBulkEnableException("Relational/legacy identity conflict for product $productId");
            }
            $legacyIndex = (int)$legacyTargets[0]['index'];
            if ($resolved['target_index'] !== $legacyIndex || $resolved['target']['country'] !== TCL_RUSSIA_CHINA_TARGET) {
                throw new TclRussiaChinaBulkEnableException("Ambiguous target identity for product $productId");
            }
            $legacyActive = $legacy[$legacyIndex]['is_active'];
            if (!in_array($variant['is_active'], [0, 1, '0', '1'], true)) {
                throw new TclRussiaChinaBulkEnableException("Invalid relational activity value for product $productId");
            }
            $relationalActive = (int)$variant['is_active'] === 1;
            if ($legacyActive !== $relationalActive) {
                throw new TclRussiaChinaBulkEnableException("Target activity conflict for product $productId");
            }

            $variantId = (int)$variant['id'];
            $targets[] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'legacy_index' => $legacyIndex,
                'was_active' => $relationalActive,
                'product_before' => $product,
                'legacy_before' => $legacy,
                'variant_before' => $variant,
                'override_before' => tclRussiaChinaRows($pdo, 'product_variant_price_overrides', 'product_variant_id', $variantId),
                'matches_before' => tclRussiaChinaRows($pdo, 'supplier_product_matches', 'product_variant_id', $variantId),
                'offers_before' => tclRussiaChinaRows($pdo, 'supplier_offers', 'product_variant_id', $variantId),
                'import_rows_before' => tclRussiaChinaRows($pdo, 'supplier_import_rows', 'matched_product_variant_id', $variantId),
            ];
        }

        $enabled = 0;
        foreach ($targets as $target) {
            if ($target['was_active']) continue;
            $stmt = $pdo->prepare('UPDATE product_variants SET is_active = 1 WHERE id = :id AND product_id = :product_id AND is_active = 0');
            $stmt->execute([':id' => $target['variant_id'], ':product_id' => $target['product_id']]);
            if ($stmt->rowCount() !== 1) throw new TclRussiaChinaBulkEnableException("Variant changed during update: product {$target['product_id']}");

            $legacy = $target['legacy_before'];
            $legacy[$target['legacy_index']]['is_active'] = true;
            $encoded = json_encode($legacy, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $stmt = $pdo->prepare('UPDATE products SET variants = :variants WHERE id = :id');
            $stmt->execute([':variants' => $encoded, ':id' => $target['product_id']]);
            if ($stmt->rowCount() !== 1) throw new TclRussiaChinaBulkEnableException("Legacy state changed during update: product {$target['product_id']}");
            $enabled++;
        }

        foreach ($targets as $target) {
            $stmt = $pdo->prepare('SELECT * FROM product_variants WHERE id = :id AND product_id = :product_id');
            $stmt->execute([':id' => $target['variant_id'], ':product_id' => $target['product_id']]);
            $variantAfter = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($variantAfter) || (int)$variantAfter['is_active'] !== 1 ||
                tclRussiaChinaComparable($variantAfter, ['is_active', 'updated_at']) !== tclRussiaChinaComparable($target['variant_before'], ['is_active', 'updated_at'])) {
                throw new TclRussiaChinaBulkEnableException("Relational post-check failed for product {$target['product_id']}");
            }

            $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id');
            $stmt->execute([':id' => $target['product_id']]);
            $productAfter = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($productAfter)) throw new TclRussiaChinaBulkEnableException("Product post-check failed for product {$target['product_id']}");
            try { $legacyAfter = json_decode((string)$productAfter['variants'], true, 512, JSON_THROW_ON_ERROR); }
            catch (JsonException) { throw new TclRussiaChinaBulkEnableException("Legacy post-check JSON failed for product {$target['product_id']}"); }
            $expectedLegacy = $target['legacy_before'];
            $expectedLegacy[$target['legacy_index']]['is_active'] = true;
            if ($legacyAfter !== $expectedLegacy || $legacyAfter[$target['legacy_index']]['is_active'] !== true ||
                tclRussiaChinaComparable($productAfter, ['variants', 'updated_at']) !== tclRussiaChinaComparable($target['product_before'], ['variants', 'updated_at'])) {
                throw new TclRussiaChinaBulkEnableException("Legacy/product post-check failed for product {$target['product_id']}");
            }

            $snapshots = [
                ['product_variant_price_overrides', 'product_variant_id', 'override_before'],
                ['supplier_product_matches', 'product_variant_id', 'matches_before'],
                ['supplier_offers', 'product_variant_id', 'offers_before'],
                ['supplier_import_rows', 'matched_product_variant_id', 'import_rows_before'],
            ];
            foreach ($snapshots as [$table, $column, $key]) {
                if (tclRussiaChinaRows($pdo, $table, $column, $target['variant_id']) !== $target[$key]) {
                    throw new TclRussiaChinaBulkEnableException("Price/supplier reference post-check failed for product {$target['product_id']}");
                }
            }
        }

        $result = [
            'products_inspected' => count($products),
            'found' => count($targets),
            'enabled' => $enabled,
            'already_enabled' => count($targets) - $enabled,
            'variants' => array_map(static fn(array $row): array => [
                'product_id' => $row['product_id'],
                'product_variant_id' => $row['variant_id'],
                'result' => $row['was_active'] ? 'already_enabled' : 'enabled',
            ], $targets),
        ];
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function tclRussiaChinaProductionMain(array $argv): never
{
    if (count($argv) !== 2 || $argv[1] !== '--apply') {
        fwrite(STDERR, "Usage: php enable_tcl_russia_china_variants_production.php --apply\n");
        exit(2);
    }
    try {
        $runtimeConfig = __DIR__ . '/runtime_config.php';
        if (!is_file($runtimeConfig)) $runtimeConfig = dirname(__DIR__) . '/runtime_config.php';
        require_once $runtimeConfig;
        $secretsFile = telvoraSecretsFile();
        if (!is_file($secretsFile) || !is_readable($secretsFile)) throw new RuntimeException('Production runtime configuration is unavailable');
        $secrets = require $secretsFile;
        foreach (['db_host', 'db_name', 'db_user', 'db_password'] as $key) {
            if (!isset($secrets[$key]) || !is_string($secrets[$key]) || $secrets[$key] === '') throw new RuntimeException('Production database configuration is incomplete');
        }
        $pdo = new PDO(
            'mysql:host=' . $secrets['db_host'] . ';dbname=' . $secrets['db_name'] . ';charset=utf8mb4',
            $secrets['db_user'],
            $secrets['db_password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $result = tclRussiaChinaBulkEnable($pdo);
        echo 'TCL products inspected: ' . $result['products_inspected'] . "\n";
        echo 'Exact variants found: ' . $result['found'] . "\n";
        echo 'Variants enabled: ' . $result['enabled'] . "\n";
        echo 'Already enabled: ' . $result['already_enabled'] . "\n";
        foreach ($result['variants'] as $variant) {
            echo 'product_id=' . $variant['product_id'] . ' product_variant_id=' . $variant['product_variant_id'] . ' result=' . $variant['result'] . "\n";
        }
    } catch (TclRussiaChinaBulkEnableException $error) {
        fwrite(STDERR, 'Stopped; transaction rolled back: ' . $error->getMessage() . "\n");
        exit(3);
    } catch (Throwable) {
        fwrite(STDERR, "Stopped; transaction rolled back because of an internal database or runtime error.\n");
        exit(1);
    }
    exit(0);
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    tclRussiaChinaProductionMain($argv);
}

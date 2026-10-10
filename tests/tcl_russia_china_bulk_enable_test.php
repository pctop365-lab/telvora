<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/enable_tcl_russia_china_variants_production.php';

$host = getenv('TELVORA_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('TELVORA_TEST_DB_PORT') ?: 3307);
$database = getenv('TELVORA_TEST_DB_NAME') ?: 'telvora_stage12lc_test';
$user = getenv('TELVORA_TEST_DB_USER') ?: 'telvora_stage12lc';
$passwordFile = 'C:/Users/ASRock/Telvora-MySQL-Test/private/test-password.txt';
$password = getenv('TELVORA_TEST_DB_PASSWORD');
if ($password === false) $password = trim((string)file_get_contents($passwordFile));
if (!in_array($host, ['127.0.0.1', 'localhost'], true) || $port !== 3307 || $database !== 'telvora_stage12lc_test' || $user !== 'telvora_stage12lc' || $password === '') {
    throw new RuntimeException('Refusing non-disposable TCL test database configuration');
}

$pdo = new PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4", $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$identity = $pdo->query('SELECT @@port AS port, DATABASE() AS database_name, CURRENT_USER() AS db_user')->fetch();
if ((int)$identity['port'] !== 3307 || $identity['database_name'] !== 'telvora_stage12lc_test' || !str_starts_with($identity['db_user'], 'telvora_stage12lc@')) {
    throw new RuntimeException('Refusing unexpected MySQL test server identity');
}

function tclBulkTestAssert(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException("FAIL $label");
    echo "PASS $label\n";
}

function tclBulkTestLegacy(string $country, int $price, ?int $oldPrice, bool $active): array
{
    return ['country' => $country, 'price' => $price, 'old_price' => $oldPrice, 'is_active' => $active];
}

function tclBulkTestSeed(PDO $pdo, int $productId, string $brand, array $legacy, array $countries): array
{
    $insertProduct = $pdo->prepare('INSERT INTO products (id, brand, name, is_active, publication_status, variants) VALUES (?, ?, ?, ?, ?, ?)');
    $insertProduct->execute([$productId, $brand, "Fixture $productId", $brand === 'TCL' ? 0 : 1, $brand === 'TCL' ? 'draft' : 'published', json_encode($legacy, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    $ids = [];
    foreach ($countries as $index => $country) {
        $key = 'legacy-country-sha256-' . hash('sha256', $country);
        $stmt = $pdo->prepare('INSERT INTO product_variants (product_id, variant_key, assembly_country, display_name, classification_status, is_active) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$productId, $key, $country, $country, 'requires_classification', $legacy[$index]['is_active'] ? 1 : 0]);
        $ids[] = (int)$pdo->lastInsertId();
    }
    return $ids;
}

function tclBulkTestFetch(PDO $pdo, string $sql, int $id): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
try {
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $quoted = '`' . str_replace('`', '``', (string)$table) . '`';
        $pdo->exec("DROP TABLE $quoted");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("CREATE TABLE products (id INT UNSIGNED PRIMARY KEY, brand VARCHAR(80) NOT NULL, name VARCHAR(255) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 0, publication_status VARCHAR(32) NOT NULL, variants JSON NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE product_variants (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NOT NULL, variant_key VARCHAR(191) NOT NULL, assembly_country VARCHAR(100) DEFAULT NULL, manufacturer_part_number VARCHAR(191) DEFAULT NULL, display_name VARCHAR(255) DEFAULT NULL, classification_status VARCHAR(50) NOT NULL, classification_evidence JSON DEFAULT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, UNIQUE KEY uq_product_variant_key (product_id, variant_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec('CREATE TABLE product_variant_price_overrides (product_variant_id BIGINT UNSIGNED PRIMARY KEY, manual_price DECIMAL(12,2) NOT NULL, manual_old_price DECIMAL(12,2) DEFAULT NULL, is_active TINYINT(1) NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE supplier_product_matches (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_variant_id BIGINT UNSIGNED NOT NULL, supplier_sku VARCHAR(191), status VARCHAR(50)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE supplier_offers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_variant_id BIGINT UNSIGNED NOT NULL, purchase_price DECIMAL(15,2), stock_quantity INT, is_active TINYINT(1)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE supplier_import_rows (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, matched_product_variant_id BIGINT UNSIGNED DEFAULT NULL, status VARCHAR(50)) ENGINE=InnoDB');

    $target = TCL_RUSSIA_CHINA_TARGET;
    $ids = tclBulkTestSeed($pdo, 101, 'TCL', [tclBulkTestLegacy($target, 120000, 125000, false), tclBulkTestLegacy('Россия', 90000, null, true)], [$target, 'Россия']);
    tclBulkTestSeed($pdo, 102, 'TCL', [tclBulkTestLegacy($target, 210000, 225000, true)], [$target]);
    $unrelated = tclBulkTestSeed($pdo, 103, 'Samsung', [tclBulkTestLegacy($target, 50000, null, false)], [$target]);

    $variantId = $ids[0];
    $pdo->prepare('INSERT INTO product_variant_price_overrides VALUES (?, ?, ?, 1)')->execute([$variantId, '118500.00', '123500.00']);
    $pdo->prepare('INSERT INTO supplier_product_matches (product_variant_id, supplier_sku, status) VALUES (?, ?, ?)')->execute([$variantId, 'tcl-sku', 'confirmed']);
    $pdo->prepare('INSERT INTO supplier_offers (product_variant_id, purchase_price, stock_quantity, is_active) VALUES (?, ?, ?, 1)')->execute([$variantId, '99000.00', 7]);
    $pdo->prepare('INSERT INTO supplier_import_rows (matched_product_variant_id, status) VALUES (?, ?)')->execute([$variantId, 'matched']);
    $before = [
        'override' => tclBulkTestFetch($pdo, 'SELECT * FROM product_variant_price_overrides WHERE product_variant_id = ?', $variantId),
        'matches' => tclBulkTestFetch($pdo, 'SELECT * FROM supplier_product_matches WHERE product_variant_id = ? ORDER BY id', $variantId),
        'offers' => tclBulkTestFetch($pdo, 'SELECT * FROM supplier_offers WHERE product_variant_id = ? ORDER BY id', $variantId),
        'imports' => tclBulkTestFetch($pdo, 'SELECT * FROM supplier_import_rows WHERE matched_product_variant_id = ? ORDER BY id', $variantId),
    ];

    $result = tclRussiaChinaBulkEnable($pdo);
    tclBulkTestAssert($result['products_inspected'] === 2 && $result['found'] === 2 && $result['enabled'] === 1 && $result['already_enabled'] === 1, 'counts include TCL drafts and already-enabled matches');
    tclBulkTestAssert($result['variants'][0]['product_id'] === 101 && $result['variants'][0]['product_variant_id'] === $variantId, 'reports product and stable variant IDs');
    $relational = $pdo->prepare('SELECT is_active FROM product_variants WHERE id = ?'); $relational->execute([$variantId]);
    $product = $pdo->query('SELECT variants, is_active, publication_status FROM products WHERE id = 101')->fetch();
    $legacy = json_decode($product['variants'], true, 512, JSON_THROW_ON_ERROR);
    tclBulkTestAssert((int)$relational->fetchColumn() === 1 && $legacy[0]['is_active'] === true && $legacy[0]['price'] === 120000 && $legacy[0]['old_price'] === 125000, 'relational and legacy activity sync while retaining prices');
    tclBulkTestAssert((int)$product['is_active'] === 0 && $product['publication_status'] === 'draft', 'product active and publication status stay unchanged');
    tclBulkTestAssert($before['override'] === tclBulkTestFetch($pdo, 'SELECT * FROM product_variant_price_overrides WHERE product_variant_id = ?', $variantId) && $before['matches'] === tclBulkTestFetch($pdo, 'SELECT * FROM supplier_product_matches WHERE product_variant_id = ? ORDER BY id', $variantId) && $before['offers'] === tclBulkTestFetch($pdo, 'SELECT * FROM supplier_offers WHERE product_variant_id = ? ORDER BY id', $variantId) && $before['imports'] === tclBulkTestFetch($pdo, 'SELECT * FROM supplier_import_rows WHERE matched_product_variant_id = ? ORDER BY id', $variantId), 'manual override and supplier mappings/offers/import links stay unchanged');
    $stmt = $pdo->prepare('SELECT is_active FROM product_variants WHERE id = ?'); $stmt->execute([$unrelated[0]]);
    tclBulkTestAssert((int)$stmt->fetchColumn() === 0, 'non-TCL target variant stays unchanged');

    $pending = tclBulkTestSeed($pdo, 104, 'TCL', [tclBulkTestLegacy($target, 80000, 82000, false)], [$target]);
    tclBulkTestSeed($pdo, 105, 'TCL', [tclBulkTestLegacy($target, 70000, null, false)], ['Россия']);
    try {
        tclRussiaChinaBulkEnable($pdo);
        throw new RuntimeException('Expected target identity conflict');
    } catch (TclRussiaChinaBulkEnableException $error) {
        tclBulkTestAssert(str_contains($error->getMessage(), 'mismatch'), 'relational/legacy mismatch aborts the batch');
    }
    $stmt = $pdo->prepare('SELECT is_active FROM product_variants WHERE id = ?'); $stmt->execute([$pending[0]]);
    $pendingProduct = $pdo->query('SELECT variants FROM products WHERE id = 104')->fetchColumn();
    $pendingLegacy = json_decode((string)$pendingProduct, true, 512, JSON_THROW_ON_ERROR);
    tclBulkTestAssert((int)$stmt->fetchColumn() === 0 && $pendingLegacy[0]['is_active'] === false, 'conflict rolls back before any batch update');
    echo "PASS TCL Russia-China bulk enable MySQL suite (isolated test database)\n";
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $quoted = '`' . str_replace('`', '``', (string)$table) . '`';
        $pdo->exec("DROP TABLE $quoted");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

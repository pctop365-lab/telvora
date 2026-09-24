<?php

declare(strict_types=1);

define('TELVORA_MANAGER_REQUEST', true);

require_once dirname(__DIR__) . '/product_variant_mutation_service.php';
require_once dirname(__DIR__) . '/supplier_import_stage.php';
require_once dirname(__DIR__) . '/supplier_offer_service.php';
require_once dirname(__DIR__) . '/price_publication_service.php';
require_once dirname(__DIR__) . '/storefront_cart_service.php';

const PIPELINE_DSN = 'mysql:host=127.0.0.1;port=3307;dbname=telvora_stage12lc_test;charset=utf8mb4';
const PIPELINE_USER = 'telvora_stage12lc';
const PIPELINE_PASSWORD_FILE = 'C:/Users/ASRock/Telvora-MySQL-Test/private/test-password.txt';

function pipelineAssert(string $name, bool $condition, mixed $detail = null): void
{
    if (!$condition) {
        throw new RuntimeException("FAIL {$name}: " . json_encode($detail, JSON_UNESCAPED_UNICODE));
    }
    echo "PASS {$name}\n";
}

function pipelineDropSchema(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ([
        'seo_publication_jobs',
        'order_items', 'orders', 'product_price_publication_audit', 'product_variant_price_overrides', 'pricing_rules',
        'supplier_offers', 'supplier_import_rows', 'supplier_product_matches',
        'supplier_availability_mappings', 'supplier_import_jobs',
        'supplier_import_profiles', 'suppliers', 'product_variants', 'products',
    ] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function pipelineCreateSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE products (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(255) NOT NULL,
        name VARCHAR(255) NOT NULL, category VARCHAR(20) NOT NULL, screen_size VARCHAR(50) NULL,
        price DECIMAL(12,2) NOT NULL DEFAULT 0, old_price DECIMAL(12,2) NULL,
        variants JSON NULL, is_active TINYINT(1) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_products_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE product_variants (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NOT NULL,
        variant_key VARCHAR(191) NOT NULL, assembly_country VARCHAR(100) NULL,
        display_name VARCHAR(255) NULL,
        classification_status VARCHAR(50) NOT NULL DEFAULT 'requires_classification',
        classification_evidence JSON NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_product_variants_product_key (product_id, variant_key),
        KEY idx_product_variants_product_active (product_id, is_active),
        CONSTRAINT fk_pipeline_variant_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT ON UPDATE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE suppliers (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL,
        internal_code VARCHAR(100) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_suppliers_internal_code (internal_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE product_variant_price_overrides (
        product_variant_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        manual_price DECIMAL(12,2) NOT NULL, manual_old_price DECIMAL(12,2) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_pipeline_price_variant FOREIGN KEY (product_variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
        CONSTRAINT chk_pipeline_manual_price CHECK (manual_price > 0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE supplier_import_profiles (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, supplier_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(255) NOT NULL, arrival_date_format VARCHAR(20) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE supplier_availability_mappings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, import_profile_id BIGINT UNSIGNED NOT NULL,
        raw_value VARCHAR(191) NOT NULL, raw_value_hash CHAR(64) NOT NULL,
        normalized_status VARCHAR(50) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE supplier_import_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, supplier_id BIGINT UNSIGNED NOT NULL,
        import_profile_id BIGINT UNSIGNED NULL, original_filename VARCHAR(255) NOT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'pending', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at TIMESTAMP NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE supplier_product_matches (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, supplier_id BIGINT UNSIGNED NOT NULL,
        supplier_sku VARCHAR(191) NULL, product_id INT UNSIGNED NULL,
        product_variant_id BIGINT UNSIGNED NULL, match_method VARCHAR(50) NOT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'matched', is_active TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uq_supplier_product_matches_sku (supplier_id, supplier_sku)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE supplier_import_rows (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, import_job_id BIGINT UNSIGNED NOT NULL,
        source_row_number INT UNSIGNED NOT NULL, supplier_sku VARCHAR(191) NULL,
        raw_product_name VARCHAR(500) NULL, normalized_product_name VARCHAR(500) NULL,
        normalized_model VARCHAR(255) NULL, purchase_price DECIMAL(15,2) NULL,
        currency_code CHAR(3) NULL, raw_availability VARCHAR(255) NULL,
        normalized_availability VARCHAR(50) NULL, raw_arrival_info VARCHAR(255) NULL,
        detected_assembly_country VARCHAR(100) NULL, detected_market_region VARCHAR(255) NULL,
        detected_certification_supply_type VARCHAR(255) NULL, variant_detection_evidence JSON NULL,
        matched_product_id INT UNSIGNED NULL, matched_product_variant_id BIGINT UNSIGNED NULL,
        match_id BIGINT UNSIGNED NULL, status VARCHAR(50) NOT NULL DEFAULT 'requires_matching',
        review_reason VARCHAR(1000) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_supplier_import_rows_job_row (import_job_id, source_row_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE supplier_offers (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, supplier_id BIGINT UNSIGNED NOT NULL,
        product_variant_id BIGINT UNSIGNED NOT NULL, supplier_sku VARCHAR(191) NULL,
        supplier_product_name VARCHAR(500) NOT NULL, purchase_price DECIMAL(15,2) NOT NULL,
        currency_code CHAR(3) NOT NULL, availability_status VARCHAR(50) NOT NULL,
        stock_quantity INT UNSIGNED NULL, expected_arrival_at DATETIME NULL,
        delivery_info VARCHAR(500) NULL, source_import_row_id BIGINT UNSIGNED NULL,
        source_updated_at DATETIME NULL, imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_supplier_offers_sku (supplier_id, supplier_sku),
        KEY idx_supplier_offers_variant_eligibility (product_variant_id, is_active, availability_status, purchase_price)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE pricing_rules (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL,
        priority INT NOT NULL DEFAULT 100, category_scope VARCHAR(100) NULL,
        purchase_price_min DECIMAL(15,2) NULL, purchase_price_max DECIMAL(15,2) NULL,
        markup_percent DECIMAL(9,4) NULL, minimum_margin DECIMAL(15,2) NULL,
        rounding_strategy VARCHAR(50) NULL, rounding_parameters JSON NULL,
        additional_scope JSON NULL, valid_from DATETIME NULL, valid_until DATETIME NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE product_price_publication_audit (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NOT NULL,
        product_variant_id BIGINT UNSIGNED NOT NULL, supplier_id BIGINT UNSIGNED NOT NULL,
        supplier_offer_id BIGINT UNSIGNED NOT NULL, supplier_sku VARCHAR(191) NULL,
        pricing_rule_id BIGINT UNSIGNED NOT NULL, source_import_row_id BIGINT UNSIGNED NULL,
        source_import_job_id BIGINT UNSIGNED NULL, variant_key VARCHAR(191) NOT NULL,
        assembly_country VARCHAR(100) NOT NULL, old_live_price DECIMAL(12,2) NOT NULL,
        new_live_price DECIMAL(12,2) NOT NULL, purchase_price DECIMAL(15,2) NOT NULL,
        currency_code CHAR(3) NOT NULL, margin_amount DECIMAL(15,2) NOT NULL,
        margin_percent DECIMAL(9,4) NOT NULL, source_type VARCHAR(50) NOT NULL,
        admin_actor VARCHAR(100) NOT NULL, admin_comment VARCHAR(500) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE orders (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE order_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NULL, product_variant_id BIGINT UNSIGNED NULL,
        supplier_offer_id_at_order BIGINT UNSIGNED NULL,
        availability_status_at_order VARCHAR(50) NULL, expected_arrival_at_order DATETIME NULL,
        product_name VARCHAR(255) NOT NULL, quantity INT UNSIGNED NOT NULL DEFAULT 1,
        price DECIMAL(10,2) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function pipelineImport(PDO $pdo, int $supplierId, int $profileId, array $rows, string $filename): int
{
    $pdo->prepare("INSERT INTO supplier_import_jobs(supplier_id,import_profile_id,original_filename,status,finished_at) VALUES(?,?,?,'ready_for_review',CURRENT_TIMESTAMP)")
        ->execute([$supplierId, $profileId, $filename]);
    $jobId = (int)$pdo->lastInsertId();
    $prepared = array_map('supplierStagePrepareRow', $rows);
    $counters = ['total' => 0, 'matched' => 0, 'unmatched' => 0, 'errors' => 0];
    supplierStageInsertChunk($pdo, $jobId, $supplierId, $prepared, $counters);
    pipelineAssert("{$filename} rows matched to canonical variants", $counters === ['total' => count($rows), 'matched' => count($rows), 'unmatched' => 0, 'errors' => 0], $counters);
    $pdo->beginTransaction();
    try {
        $analysis = supplierOfferPublishAnalysis($pdo, $jobId, true);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    pipelineAssert("{$filename} offers published", ($analysis['summary']['eligible_rows'] ?? 0) === count($rows), $analysis);
    return $jobId;
}

function pipelineRow(int $number, string $sku, string $country, string $price): array
{
    return [
        'source_row_number' => $number,
        'values' => ['supplier_sku' => $sku, 'product_name' => "Synthetic TV {$country}", 'model' => 'PIPE-1', 'availability' => 'available', 'arrival_info' => '', 'assembly_country' => $country],
        'normalized' => ['purchase_price' => $price, 'currency_code' => 'RUB'],
        'errors' => [], 'warnings' => [],
    ];
}

$password = trim((string)file_get_contents(PIPELINE_PASSWORD_FILE));
$pdo = new PDO(PIPELINE_DSN, PIPELINE_USER, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$identity = $pdo->query('SELECT @@port port, DATABASE() db, CURRENT_USER() authenticated_user, @@datadir datadir, @@transaction_isolation isolation_level')->fetch();
pipelineAssert('isolated database identity guard',
    (int)$identity['port'] === 3307 && $identity['db'] === 'telvora_stage12lc_test' &&
    str_starts_with((string)$identity['authenticated_user'], PIPELINE_USER . '@') &&
    str_contains(str_replace('\\', '/', strtolower((string)$identity['datadir'])), '/users/asrock/telvora-mysql-test/data/'),
    $identity
);

try {
    pipelineDropSchema($pdo);
    pipelineCreateSchema($pdo);
    $pdo->exec("INSERT INTO products(id,slug,name,category,price,old_price,variants,is_active) VALUES(1,'pipeline-tv','Pipeline TV','OLED',0,NULL,JSON_ARRAY(),1)");
    $first = productVariantAdd($pdo, 1, 'Indonesia');
    $second = productVariantAdd($pdo, 1, 'Poland');
    pipelineAssert('two canonical assembly drafts added', $first['is_active'] && $second['is_active'] && (int)$pdo->query('SELECT COUNT(*) FROM product_variants')->fetchColumn() === 2);

    $pdo->exec("INSERT INTO suppliers(id,name,internal_code,is_active) VALUES(1,'Synthetic supplier','PIPE',1)");
    $pdo->exec("INSERT INTO supplier_import_profiles(id,supplier_id,name) VALUES(1,1,'Synthetic profile')");
    $pdo->prepare("INSERT INTO supplier_availability_mappings(import_profile_id,raw_value,raw_value_hash,normalized_status,is_active) VALUES(1,'available',?,'in_stock',1)")
        ->execute([hash('sha256', 'available')]);
    $match = $pdo->prepare("INSERT INTO supplier_product_matches(supplier_id,supplier_sku,product_id,product_variant_id,match_method,status,is_active) VALUES(1,?,1,?,'manual','matched',1)");
    $match->execute(['PIPE-ID', $first['product_variant_id']]);
    $match->execute(['PIPE-PL', $second['product_variant_id']]);

    pipelineImport($pdo, 1, 1, [pipelineRow(1, 'PIPE-ID', 'Indonesia', '100000.00'), pipelineRow(2, 'PIPE-PL', 'Poland', '90000.00')], 'initial.csv');
    $links = $pdo->query('SELECT supplier_sku,matched_product_variant_id,status FROM supplier_import_rows ORDER BY supplier_sku')->fetchAll();
    pipelineAssert('matching preserves exact variant ids', (int)$links[0]['matched_product_variant_id'] === (int)$first['product_variant_id'] && (int)$links[1]['matched_product_variant_id'] === (int)$second['product_variant_id'] && $links[0]['status'] === 'matched' && $links[1]['status'] === 'matched', $links);

    $pdo->exec("INSERT INTO pricing_rules(id,name,priority,markup_percent,rounding_strategy,is_active) VALUES(1,'Synthetic 100%',1,100.0000,'none',1)");
    $offers = $pdo->query('SELECT id,supplier_sku FROM supplier_offers ORDER BY supplier_sku')->fetchAll();
    foreach ($offers as $offer) {
        $context = pricePublicationContext($pdo, (int)$offer['id'], false);
        pipelineAssert('price preflight is publishable ' . $offer['supplier_sku'], $context['can_publish'], $context['blocking_reasons']);
        $result = pricePublicationPublish($pdo, (int)$offer['id'], $context['snapshot_token'], 'synthetic integration');
        pipelineAssert('price published ' . $offer['supplier_sku'], $result['status'] === 'published', $result);
    }
    $legacy = json_decode((string)$pdo->query('SELECT variants FROM products WHERE id=1')->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    $prices = array_column($legacy, 'price'); sort($prices, SORT_NUMERIC);
    pipelineAssert('storefront minimum is lower published variant price', $prices === [180900, 200900], $prices);

    $cart = storefrontCartResolve($pdo, [['product_variant_id' => (int)$second['product_variant_id'], 'quantity' => 1]]);
    pipelineAssert('cart uses published variant identity price and availability', $cart['all_orderable'] && $cart['items'][0]['price'] === 180900 && $cart['items'][0]['status'] === 'in_stock', $cart);
    $qualifyingOffer = (int)$cart['items'][0]['_qualifying_offer_id'];
    $pdo->exec('INSERT INTO orders(id) VALUES(1)');
    $snapshot = $pdo->prepare("INSERT INTO order_items(order_id,product_id,product_variant_id,supplier_offer_id_at_order,availability_status_at_order,product_name,quantity,price) VALUES(1,1,?,?,'in_stock','Pipeline TV',1,180900)");
    $snapshot->execute([$second['product_variant_id'], $qualifyingOffer]);

    $manual = productVariantPriceSet($pdo, (int)$second['product_variant_id'], true, '175000.00', '185000.00');
    pipelineAssert('manual retail price enabled', $manual['price_source'] === 'manual' && $manual['price'] === 175000, $manual);
    $manualCart = storefrontCartResolve($pdo, [['product_variant_id' => (int)$second['product_variant_id'], 'quantity' => 1]]);
    pipelineAssert('cart uses manual retail overlay', $manualCart['all_orderable'] && $manualCart['items'][0]['price'] === 175000, $manualCart);
    $effectivePrices = [200900, (int)$manualCart['items'][0]['price']];
    pipelineAssert('storefront minimum includes manual effective price', min($effectivePrices) === 175000, $effectivePrices);

    pipelineImport($pdo, 1, 1, [pipelineRow(1, 'PIPE-PL', 'Poland', '95000.00')], 'update.csv');
    $updatedOffer = (int)$pdo->query("SELECT id FROM supplier_offers WHERE supplier_sku='PIPE-PL'")->fetchColumn();
    $context = pricePublicationContext($pdo, $updatedOffer, false);
    $result = pricePublicationPublish($pdo, $updatedOffer, $context['snapshot_token'], 'synthetic update');
    pipelineAssert('updated supplier price republishes target variant', $result['published_price'] === '190900.00', $result);
    $updatedLegacy = json_decode((string)$pdo->query('SELECT variants FROM products WHERE id=1')->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    pipelineAssert('other assembly price is unchanged', $updatedLegacy[0]['price'] === 200900 && $updatedLegacy[1]['price'] === 190900, $updatedLegacy);
    $preservedManualCart = storefrontCartResolve($pdo, [['product_variant_id' => (int)$second['product_variant_id'], 'quantity' => 1]]);
    pipelineAssert('repeat import and Stage9 publication preserve manual retail price', $preservedManualCart['items'][0]['price'] === 175000, $preservedManualCart);
    $automatic = productVariantPriceSet($pdo, (int)$second['product_variant_id'], false);
    pipelineAssert('explicit return to automatic exposes latest Stage9 price', $automatic['price_source'] === 'automatic' && $automatic['price'] === 190900, $automatic);
    $automaticCart = storefrontCartResolve($pdo, [['product_variant_id' => (int)$second['product_variant_id'], 'quantity' => 1]]);
    pipelineAssert('cart returns to latest automatic retail price', $automaticCart['items'][0]['price'] === 190900, $automaticCart);
    $pdo->exec("UPDATE supplier_offers SET availability_status='out_of_stock', stock_quantity=0 WHERE supplier_sku='PIPE-PL'");
    $unavailableCart = storefrontCartResolve($pdo, [['product_variant_id' => (int)$second['product_variant_id'], 'quantity' => 1]]);
    pipelineAssert('retail price never makes unavailable variant orderable', !$unavailableCart['all_orderable'] && !$unavailableCart['items'][0]['orderable'], $unavailableCart);
    $historical = $pdo->query('SELECT product_variant_id,supplier_offer_id_at_order,availability_status_at_order,price FROM order_items WHERE id=1')->fetch();
    pipelineAssert('historical order snapshot is immutable after price update', (int)$historical['product_variant_id'] === (int)$second['product_variant_id'] && (int)$historical['supplier_offer_id_at_order'] === $qualifyingOffer && $historical['availability_status_at_order'] === 'in_stock' && (string)$historical['price'] === '180900.00', $historical);
    pipelineAssert('publication audit records initial and update history', (int)$pdo->query('SELECT COUNT(*) FROM product_price_publication_audit')->fetchColumn() === 3);
    $pdo->exec("INSERT INTO products(id,slug,name,category,price,old_price,variants,is_active) VALUES(2,'manual-only','Manual only','OLED',0,NULL,JSON_ARRAY(),1)");
    $manualOnly = productVariantAdd($pdo, 2, 'Japan');
    productVariantPriceSet($pdo, (int)$manualOnly['product_variant_id'], true, '150000.00');
    try {
        productVariantPriceSet($pdo, (int)$manualOnly['product_variant_id'], false);
        throw new RuntimeException('FAIL automatic mode removed last ready price');
    } catch (ProductVariantPriceException $error) {
        pipelineAssert('automatic mode cannot remove last ready price of active product', $error->httpStatus === 409, $error->getMessage());
    }
    require_once __DIR__ . '/import_price_preview_mysql_cases.php';
    importPricePreviewMysqlCases($pdo);
    require_once __DIR__ . '/product_bulk_publication_mysql_cases.php';
    productBulkPublicationMysqlCases($pdo);
    echo "PASS Stage12L end-to-end price pipeline\n";
} finally {
    pipelineDropSchema($pdo);
}

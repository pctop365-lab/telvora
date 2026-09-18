<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/product_delete_service.php';

const DELETE_TEST_DEFAULT_HOST = '127.0.0.1';
const DELETE_TEST_DEFAULT_PORT = '3307';
const DELETE_TEST_DEFAULT_NAME = 'telvora_stage12lc_test';
const DELETE_TEST_DEFAULT_USER = 'telvora_stage12lc';
const DELETE_TEST_PASSWORD_FILE = 'C:/Users/ASRock/Telvora-MySQL-Test/private/test-password.txt';

function deleteTestEnv(string $name, ?string $fallback = null): ?string
{
    $value = getenv($name);
    return $value === false || $value === '' ? $fallback : $value;
}

/** @return array{host:string,port:string,name:string,user:string,password:string,ci:bool} */
function deleteTestConfig(): array
{
    $ci = in_array(strtolower((string)(getenv('CI') ?: '')), ['1', 'true', 'yes'], true);
    $host = deleteTestEnv('TELVORA_TEST_DB_HOST', $ci ? null : DELETE_TEST_DEFAULT_HOST);
    $port = deleteTestEnv('TELVORA_TEST_DB_PORT', $ci ? null : DELETE_TEST_DEFAULT_PORT);
    $name = deleteTestEnv('TELVORA_TEST_DB_NAME', $ci ? null : DELETE_TEST_DEFAULT_NAME);
    $user = deleteTestEnv('TELVORA_TEST_DB_USER', $ci ? null : DELETE_TEST_DEFAULT_USER);
    $password = deleteTestEnv('TELVORA_TEST_DB_PASSWORD');
    if ($password === null && !$ci) {
        $password = is_readable(DELETE_TEST_PASSWORD_FILE)
            ? trim((string)file_get_contents(DELETE_TEST_PASSWORD_FILE))
            : null;
    }
    if ($host === null || $port === null || $name === null || $user === null || $password === null) {
        throw new RuntimeException('Explicit test-only DB variables are required in CI.');
    }
    $lowerHost = strtolower($host);
    $lowerName = strtolower($name);
    if (preg_match('/(?:telvora\.ru|server45|hosting\.reg\.ru|reg\.ru)/i', $lowerHost)) {
        throw new RuntimeException('Refusing a production-looking database host.');
    }
    if (!preg_match('/(?:test|ci|tmp|temp|dev|sandbox)/i', $lowerName)
        || preg_match('/(?:production|prod|live|server45|reg\.ru)/i', $lowerName)) {
        throw new RuntimeException('Refusing a non-test-looking database name.');
    }
    if (!preg_match('/^\d{1,5}$/', $port) || (int)$port < 1 || (int)$port > 65535) {
        throw new RuntimeException('Invalid test database port.');
    }
    return compact('host', 'port', 'name', 'user', 'password', 'ci');
}

function deleteTestAssert(string $name, bool $condition, mixed $detail = null): void
{
    if (!$condition) throw new RuntimeException("FAIL {$name}: " . json_encode($detail, JSON_UNESCAPED_UNICODE));
    echo "PASS {$name}\n";
}

function deleteTestPdo(): PDO
{
    $config = deleteTestConfig();
    $pdo = new PDO(
        "mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset=utf8mb4",
        $config['user'],
        $config['password'],
        [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $identity = $pdo->query('SELECT @@port port, DATABASE() db, CURRENT_USER() authenticated_user, @@datadir datadir')->fetch();
    $expectedUser = $config['user'];
    deleteTestAssert(
        'isolated database identity guard',
        (int)$identity['port'] === (int)$config['port']
        && $identity['db'] === $config['name']
        && str_starts_with((string)$identity['authenticated_user'], $expectedUser . '@')
        && (!$config['ci'] || in_array($config['host'], ['127.0.0.1', 'localhost', '::1'], true)),
        $identity
    );
    return $pdo;
}

function deleteTestReset(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ([
        'product_images', 'product_price_publication_audit', 'order_items', 'orders',
        'product_variant_price_overrides', 'supplier_offers', 'supplier_import_rows',
        'supplier_import_jobs', 'supplier_product_matches', 'pricing_rules', 'suppliers',
        'product_variants', 'products',
    ] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
    }
    $pdo->exec('DROP TRIGGER IF EXISTS product_delete_test_failure');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function deleteTestSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE products (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(255) NOT NULL,
        name VARCHAR(255) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uq_delete_products_slug (slug)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE product_variants (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NOT NULL,
        variant_key VARCHAR(191) NOT NULL,
        CONSTRAINT fk_delete_variant_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT ON UPDATE RESTRICT
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE product_images (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NOT NULL, image_path VARCHAR(512) NOT NULL,
        position SMALLINT UNSIGNED NOT NULL, is_primary TINYINT(1) NOT NULL DEFAULT 0,
        CONSTRAINT fk_delete_image_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE suppliers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE supplier_product_matches (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, supplier_id BIGINT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NULL, product_variant_id BIGINT UNSIGNED NULL,
        CONSTRAINT fk_delete_match_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
        CONSTRAINT fk_delete_match_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
        CONSTRAINT fk_delete_match_variant FOREIGN KEY (product_variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE supplier_import_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, supplier_id BIGINT UNSIGNED NOT NULL,
        CONSTRAINT fk_delete_job_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE supplier_import_rows (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, import_job_id BIGINT UNSIGNED NOT NULL,
        matched_product_id INT UNSIGNED NULL, matched_product_variant_id BIGINT UNSIGNED NULL, match_id BIGINT UNSIGNED NULL,
        CONSTRAINT fk_delete_import_job FOREIGN KEY (import_job_id) REFERENCES supplier_import_jobs(id) ON DELETE CASCADE,
        CONSTRAINT fk_delete_import_product FOREIGN KEY (matched_product_id) REFERENCES products(id) ON DELETE RESTRICT,
        CONSTRAINT fk_delete_import_variant FOREIGN KEY (matched_product_variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT,
        CONSTRAINT fk_delete_import_match FOREIGN KEY (match_id) REFERENCES supplier_product_matches(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE supplier_offers (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, supplier_id BIGINT UNSIGNED NOT NULL,
        product_variant_id BIGINT UNSIGNED NOT NULL, source_import_row_id BIGINT UNSIGNED NULL,
        CONSTRAINT fk_delete_offer_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
        CONSTRAINT fk_delete_offer_variant FOREIGN KEY (product_variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT,
        CONSTRAINT fk_delete_offer_import FOREIGN KEY (source_import_row_id) REFERENCES supplier_import_rows(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE product_variant_price_overrides (
        product_variant_id BIGINT UNSIGNED PRIMARY KEY,
        CONSTRAINT fk_delete_override_variant FOREIGN KEY (product_variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE orders (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB");
    // Production migration uses nullable snapshots without product FKs so
    // historical rows survive product lifecycle changes.
    $pdo->exec("CREATE TABLE order_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NULL, product_variant_id BIGINT UNSIGNED NULL,
        CONSTRAINT fk_delete_item_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE pricing_rules (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE product_price_publication_audit (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id INT UNSIGNED NOT NULL,
        product_variant_id BIGINT UNSIGNED NOT NULL, supplier_id BIGINT UNSIGNED NOT NULL,
        supplier_offer_id BIGINT UNSIGNED NOT NULL, pricing_rule_id BIGINT UNSIGNED NOT NULL,
        source_import_row_id BIGINT UNSIGNED NULL, source_import_job_id BIGINT UNSIGNED NULL,
        variant_key VARCHAR(191) NOT NULL, assembly_country VARCHAR(100) NOT NULL,
        CONSTRAINT fk_delete_audit_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
        CONSTRAINT fk_delete_audit_variant FOREIGN KEY (product_variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT,
        CONSTRAINT fk_delete_audit_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
        CONSTRAINT fk_delete_audit_offer FOREIGN KEY (supplier_offer_id) REFERENCES supplier_offers(id) ON DELETE RESTRICT,
        CONSTRAINT fk_delete_audit_rule FOREIGN KEY (pricing_rule_id) REFERENCES pricing_rules(id) ON DELETE RESTRICT,
        CONSTRAINT fk_delete_audit_import FOREIGN KEY (source_import_row_id) REFERENCES supplier_import_rows(id) ON DELETE RESTRICT,
        CONSTRAINT fk_delete_audit_job FOREIGN KEY (source_import_job_id) REFERENCES supplier_import_jobs(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");
}

function deleteTestProduct(PDO $pdo, string $slug): int
{
    $stmt = $pdo->prepare('INSERT INTO products(slug,name) VALUES(?,?)');
    $stmt->execute([$slug, $slug]);
    return (int)$pdo->lastInsertId();
}

function deleteTestVariant(PDO $pdo, int $productId, string $key): int
{
    $stmt = $pdo->prepare('INSERT INTO product_variants(product_id,variant_key) VALUES(?,?)');
    $stmt->execute([$productId, $key]);
    return (int)$pdo->lastInsertId();
}

function deleteTestCount(PDO $pdo, string $table, string $where = '1=1', array $params = []): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$where}");
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function deleteTestOperationalRows(PDO $pdo, int $productId, array $variantIds): void
{
    $pdo->prepare('INSERT INTO product_images(product_id,image_path,position) VALUES(?,?,?)')->execute([$productId, '/images/product-a.png', 1]);
    foreach ($variantIds as $index => $variantId) {
        $pdo->prepare('INSERT INTO product_variant_price_overrides(product_variant_id) VALUES(?)')->execute([$variantId]);
        $pdo->prepare('INSERT INTO supplier_offers(supplier_id,product_variant_id) VALUES(1,?)')->execute([$variantId]);
        $pdo->prepare('INSERT INTO supplier_product_matches(supplier_id,product_id,product_variant_id) VALUES(1,?,?)')->execute([$productId, $variantId]);
    }
}

$pdo = deleteTestPdo();
deleteTestReset($pdo);
try {
    deleteTestSchema($pdo);
    $pdo->exec('INSERT INTO suppliers(id,name) VALUES(1,"Supplier A"),(2,"Supplier B")');
    $pdo->exec('INSERT INTO pricing_rules(id,name) VALUES(1,"Rule A")');

    // Safe deletion: only operational references exist.
    $productA = deleteTestProduct($pdo, 'product-a');
    $variantA = [deleteTestVariant($pdo, $productA, 'one'), deleteTestVariant($pdo, $productA, 'two')];
    deleteTestOperationalRows($pdo, $productA, $variantA);
    $productB = deleteTestProduct($pdo, 'product-b');
    $variantB = deleteTestVariant($pdo, $productB, 'one');
    $pdo->prepare('INSERT INTO product_images(product_id,image_path,position) VALUES(?,?,?)')->execute([$productB, '/images/product-b.png', 1]);
    productDelete($pdo, $productA);
    deleteTestAssert('safe delete removes product', deleteTestCount($pdo, 'products', 'id = ?', [$productA]) === 0);
    deleteTestAssert('safe delete removes variants', deleteTestCount($pdo, 'product_variants', 'product_id = ?', [$productA]) === 0);
    deleteTestAssert('safe delete removes offers', deleteTestCount($pdo, 'supplier_offers') === 0);
    deleteTestAssert('safe delete removes matches', deleteTestCount($pdo, 'supplier_product_matches') === 0);
    deleteTestAssert('safe delete removes overrides', deleteTestCount($pdo, 'product_variant_price_overrides') === 0);
    deleteTestAssert('product image cascade follows FK', deleteTestCount($pdo, 'product_images', 'product_id = ?', [$productA]) === 0);
    deleteTestAssert('unrelated product remains', deleteTestCount($pdo, 'products', 'id = ?', [$productB]) === 1);
    deleteTestAssert('unrelated variant remains', deleteTestCount($pdo, 'product_variants', 'id = ?', [$variantB]) === 1);

    // Order history blocks and rolls back before operational cleanup.
    $orderProduct = deleteTestProduct($pdo, 'order-product');
    $orderVariant = deleteTestVariant($pdo, $orderProduct, 'one');
    deleteTestOperationalRows($pdo, $orderProduct, [$orderVariant]);
    $pdo->exec('INSERT INTO orders(id) VALUES(1)');
    $pdo->prepare('INSERT INTO order_items(order_id,product_id,product_variant_id) VALUES(1,?,?)')->execute([$orderProduct, $orderVariant]);
    try { productDelete($pdo, $orderProduct); throw new RuntimeException('order delete unexpectedly succeeded'); }
    catch (ProductDeleteBlockedException $error) { deleteTestAssert('order history returns blocker', str_contains($error->getMessage(), 'заказ')); }
    deleteTestAssert('order block preserves product', deleteTestCount($pdo, 'products', 'id = ?', [$orderProduct]) === 1);
    deleteTestAssert('order block preserves operational rows', deleteTestCount($pdo, 'supplier_offers') === 1 && deleteTestCount($pdo, 'supplier_product_matches') === 1);
    deleteTestAssert('order item remains', deleteTestCount($pdo, 'order_items') === 1);

    // Import history blocks, including match_id-only historical linkage.
    $importProduct = deleteTestProduct($pdo, 'import-product');
    $importVariant = deleteTestVariant($pdo, $importProduct, 'one');
    deleteTestOperationalRows($pdo, $importProduct, [$importVariant]);
    $pdo->exec('INSERT INTO supplier_import_jobs(id,supplier_id) VALUES(1,1)');
    $pdo->prepare('INSERT INTO supplier_import_rows(import_job_id,matched_product_id,matched_product_variant_id) VALUES(1,?,?)')->execute([$importProduct, $importVariant]);
    try { productDelete($pdo, $importProduct); throw new RuntimeException('import delete unexpectedly succeeded'); }
    catch (ProductDeleteBlockedException $error) { deleteTestAssert('import history returns blocker', str_contains($error->getMessage(), 'импорта')); }
    deleteTestAssert('import block preserves rows', deleteTestCount($pdo, 'supplier_import_rows') === 1 && deleteTestCount($pdo, 'products', 'id = ?', [$importProduct]) === 1);

    // Publication audit blocks physical deletion.
    $auditProduct = deleteTestProduct($pdo, 'audit-product');
    $auditVariant = deleteTestVariant($pdo, $auditProduct, 'one');
    deleteTestOperationalRows($pdo, $auditProduct, [$auditVariant]);
    $auditOffer = (int)$pdo->query('SELECT id FROM supplier_offers ORDER BY id DESC LIMIT 1')->fetchColumn();
    $pdo->prepare('INSERT INTO product_price_publication_audit(product_id,product_variant_id,supplier_id,supplier_offer_id,pricing_rule_id,variant_key,assembly_country) VALUES(?,?,?,?,?,?,?)')
        ->execute([$auditProduct, $auditVariant, 1, $auditOffer, 1, 'audit-one', 'Россия']);
    try { productDelete($pdo, $auditProduct); throw new RuntimeException('audit delete unexpectedly succeeded'); }
    catch (ProductDeleteBlockedException $error) { deleteTestAssert('publication audit returns blocker', str_contains($error->getMessage(), 'публикации')); }
    deleteTestAssert('audit block preserves audit and product', deleteTestCount($pdo, 'product_price_publication_audit') === 1 && deleteTestCount($pdo, 'products', 'id = ?', [$auditProduct]) === 1);

    // Trigger failure after operational deletes proves transaction rollback.
    $failureProduct = deleteTestProduct($pdo, 'failure-product');
    $failureVariant = deleteTestVariant($pdo, $failureProduct, 'one');
    deleteTestOperationalRows($pdo, $failureProduct, [$failureVariant]);
    $pdo->exec("CREATE TRIGGER product_delete_test_failure BEFORE DELETE ON products FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test failure'");
    try { productDelete($pdo, $failureProduct); throw new RuntimeException('trigger failure unexpectedly ignored'); }
    catch (PDOException $error) { deleteTestAssert('mid-transaction failure is surfaced', true); }
    $pdo->exec('DROP TRIGGER product_delete_test_failure');
    deleteTestAssert('rollback restores product', deleteTestCount($pdo, 'products', 'id = ?', [$failureProduct]) === 1);
    deleteTestAssert('rollback restores operational rows', deleteTestCount($pdo, 'supplier_offers') === 4 && deleteTestCount($pdo, 'supplier_product_matches') === 4 && deleteTestCount($pdo, 'product_variant_price_overrides') === 4);

    try { productDelete($pdo, 999999); throw new RuntimeException('missing product unexpectedly succeeded'); }
    catch (InvalidArgumentException $error) { deleteTestAssert('missing product is clear', str_contains($error->getMessage(), 'не найден')); }
    productDelete($pdo, $productB);
    try { productDelete($pdo, $productB); throw new RuntimeException('double delete unexpectedly succeeded'); }
    catch (InvalidArgumentException $error) { deleteTestAssert('double delete is clear', str_contains($error->getMessage(), 'не найден')); }

    echo "PASS product deletion isolated MySQL integration\n";
} finally {
    deleteTestReset($pdo);
}

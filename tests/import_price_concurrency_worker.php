<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
define('TELVORA_MANAGER_REQUEST', true);
require_once dirname(__DIR__) . '/import_price_preview_service.php';
require_once dirname(__DIR__) . '/product_bulk_publication_service.php';
$password = trim((string)file_get_contents('C:/Users/ASRock/Telvora-MySQL-Test/private/test-password.txt'));
$pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname=telvora_stage12lc_test;charset=utf8mb4',
    'telvora_stage12lc', $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$identity = $pdo->query('SELECT @@port port, DATABASE() db, CURRENT_USER() actor, @@datadir datadir')->fetch();
if ((int)$identity['port'] !== 3307 || $identity['db'] !== 'telvora_stage12lc_test' ||
    !str_starts_with($identity['actor'], 'telvora_stage12lc@') ||
    !str_contains(str_replace('\\', '/', strtolower($identity['datadir'])), '/users/asrock/telvora-mysql-test/data/')) {
    throw new RuntimeException('Isolated database guard failed');
}
echo "READY\n";
fflush(STDOUT);
$request = json_decode((string)fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
echo "START\n";
fflush(STDOUT);
try {
    $result = match ($request['operation']) {
        'bulk' => importPriceBulkConfirm($pdo, $request['snapshot']),
        'product_bulk' => productBulkPublicationConfirm($pdo, $request['snapshot']),
        'product_publish' => seoPublicationRequestPublish($pdo, $request['product_id'], $request['revision']),
        default => pricePublicationPublish($pdo, $request['offer_id'], $request['token'], null),
    };
    echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
} catch (PricePublicationException|SeoPublicationStateException|ProductActivationException $error) {
    echo json_encode(['status' => 'rejected', 'code' => $error->httpStatus], JSON_THROW_ON_ERROR) . "\n";
}

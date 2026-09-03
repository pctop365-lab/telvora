<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/storefront_availability_service.php';
require_once dirname(__DIR__) . '/storefront_cart_service.php';

$now = new DateTimeImmutable('2026-09-03 12:00:00');
$offer = static function (int $id, string $status, ?int $stock, string $time, string $arrival = null, string $price = '1.00'): array {
    return ['offer_id' => $id, 'availability_status' => $status, 'stock_quantity' => $stock,
        'effective_source_at' => $time, 'expected_arrival_at' => $arrival,
        'offer_active' => 1, 'supplier_active' => 1, 'purchase_price' => $price];
};
$assert = static function (string $name, array $actual, string $status, bool $orderable, ?int $offerId = null): void {
    if ($actual['status'] !== $status || $actual['orderable'] !== $orderable || $actual['qualifying_offer_id'] !== $offerId) {
        throw new RuntimeException("FAIL $name: " . json_encode($actual));
    }
    echo "PASS $name\n";
};

$assert('null stock', storefrontAvailabilityResolve([$offer(1, 'in_stock', null, '2026-09-03 11:00:00')], 1, $now), 'in_stock', true, 1);
$assert('exact known stock', storefrontAvailabilityResolve([$offer(2, 'in_stock', 5, '2026-09-03 11:00:00')], 5, $now), 'in_stock', true, 2);
$assert('insufficient known stock', storefrontAvailabilityResolve([$offer(3, 'in_stock', 4, '2026-09-03 11:00:00')], 5, $now), 'unknown', false);
$assert('no split fulfillment', storefrontAvailabilityResolve([$offer(4, 'in_stock', 1, '2026-09-03 11:00:00'), $offer(5, 'in_stock', 1, '2026-09-03 11:00:00')], 2, $now), 'unknown', false);
$assert('expected', storefrontAvailabilityResolve([$offer(6, 'expected', null, '2026-09-03 11:00:00', '2026-09-10 00:00:00')], 1, $now), 'expected', false);
$assert('all out', storefrontAvailabilityResolve([$offer(7, 'out_of_stock', null, '2026-09-03 11:00:00')], 1, $now), 'out_of_stock', false);
$assert('no offers', storefrontAvailabilityResolve([], 1, $now), 'unknown', false);
$assert('stale in stock', storefrontAvailabilityResolve([$offer(8, 'in_stock', null, '2026-09-02 11:59:59')], 1, $now), 'unknown', false);
$assert('variant isolation first', storefrontAvailabilityResolve([$offer(9, 'out_of_stock', null, '2026-09-03 11:00:00')], 1, $now), 'out_of_stock', false);
$assert('variant isolation second', storefrontAvailabilityResolve([$offer(10, 'in_stock', null, '2026-09-03 11:00:00')], 1, $now), 'in_stock', true, 10);
$assert('purchase price independent A', storefrontAvailabilityResolve([$offer(11, 'out_of_stock', null, '2026-09-03 11:00:00', null, '1.00'), $offer(12, 'in_stock', null, '2026-09-03 10:00:00', null, '999.00')], 1, $now), 'in_stock', true, 12);
$assert('purchase price independent B', storefrontAvailabilityResolve([$offer(11, 'out_of_stock', null, '2026-09-03 11:00:00', null, '999.00'), $offer(12, 'in_stock', null, '2026-09-03 10:00:00', null, '1.00')], 1, $now), 'in_stock', true, 12);
$assert('inactive ignored', storefrontAvailabilityResolve([array_merge($offer(13, 'in_stock', null, '2026-09-03 11:00:00'), ['offer_active' => 0])], 1, $now), 'unknown', false);

$public = storefrontCartPublic(['all_orderable' => true, 'items' => [[
    'product_id' => 2, 'product_variant_id' => 2, 'slug' => 'tv', 'assembly_country' => 'Польша',
    'price' => 160000.0, 'status' => 'in_stock', 'orderable' => true,
    'expected_arrival_at' => null, '_qualifying_offer_id' => 99
]]]);
if ($public['items'][0]['price'] !== 160000.0 || array_key_exists('_qualifying_offer_id', $public['items'][0])) {
    throw new RuntimeException('FAIL public authoritative price projection');
}
echo "PASS public authoritative price projection\n";

class Stage11TestPdo extends PDO { public function __construct() {} }
try {
    storefrontCartResolve(new Stage11TestPdo(), [[
        'product_variant_id' => '2', 'slug' => 'tv', 'assembly_country' => 'Польша', 'quantity' => 1
    ]]);
    throw new RuntimeException('FAIL explicit invalid variant id used legacy fallback');
} catch (StorefrontCartException $error) {
    if ($error->getMessage() !== 'Invalid product variant id') throw $error;
    echo "PASS explicit invalid variant id fails closed\n";
}

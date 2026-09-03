<?php

declare(strict_types=1);

const STOREFRONT_AVAILABILITY_TTL_SECONDS = 86400;
const STOREFRONT_AVAILABILITY_STATUSES = ['in_stock', 'out_of_stock', 'expected', 'unknown'];

function storefrontAvailabilityResolve(array $offers, int $quantity, ?DateTimeImmutable $now = null): array
{
    if ($quantity < 1) throw new InvalidArgumentException('Quantity must be positive');
    $now ??= new DateTimeImmutable('now');
    $fresh = [];
    foreach ($offers as $offer) {
        if (!is_array($offer) || !in_array($offer['availability_status'] ?? null, STOREFRONT_AVAILABILITY_STATUSES, true)) continue;
        if (array_key_exists('offer_active', $offer) && !(bool)$offer['offer_active']) continue;
        if (array_key_exists('supplier_active', $offer) && !(bool)$offer['supplier_active']) continue;
        $source = $offer['effective_source_at'] ?? null;
        if (!is_string($source) || $source === '') continue;
        try { $sourceAt = new DateTimeImmutable($source); } catch (Throwable) { continue; }
        $age = $now->getTimestamp() - $sourceAt->getTimestamp();
        if ($age < 0 || $age > STOREFRONT_AVAILABILITY_TTL_SECONDS) continue;
        $offer['_source_ts'] = $sourceAt->getTimestamp();
        $fresh[] = $offer;
    }
    $qualifying = array_values(array_filter($fresh, static function (array $offer) use ($quantity): bool {
        if ($offer['availability_status'] !== 'in_stock') return false;
        if ($offer['stock_quantity'] === null) return true;
        return is_int($offer['stock_quantity']) && $offer['stock_quantity'] >= $quantity;
    }));
    if ($qualifying !== []) {
        usort($qualifying, static fn(array $a, array $b): int =>
            ($b['_source_ts'] <=> $a['_source_ts']) ?: ((int)$a['offer_id'] <=> (int)$b['offer_id']));
        return storefrontAvailabilityResult('in_stock', $qualifying[0]);
    }
    $expected = array_values(array_filter($fresh, static fn(array $o): bool => $o['availability_status'] === 'expected'));
    if ($expected !== []) {
        usort($expected, static function (array $a, array $b): int {
            $ad = $a['expected_arrival_at'] ?? null; $bd = $b['expected_arrival_at'] ?? null;
            if ($ad !== $bd) { if ($ad === null) return 1; if ($bd === null) return -1; return strcmp($ad, $bd); }
            return ($b['_source_ts'] <=> $a['_source_ts']) ?: ((int)$a['offer_id'] <=> (int)$b['offer_id']);
        });
        return storefrontAvailabilityResult('expected', $expected[0]);
    }
    if ($fresh !== [] && array_reduce($fresh, static fn(bool $all, array $o): bool => $all && $o['availability_status'] === 'out_of_stock', true)) {
        usort($fresh, static fn(array $a, array $b): int =>
            ($b['_source_ts'] <=> $a['_source_ts']) ?: ((int)$a['offer_id'] <=> (int)$b['offer_id']));
        return storefrontAvailabilityResult('out_of_stock', $fresh[0]);
    }
    return storefrontAvailabilityResult('unknown', null);
}

function storefrontAvailabilityResult(string $status, ?array $offer): array
{
    return ['status' => $status, 'orderable' => $status === 'in_stock',
        'expected_arrival_at' => $status === 'expected' ? ($offer['expected_arrival_at'] ?? null) : null,
        'qualifying_offer_id' => $status === 'in_stock' ? (int)$offer['offer_id'] : null];
}

function storefrontAvailabilityLoadOffers(PDO $pdo, array $variantIds, bool $lock = false): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $variantIds), static fn(int $id): bool => $id > 0)));
    if ($ids === []) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT o.id AS offer_id, o.product_variant_id, o.availability_status,
                   o.stock_quantity, o.expected_arrival_at,
                   COALESCE(o.source_updated_at, o.imported_at) AS effective_source_at,
                   o.is_active AS offer_active, s.is_active AS supplier_active
            FROM supplier_offers o INNER JOIN suppliers s ON s.id = o.supplier_id
            WHERE o.product_variant_id IN ($placeholders)
            ORDER BY o.id ASC" . ($lock ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql); $stmt->execute($ids);
    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $row['offer_id'] = (int)$row['offer_id'];
        $row['product_variant_id'] = (int)$row['product_variant_id'];
        $row['stock_quantity'] = $row['stock_quantity'] === null ? null : (int)$row['stock_quantity'];
        $grouped[$row['product_variant_id']][] = $row;
    }
    return $grouped;
}

function storefrontAvailabilityPublic(array $resolution, int $variantId): array
{
    return ['product_variant_id' => $variantId, 'status' => $resolution['status'],
        'orderable' => $resolution['orderable'], 'expected_arrival_at' => $resolution['expected_arrival_at']];
}

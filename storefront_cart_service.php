<?php

declare(strict_types=1);

require_once __DIR__ . '/product_variant_identity_service.php';
require_once __DIR__ . '/storefront_availability_service.php';

final class StorefrontCartException extends RuntimeException
{
}

function storefrontCartResolve(PDO $pdo, array $items, bool $lock = false): array
{
    if ($items === [] || count($items) > 100) throw new StorefrontCartException('Invalid cart');
    $variantIds = []; $legacySlugs = [];
    foreach ($items as $item) {
        if (!is_array($item) || !is_int($item['quantity'] ?? null) || $item['quantity'] < 1 || $item['quantity'] > 100) throw new StorefrontCartException('Invalid cart item');
        if (array_key_exists('product_variant_id', $item)) {
            $variantId = $item['product_variant_id'];
            if (!is_int($variantId) || $variantId < 1) throw new StorefrontCartException('Invalid product variant id');
            $variantIds[] = $variantId;
        } else {
            $slug = trim((string)($item['slug'] ?? ''));
            $country = trim((string)($item['assembly_country'] ?? ''));
            if ($slug === '' || $country === '' || strlen($slug) > 255) throw new StorefrontCartException('Unresolved legacy cart item');
            $legacySlugs[] = $slug;
        }
    }
    $conditions = []; $params = [];
    if ($variantIds !== []) { $conditions[] = 'pv.id IN (' . implode(',', array_fill(0, count($variantIds), '?')) . ')'; array_push($params, ...$variantIds); }
    if ($legacySlugs !== []) { $conditions[] = 'p.slug IN (' . implode(',', array_fill(0, count($legacySlugs), '?')) . ')'; array_push($params, ...$legacySlugs); }
    $sql = "SELECT pv.id AS product_variant_id, p.id AS id, pv.product_id, pv.variant_key, pv.assembly_country,
                   pv.display_name, pv.is_active AS variant_active, p.slug, p.name, p.variants,
                   p.is_active AS product_active
            FROM product_variants pv INNER JOIN products p ON p.id = pv.product_id
            WHERE (" . implode(' OR ', $conditions) . ')';
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $byId = []; $bySlug = [];
    foreach ($stmt->fetchAll() as $row) {
        $row['product_variant_id'] = (int)$row['product_variant_id']; $row['product_id'] = (int)$row['product_id'];
        $byId[$row['product_variant_id']] = $row; $bySlug[$row['slug']][] = $row;
    }
    $resolvedRows = [];
    foreach ($items as $index => $item) {
        $hasVariantId = array_key_exists('product_variant_id', $item);
        $row = $hasVariantId ? ($byId[(int)$item['product_variant_id']] ?? null) : null;
        if ($row === null && !$hasVariantId) {
            $matches = [];
            foreach ($bySlug[trim((string)($item['slug'] ?? ''))] ?? [] as $candidate) {
                try { $identity = productVariantIdentityResolve($pdo, $candidate, $candidate); }
                catch (ProductVariantIdentityException) { continue; }
                if ($identity['target']['country'] === trim((string)($item['assembly_country'] ?? ''))) $matches[] = $candidate;
            }
            if (count($matches) === 1) $row = $matches[0];
        }
        if (!is_array($row) || !(bool)$row['product_active'] || !(bool)$row['variant_active']) {
            $resolvedRows[$index] = null; continue;
        }
        try { $identity = productVariantIdentityResolve($pdo, $row, $row); }
        catch (ProductVariantIdentityException) { $resolvedRows[$index] = null; continue; }
        if (!$identity['target']['is_active']) { $resolvedRows[$index] = null; continue; }
        $row['_identity'] = $identity; $resolvedRows[$index] = $row;
    }
    $seenResolvedVariants = [];
    foreach ($resolvedRows as $row) {
        if ($row === null) continue;
        $id = $row['product_variant_id'];
        if (isset($seenResolvedVariants[$id])) throw new StorefrontCartException('Duplicate product variant');
        $seenResolvedVariants[$id] = true;
    }
    $resolvedVariantIds = array_column(array_filter($resolvedRows), 'product_variant_id');
    $offerGroups = storefrontAvailabilityLoadOffers($pdo, $resolvedVariantIds, $lock);
    if ($lock && $resolvedVariantIds !== []) {
        $lockIds = array_values(array_unique(array_map('intval', $resolvedVariantIds)));
        sort($lockIds, SORT_NUMERIC);
        $lockSql = "SELECT pv.id AS product_variant_id, p.id AS id, pv.product_id, pv.variant_key, pv.assembly_country,
                           pv.display_name, pv.is_active AS variant_active, p.slug, p.name, p.variants,
                           p.is_active AS product_active
                    FROM product_variants pv INNER JOIN products p ON p.id = pv.product_id
                    WHERE pv.id IN (" . implode(',', array_fill(0, count($lockIds), '?')) . ")
                    ORDER BY pv.id ASC FOR UPDATE";
        $lockStmt = $pdo->prepare($lockSql); $lockStmt->execute($lockIds);
        $lockedById = [];
        foreach ($lockStmt->fetchAll() as $lockedRow) {
            $lockedRow['product_variant_id'] = (int)$lockedRow['product_variant_id'];
            $lockedRow['product_id'] = (int)$lockedRow['product_id'];
            $lockedById[$lockedRow['product_variant_id']] = $lockedRow;
        }
        foreach ($resolvedRows as $index => $resolvedRow) {
            if ($resolvedRow === null) continue;
            $lockedRow = $lockedById[$resolvedRow['product_variant_id']] ?? null;
            if (!is_array($lockedRow) || !(bool)$lockedRow['product_active'] || !(bool)$lockedRow['variant_active']) {
                $resolvedRows[$index] = null; continue;
            }
            try { $lockedRow['_identity'] = productVariantIdentityResolve($pdo, $lockedRow, $lockedRow); }
            catch (ProductVariantIdentityException) { $resolvedRows[$index] = null; continue; }
            if (!$lockedRow['_identity']['target']['is_active']) { $resolvedRows[$index] = null; continue; }
            $resolvedRows[$index] = $lockedRow;
        }
    }
    $results = []; $allOrderable = true;
    foreach ($items as $index => $item) {
        $row = $resolvedRows[$index] ?? null;
        if ($row === null) {
            $availability = storefrontAvailabilityResult('unknown', null); $allOrderable = false;
            $results[] = ['product_variant_id' => null, 'status' => 'unknown', 'orderable' => false, 'expected_arrival_at' => null];
            continue;
        }
        $id = $row['product_variant_id'];
        $availability = storefrontAvailabilityResolve($offerGroups[$id] ?? [], $item['quantity']);
        if (!$availability['orderable']) $allOrderable = false;
        $results[] = storefrontAvailabilityPublic($availability, $id) + [
            'product_id' => $row['product_id'], 'slug' => $row['slug'], 'name' => $row['name'],
            'assembly_country' => $row['_identity']['target']['country'],
            'quantity' => $item['quantity'], 'price' => $row['_identity']['variants'][$row['_identity']['target_index']]['price'],
            '_qualifying_offer_id' => $availability['qualifying_offer_id']
        ];
    }
    return ['all_orderable' => $allOrderable, 'items' => $results];
}

function storefrontCartPublic(array $resolved): array
{
    return ['all_orderable' => $resolved['all_orderable'], 'items' => array_map(static fn(array $item): array => [
        'product_id' => $item['product_id'] ?? null, 'product_variant_id' => $item['product_variant_id'],
        'slug' => $item['slug'] ?? null, 'assembly_country' => $item['assembly_country'] ?? null,
        'price' => $item['price'] ?? null,
        'status' => $item['status'], 'orderable' => $item['orderable'],
        'expected_arrival_at' => $item['expected_arrival_at'],
        'message' => $item['orderable'] ? null : match ($item['status']) {
            'out_of_stock' => 'Нет в наличии', 'expected' => 'Ожидается поступление', default => 'Наличие уточняется'
        }
    ], $resolved['items'])];
}

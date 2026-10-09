<?php

declare(strict_types=1);

require_once __DIR__ . '/product_variant_identity_service.php';
require_once __DIR__ . '/product_variant_price_service.php';
require_once __DIR__ . '/storefront_availability_service.php';

// Shared, unchanged public variant serializer for API and private SEO snapshots.
function attachStorefrontVariants(PDO $pdo, array $products): array
{
    $productIds = array_values(array_map(static fn(array $product): int => (int)$product['id'], $products));
    if ($productIds === []) return $products;
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $pdo->prepare("SELECT id, product_id, variant_key, assembly_country, manufacturer_part_number, display_name, is_active
                           FROM product_variants
                           WHERE product_id IN ($placeholders) AND is_active = 1
                           ORDER BY product_id ASC, id ASC");
    $stmt->execute($productIds);
    $byProduct = []; $variantIds = [];
    foreach ($stmt->fetchAll() as $variant) {
        $variant['id'] = (int)$variant['id'];
        $variant['product_id'] = (int)$variant['product_id'];
        $byProduct[$variant['product_id']][] = $variant;
        $variantIds[] = $variant['id'];
    }
    $offersByVariant = storefrontAvailabilityLoadOffers($pdo, $variantIds);
    $priceOverrides = productVariantPriceLoad($pdo, $variantIds);
    foreach ($products as &$product) {
        $publicVariants = [];
        foreach ($byProduct[(int)$product['id']] ?? [] as $variant) {
            try {
                $identity = productVariantIdentityResolve($pdo, $product, $variant, true);
            } catch (ProductVariantIdentityException) {
                continue;
            }
            $legacy = $identity['variants'][$identity['target_index']];
            try {
                $effectivePrice = productVariantPriceEffective(
                    $identity['target'] + ['price' => $legacy['price'], 'old_price' => $legacy['old_price']],
                    $priceOverrides[$variant['id']] ?? null
                );
            } catch (ProductVariantPriceException) {
                continue;
            }

            // A zero legacy price is allowed during identity resolution because
            // an active manual override may provide the actual storefront price.
            // Never expose a variant whose final effective price is still zero.
            if (($effectivePrice['price_minor'] ?? 0) <= 0) {
                continue;
            }

            $availability = storefrontAvailabilityResolve($offersByVariant[$variant['id']] ?? [], 1);
            $publicVariants[] = [
                'product_variant_id' => $variant['id'],
                'country' => $identity['target']['country'],
                'model_code' => $variant['manufacturer_part_number'] === null || trim((string)$variant['manufacturer_part_number']) === ''
                    ? null
                    : (string)$variant['manufacturer_part_number'],
                'display_name' => $variant['display_name'],
                'price' => $effectivePrice['price'],
                'old_price' => $effectivePrice['old_price'],
                'is_active' => $identity['target']['is_active'],
                'availability' => storefrontAvailabilityPublic($availability, $variant['id'])
            ];
        }
        $product['storefront_variants'] = $publicVariants;
    }
    unset($product);
    return $products;
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/product_variant_identity_service.php';

final class ProductVariantPriceException extends RuntimeException
{
    public function __construct(public readonly int $httpStatus, string $message)
    {
        parent::__construct($message);
    }
}

function productVariantPriceMinor(mixed $value, bool $allowNull = false): ?int
{
    if ($allowNull && ($value === null || $value === '')) return null;
    if (!is_int($value) && !is_float($value) && !is_string($value)) return null;
    $text = trim((string)$value);
    if (preg_match('/\A(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?\z/D', $text) !== 1) return null;
    [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
    $minor = ((int)$whole * 100) + (int)str_pad($fraction, 2, '0');
    return $minor > 0 && $minor <= 999999999999 ? $minor : null;
}

function productVariantPriceNumber(int $minor): int|float
{
    return $minor % 100 === 0 ? intdiv($minor, 100) : (float)number_format($minor / 100, 2, '.', '');
}

function productVariantPriceLoad(PDO $pdo, array $variantIds, bool $lock = false): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $variantIds), static fn(int $id): bool => $id > 0)));
    if ($ids === []) return [];
    sort($ids, SORT_NUMERIC);
    $sql = 'SELECT product_variant_id, manual_price, manual_old_price, is_active, updated_at
            FROM product_variant_price_overrides
            WHERE product_variant_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
            ORDER BY product_variant_id ASC' . ($lock ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) $rows[(int)$row['product_variant_id']] = $row;
    return $rows;
}

function productVariantPriceEffective(array $legacyVariant, ?array $override): array
{
    $manual = is_array($override) && (bool)($override['is_active'] ?? false);
    if ($manual) {
        $priceMinor = productVariantPriceMinor($override['manual_price'] ?? null);
        $oldMinor = productVariantPriceMinor($override['manual_old_price'] ?? null, true);
        if ($priceMinor === null || (($override['manual_old_price'] ?? null) !== null && $oldMinor === null)) {
            throw new ProductVariantPriceException(409, 'Ручная цена варианта повреждена');
        }
        return [
            'price_source' => 'manual',
            'price_minor' => $priceMinor,
            'price' => productVariantPriceNumber($priceMinor),
            'old_price' => $oldMinor === null ? null : productVariantPriceNumber($oldMinor),
        ];
    }
    return [
        'price_source' => 'automatic',
        'price_minor' => (int)$legacyVariant['price_minor'],
        'price' => $legacyVariant['price'],
        'old_price' => $legacyVariant['old_price'],
    ];
}

function productVariantPriceSet(PDO $pdo, int $variantId, bool $manual, mixed $price = null, mixed $oldPrice = null): array
{
    $priceMinor = $manual ? productVariantPriceMinor($price) : null;
    $oldPriceMinor = $manual ? productVariantPriceMinor($oldPrice, true) : null;
    if ($manual && $priceMinor === null) throw new ProductVariantPriceException(400, 'Укажите положительную ручную цену не более 9 999 999 999,99');
    if ($manual && $oldPrice !== null && $oldPrice !== '' && $oldPriceMinor === null) throw new ProductVariantPriceException(400, 'Старая цена должна быть положительной');

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        try {
            $pdo->beginTransaction();
            $targetStmt = $pdo->prepare('SELECT id, product_id FROM product_variants WHERE id = :id');
            $targetStmt->execute([':id' => $variantId]);
            $target = $targetStmt->fetch();
            if (!is_array($target)) throw new ProductVariantPriceException(404, 'Вариант не найден');

            $variantsStmt = $pdo->prepare('SELECT id, product_id, variant_key, assembly_country, display_name, is_active
                FROM product_variants WHERE product_id = :product_id ORDER BY id ASC FOR UPDATE');
            $variantsStmt->execute([':product_id' => $target['product_id']]);
            $variants = $variantsStmt->fetchAll();
            $lockedTarget = null;
            foreach ($variants as $variant) if ((int)$variant['id'] === $variantId) $lockedTarget = $variant;
            if (!is_array($lockedTarget)) throw new ProductVariantPriceException(404, 'Вариант не найден');

            $productStmt = $pdo->prepare('SELECT id, is_active, variants FROM products WHERE id = :id FOR UPDATE');
            $productStmt->execute([':id' => $lockedTarget['product_id']]);
            $product = $productStmt->fetch();
            if (!is_array($product)) throw new ProductVariantPriceException(404, 'Товар не найден');
            try { $targetIdentity = productVariantIdentityResolve($pdo, $product, $lockedTarget, true); }
            catch (ProductVariantIdentityException) { throw new ProductVariantPriceException(409, 'Идентичность варианта повреждена'); }

            $ids = array_map(static fn(array $variant): int => (int)$variant['id'], $variants);
            $overrides = productVariantPriceLoad($pdo, $ids, true);
            if (!$manual && (bool)$product['is_active'] && (bool)$lockedTarget['is_active']) {
                $automatic = productVariantPriceEffective($targetIdentity['target'] + [
                    'price' => $targetIdentity['variants'][$targetIdentity['target_index']]['price'],
                    'old_price' => $targetIdentity['variants'][$targetIdentity['target_index']]['old_price'],
                ], null);
                if ($automatic['price_minor'] <= 0) {
                    $alternateReady = false;
                    foreach ($variants as $variant) {
                        if ((int)$variant['id'] === $variantId || !(bool)$variant['is_active']) continue;
                        try { $identity = productVariantIdentityResolve($pdo, $product, $variant, true); }
                        catch (ProductVariantIdentityException) { continue; }
                        if (!$identity['target']['is_active']) continue;
                        $legacy = $identity['variants'][$identity['target_index']];
                        try { $effective = productVariantPriceEffective($identity['target'] + ['price'=>$legacy['price'],'old_price'=>$legacy['old_price']], $overrides[(int)$variant['id']] ?? null); }
                        catch (ProductVariantPriceException) { continue; }
                        if ($effective['price_minor'] > 0) { $alternateReady = true; break; }
                    }
                    if (!$alternateReady) throw new ProductVariantPriceException(409, 'Нельзя снять ручную цену последнего готового варианта активного товара');
                }
            }

            if ($manual) {
                $stmt = $pdo->prepare('INSERT INTO product_variant_price_overrides
                    (product_variant_id, manual_price, manual_old_price, is_active)
                    VALUES (:id, :price, :old_price, 1)
                    ON DUPLICATE KEY UPDATE manual_price = VALUES(manual_price), manual_old_price = VALUES(manual_old_price), is_active = 1');
                $stmt->execute([':id'=>$variantId, ':price'=>number_format($priceMinor / 100, 2, '.', ''), ':old_price'=>$oldPriceMinor === null ? null : number_format($oldPriceMinor / 100, 2, '.', '')]);
            } else {
                $stmt = $pdo->prepare('UPDATE product_variant_price_overrides SET is_active = 0 WHERE product_variant_id = :id');
                $stmt->execute([':id'=>$variantId]);
            }
            $pdo->commit();
            $legacy = $targetIdentity['variants'][$targetIdentity['target_index']];
            $effective = $manual
                ? ['price_source'=>'manual','price'=>productVariantPriceNumber($priceMinor),'old_price'=>$oldPriceMinor===null?null:productVariantPriceNumber($oldPriceMinor)]
                : ['price_source'=>'automatic','price'=>$legacy['price'],'old_price'=>$legacy['old_price']];
            return ['product_id'=>(int)$product['id'],'product_variant_id'=>$variantId] + $effective;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $driverCode = $error instanceof PDOException ? (int)($error->errorInfo[1] ?? 0) : 0;
            if ($driverCode === 1205 || $driverCode === 1213) {
                if ($attempt < 2) continue;
                throw new ProductVariantPriceException(409, 'Конфликт одновременного изменения. Повторите попытку.');
            }
            throw $error;
        }
    }
    throw new LogicException('Price mode retry loop exhausted');
}

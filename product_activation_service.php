<?php

declare(strict_types=1);

require_once __DIR__ . '/product_variant_identity_service.php';
require_once __DIR__ . '/product_variant_price_service.php';

final class ProductActivationException extends RuntimeException
{
    public function __construct(public readonly int $httpStatus, string $message)
    {
        parent::__construct($message);
    }
}

function productActivationReadyCandidate(PDO $pdo, int $productId): array
{
    $productStmt = $pdo->prepare('
        SELECT id, is_active, variants
        FROM products
        WHERE id = :id
        LIMIT 1
    ');
    $productStmt->execute([':id' => $productId]);
    $product = $productStmt->fetch();
    if (!is_array($product)) {
        throw new ProductActivationException(404, 'Товар не найден');
    }
    if ((int)$product['is_active'] !== 0) {
        return ['product_was_active' => true, 'candidate_id' => null];
    }

    $variantStmt = $pdo->prepare('
        SELECT id, product_id, variant_key, assembly_country, display_name, is_active
        FROM product_variants
        WHERE product_id = :product_id AND is_active = 1
        ORDER BY id ASC
    ');
    $variantStmt->execute([':product_id' => $productId]);
    $relationalVariants = $variantStmt->fetchAll();
    $overrides = productVariantPriceLoad($pdo, array_column($relationalVariants, 'id'));
    foreach ($relationalVariants as $relationalVariant) {
        try {
            $identity = productVariantIdentityResolve($pdo, $product, $relationalVariant, true);
        } catch (ProductVariantIdentityException) {
            continue;
        }
        $legacy = $identity['variants'][$identity['target_index']];
        try { $effective = productVariantPriceEffective($identity['target'] + ['price'=>$legacy['price'],'old_price'=>$legacy['old_price']], $overrides[(int)$relationalVariant['id']] ?? null); }
        catch (ProductVariantPriceException) { continue; }
        if ($identity['target']['is_active'] === true && $effective['price_minor'] > 0) {
            return ['product_was_active' => false, 'candidate_id' => (int)$relationalVariant['id']];
        }
    }

    throw new ProductActivationException(
        409,
        'Товар нельзя активировать: сначала настройте вариант и опубликуйте положительную розничную цену.'
    );
}

function productActivationLockAndValidate(PDO $pdo, int $productId, array $preflight): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Product activation requires an active transaction');

    $candidate = null;
    if ($preflight['candidate_id'] !== null) {
        $variantStmt = $pdo->prepare('
            SELECT id, product_id, variant_key, assembly_country, display_name, is_active
            FROM product_variants
            WHERE id = :id
            LIMIT 1
            FOR UPDATE
        ');
        $variantStmt->execute([':id' => $preflight['candidate_id']]);
        $candidate = $variantStmt->fetch();
    }

    $productStmt = $pdo->prepare('
        SELECT id, is_active, variants
        FROM products
        WHERE id = :id
        LIMIT 1
        FOR UPDATE
    ');
    $productStmt->execute([':id' => $productId]);
    $product = $productStmt->fetch();
    if (!is_array($product)) {
        throw new ProductActivationException(404, 'Товар не найден');
    }

    if ((int)$product['is_active'] !== 0) {
        return $product;
    }

    if (!is_array($candidate) || (int)$candidate['product_id'] !== $productId || !(bool)$candidate['is_active']) {
        throw new ProductActivationException(409, 'Данные вариантов изменились. Повторите активацию.');
    }
    try {
        $identity = productVariantIdentityResolve($pdo, $product, $candidate, true);
    } catch (ProductVariantIdentityException) {
        throw new ProductActivationException(409, 'Данные вариантов изменились. Повторите активацию.');
    }
    $legacy = $identity['variants'][$identity['target_index']];
    try {
        $overrides = productVariantPriceLoad($pdo, [(int)$candidate['id']], true);
        $effective = productVariantPriceEffective($identity['target'] + ['price'=>$legacy['price'],'old_price'=>$legacy['old_price']], $overrides[(int)$candidate['id']] ?? null);
    } catch (ProductVariantPriceException) {
        throw new ProductActivationException(409, 'Данные цены варианта изменились. Повторите активацию.');
    }
    if ($identity['target']['is_active'] !== true || $effective['price_minor'] <= 0) {
        throw new ProductActivationException(409, 'Данные вариантов изменились. Повторите активацию.');
    }

    return $product;
}

function productActivationIsLockConflict(PDOException $error): bool
{
    $driverCode = isset($error->errorInfo[1]) ? (int)$error->errorInfo[1] : 0;
    return $driverCode === 1205 || $driverCode === 1213;
}

function productActivationRun(PDO $pdo, int $productId, callable $update, int $maxAttempts = 2): void
{
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $preflight = productActivationReadyCandidate($pdo, $productId);
            $pdo->beginTransaction();
            productActivationLockAndValidate($pdo, $productId, $preflight);
            $update();
            $pdo->commit();
            return;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($error instanceof PDOException && productActivationIsLockConflict($error)) {
                if ($attempt < $maxAttempts) continue;
                throw new ProductActivationException(409, 'Конфликт одновременного изменения. Повторите попытку.');
            }
            throw $error;
        }
    }
}

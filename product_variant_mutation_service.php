<?php

declare(strict_types=1);

require_once __DIR__ . '/product_variant_identity_service.php';
require_once __DIR__ . '/product_activation_service.php';

final class ProductVariantMutationException extends RuntimeException
{
    public function __construct(public readonly int $httpStatus, string $message) { parent::__construct($message); }
}

function productVariantMutationPositiveId(mixed $value): ?int
{
    if (!is_int($value) && !(is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value) === 1)) return null;
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>PHP_INT_MAX]]);
    return $id === false ? null : (int)$id;
}

function productVariantMutationCountry(PDO $pdo, mixed $value): array
{
    if (!is_string($value)) throw new ProductVariantMutationException(400, 'Некорректная страна сборки');
    $stmt = $pdo->prepare('SELECT HEX(WEIGHT_STRING(CAST(:value AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci))');
    try {
        return productVariantIdentityCountry($stmt, ['country'=>$value,'price'=>0,'old_price'=>null,'is_active'=>true], 0, true);
    } catch (ProductVariantIdentityException) {
        throw new ProductVariantMutationException(400, 'Некорректная страна сборки');
    }
}

function productVariantMutationLegacy(PDO $pdo, array $product): array
{
    $raw = $product['variants'] ?? null;
    if (!is_string($raw)) throw new ProductVariantMutationException(409, 'Legacy-варианты товара повреждены');
    try { $variants = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new ProductVariantMutationException(409, 'Legacy-варианты товара повреждены'); }
    if (!is_array($variants) || !array_is_list($variants) || count($variants) > 200) {
        throw new ProductVariantMutationException(409, 'Legacy-варианты товара повреждены');
    }
    $stmt = $pdo->prepare('SELECT HEX(WEIGHT_STRING(CAST(:value AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci))');
    $seen = [];
    foreach ($variants as $variant) {
        if (!is_array($variant)) throw new ProductVariantMutationException(409, 'Legacy-варианты товара повреждены');
        try { $detail = productVariantIdentityCountry($stmt, $variant, (int)$product['id'], true); }
        catch (ProductVariantIdentityException) { throw new ProductVariantMutationException(409, 'Legacy-варианты товара повреждены'); }
        if (isset($seen[$detail['weight']])) throw new ProductVariantMutationException(409, 'Legacy-варианты товара повреждены');
        $seen[$detail['weight']] = true;
    }
    return ['variants'=>$variants, 'weights'=>$seen, 'raw_hash'=>hash('sha256',$raw)];
}

function productVariantMutationRun(PDO $pdo, callable $operation, int $maxAttempts = 2): mixed
{
    for ($attempt=1; $attempt<=$maxAttempts; $attempt++) {
        try {
            $pdo->beginTransaction();
            $result = $operation();
            $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($error instanceof PDOException && productActivationIsLockConflict($error)) {
                if ($attempt < $maxAttempts) continue;
                throw new ProductVariantMutationException(409, 'Конфликт одновременного изменения. Повторите попытку.');
            }
            throw $error;
        }
    }
    throw new LogicException('Variant mutation retry loop exhausted');
}

function productVariantAdd(PDO $pdo, int $productId, string $country): array
{
    $countryDetail = productVariantMutationCountry($pdo, $country);
    return productVariantMutationRun($pdo, static function () use ($pdo,$productId,$countryDetail): array {
        try {
            $stmt=$pdo->prepare("INSERT INTO product_variants (product_id,variant_key,assembly_country,display_name,classification_status,classification_evidence,is_active) VALUES (:product_id,:variant_key,:assembly_country,:display_name,'requires_classification',NULL,1)");
            $stmt->execute([':product_id'=>$productId,':variant_key'=>'legacy-country-sha256-'.hash('sha256',$countryDetail['country']),':assembly_country'=>$countryDetail['country'],':display_name'=>$countryDetail['country']]);
        } catch (PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0) === 1062) throw new ProductVariantMutationException(409, 'Такая страна сборки уже существует');
            if ((int)($error->errorInfo[1] ?? 0) === 1452) throw new ProductVariantMutationException(404, 'Товар не найден');
            throw $error;
        }
        $variantId=(int)$pdo->lastInsertId();
        $stmt=$pdo->prepare('SELECT id,is_active,variants FROM products WHERE id=:id LIMIT 1 FOR UPDATE');
        $stmt->execute([':id'=>$productId]); $product=$stmt->fetch();
        if (!is_array($product)) throw new ProductVariantMutationException(404,'Товар не найден');
        $legacy=productVariantMutationLegacy($pdo,$product);
        if (isset($legacy['weights'][$countryDetail['weight']])) throw new ProductVariantMutationException(409,'Такая страна сборки уже существует');
        if (count($legacy['variants']) >= 200) throw new ProductVariantMutationException(409,'Достигнут лимит вариантов товара');
        $legacy['variants'][]=['country'=>$countryDetail['country'],'price'=>0,'old_price'=>null,'is_active'=>true];
        $encoded=json_encode($legacy['variants'],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
        $pdo->prepare('UPDATE products SET variants=:variants WHERE id=:id')->execute([':variants'=>$encoded,':id'=>$productId]);
        return ['product_id'=>$productId,'product_variant_id'=>$variantId,'variant_key'=>'legacy-country-sha256-'.hash('sha256',$countryDetail['country']),'assembly_country'=>$countryDetail['country'],'is_active'=>true];
    });
}

function productVariantSetActive(PDO $pdo, int $variantId, bool $requestedActive): array
{
    return productVariantMutationRun($pdo, static function () use ($pdo,$variantId,$requestedActive): array {
        $pre=$pdo->prepare('SELECT pv.id,pv.product_id,pv.variant_key,pv.assembly_country,pv.display_name,pv.is_active,p.is_active product_is_active,p.variants FROM product_variants pv JOIN products p ON p.id=pv.product_id WHERE pv.id=:id');
        $pre->execute([':id'=>$variantId]); $snapshot=$pre->fetch();
        if (!is_array($snapshot)) throw new ProductVariantMutationException(404,'Вариант не найден');
        $alternateId=null;
        if (!$requestedActive && (bool)$snapshot['product_is_active']) {
            $stmt=$pdo->prepare('SELECT id,product_id,variant_key,assembly_country,display_name,is_active FROM product_variants WHERE product_id=:product_id AND id<>:id AND is_active=1 ORDER BY id ASC');
            $stmt->execute([':product_id'=>$snapshot['product_id'],':id'=>$variantId]);
            $product=['id'=>$snapshot['product_id'],'variants'=>$snapshot['variants']];
            foreach ($stmt->fetchAll() as $candidate) {
                try { $identity=productVariantIdentityResolve($pdo,$product,$candidate,true); }
                catch (ProductVariantIdentityException) { continue; }
                if ($identity['target']['is_active'] && $identity['target']['price_minor']>0) { $alternateId=(int)$candidate['id']; break; }
            }
        }
        $ids=[$variantId]; if ($alternateId!==null) $ids[]=$alternateId; sort($ids,SORT_NUMERIC);
        $locked=[]; $stmt=$pdo->prepare('SELECT id,product_id,variant_key,assembly_country,display_name,is_active FROM product_variants WHERE id=:id FOR UPDATE');
        foreach ($ids as $id) { $stmt->execute([':id'=>$id]); $row=$stmt->fetch(); if (is_array($row)) $locked[$id]=$row; }
        if (!isset($locked[$variantId])) throw new ProductVariantMutationException(404,'Вариант не найден');
        $target=$locked[$variantId];
        $stmt=$pdo->prepare('SELECT id,is_active,variants FROM products WHERE id=:id FOR UPDATE'); $stmt->execute([':id'=>$target['product_id']]); $product=$stmt->fetch();
        if (!is_array($product)) throw new ProductVariantMutationException(404,'Товар не найден');
        try { $identity=productVariantIdentityResolve($pdo,$product,$target,true); }
        catch (ProductVariantIdentityException) { throw new ProductVariantMutationException(409,'Идентичность варианта повреждена'); }
        if (!$requestedActive && (bool)$product['is_active']) {
            if ($alternateId===null || !isset($locked[$alternateId]) || !(bool)$locked[$alternateId]['is_active']) throw new ProductVariantMutationException(409,'Нельзя отключить последний готовый вариант активного товара');
            try { $alternate=productVariantIdentityResolve($pdo,$product,$locked[$alternateId],true); }
            catch (ProductVariantIdentityException) { throw new ProductVariantMutationException(409,'Нельзя отключить последний готовый вариант активного товара'); }
            if (!$alternate['target']['is_active'] || $alternate['target']['price_minor']<=0) throw new ProductVariantMutationException(409,'Нельзя отключить последний готовый вариант активного товара');
        }
        $variants=$identity['variants']; $variants[$identity['target_index']]['is_active']=$requestedActive;
        $encoded=json_encode($variants,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
        $pdo->prepare('UPDATE products SET variants=:variants WHERE id=:id')->execute([':variants'=>$encoded,':id'=>$product['id']]);
        $pdo->prepare('UPDATE product_variants SET is_active=:active WHERE id=:id')->execute([':active'=>$requestedActive?1:0,':id'=>$variantId]);
        return ['product_id'=>(int)$product['id'],'product_variant_id'=>$variantId,'is_active'=>$requestedActive];
    });
}

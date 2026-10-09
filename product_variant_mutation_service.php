<?php

declare(strict_types=1);

require_once __DIR__ . '/product_variant_identity_service.php';
require_once __DIR__ . '/product_activation_service.php';
require_once __DIR__ . '/product_variant_price_service.php';

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

function productVariantMutationModelCode(mixed $value): ?string
{
    if ($value === null) return null;
    if (!is_string($value)) throw new ProductVariantMutationException(400, 'Некорректная модель варианта');
    $code = trim($value);
    if ($code === '') return null;
    if (!mb_check_encoding($code, 'UTF-8') || mb_strlen($code, 'UTF-8') > 191 || preg_match('/[\x00-\x1F\x7F]/u', $code) === 1) {
        throw new ProductVariantMutationException(400, 'Некорректная модель варианта');
    }
    return $code;
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

function productVariantAdd(PDO $pdo, int $productId, string $country, ?string $modelCode = null): array
{
    $countryDetail = productVariantMutationCountry($pdo, $country);
    $modelCode = productVariantMutationModelCode($modelCode);
    return productVariantMutationRun($pdo, static function () use ($pdo,$productId,$countryDetail,$modelCode): array {
        try {
            $stmt=$pdo->prepare("INSERT INTO product_variants (product_id,variant_key,assembly_country,manufacturer_part_number,display_name,classification_status,classification_evidence,is_active) VALUES (:product_id,:variant_key,:assembly_country,:model_code,:display_name,'requires_classification',NULL,1)");
            $stmt->execute([':product_id'=>$productId,':variant_key'=>'legacy-country-sha256-'.hash('sha256',$countryDetail['country']),':assembly_country'=>$countryDetail['country'],':model_code'=>$modelCode,':display_name'=>$countryDetail['country']]);
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
        return ['product_id'=>$productId,'product_variant_id'=>$variantId,'variant_key'=>'legacy-country-sha256-'.hash('sha256',$countryDetail['country']),'assembly_country'=>$countryDetail['country'],'model_code'=>$modelCode,'is_active'=>true];
    });
}

function productVariantUpdateModelCode(PDO $pdo, int $productId, int $variantId, mixed $rawModelCode): array
{
    $modelCode = productVariantMutationModelCode($rawModelCode);
    return productVariantMutationRun($pdo, static function () use ($pdo, $productId, $variantId, $modelCode): array {
        $lock = $pdo->prepare('SELECT id, product_id FROM product_variants WHERE id = :id FOR UPDATE');
        $lock->execute([':id' => $variantId]);
        $variant = $lock->fetch();
        if (!is_array($variant)) throw new ProductVariantMutationException(404, 'Вариант не найден');
        if ((int)$variant['product_id'] !== $productId) throw new ProductVariantMutationException(404, 'Вариант не найден');
        $update = $pdo->prepare('UPDATE product_variants SET manufacturer_part_number = :model_code WHERE id = :id AND product_id = :product_id');
        $update->execute([':model_code' => $modelCode, ':id' => $variantId, ':product_id' => $productId]);
        return ['product_id' => $productId, 'product_variant_id' => $variantId, 'model_code' => $modelCode];
    });
}

/** Rename the legacy label and its relational identity together; never replace the ID. */
function productVariantRename(PDO $pdo, int $productId, int $variantId, mixed $name, mixed $expectedName): array
{
    $detail = productVariantMutationCountry($pdo, $name);
    if (!is_string($expectedName)) throw new ProductVariantMutationException(400, 'Не указано прежнее название варианта');
    return productVariantMutationRun($pdo, static function () use ($pdo, $productId, $variantId, $detail, $expectedName): array {
        $q = $pdo->prepare('SELECT * FROM product_variants WHERE id=:id AND product_id=:product_id FOR UPDATE');
        $q->execute([':id'=>$variantId, ':product_id'=>$productId]); $variant=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($variant)) throw new ProductVariantMutationException(404, 'Вариант не найден');
        if ($variant['assembly_country'] !== $expectedName) throw new ProductVariantMutationException(409, 'Название уже изменилось. Обновите список вариантов.');
        $q=$pdo->prepare('SELECT id,is_active,variants FROM products WHERE id=:id FOR UPDATE');
        $q->execute([':id'=>$productId]); $product=$q->fetch(PDO::FETCH_ASSOC);
        try { $identity=productVariantIdentityResolve($pdo,$product,$variant,true); }
        catch (ProductVariantIdentityException) { throw new ProductVariantMutationException(409, 'Идентичность варианта повреждена'); }
        $weight=$pdo->prepare('SELECT HEX(WEIGHT_STRING(CAST(:value AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci))');
        foreach ($identity['variants'] as $index=>$entry) {
            if ($index === $identity['target_index']) continue;
            $other=productVariantIdentityCountry($weight,$entry,$productId,true);
            if ($other['weight'] === $detail['weight']) throw new ProductVariantMutationException(409, 'Вариант с таким названием уже существует');
        }
        $variants=$identity['variants']; $variants[$identity['target_index']]['country']=$detail['country'];
        try {
            $q=$pdo->prepare('UPDATE product_variants SET assembly_country=:name,display_name=:display,variant_key=:key WHERE id=:id AND product_id=:product_id');
            $q->execute([':name'=>$detail['country'],':display'=>$detail['country'],':key'=>'legacy-country-sha256-'.hash('sha256',$detail['country']),':id'=>$variantId,':product_id'=>$productId]);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1]??0)===1062) throw new ProductVariantMutationException(409, 'Вариант с таким названием уже существует');
            throw $e;
        }
        $pdo->prepare('UPDATE products SET variants=:variants WHERE id=:id')->execute([':variants'=>json_encode($variants,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR),':id'=>$productId]);
        return ['product_id'=>$productId,'product_variant_id'=>$variantId,'name'=>$detail['country']];
    });
}

/** Include non-FK order snapshots and any additional FK introduced by another module. */
function productVariantReferences(PDO $pdo, int $variantId): array
{
    $q=$pdo->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME IN ('product_variant_id','matched_product_variant_id')
        UNION SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.key_column_usage WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='product_variants' AND REFERENCED_COLUMN_NAME='id'");
    $counts=[];
    foreach ($q->fetchAll(PDO::FETCH_NUM) as [$table,$column]) {
        $quotedTable='`'.str_replace('`','``',$table).'`'; $quotedColumn='`'.str_replace('`','``',$column).'`';
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM '.$quotedTable.' WHERE '.$quotedColumn.'=:id');
        $stmt->execute([':id'=>$variantId]); $counts[$table.'.'.$column]=(int)$stmt->fetchColumn();
    }
    // Inspect stock/purchase prices as well; never use them as permission to delete history.
    if (isset($counts['supplier_offers.product_variant_id'])) {
        $stmt=$pdo->prepare('SELECT currency_code,COUNT(*) AS offers,SUM(is_active=1) AS active_offers,SUM(stock_quantity) AS stock_quantity,MIN(purchase_price) AS min_purchase_price,MAX(purchase_price) AS max_purchase_price FROM supplier_offers WHERE product_variant_id=:id GROUP BY currency_code');
        $stmt->execute([':id'=>$variantId]); $counts['supplier_offer_summary']=$stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    // Offers/overrides are retained verbatim, including their stock and price values.
    return $counts;
}

function productVariantSetActive(PDO $pdo, int $variantId, bool $requestedActive, ?int $expectedProductId = null, bool $archive = false): array
{
    return productVariantMutationRun($pdo, static function () use ($pdo,$variantId,$requestedActive,$expectedProductId,$archive): array {
        $pre=$pdo->prepare('SELECT pv.id,pv.product_id,pv.variant_key,pv.assembly_country,pv.display_name,pv.is_active,p.is_active product_is_active,p.variants FROM product_variants pv JOIN products p ON p.id=pv.product_id WHERE pv.id=:id');
        $pre->execute([':id'=>$variantId]); $snapshot=$pre->fetch();
        if (!is_array($snapshot)) throw new ProductVariantMutationException(404,'Вариант не найден');
        if ($expectedProductId !== null && (int)$snapshot['product_id'] !== $expectedProductId) throw new ProductVariantMutationException(404,'Вариант не найден');
        $alternateId=null;
        if (!$requestedActive && (bool)$snapshot['product_is_active']) {
            $stmt=$pdo->prepare('SELECT id,product_id,variant_key,assembly_country,display_name,is_active FROM product_variants WHERE product_id=:product_id AND id<>:id AND is_active=1 ORDER BY id ASC');
            $stmt->execute([':product_id'=>$snapshot['product_id'],':id'=>$variantId]);
            $product=['id'=>$snapshot['product_id'],'variants'=>$snapshot['variants']];
            $candidates = $stmt->fetchAll();
            $overrides = productVariantPriceLoad($pdo, array_column($candidates, 'id'));
            foreach ($candidates as $candidate) {
                try { $identity=productVariantIdentityResolve($pdo,$product,$candidate,true); }
                catch (ProductVariantIdentityException) { continue; }
                $legacyVariant=$identity['variants'][$identity['target_index']];
                try { $effective=productVariantPriceEffective($identity['target']+['price'=>$legacyVariant['price'],'old_price'=>$legacyVariant['old_price']],$overrides[(int)$candidate['id']]??null); }
                catch (ProductVariantPriceException) { continue; }
                if ($identity['target']['is_active'] && $effective['price_minor']>0) { $alternateId=(int)$candidate['id']; break; }
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
        $references=$archive ? productVariantReferences($pdo,$variantId) : [];
        if ($archive && !(bool)$target['is_active'] && !$identity['target']['is_active']) return ['product_id'=>(int)$product['id'],'product_variant_id'=>$variantId,'is_active'=>false,'removal_mode'=>'disabled','references'=>$references];
        if (!$requestedActive && (bool)$product['is_active']) {
            if ($alternateId===null || !isset($locked[$alternateId]) || !(bool)$locked[$alternateId]['is_active']) throw new ProductVariantMutationException(409,'Нельзя отключить последний готовый вариант активного товара');
            try { $alternate=productVariantIdentityResolve($pdo,$product,$locked[$alternateId],true); }
            catch (ProductVariantIdentityException) { throw new ProductVariantMutationException(409,'Нельзя отключить последний готовый вариант активного товара'); }
            $legacyAlternate=$alternate['variants'][$alternate['target_index']];
            try { $override=productVariantPriceLoad($pdo,[$alternateId],true); $effective=productVariantPriceEffective($alternate['target']+['price'=>$legacyAlternate['price'],'old_price'=>$legacyAlternate['old_price']],$override[$alternateId]??null); }
            catch (ProductVariantPriceException) { throw new ProductVariantMutationException(409,'Нельзя отключить последний готовый вариант активного товара'); }
            if (!$alternate['target']['is_active'] || $effective['price_minor']<=0) throw new ProductVariantMutationException(409,'Нельзя отключить последний готовый вариант активного товара');
        }
        $variants=$identity['variants']; $variants[$identity['target_index']]['is_active']=$requestedActive;
        $encoded=json_encode($variants,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
        $pdo->prepare('UPDATE products SET variants=:variants WHERE id=:id')->execute([':variants'=>$encoded,':id'=>$product['id']]);
        $pdo->prepare('UPDATE product_variants SET is_active=:active WHERE id=:id')->execute([':active'=>$requestedActive?1:0,':id'=>$variantId]);
        return ['product_id'=>(int)$product['id'],'product_variant_id'=>$variantId,'is_active'=>$requestedActive]+($archive ? ['removal_mode'=>'disabled','references'=>$references] : []);
    });
}

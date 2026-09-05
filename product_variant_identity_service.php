<?php

declare(strict_types=1);

final class ProductVariantIdentityException extends RuntimeException
{
}

function productVariantIdentityMinor(mixed $value, bool $allowNull = false, bool $allowZero = false): ?int
{
    if ($allowNull && $value === null) return null;
    if (is_int($value)) return ($value > 0 || ($allowZero && $value === 0)) ? $value * 100 : null;
    if (!is_float($value) || !is_finite($value) || $value < 0 || (!$allowZero && $value === 0.0)) return null;
    $scaled = $value * 100;
    $rounded = round($scaled);
    if (!is_finite($scaled) || abs($scaled - $rounded) > 0.000001 || $rounded > 999999999999) return null;
    return (int)$rounded;
}

function productVariantIdentityCountry(PDOStatement $weightStatement, array $variant, int $productId, bool $allowZeroPrice = false): array
{
    if (array_is_list($variant)) throw new ProductVariantIdentityException('Структура вариантов товара не поддерживает безопасную публикацию');
    $keys = array_keys($variant);
    sort($keys, SORT_STRING);
    if ($keys !== ['country', 'is_active', 'old_price', 'price']) {
        throw new ProductVariantIdentityException('Структура вариантов товара изменилась после классификации');
    }
    if (!is_string($variant['country']) || !mb_check_encoding($variant['country'], 'UTF-8')) {
        throw new ProductVariantIdentityException('Страна сборки варианта некорректна');
    }
    $country = preg_replace('/\A\s+|\s+\z/u', '', $variant['country']);
    $controls = is_string($country) ? preg_match('/[\x00-\x1F\x7F]/u', $country) : false;
    if (!is_string($country) || $country === '' || mb_strlen($country, 'UTF-8') > 100 || $controls !== 0) {
        throw new ProductVariantIdentityException('Страна сборки варианта некорректна');
    }
    if (!is_bool($variant['is_active'])) throw new ProductVariantIdentityException('Статус legacy-варианта некорректен');
    $priceMinor = productVariantIdentityMinor($variant['price'], false, $allowZeroPrice);
    $oldPriceMinor = productVariantIdentityMinor($variant['old_price'], true);
    if ($priceMinor === null || $priceMinor > 999999999999) {
        throw new ProductVariantIdentityException('Цена legacy-варианта некорректна');
    }
    if ($variant['old_price'] !== null && ($oldPriceMinor === null || $oldPriceMinor > 999999999999)) {
        throw new ProductVariantIdentityException('Старая цена legacy-варианта некорректна');
    }
    $weightStatement->execute([':value' => $country]);
    $weight = $weightStatement->fetchColumn();
    if (!is_string($weight) || $weight === '') throw new RuntimeException("Unable to calculate collation weight for product $productId");
    return ['country' => $country, 'weight' => $weight, 'price_minor' => $priceMinor,
        'old_price_minor' => $oldPriceMinor, 'is_active' => $variant['is_active']];
}

function productVariantIdentityResolve(PDO $pdo, array $product, array $relationalVariant, bool $allowZeroPrice = false): array
{
    $raw = $product['variants'] ?? null;
    if (!is_string($raw)) throw new ProductVariantIdentityException('Legacy-варианты товара недоступны');
    try { $variants = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new ProductVariantIdentityException('Legacy-варианты товара содержат некорректный JSON'); }
    if (!is_array($variants) || !array_is_list($variants) || count($variants) > 200) {
        throw new ProductVariantIdentityException('Структура legacy-вариантов товара не поддерживается');
    }
    $weightStatement = $pdo->prepare('SELECT HEX(WEIGHT_STRING(CAST(:value AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci))');
    $seen = []; $targets = []; $details = [];
    foreach ($variants as $index => $variant) {
        if (!is_array($variant)) throw new ProductVariantIdentityException('Legacy-вариант товара не является объектом');
        $detail = productVariantIdentityCountry($weightStatement, $variant, (int)$product['id'], $allowZeroPrice);
        if (isset($seen[$detail['weight']])) throw new ProductVariantIdentityException('У товара есть дублирующиеся страны сборки');
        $seen[$detail['weight']] = true;
        $details[$index] = $detail;
        $expectedKey = 'legacy-country-sha256-' . hash('sha256', $detail['country']);
        if ($detail['country'] === $relationalVariant['assembly_country'] && $expectedKey === $relationalVariant['variant_key']) $targets[] = $index;
    }
    if (count($targets) !== 1) throw new ProductVariantIdentityException('Не найдено однозначное соответствие relational и legacy-варианта');
    $target = $targets[0];
    return ['variants' => $variants, 'target_index' => $target, 'target' => $details[$target], 'raw_hash' => hash('sha256', $raw)];
}

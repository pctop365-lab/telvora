<?php

declare(strict_types=1);

require_once __DIR__ . '/product_gallery_service.php';

function priceRuFeedEscape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function priceRuFeedModelCode(array $product, array $variant): ?string
{
    $code = trim((string)($variant['model_code'] ?? ''));
    if ($code === '') {
        $brand = trim((string)($product['brand'] ?? ''));
        $name = trim((string)($product['name'] ?? ''));
        $screen = preg_replace('/[^0-9.]/', '', (string)($product['screen_size'] ?? ''));
        if ($brand === '' || $name === '' || $screen === '') return null;
        $pattern = '/(?:^|\s)' . preg_quote($brand, '/') . '\s+(.+?)\s+' . preg_quote($screen, '/') . '\s*(?:"|″|дюйм(?:а|ов)?)(?:\s|$)/iu';
        if (preg_match($pattern, $name, $match) !== 1) return null;
        $code = trim($match[1]);
    }

    if (preg_match('/\A[\pL\pN]+(?:[ -][\pL\pN]+)*\z/u', $code) !== 1
        || preg_match('/\pL/u', $code) !== 1 || preg_match('/\pN/u', $code) !== 1) return null;
    $slug = strtolower((string)($product['slug'] ?? ''));
    $brandSlug = strtolower((string)preg_replace('/[^a-z0-9]/i', '', (string)($product['brand'] ?? '')));
    $normalizedSlug = (string)preg_replace('/[^a-z0-9]/', '', $slug);
    $normalizedCode = strtolower((string)preg_replace('/[^a-z0-9]/i', '', $code));
    if ($brandSlug === '' || !str_starts_with($normalizedSlug, $brandSlug)
        || substr($normalizedSlug, strlen($brandSlug)) !== $normalizedCode) return null;
    return $code;
}

function priceRuFeedAssemblyCountry(array $variant): ?string
{
    $country = trim((string)($variant['country'] ?? ''));
    $normalized = mb_strtolower($country, 'UTF-8');
    if ($country === '' || in_array($normalized, ['уточняется', 'неизвестно', 'неизвестная страна', 'не указано', 'не указана', 'н/д', 'не применимо'], true)) return null;
    return $country;
}

function priceRuFeedPicture(array $product, ?callable $photoResolver = null): ?string
{
    $path = trim((string)($product['image'] ?? ''));
    if ($path === '') return null;
    if ($photoResolver !== null) {
        $resolved = $photoResolver($path, $product);
        return is_string($resolved) && preg_match('#\Ahttps://[^\s<>"\']+\z#i', $resolved) === 1 ? $resolved : null;
    }
    if (!productGalleryIsManagedPath($path)) return null;
    $metadata = $product['image_variants'][$path] ?? null;
    $root = __DIR__;
    foreach (array_reverse(is_array($metadata) ? ($metadata['sources'] ?? []) : []) as $source) {
        if (!is_array($source) || !in_array($source['type'] ?? '', ['image/jpeg', 'image/png'], true)) continue;
        $src = (string)($source['src'] ?? '');
        if (!str_starts_with($src, $path . '.') || !preg_match('/\.(?:jpg|png)\z/i', $src)) continue;
        if ((int)($source['width'] ?? 0) < 100 && (int)($source['height'] ?? 0) < 100) continue;
        $fullPath = $root . $src;
        $info = is_file($fullPath) && filesize($fullPath) <= 10 * 1024 * 1024 ? @getimagesize($fullPath) : false;
        if ($info === false || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png'], true)
            || ($info[0] < 100 && $info[1] < 100)) continue;
        return 'https://telvora.ru' . $src;
    }
    // Legacy galleries may have an original photo without optimized sidecars.
    // Accept it only when it is a managed, real JPEG/PNG of a useful size.
    $original = __DIR__ . $path;
    $originalInfo = is_file($original) && filesize($original) <= 10 * 1024 * 1024 ? @getimagesize($original) : false;
    if ($originalInfo !== false && in_array($originalInfo['mime'] ?? '', ['image/jpeg', 'image/png'], true)
        && ($originalInfo[0] >= 100 || $originalInfo[1] >= 100)) return 'https://telvora.ru' . $path;
    return null;
}

function priceRuFeedOffers(array $products, ?callable $photoResolver = null): array
{
    $offers = [];
    foreach ($products as $product) {
        if (!is_array($product) || !in_array($product['is_active'] ?? false, [true, 1, '1'], true)) continue;
        $slug = (string)($product['slug'] ?? '');
        $categorySlug = ['OLED' => 'oled', 'QLED' => 'qled', 'LED' => 'led', '8K' => '8k'][$product['category'] ?? ''] ?? null;
        if ($categorySlug === null || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1) continue;
        $picture = priceRuFeedPicture($product, $photoResolver);
        if ($picture === null) continue;
        foreach (($product['storefront_variants'] ?? []) as $variant) {
            if (!is_array($variant) || !in_array($variant['is_active'] ?? false, [true, 1, '1'], true)) continue;
            $availability = $variant['availability'] ?? [];
            if (($availability['status'] ?? null) !== 'in_stock' || ($availability['orderable'] ?? false) !== true) continue;
            $price = $variant['price'] ?? null;
            if ((!is_int($price) && !is_float($price) && !is_string($price)) || !is_numeric($price) || !is_finite((float)$price) || (float)$price <= 0) continue;
            $country = priceRuFeedAssemblyCountry($variant);
            $model = priceRuFeedModelCode($product, $variant);
            $variantId = (int)($variant['product_variant_id'] ?? 0);
            $productId = (int)($product['id'] ?? 0);
            if ($country === null || $model === null || $variantId < 1 || $productId < 1) continue;
            $offers[] = [
                'id' => 'p' . $productId . '-v' . $variantId,
                'name' => trim((string)($product['name'] ?? '')) . ' — ' . $country,
                'description' => trim(strip_tags((string)($product['description'] ?? ''))),
                'url' => 'https://telvora.ru/catalog/' . $categorySlug . '/' . $slug . '?variant=' . $variantId,
                'picture' => $picture,
                'price' => (string)$price,
                'category_id' => 1,
                'vendor' => trim((string)($product['brand'] ?? '')),
                'model' => $model,
                'country' => $country,
            ];
        }
    }
    return $offers;
}

function priceRuFeedXml(array $products, ?DateTimeImmutable $now = null, ?callable $photoResolver = null): string
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow'));
    $lines = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<priceru_feed date="' . $now->format('Y-m-d H:i') . '">',
        '  <shop>',
        '    <company>TELVORA</company>',
        '    <url>https://telvora.ru/</url>',
        '    <currencies><currency id="RUB" rate="1"/></currencies>',
        '    <categories><category id="1" parentId="0">Телевизоры</category></categories>',
        '    <offers>',
    ];
    foreach (priceRuFeedOffers($products, $photoResolver) as $offer) {
        $lines[] = '      <offer id="' . priceRuFeedEscape($offer['id']) . '" available="true">';
        foreach (['name', 'description', 'url', 'picture', 'price'] as $field) {
            $lines[] = '        <' . $field . '>' . priceRuFeedEscape($offer[$field]) . '</' . $field . '>';
        }
        $lines[] = '        <currencyId>RUB</currencyId>';
        $lines[] = '        <categoryId>' . $offer['category_id'] . '</categoryId>';
        $lines[] = '        <typePrefix>Телевизор</typePrefix>';
        $lines[] = '        <vendor>' . priceRuFeedEscape($offer['vendor']) . '</vendor>';
        $lines[] = '        <model>' . priceRuFeedEscape($offer['model']) . '</model>';
        $lines[] = '        <vendorCode>' . priceRuFeedEscape($offer['model']) . '</vendorCode>';
        $lines[] = '        <param name="Страна сборки">' . priceRuFeedEscape($offer['country']) . '</param>';
        $lines[] = '      </offer>';
    }
    $lines[] = '    </offers>';
    $lines[] = '  </shop>';
    $lines[] = '</priceru_feed>';
    return implode("\n", $lines) . "\n";
}

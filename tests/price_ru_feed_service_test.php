<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/price_ru_feed_service.php';

function priceRuAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$base = [
    'id' => 77,
    'slug' => 'lg-oled77c6rla',
    'name' => 'Телевизор LG OLED77C6RLA 77" & экран',
    'brand' => 'LG',
    'category' => 'OLED',
    'screen_size' => '77',
    'description' => 'OLED & 4K <TV>',
    'image' => '/uploads/products/product_0123456789abcdef01234567.jpg',
    'is_active' => true,
    'storefront_variants' => [
        ['product_variant_id' => 301, 'country' => 'Польша & ЕС', 'model_code' => null, 'price' => 123456.78, 'is_active' => true, 'availability' => ['status' => 'in_stock', 'orderable' => true]],
        ['product_variant_id' => 302, 'country' => 'Индонезия', 'model_code' => 'OLED77C6RLA', 'price' => 129990, 'is_active' => true, 'availability' => ['status' => 'in_stock', 'orderable' => true]],
        ['product_variant_id' => 303, 'country' => 'Россия', 'model_code' => 'OLED77C6RLA', 'price' => 99999, 'is_active' => true, 'availability' => ['status' => 'unknown', 'orderable' => false]],
        ['product_variant_id' => 304, 'country' => 'Китай', 'model_code' => 'OLED77C6RLA', 'price' => 99999, 'is_active' => true, 'availability' => ['status' => 'in_stock', 'orderable' => false]],
        ['product_variant_id' => 305, 'country' => 'Корея', 'model_code' => 'OLED77C6RLA', 'price' => 0, 'is_active' => true, 'availability' => ['status' => 'in_stock', 'orderable' => true]],
        ['product_variant_id' => 306, 'country' => 'Япония', 'model_code' => 'OLED77C6RLA', 'price' => 50000, 'is_active' => false, 'availability' => ['status' => 'in_stock', 'orderable' => true]],
        ['product_variant_id' => 307, 'country' => 'Уточняется', 'model_code' => 'OLED77C6RLA', 'price' => 50000, 'is_active' => true, 'availability' => ['status' => 'in_stock', 'orderable' => true]],
    ],
];
$inactiveProduct = $base;
$inactiveProduct['id'] = 78;
$inactiveProduct['is_active'] = false;
$unresolvedModel = $base;
$unresolvedModel['id'] = 79;
$unresolvedModel['slug'] = 'lg-different-model';
$unresolvedModel['storefront_variants'] = [$base['storefront_variants'][0]];
$photoResolver = static fn(string $path): string => $path === $base['image'] ? 'https://telvora.ru/test.jpg' : '';

$offers = priceRuFeedOffers([$base, $inactiveProduct, $unresolvedModel], $photoResolver);
priceRuAssert(count($offers) === 2, 'only two active, priced, orderable, identifiable variants with a real photo fixture should remain: ' . var_export($offers, true));
priceRuAssert($offers[0]['id'] === 'p77-v301' && $offers[0]['model'] === 'OLED77C6RLA', 'offer identity and exact model parsed from product name');
priceRuAssert($offers[0]['price'] === '123456.78', 'effective storefront price is preserved without rounding');
priceRuAssert(str_contains($offers[0]['url'], '?variant=301'), 'offer URL selects its exact variant');
priceRuAssert($offers[1]['model'] === 'OLED77C6RLA' && $offers[1]['country'] === 'Индонезия', 'relational model code and assembly country are preserved');

$xml = priceRuFeedXml([$base], new DateTimeImmutable('2026-10-10 12:34:00+03:00'), $photoResolver);
priceRuAssert(str_starts_with($xml, '<?xml version="1.0" encoding="UTF-8"?>'), 'UTF-8 XML declaration is first');
priceRuAssert(str_contains($xml, '<priceru_feed date="2026-10-10 12:34">'), 'Price.ru feed root and timestamp');
priceRuAssert(str_contains($xml, 'Телевизор LG OLED77C6RLA 77&quot; &amp; экран'), 'offer name is XML-escaped');
priceRuAssert(str_contains($xml, 'OLED &amp; 4K') && !str_contains($xml, '&lt;TV&gt;'), 'description text is escaped and HTML markup is not emitted');
priceRuAssert(str_contains($xml, 'Польша &amp; ЕС'), 'XML attribute parameter values are escaped');
priceRuAssert(str_contains($xml, '<currencyId>RUB</currencyId>'), 'RUB price currency');
priceRuAssert(str_contains($xml, 'available="true"'), 'only confirmed orderable stock is advertised as available');
priceRuAssert(substr_count($xml, '<offer ') === 2, 'XML contains one offer per qualifying variant');
priceRuAssert(function_exists('simplexml_load_string') || class_exists(DOMDocument::class), 'PHP XML parser extension is required for the format test');
if (function_exists('simplexml_load_string')) {
    libxml_use_internal_errors(true);
    $parsed = simplexml_load_string($xml);
    priceRuAssert($parsed !== false && $parsed->getName() === 'priceru_feed', 'generated feed is well-formed XML');
} else {
    $document = new DOMDocument();
    priceRuAssert($document->loadXML($xml) && $document->documentElement?->tagName === 'priceru_feed', 'generated feed is well-formed XML');
}

echo "PASS Price.ru feed filters, exact variant identity, XML escaping and well-formed output\n";

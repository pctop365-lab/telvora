<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/product_variant_price_service.php';

function priceTest(string $name, bool $condition): void
{
    if (!$condition) throw new RuntimeException("FAIL $name");
    echo "PASS $name\n";
}

priceTest('integer manual price', productVariantPriceMinor(271400) === 27140000);
priceTest('decimal manual price', productVariantPriceMinor('271400.25') === 27140025);
foreach ([null, '', 0, -1, '01', '1.234', 'NaN', 'INF', true, '10000000000'] as $invalid) {
    priceTest('invalid manual price ' . var_export($invalid, true), productVariantPriceMinor($invalid) === null);
}
priceTest('optional old price accepts null', productVariantPriceMinor(null, true) === null);

$legacy = ['price_minor'=>19000000, 'price'=>190000, 'old_price'=>180000];
$automatic = productVariantPriceEffective($legacy, null);
priceTest('automatic source uses Stage9 legacy price', $automatic['price_source']==='automatic' && $automatic['price']===190000);
$manual = productVariantPriceEffective($legacy, ['is_active'=>1,'manual_price'=>'175000.00','manual_old_price'=>'185000.00']);
priceTest('manual source overlays retail price', $manual['price_source']==='manual' && $manual['price']===175000 && $manual['old_price']===185000);
$disabled = productVariantPriceEffective($legacy, ['is_active'=>0,'manual_price'=>'175000.00','manual_old_price'=>null]);
priceTest('disabled override returns latest automatic price', $disabled['price_source']==='automatic' && $disabled['price']===190000);

$zeroLegacy = ['price_minor'=>0, 'price'=>0, 'old_price'=>null];

$manualOverZero = productVariantPriceEffective(
    $zeroLegacy,
    ['is_active'=>1, 'manual_price'=>'250000.00', 'manual_old_price'=>null]
);

priceTest(
    'manual override supplies price when legacy price is zero',
    $manualOverZero['price_source']==='manual' &&
    $manualOverZero['price_minor']===25000000 &&
    $manualOverZero['price']===250000
);

$automaticZero = productVariantPriceEffective($zeroLegacy, null);

priceTest(
    'zero legacy price stays zero without manual override',
    $automaticZero['price_source']==='automatic' &&
    $automaticZero['price_minor']===0 &&
    $automaticZero['price']===0
);

echo "PASS product variant price service fixtures\n";

<?php

declare(strict_types=1);

define('TELVORA_MANAGER_REQUEST', true);

require_once dirname(__DIR__) . '/supplier_import_preview.php';
require_once dirname(__DIR__) . '/supplier_import_stage.php';
require_once dirname(__DIR__) . '/supplier_offer_service.php';

function normalizationAssert(string $name, bool $condition, mixed $detail = null): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL ' . $name . ': ' . json_encode($detail, JSON_UNESCAPED_UNICODE));
    }
    echo "PASS {$name}\n";
}

$profile = [
    'supplier_code' => 'aleksey_europa',
    'arrival_date_format' => null,
    'mapping' => [
        'supplier_sku' => ['index' => 0, 'column' => 'Артикул'],
        'model' => ['index' => 1, 'column' => 'Модель'],
        'product_name' => ['index' => 2, 'column' => 'Наименование'],
        'purchase_price' => ['index' => 3, 'column' => 'Цена'],
        'availability' => ['index' => 4, 'column' => 'Наличие'],
        'arrival_info' => ['index' => 5, 'column' => 'Поставка'],
        'currency_code' => ['index' => 6, 'column' => 'Валюта']
    ],
    'options' => [
        'trim_values' => true,
        'decimal_separator' => '.',
        'default_currency_code' => 'RUB'
    ]
];

function normalizationRow(array $profile, array $values, array $warnings = []): array
{
    return supplierPreviewBuildRow(1, $values, $profile, $warnings);
}

function normalizationStage(array $row, array $profile): array
{
    return supplierStagePrepareRow($row, $profile);
}

$inStock = normalizationRow($profile, [
    'supplier_sku' => '1154770', 'model' => '77C5RLA',
    'product_name' => 'LG OLED77C5RLA', 'purchase_price' => '230000',
    'availability' => '', 'arrival_info' => '', 'currency_code' => 'RUB'
]);
normalizationAssert('blank availability maps to in_stock', $inStock['availability_normalization']['status'] === 'in_stock');
normalizationAssert('first product is valid', $inStock['errors'] === [] && $inStock['_skip_reason'] === null);
normalizationAssert('raw blank availability is preserved', $inStock['values']['availability'] === '');

$secondInStock = normalizationRow($profile, [
    'supplier_sku' => '1155145', 'model' => 'OLED77C5',
    'product_name' => 'LG OLED 77C5', 'purchase_price' => '215687.5',
    'availability' => '  ', 'arrival_info' => '', 'currency_code' => 'RUB'
]);
normalizationAssert('whitespace availability maps to in_stock', $secondInStock['availability_normalization']['status'] === 'in_stock');
normalizationAssert('decimal cached price remains valid', $secondInStock['normalized']['purchase_price'] === '215687.5');

$incoming = normalizationRow($profile, [
    'supplier_sku' => '', 'model' => '55G5LS',
    'product_name' => 'LG OLED 55G5LS', 'purchase_price' => '0',
    'availability' => ' NEW ', 'arrival_info' => '22-23.09', 'currency_code' => 'RUB'
]);
normalizationAssert('NEW maps to expected', $incoming['availability_normalization']['status'] === 'expected');
normalizationAssert('partial arrival is preserved as raw text', $incoming['values']['arrival_info'] === '22-23.09');
normalizationAssert('partial arrival is not fabricated as a date', $incoming['availability_normalization']['expected_arrival_at'] === null);
normalizationAssert('incoming arrival warning is informational', in_array('arrival_info_preserved', array_column($incoming['availability_normalization']['warnings'], 'code'), true));
$incomingStage = normalizationStage($incoming, $profile);
normalizationAssert('incoming staged status is expected', $incomingStage['normalized_availability'] === 'expected');
normalizationAssert('zero incoming price is retained for history but not valid offer basis', supplierOfferMinorUnits('0') === null && supplierOfferMinorUnits('0', true) === 0);

$outOfStock = normalizationRow($profile, [
    'supplier_sku' => '', 'model' => '55G5LW',
    'product_name' => 'LG OLED 55G5LW', 'purchase_price' => '0',
    'availability' => ' ЗАКОНЧИЛИСЬ ', 'arrival_info' => '', 'currency_code' => 'RUB'
]);
normalizationAssert('out-of-stock maps correctly', $outOfStock['availability_normalization']['status'] === 'out_of_stock');
normalizationAssert('zero out-of-stock row is not a validation error', $outOfStock['errors'] === []);

foreach (['—', '–', '−', '-'] as $dash) {
    $dashRow = normalizationRow($profile, [
        'supplier_sku' => '', 'model' => '42C6RLA', 'product_name' => '42C6RLA',
        'purchase_price' => '100000', 'availability' => $dash, 'arrival_info' => '', 'currency_code' => 'RUB'
    ]);
    normalizationAssert('dash availability maps to in_stock: ' . bin2hex($dash), $dashRow['availability_normalization']['status'] === 'in_stock');
}

$formulaWarning = normalizationRow($profile, [
    'supplier_sku' => '1154770', 'model' => '77C5RLA', 'product_name' => 'LG OLED77C5RLA',
    'purchase_price' => '230000', 'availability' => '', 'arrival_info' => '', 'currency_code' => 'RUB'
], ['purchase_price: использовано сохранённое значение формулы']);
normalizationAssert('cached formula warning remains informational', $formulaWarning['errors'] === [] && count($formulaWarning['warnings']) === 1);

$sectionRows = [
    normalizationRow($profile, ['supplier_sku' => '', 'model' => '', 'product_name' => 'ТЕЛЕВИЗОРЫ LG', 'purchase_price' => 'РУБЛИ', 'availability' => '', 'arrival_info' => '', 'currency_code' => '']),
    normalizationRow($profile, ['supplier_sku' => '', 'model' => '', 'product_name' => 'РСТ', 'purchase_price' => '', 'availability' => '', 'arrival_info' => '', 'currency_code' => '']),
    normalizationRow($profile, ['supplier_sku' => '', 'model' => '', 'product_name' => 'NEW LG 2025', 'purchase_price' => '', 'availability' => '', 'arrival_info' => '', 'currency_code' => '']),
    normalizationRow($profile, ['supplier_sku' => '', 'model' => '', 'product_name' => 'PHILIPS', 'purchase_price' => '', 'availability' => '', 'arrival_info' => '', 'currency_code' => '']),
    normalizationRow($profile, ['supplier_sku' => '', 'model' => '', 'product_name' => 'SAMSUNG 2025', 'purchase_price' => '', 'availability' => '', 'arrival_info' => '', 'currency_code' => ''])
];
normalizationAssert('television section is classified as header', $sectionRows[0]['_skip_reason'] === 'section_header');
normalizationAssert('РСТ section is classified as header', $sectionRows[1]['_skip_reason'] === 'section_header');
normalizationAssert('NEW LG section is classified as header', $sectionRows[2]['_skip_reason'] === 'section_header');
normalizationAssert('PHILIPS section is classified as header', $sectionRows[3]['_skip_reason'] === 'section_header');
normalizationAssert('SAMSUNG section is classified as header', $sectionRows[4]['_skip_reason'] === 'section_header');

$realEmptySku = normalizationRow($profile, [
    'supplier_sku' => '', 'model' => '42C6RLA', 'product_name' => '42C6RLA',
    'purchase_price' => '100000', 'availability' => '', 'arrival_info' => '', 'currency_code' => 'RUB'
]);
normalizationAssert('valid model without supplier SKU is retained', $realEmptySku['_skip_reason'] === null && $realEmptySku['errors'] === []);

$accumulator = supplierPreviewAccumulator(null, 10);
normalizationAssert('preview accumulator receives callback then capture limit', is_array($accumulator) && $accumulator['_captured_row_limit'] === 10);
foreach (array_merge($sectionRows, [$realEmptySku]) as $row) {
    supplierPreviewAccumulateRow($accumulator, $row, true);
}
normalizationAssert('skipped headers are separate from import errors', $accumulator['rows_skipped_headers'] === 5 && $accumulator['rows_with_errors'] === 0);
normalizationAssert('valid row remains in preview rows', count($accumulator['rows']) === 1 && ($accumulator['rows'][0]['_skip_reason'] ?? null) === null);
normalizationAssert('skipped rows are available for preview explanation', count($accumulator['skipped_rows']) === 5);

echo "Supplier import normalization tests PASS\n";

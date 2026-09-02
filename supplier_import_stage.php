<?php

declare(strict_types=1);

if (!defined('TELVORA_MANAGER_REQUEST')) {
    http_response_code(404);
    exit;
}

function supplierStageNormalizeModel(string $value): ?string
{
    $value = trim($value);
    $value = preg_replace('/[\x20\t]+/u', ' ', $value);
    if (!is_string($value) || $value === '') {
        return null;
    }
    return mb_strtolower($value, 'UTF-8');
}

function supplierStageBoundedValue(
    mixed $value,
    int $maxLength,
    string $label,
    array &$errors
): ?string {
    if ($value === null) {
        return null;
    }
    $value = (string)$value;
    if ($value === '') {
        return null;
    }
    if (mb_strlen($value, 'UTF-8') > $maxLength) {
        $errors[] = $label . ': значение превышает допустимую длину staging-поля';
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return $value;
}

function supplierStageReviewReason(array $errors, array $warnings): ?string
{
    if ($errors === [] && $warnings === []) {
        return null;
    }
    $payload = [
        'errors' => array_map(
            static fn(mixed $value): string => mb_substr((string)$value, 0, 240, 'UTF-8'),
            array_slice(array_values(array_unique($errors)), 0, 4)
        ),
        'warnings' => array_map(
            static fn(mixed $value): string => mb_substr((string)$value, 0, 240, 'UTF-8'),
            array_slice(array_values(array_unique($warnings)), 0, 4)
        )
    ];

    do {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            return '{"errors":["Не удалось сохранить детали проверки"],"warnings":[]}';
        }
        if (mb_strlen($encoded, 'UTF-8') <= 1000) {
            return $encoded;
        }
        if ($payload['warnings'] !== []) {
            array_pop($payload['warnings']);
        } elseif (count($payload['errors']) > 1) {
            array_pop($payload['errors']);
        } else {
            $payload['errors'][0] = mb_substr($payload['errors'][0], 0, 700, 'UTF-8');
        }
    } while (true);
}

function supplierStagePrepareRow(array $row): array
{
    $values = is_array($row['values'] ?? null) ? $row['values'] : [];
    $normalized = is_array($row['normalized'] ?? null) ? $row['normalized'] : [];
    $errors = is_array($row['errors'] ?? null) ? $row['errors'] : [];
    $warnings = is_array($row['warnings'] ?? null) ? $row['warnings'] : [];

    $supplierSku = supplierStageBoundedValue(
        $values['supplier_sku'] ?? null, 191, 'supplier_sku', $errors
    );
    $rawProductName = supplierStageBoundedValue(
        $values['product_name'] ?? null, 500, 'product_name', $errors
    );
    $normalizedProductName = $rawProductName === null
        ? null
        : supplierStageBoundedValue(
            mb_strtolower(trim($rawProductName), 'UTF-8'),
            500,
            'product_name',
            $errors
        );
    $normalizedModel = supplierStageNormalizeModel((string)($values['model'] ?? ''));
    $normalizedModel = supplierStageBoundedValue(
        $normalizedModel, 255, 'model', $errors
    );

    if ($supplierSku === null && $normalizedModel === null) {
        $errors[] = 'Не указан ни артикул поставщика, ни модель';
    }

    $purchasePrice = $normalized['purchase_price'] ?? null;
    if (
        $purchasePrice !== null &&
        (!is_string($purchasePrice) ||
            preg_match('/\A\d{1,13}(?:\.\d{1,2})?\z/D', $purchasePrice) !== 1)
    ) {
        $errors[] = 'Цена не помещается в точность staging-поля';
        $purchasePrice = null;
    }

    $currencyCode = $normalized['currency_code'] ?? null;
    if (!is_string($currencyCode) || preg_match('/\A[A-Z]{3}\z/D', $currencyCode) !== 1) {
        if ($currencyCode !== null && $currencyCode !== '') {
            $errors[] = 'Некорректный код валюты';
        }
        $currencyCode = null;
    }

    return [
        'source_row_number' => (int)$row['source_row_number'],
        'supplier_sku' => $supplierSku,
        'raw_product_name' => $rawProductName,
        'normalized_product_name' => $normalizedProductName,
        'normalized_model' => $normalizedModel,
        'purchase_price' => $purchasePrice,
        'currency_code' => $currencyCode,
        'raw_availability' => supplierStageBoundedValue(
            $values['availability'] ?? null, 255, 'availability', $errors
        ),
        'raw_arrival_info' => supplierStageBoundedValue(
            $values['arrival_info'] ?? null, 255, 'arrival_info', $errors
        ),
        'detected_assembly_country' => supplierStageBoundedValue(
            $values['assembly_country'] ?? null, 100, 'assembly_country', $errors
        ),
        'detected_market_region' => supplierStageBoundedValue(
            $values['market_region'] ?? null, 255, 'market_region', $errors
        ),
        'detected_certification_supply_type' => supplierStageBoundedValue(
            $values['certification_supply_type'] ?? null,
            255,
            'certification_supply_type',
            $errors
        ),
        'errors' => array_values(array_unique($errors)),
        'warnings' => array_values(array_unique($warnings))
    ];
}

function supplierStageExistingSkuMatches(PDO $pdo, int $supplierId, array $rows): array
{
    $skus = [];
    foreach ($rows as $row) {
        if ($row['supplier_sku'] !== null && $row['errors'] === []) {
            $skus[$row['supplier_sku']] = true;
        }
    }
    $skus = array_keys($skus);
    if ($skus === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($skus), '?'));
    $stmt = $pdo->prepare("
        SELECT m.id, m.supplier_sku, m.product_id, m.product_variant_id,
               p.id AS existing_product_id,
               pv.id AS existing_variant_id, pv.product_id AS variant_product_id
        FROM supplier_product_matches m
        LEFT JOIN products p ON p.id = m.product_id
        LEFT JOIN product_variants pv ON pv.id = m.product_variant_id
        WHERE m.supplier_id = ? AND m.is_active = 1
          AND BINARY m.supplier_sku IN ($placeholders)
    ");
    $stmt->execute(array_merge([$supplierId], $skus));

    $matches = [];
    foreach ($stmt->fetchAll() as $match) {
        $sku = (string)$match['supplier_sku'];
        $productId = $match['existing_product_id'] === null
            ? null : (int)$match['product_id'];
        $variantId = $match['existing_variant_id'] === null
            ? null : (int)$match['product_variant_id'];
        $variantProductId = $match['variant_product_id'] === null
            ? null : (int)$match['variant_product_id'];
        if ($variantId !== null && $variantProductId !== $productId) {
            continue;
        }
        $matches[$sku] = [
            'match_id' => (int)$match['id'],
            'product_id' => $productId,
            'variant_id' => $variantId
        ];
    }
    return $matches;
}

function supplierStageInsertChunk(
    PDO $pdo,
    int $jobId,
    int $supplierId,
    array $rows,
    array &$counters
): void {
    if ($rows === []) {
        return;
    }
    $matches = supplierStageExistingSkuMatches($pdo, $supplierId, $rows);
    $columns = [
        'import_job_id', 'source_row_number', 'supplier_sku',
        'raw_product_name', 'normalized_product_name', 'normalized_model',
        'purchase_price', 'currency_code', 'raw_availability',
        'normalized_availability', 'raw_arrival_info',
        'detected_assembly_country', 'detected_market_region',
        'detected_certification_supply_type', 'variant_detection_evidence',
        'matched_product_id', 'matched_product_variant_id', 'match_id',
        'status', 'review_reason'
    ];
    $rowPlaceholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
    $sql = 'INSERT INTO supplier_import_rows (' . implode(',', $columns) . ') VALUES ' .
        implode(',', array_fill(0, count($rows), $rowPlaceholder));
    $parameters = [];

    foreach ($rows as $row) {
        $match = $row['supplier_sku'] === null
            ? null : ($matches[$row['supplier_sku']] ?? null);
        if ($row['errors'] !== []) {
            $status = 'validation_error';
            $match = null;
            $counters['errors']++;
        } elseif ($match === null || $match['product_id'] === null) {
            $status = 'unmatched';
            $match = null;
            $counters['unmatched']++;
        } elseif ($match['variant_id'] === null) {
            $status = 'needs_review';
            $counters['unmatched']++;
            $row['warnings'][] = 'Связь определяет товар, но не конкретный вариант';
        } else {
            $status = 'matched';
            $counters['matched']++;
        }
        $counters['total']++;

        array_push(
            $parameters,
            $jobId,
            $row['source_row_number'],
            $row['supplier_sku'],
            $row['raw_product_name'],
            $row['normalized_product_name'],
            $row['normalized_model'],
            $row['purchase_price'],
            $row['currency_code'],
            $row['raw_availability'],
            null,
            $row['raw_arrival_info'],
            $row['detected_assembly_country'],
            $row['detected_market_region'],
            $row['detected_certification_supply_type'],
            null,
            $match['product_id'] ?? null,
            $match['variant_id'] ?? null,
            $match['match_id'] ?? null,
            $status,
            supplierStageReviewReason($row['errors'], $row['warnings'])
        );
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parameters);
}

function supplierStageDecodeReviewReason(?string $reviewReason): array
{
    if ($reviewReason === null || $reviewReason === '') {
        return ['errors' => [], 'warnings' => []];
    }
    $decoded = json_decode($reviewReason, true);
    if (!is_array($decoded)) {
        return ['errors' => [$reviewReason], 'warnings' => []];
    }
    return [
        'errors' => is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [],
        'warnings' => is_array($decoded['warnings'] ?? null) ? $decoded['warnings'] : []
    ];
}

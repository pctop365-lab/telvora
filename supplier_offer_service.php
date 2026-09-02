<?php

declare(strict_types=1);

if (!defined('TELVORA_MANAGER_REQUEST')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/supplier_availability_service.php';

function supplierOfferMinorUnits(mixed $value, bool $allowZero = false): ?int
{
    if (!is_string($value) && !is_int($value)) {
        return null;
    }
    $value = (string)$value;
    if (preg_match('/\A(\d{1,13})(?:\.(\d{1,2}))?\z/D', $value, $matches) !== 1) {
        return null;
    }
    $minor = ((int)$matches[1] * 100) + (int)str_pad($matches[2] ?? '', 2, '0');
    return ($allowZero || $minor > 0) ? $minor : null;
}

function supplierOfferMoney(int $minor): string
{
    return intdiv($minor, 100) . '.' . str_pad((string)($minor % 100), 2, '0', STR_PAD_LEFT);
}

function supplierOfferDeliveryInfo(?string $availability, ?string $arrival): ?string
{
    $parts = [];
    if (is_string($availability) && trim($availability) !== '') {
        $parts[] = 'Наличие: ' . trim($availability);
    }
    if (is_string($arrival) && trim($arrival) !== '') {
        $parts[] = 'Поступление: ' . trim($arrival);
    }
    if ($parts === []) {
        return null;
    }
    return mb_substr(implode('; ', $parts), 0, 500, 'UTF-8');
}

function supplierOfferPublishAnalysis(PDO $pdo, int $jobId, bool $publish): array
{
    $jobSql = "
        SELECT j.id, j.supplier_id, j.import_profile_id, j.status, j.created_at,
               p.arrival_date_format, s.id AS existing_supplier_id
        FROM supplier_import_jobs j
        INNER JOIN suppliers s ON s.id = j.supplier_id
        LEFT JOIN supplier_import_profiles p ON p.id = j.import_profile_id
        WHERE j.id = :id LIMIT 1" . ($publish ? ' FOR UPDATE' : '');
    $jobStmt = $pdo->prepare($jobSql);
    $jobStmt->execute([':id' => $jobId]);
    $job = $jobStmt->fetch();
    if (!is_array($job)) {
        return ['found' => false];
    }
    $rowCountStmt = $pdo->prepare('SELECT COUNT(*) FROM supplier_import_rows WHERE import_job_id = :job_id');
    $rowCountStmt->execute([':job_id' => $jobId]);
    if ((int)$rowCountStmt->fetchColumn() > 50000) {
        throw new RuntimeException('supplier offer publish row limit exceeded');
    }
    $availabilityMappings = $job['import_profile_id'] === null
        ? []
        : supplierAvailabilityLoadMappings($pdo, (int)$job['import_profile_id'], $publish);
    $availabilityProfile = ['arrival_date_format' => $job['arrival_date_format']];

    $duplicateStmt = $pdo->prepare("
        SELECT r.id
        FROM supplier_import_rows r
        INNER JOIN (
            SELECT supplier_sku
            FROM supplier_import_rows
            WHERE import_job_id = :group_job_id AND supplier_sku IS NOT NULL
            GROUP BY supplier_sku HAVING COUNT(*) > 1
        ) duplicate_skus ON duplicate_skus.supplier_sku = r.supplier_sku
        WHERE r.import_job_id = :row_job_id
    ");
    $duplicateStmt->execute([
        ':group_job_id' => $jobId,
        ':row_job_id' => $jobId
    ]);
    $duplicateRowIds = [];
    foreach ($duplicateStmt->fetchAll(PDO::FETCH_COLUMN) as $duplicateRowId) {
        $duplicateRowIds[(int)$duplicateRowId] = true;
    }

    $summary = [
        'total_rows' => 0,
        'eligible_rows' => 0,
        'offers_to_create' => 0,
        'offers_to_update' => 0,
        'skipped_errors' => 0,
        'skipped_unmatched' => 0,
        'skipped_no_variant' => 0,
        'skipped_invalid_price' => 0,
        'skipped_invalid_currency' => 0,
        'skipped_missing_sku' => 0,
        'skipped_duplicate_sku' => 0,
        'skipped_missing_name' => 0,
        'skipped_offer_conflict' => 0,
        'skipped_stale_source' => 0,
        'skipped_unknown_source' => 0
    ];
    $lastRowId = 0;
    do {
        $rowsSql = "
            SELECT r.id, r.status, r.supplier_sku, r.raw_product_name,
                   r.purchase_price, r.currency_code, r.raw_availability,
                   r.raw_arrival_info, r.matched_product_id,
                   r.matched_product_variant_id, pv.id AS existing_variant_id,
                   pv.product_id AS variant_product_id,
                   p.id AS existing_product_id, o.id AS existing_offer_id,
                   o.product_variant_id AS offer_variant_id,
                   source_row.id AS existing_source_row_id,
                   source_job.id AS existing_source_job_id,
                   source_job.supplier_id AS existing_source_supplier_id,
                   source_job.created_at AS existing_source_job_created_at
            FROM supplier_import_rows r
            LEFT JOIN products p ON p.id = r.matched_product_id
            LEFT JOIN product_variants pv ON pv.id = r.matched_product_variant_id
            LEFT JOIN supplier_offers o
              ON o.supplier_id = :supplier_id AND o.supplier_sku = r.supplier_sku
            LEFT JOIN supplier_import_rows source_row
              ON source_row.id = o.source_import_row_id
            LEFT JOIN supplier_import_jobs source_job
              ON source_job.id = source_row.import_job_id
            WHERE r.import_job_id = :job_id AND r.id > :last_id
            ORDER BY r.id ASC LIMIT 500
        " . ($publish ? ' FOR UPDATE' : '');
        $rowsStmt = $pdo->prepare($rowsSql);
        $rowsStmt->execute([
            ':supplier_id' => (int)$job['supplier_id'],
            ':job_id' => $jobId,
            ':last_id' => $lastRowId
        ]);
        $rows = $rowsStmt->fetchAll();
        $publishRows = [];
        foreach ($rows as $row) {
            $lastRowId = (int)$row['id'];
            $summary['total_rows']++;
            if ($row['status'] === 'validation_error') {
                $summary['skipped_errors']++;
                continue;
            }
            if ($row['status'] !== 'matched') {
                if ($row['status'] === 'needs_review' && $row['matched_product_variant_id'] === null) {
                    $summary['skipped_no_variant']++;
                } else {
                    $summary['skipped_unmatched']++;
                }
                continue;
            }
            $productId = $row['existing_product_id'] === null ? null : (int)$row['matched_product_id'];
            $variantId = $row['existing_variant_id'] === null ? null : (int)$row['matched_product_variant_id'];
            if ($productId === null || $variantId === null || (int)$row['variant_product_id'] !== $productId) {
                $summary['skipped_no_variant']++;
                continue;
            }
            $priceMinor = supplierOfferMinorUnits($row['purchase_price']);
            if ($priceMinor === null) {
                $summary['skipped_invalid_price']++;
                continue;
            }
            $currency = is_string($row['currency_code']) ? strtoupper(trim($row['currency_code'])) : '';
            if (preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
                $summary['skipped_invalid_currency']++;
                continue;
            }
            $sku = is_string($row['supplier_sku']) ? $row['supplier_sku'] : '';
            if (trim($sku) === '') {
                $summary['skipped_missing_sku']++;
                continue;
            }
            if (isset($duplicateRowIds[(int)$row['id']])) {
                $summary['skipped_duplicate_sku']++;
                continue;
            }
            $productName = is_string($row['raw_product_name']) ? trim($row['raw_product_name']) : '';
            if ($productName === '') {
                $summary['skipped_missing_name']++;
                continue;
            }
            if ($row['existing_offer_id'] !== null && (int)$row['offer_variant_id'] !== $variantId) {
                $summary['skipped_offer_conflict']++;
                continue;
            }
            if ($row['existing_offer_id'] !== null) {
                if (
                    $row['existing_source_row_id'] === null ||
                    $row['existing_source_job_id'] === null ||
                    $row['existing_source_job_created_at'] === null ||
                    (int)$row['existing_source_supplier_id'] !== (int)$job['supplier_id']
                ) {
                    $summary['skipped_unknown_source']++;
                    continue;
                }
                $sourceJobId = (int)$row['existing_source_job_id'];
                if ($sourceJobId !== $jobId) {
                    $createdAtComparison = strcmp(
                        (string)$row['existing_source_job_created_at'],
                        (string)$job['created_at']
                    );
                    if ($createdAtComparison > 0 || ($createdAtComparison === 0 && $sourceJobId > $jobId)) {
                        $summary['skipped_stale_source']++;
                        continue;
                    }
                }
            }
            $summary['eligible_rows']++;
            if ($row['existing_offer_id'] === null) {
                $summary['offers_to_create']++;
            } else {
                $summary['offers_to_update']++;
            }
            if ($publish) {
                $availability = normalizeSupplierAvailability(
                    $availabilityProfile,
                    $row['raw_availability'],
                    $row['raw_arrival_info'],
                    null,
                    $availabilityMappings
                );
                $publishRows[] = [
                    (int)$job['supplier_id'], $variantId, $sku,
                    mb_substr($productName, 0, 500, 'UTF-8'),
                    supplierOfferMoney($priceMinor), $currency, $availability['status'],
                    $availability['stock_quantity'], $availability['expected_arrival_at'],
                    supplierOfferDeliveryInfo($row['raw_availability'], $row['raw_arrival_info']),
                    (int)$row['id']
                ];
            }
        }

        if ($publishRows !== []) {
            $rowSql = '(' . implode(',', array_fill(0, 11, '?')) . ')';
            $sql = "INSERT INTO supplier_offers (
                        supplier_id, product_variant_id, supplier_sku,
                        supplier_product_name, purchase_price, currency_code,
                        availability_status, stock_quantity, expected_arrival_at,
                        delivery_info, source_import_row_id
                    ) VALUES " . implode(',', array_fill(0, count($publishRows), $rowSql)) . "
                    ON DUPLICATE KEY UPDATE
                        supplier_product_name = VALUES(supplier_product_name),
                        purchase_price = VALUES(purchase_price),
                        currency_code = VALUES(currency_code),
                        availability_status = VALUES(availability_status),
                        stock_quantity = VALUES(stock_quantity),
                        expected_arrival_at = VALUES(expected_arrival_at),
                        delivery_info = VALUES(delivery_info),
                        source_import_row_id = VALUES(source_import_row_id),
                        source_updated_at = NULL,
                        imported_at = CURRENT_TIMESTAMP,
                        is_active = 1";
            $parameters = [];
            foreach ($publishRows as $publishRow) {
                array_push($parameters, ...$publishRow);
            }
            $insertStmt = $pdo->prepare($sql);
            $insertStmt->execute($parameters);
        }
    } while (count($rows) === 500);

    return [
        'found' => true,
        'job_id' => $jobId,
        'supplier_id' => (int)$job['supplier_id'],
        'job_status' => (string)$job['status'],
        'summary' => $summary
    ];
}

function supplierPricingDecimalScaled(string $value, int $scale): ?int
{
    if (preg_match('/\A(\d+)(?:\.(\d+))?\z/D', $value, $matches) !== 1) {
        return null;
    }
    $fraction = substr(str_pad($matches[2] ?? '', $scale, '0'), 0, $scale);
    $whole = (int)$matches[1];
    $factor = 10 ** $scale;
    if ($whole > intdiv(PHP_INT_MAX - (int)$fraction, $factor)) {
        return null;
    }
    return ($whole * $factor) + (int)$fraction;
}

function supplierPricingApplicableRules(array $offer, array $rules): array
{
    $offerMinor = supplierOfferMinorUnits($offer['purchase_price'] ?? null);
    if ($offerMinor === null) {
        return [];
    }
    return array_values(array_filter(
        $rules,
        static function (array $rule) use ($offer, $offerMinor): bool {
            if ($rule['category_scope'] !== null && $rule['category_scope'] !== ($offer['category'] ?? null)) {
                return false;
            }
            $minimum = $rule['purchase_price_min'] === null
                ? null : supplierOfferMinorUnits((string)$rule['purchase_price_min'], true);
            $maximum = $rule['purchase_price_max'] === null
                ? null : supplierOfferMinorUnits((string)$rule['purchase_price_max'], true);
            if (
                ($rule['purchase_price_min'] !== null && $minimum === null) ||
                ($rule['purchase_price_max'] !== null && $maximum === null)
            ) {
                return false;
            }
            return ($minimum === null || $offerMinor >= $minimum) &&
                ($maximum === null || $offerMinor <= $maximum);
        }
    ));
}

function supplierPricingCalculate(array $offer, array $rules): array
{
    $warnings = [];
    if (($offer['currency_code'] ?? '') !== 'RUB') {
        return ['calculable' => false, 'rule' => null, 'warnings' => ['FX-конвертация не настроена; расчёт доступен только для RUB']];
    }
    if ($rules === []) {
        return ['calculable' => false, 'rule' => null, 'warnings' => ['Подходящее активное правило ценообразования не найдено']];
    }
    $bestPriority = (int)$rules[0]['priority'];
    $bestSpecificity = $rules[0]['category_scope'] === null ? 0 : 1;
    $sameRank = array_filter($rules, static fn(array $rule): bool =>
        (int)$rule['priority'] === $bestPriority &&
        ($rule['category_scope'] === null ? 0 : 1) === $bestSpecificity
    );
    if (count($sameRank) > 1) {
        return ['calculable' => false, 'rule' => null, 'warnings' => ['Найдено несколько правил с одинаковым приоритетом и scope']];
    }
    $rule = $rules[0];
    $rounding = trim((string)($rule['rounding_strategy'] ?? ''));
    if ($rounding !== '' && $rounding !== 'none') {
        return ['calculable' => false, 'rule' => $rule, 'warnings' => ['Стратегия округления не имеет подтверждённой семантики']];
    }
    if (($rule['rounding_parameters'] ?? null) !== null && $rule['rounding_parameters'] !== '') {
        return ['calculable' => false, 'rule' => $rule, 'warnings' => ['Параметры округления не поддерживаются без определённой семантики']];
    }
    $purchaseMinor = supplierOfferMinorUnits($offer['purchase_price']);
    $markupScaled = $rule['markup_percent'] === null
        ? 0 : supplierPricingDecimalScaled((string)$rule['markup_percent'], 4);
    $minimumMargin = $rule['minimum_margin'] === null
        ? 0 : supplierOfferMinorUnits((string)$rule['minimum_margin'], true);
    if ($purchaseMinor === null || $markupScaled === null || $minimumMargin === null) {
        return ['calculable' => false, 'rule' => $rule, 'warnings' => ['Денежные параметры правила выходят за безопасные пределы']];
    }
    $factor = 1000000 + $markupScaled;
    if ($factor <= 0 || $purchaseMinor > intdiv(PHP_INT_MAX - 999999, $factor)) {
        return ['calculable' => false, 'rule' => $rule, 'warnings' => ['Расчёт превышает безопасный целочисленный диапазон']];
    }
    $numerator = $purchaseMinor * $factor;
    $markupCandidate = intdiv($numerator + 999999, 1000000);
    if ($purchaseMinor > PHP_INT_MAX - $minimumMargin) {
        return ['calculable' => false, 'rule' => $rule, 'warnings' => ['Расчёт минимальной маржи превышает безопасный диапазон']];
    }
    $minimumCandidate = $purchaseMinor + $minimumMargin;
    $candidate = max($markupCandidate, $minimumCandidate);
    $margin = $candidate - $purchaseMinor;
    if ($margin > intdiv(PHP_INT_MAX, 10000)) {
        return ['calculable' => false, 'rule' => $rule, 'warnings' => ['Процент маржи превышает безопасный диапазон']];
    }
    $marginPercentHundredths = intdiv($margin * 10000, $purchaseMinor);
    if ($minimumCandidate > $markupCandidate) {
        $warnings[] = 'Цена повышена до уровня минимальной абсолютной маржи';
    }
    return [
        'calculable' => true,
        'rule' => $rule,
        'purchase_price' => supplierOfferMoney($purchaseMinor),
        'markup_percent' => $rule['markup_percent'] === null ? '0.0000' : (string)$rule['markup_percent'],
        'price_before_rounding' => supplierOfferMoney(max($markupCandidate, $minimumCandidate)),
        'candidate_retail_price' => supplierOfferMoney($candidate),
        'expected_margin' => supplierOfferMoney($margin),
        'expected_margin_percent' => intdiv($marginPercentHundredths, 100) . '.' . str_pad((string)($marginPercentHundredths % 100), 2, '0', STR_PAD_LEFT),
        'warnings' => $warnings
    ];
}

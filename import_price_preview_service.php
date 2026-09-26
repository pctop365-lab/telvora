<?php

declare(strict_types=1);

require_once __DIR__ . '/price_publication_service.php';

function importPricePreview(PDO $pdo, int $jobId, int $page = 1, int $pageSize = 10, bool $includeStats = true): array
{
    $job = pricePublicationFetchOne($pdo,
        'SELECT j.id, j.supplier_id, j.import_profile_id, s.name AS supplier_name
         FROM supplier_import_jobs j LEFT JOIN suppliers s ON s.id = j.supplier_id WHERE j.id = :id',
        [':id' => $jobId], 404, 'Импорт не найден');
    // Price preview is intentionally narrower than the import/matching list:
    // only rows with a persisted, valid product + variant mapping belong here.
    // Keep LEFT JOINs so an invalid/stale mapping can be diagnosed without
    // making supplier offers the membership criterion.
    $mappedWhere = '
        r.import_job_id = :id
        AND r.matched_product_id IS NOT NULL
        AND r.matched_product_variant_id IS NOT NULL
        AND p.id IS NOT NULL
        AND pv.id IS NOT NULL
        AND pv.product_id = p.id';
    $count = $pdo->prepare("SELECT COUNT(*)
        FROM supplier_import_rows r
        LEFT JOIN products p ON p.id = r.matched_product_id
        LEFT JOIN product_variants pv ON pv.id = r.matched_product_variant_id
        WHERE $mappedWhere");
    $count->execute([':id' => $jobId]);
    $total = (int)$count->fetchColumn();
    $pageSize = max(1, min(500, $pageSize));
    $pages = max(1, (int)ceil($total / $pageSize));
    $page = max(1, min($pages, $page));
    $offset = ($page - 1) * $pageSize;
    // The unique supplier/SKU offer is optional. Never use its existence as the
    // membership criterion for an import preview, or join it to an older source.
    $statement = $pdo->prepare("
        SELECT r.id AS source_import_row_id, r.import_job_id AS source_import_job_id,
               r.supplier_sku, r.raw_product_name AS supplier_product_name,
               r.purchase_price, r.currency_code, r.raw_availability, r.raw_arrival_info,
               r.status AS row_status, r.review_reason,
               r.matched_product_id AS product_id, r.matched_product_variant_id AS product_variant_id,
               COALESCE(p.name, r.raw_product_name) AS product_name, p.category,
               pv.variant_key, pv.display_name AS variant_name,
               p.id AS existing_product_id, pv.id AS existing_variant_id,
               o.id, o.is_active, o.imported_at, o.availability_status, o.stock_quantity,
               o.expected_arrival_at, o.delivery_info
        FROM supplier_import_rows r
        LEFT JOIN products p ON p.id = r.matched_product_id
        LEFT JOIN product_variants pv ON pv.id = r.matched_product_variant_id
        LEFT JOIN supplier_offers o ON o.source_import_row_id = r.id
            AND o.supplier_id = :supplier_id AND o.supplier_sku = r.supplier_sku
        WHERE r.import_job_id = :job_id
          AND r.matched_product_id IS NOT NULL
          AND r.matched_product_variant_id IS NOT NULL
          AND p.id IS NOT NULL
          AND pv.id IS NOT NULL
          AND pv.product_id = p.id
        ORDER BY r.id ASC LIMIT $pageSize OFFSET $offset
    ");
    $statement->execute([':supplier_id' => $job['supplier_id'], ':job_id' => $jobId]);
    $offers = [];
    foreach ($statement->fetchAll() as $row) {
        $reasons = [];
        if ($row['existing_product_id'] === null) $reasons[] = 'Не сопоставлено с товаром';
        if ($row['existing_variant_id'] === null) $reasons[] = 'Не сопоставлено с вариантом товара';
        if (supplierOfferMinorUnits($row['purchase_price']) === null) $reasons[] = 'Нет корректной закупочной цены';
        if ($row['row_status'] !== 'matched') {
            $reasons[] = 'Строка импорта требует проверки: ' . $row['row_status'];
            if ($row['review_reason']) $reasons[] = (string)$row['review_reason'];
        }
        $pricing = ['calculable' => false, 'rule' => null, 'warnings' => []];
        if ($row['id'] === null) {
            $reasons[] = 'Нет опубликованного предложения для этой строки импорта';
        } else {
            try {
                $context = pricePublicationContext($pdo, (int)$row['id'], false);
                if ($context['offer']['source_import_job_id'] !== $jobId ||
                    $context['offer']['source_import_row_id'] !== (int)$row['source_import_row_id']) {
                    $reasons[] = 'Источник предложения изменился. Обновите preview';
                }
                $pricing = $context['pricing'];
                $row['purchase_price'] = $context['offer']['purchase_price'];
                $row['currency_code'] = $context['offer']['currency_code'];
                $reasons = array_merge($reasons, $context['blocking_reasons']);
                if ($context['_internal']['current_minor'] === $context['_internal']['candidate_minor']) {
                    $reasons[] = 'Цена уже актуальна';
                }
                $pricing['warnings'] = array_values(array_unique(array_merge($pricing['warnings'], $context['warnings'])));
            } catch (PricePublicationException $error) {
                $reasons[] = $error->getMessage();
            }
        }
        if (array_diff($reasons, ['Цена уже актуальна']) !== []) {
            $pricing = ['calculable' => false, 'rule' => $pricing['rule'], 'warnings' => $pricing['warnings']];
        }
        $row['cannot_confirm'] = $reasons !== [];
        $row['blocking_reasons'] = array_values(array_unique($reasons));
        $row['pricing'] = $pricing;
        $row['supplier_id'] = (int)$job['supplier_id'];
        $row['supplier_name'] = $job['supplier_name'];
        $row['availability_status'] = $row['availability_status'] ?? 'unknown';
        foreach (['id', 'product_id', 'product_variant_id', 'source_import_row_id', 'source_import_job_id', 'stock_quantity'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int)$row[$key];
        }
        $row['is_active'] = (bool)$row['is_active'];
        $offers[] = $row;
    }
    $result = ['job_id' => $jobId, 'import_profile_id' => $job['import_profile_id'],
        'page' => $page, 'page_size' => $pageSize, 'pages' => $pages, 'total' => $total,
        'offers' => $offers];
    if ($includeStats) {
        $bulk = importPriceBulkPrepare($pdo, $jobId);
        $result['confirmable_total'] = $bulk['eligible'];
        $result['attention_total'] = max(0, $bulk['total'] - $bulk['eligible']);
    }
    return $result;
}

function importPriceBulkPrepare(PDO $pdo, int $jobId): array
{
    $entries = [];
    $seenVariants = [];
    $eligible = 0;
    $page = 1;
    do {
        $preview = importPricePreview($pdo, $jobId, $page, 500, false);
        foreach ($preview['offers'] as $row) {
            $entry = ['row_id' => $row['source_import_row_id'], 'offer_id' => $row['id'],
                'reasons' => $row['blocking_reasons']];
            if (!$row['cannot_confirm']) {
                try {
                    $context = pricePublicationContext($pdo, $row['id'], false);
                } catch (PricePublicationException $error) {
                    $entry['reasons'] = [$error->getMessage()];
                    $entries[] = $entry;
                    continue;
                }
                if ($context['offer']['source_import_job_id'] !== $jobId ||
                    $context['offer']['source_import_row_id'] !== $entry['row_id']) {
                    $entry['reasons'] = ['Источник предложения изменился. Обновите preview'];
                } elseif (!$context['can_publish']) {
                    $entry['reasons'] = $context['blocking_reasons'];
                } elseif ($context['_internal']['current_minor'] === $context['_internal']['candidate_minor']) {
                    $entry['reasons'] = ['Цена уже актуальна'];
                } elseif (isset($seenVariants[$context['variant']['id']])) {
                    $entry['reasons'] = ['Для варианта уже выбрана другая строка этого preview'];
                } else {
                    $seenVariants[$context['variant']['id']] = true;
                    $entry['token'] = $context['_internal']['bulk_snapshot_token'];
                    $eligible++;
                }
            }
            $entries[] = $entry;
        }
        $page++;
    } while ($page <= $preview['pages']);
    return ['job_id' => $jobId, 'import_profile_id' => $preview['import_profile_id'],
        'eligible' => $eligible, 'total' => count($entries), 'entries' => $entries];
}

function importPriceBulkConfirm(PDO $pdo, array $snapshot): array
{
    $result = ['applied' => 0, 'skipped' => 0, 'errors' => 0, 'rows' => []];
    foreach ($snapshot['entries'] as $entry) {
        $outcome = ['row_id' => $entry['row_id'], 'offer_id' => $entry['offer_id']];
        if (!isset($entry['token'])) {
            $outcome += ['status' => 'skipped', 'reasons' => $entry['reasons']];
        } else {
            try {
                // Token includes supplier, source row/job, mapping, rule and target
                // price. The existing publisher rechecks all of them under locks.
                $publication = pricePublicationPublish($pdo, $entry['offer_id'], $entry['token'], null, true);
                $outcome += $publication['status'] === 'published'
                    ? ['status' => 'applied', 'audit_id' => $publication['audit_id']]
                    : ['status' => 'skipped', 'reasons' => ['Цена уже актуальна']];
            } catch (PricePublicationException $error) {
                $outcome += ['status' => 'skipped', 'reasons' => [$error->getMessage()]];
            } catch (Throwable $error) {
                error_log('Import bulk price publication failed: ' . $error->getMessage());
                $outcome += ['status' => 'errors', 'reasons' => ['Не удалось опубликовать цену; строка не изменена']];
            }
        }
        $result[$outcome['status']]++;
        $result['rows'][] = $outcome;
    }
    return $result;
}

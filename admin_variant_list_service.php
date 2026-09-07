<?php

declare(strict_types=1);

require_once __DIR__ . '/product_variant_identity_service.php';

function adminVariantListProductId(mixed $value): ?int
{
    if (
        !is_string($value) ||
        preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1 ||
        filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
        ]) === false
    ) {
        return null;
    }

    return (int)$value;
}

function adminVariantListDiagnosticCode(ProductVariantIdentityException $error): string
{
    return match ($error->getMessage()) {
        'Структура вариантов товара не поддерживает безопасную публикацию',
        'Структура legacy-вариантов товара не поддерживается',
        'Legacy-варианты товара недоступны',
        'Legacy-варианты товара содержат некорректный JSON',
        'Legacy-вариант товара не является объектом' => 'invalid_legacy_structure',
        'Структура вариантов товара изменилась после классификации' => 'invalid_legacy_fields',
        'Страна сборки варианта некорректна' => 'invalid_country',
        'Статус legacy-варианта некорректен' => 'invalid_legacy_status',
        'Цена legacy-варианта некорректна' => 'invalid_published_price',
        'Старая цена legacy-варианта некорректна' => 'invalid_old_price',
        'У товара есть дублирующиеся страны сборки' => 'duplicate_legacy_country',
        'Не найдено однозначное соответствие relational и legacy-варианта' => 'identity_mismatch',
        default => 'identity_invalid',
    };
}

function adminVariantListFetch(PDO $pdo, int $productId): ?array
{
    $productStmt = $pdo->prepare('SELECT id, variants FROM products WHERE id = :id LIMIT 1');
    $productStmt->execute([':id' => $productId]);
    $product = $productStmt->fetch();
    if (!is_array($product)) {
        return null;
    }

    $variantStmt = $pdo->prepare("
        SELECT pv.id, pv.product_id, pv.variant_key, pv.assembly_country,
               pv.is_active,
               (SELECT COUNT(*) FROM supplier_offers o
                WHERE o.product_variant_id = pv.id) AS offers_count,
               (SELECT COUNT(*) FROM supplier_product_matches m
                WHERE m.product_variant_id = pv.id) AS matches_count,
               (SELECT COUNT(*) FROM supplier_import_rows r
                WHERE r.matched_product_variant_id = pv.id) AS import_rows_count,
               (SELECT COUNT(*) FROM product_price_publication_audit a
                WHERE a.product_variant_id = pv.id) AS audit_count,
               (SELECT COUNT(*) FROM order_items oi
                WHERE oi.product_variant_id = pv.id) AS order_references_count,
               (SELECT COUNT(*)
                FROM supplier_offers o
                LEFT JOIN supplier_import_rows r ON r.id = o.source_import_row_id
                WHERE o.product_variant_id = pv.id
                  AND (
                      o.source_import_row_id IS NULL OR
                      r.id IS NULL OR
                      r.matched_product_variant_id IS NULL OR
                      r.matched_product_variant_id <> o.product_variant_id OR
                      r.status <> 'matched'
                  )) AS provenance_mismatch_count
        FROM product_variants pv
        WHERE pv.product_id = :product_id
        ORDER BY pv.id ASC
    ");
    $variantStmt->execute([':product_id' => $productId]);
    $relationalVariants = $variantStmt->fetchAll();

    $legacyDiagnostics = [];
    $legacyEntries = [];
    $rawVariants = $product['variants'] ?? null;
    try {
        if (!is_string($rawVariants)) {
            throw new ProductVariantIdentityException('Legacy-варианты товара недоступны');
        }
        $legacyVariants = json_decode($rawVariants, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($legacyVariants) || !array_is_list($legacyVariants) || count($legacyVariants) > 200) {
            throw new ProductVariantIdentityException('Структура legacy-вариантов товара не поддерживается');
        }

        $weightStmt = $pdo->prepare(
            'SELECT HEX(WEIGHT_STRING(CAST(:value AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci))'
        );
        $seenWeights = [];
        foreach ($legacyVariants as $index => $legacyVariant) {
            try {
                if (!is_array($legacyVariant)) {
                    throw new ProductVariantIdentityException('Legacy-вариант товара не является объектом');
                }
                $detail = productVariantIdentityCountry($weightStmt, $legacyVariant, $productId, true);
                $entryDiagnostic = null;
                if (isset($seenWeights[$detail['weight']])) {
                    $entryDiagnostic = 'duplicate_legacy_country';
                    $legacyDiagnostics[] = $entryDiagnostic;
                } else {
                    $seenWeights[$detail['weight']] = $index;
                }
                $legacyEntries[$index] = [
                    'valid' => true,
                    'country' => $detail['country'],
                    'weight' => $detail['weight'],
                    'expected_key' => 'legacy-country-sha256-' . hash('sha256', $detail['country']),
                    'legacy_is_active' => $detail['is_active'],
                    'published_price' => $legacyVariant['price'],
                    'old_price' => $legacyVariant['old_price'],
                    'diagnostic_code' => $entryDiagnostic,
                ];
            } catch (ProductVariantIdentityException $error) {
                $diagnosticCode = adminVariantListDiagnosticCode($error);
                $legacyDiagnostics[] = $diagnosticCode;
                $legacyEntries[$index] = [
                    'valid' => false,
                    'country' => null,
                    'weight' => null,
                    'expected_key' => null,
                    'legacy_is_active' => null,
                    'published_price' => null,
                    'old_price' => null,
                    'diagnostic_code' => $diagnosticCode,
                ];
            }
        }
    } catch (JsonException) {
        $legacyDiagnostics[] = 'invalid_legacy_json';
    } catch (ProductVariantIdentityException $error) {
        $legacyDiagnostics[] = adminVariantListDiagnosticCode($error);
    }
    $legacyDiagnostics = array_values(array_unique($legacyDiagnostics));
    $legacyDocumentValid = $legacyDiagnostics === [];

    $variants = [];
    foreach ($relationalVariants as $relationalVariant) {
        $legacyActive = null;
        $publishedPrice = null;
        $oldPrice = null;
        $publishedPriceMinor = null;
        $identityStatus = 'invalid';
        $diagnosticCode = 'identity_invalid';

        if ($legacyDocumentValid) {
            try {
                $identity = productVariantIdentityResolve($pdo, $product, $relationalVariant, true);
                $legacyIndex = (int)$identity['target_index'];
                $legacyVariant = $identity['variants'][$legacyIndex];
                $legacyActive = $identity['target']['is_active'];
                $publishedPrice = $legacyVariant['price'];
                $oldPrice = $legacyVariant['old_price'];
                $publishedPriceMinor = $identity['target']['price_minor'];

                if ((bool)$relationalVariant['is_active'] === $legacyActive) {
                    $identityStatus = 'ok';
                    $diagnosticCode = null;
                } else {
                    $identityStatus = 'mismatch';
                    $diagnosticCode = 'active_status_mismatch';
                    $publishedPrice = null;
                    $oldPrice = null;
                    $publishedPriceMinor = null;
                }
            } catch (ProductVariantIdentityException $error) {
                $diagnosticCode = adminVariantListDiagnosticCode($error);
                if ($legacyEntries === []) {
                    $diagnosticCode = 'relational_without_legacy';
                }
            }
        } else {
            $diagnosticCode = 'legacy_document_invalid';
        }

        $provenanceMismatchCount = (int)$relationalVariant['provenance_mismatch_count'];
        $diagnostics = [];
        if ($diagnosticCode !== null) {
            $diagnostics[] = $diagnosticCode;
        }
        if ($provenanceMismatchCount > 0) {
            $diagnostics[] = 'offer_source_mismatch';
        }

        $variants[] = [
            'product_variant_id' => (int)$relationalVariant['id'],
            'product_id' => (int)$relationalVariant['product_id'],
            'variant_key' => (string)$relationalVariant['variant_key'],
            'assembly_country' => $relationalVariant['assembly_country'],
            'relational_is_active' => (bool)$relationalVariant['is_active'],
            'legacy_is_active' => $legacyActive,
            'published_price' => $publishedPrice,
            'old_price' => $oldPrice,
            'identity_status' => $identityStatus,
            'diagnostic_code' => $diagnosticCode,
            'diagnostics' => $diagnostics,
            'identity_ready' => $identityStatus === 'ok',
            'has_published_price' => $identityStatus === 'ok' &&
                $publishedPriceMinor !== null &&
                $publishedPriceMinor > 0,
            'references' => [
                'offers' => (int)$relationalVariant['offers_count'],
                'matches' => (int)$relationalVariant['matches_count'],
                'import_rows' => (int)$relationalVariant['import_rows_count'],
                'audit' => (int)$relationalVariant['audit_count'],
                'orders' => (int)$relationalVariant['order_references_count'],
            ],
            'provenance_mismatch_count' => $provenanceMismatchCount,
        ];
    }

    $legacyOrphans = [];
    $legacyUnresolved = [];
    $hasRelationalWithoutExactLegacy = false;
    foreach ($relationalVariants as $relationalVariant) {
        $hasExactLegacy = false;
        foreach ($legacyEntries as $entry) {
            if (
                $entry['valid'] &&
                $entry['country'] === $relationalVariant['assembly_country'] &&
                $entry['expected_key'] === $relationalVariant['variant_key']
            ) {
                $hasExactLegacy = true;
                break;
            }
        }
        if (!$hasExactLegacy) {
            $hasRelationalWithoutExactLegacy = true;
            break;
        }
    }
    foreach ($legacyEntries as $index => $entry) {
        $base = [
            'legacy_index' => $index,
            'product_variant_id' => null,
            'product_id' => $productId,
            'country' => $entry['country'],
            'legacy_is_active' => null,
            'published_price' => null,
            'old_price' => null,
            'identity_status' => 'indeterminate',
            'diagnostic_code' => $entry['diagnostic_code'] ?? 'legacy_document_invalid',
        ];
        if (!$legacyDocumentValid) {
            $legacyUnresolved[] = $base;
            continue;
        }

        $exactCounterparts = 0;
        $partialCounterparts = 0;
        foreach ($relationalVariants as $relationalVariant) {
            $countryMatches = $entry['country'] === $relationalVariant['assembly_country'];
            $keyMatches = $entry['expected_key'] === $relationalVariant['variant_key'];
            if ($countryMatches && $keyMatches) {
                $exactCounterparts++;
            } elseif ($countryMatches || $keyMatches) {
                $partialCounterparts++;
            }
        }
        if ($exactCounterparts === 1) {
            continue;
        }
        if ($exactCounterparts > 1 || $partialCounterparts > 0 || $hasRelationalWithoutExactLegacy) {
            $base['diagnostic_code'] = 'counterpart_identity_mismatch';
            $legacyUnresolved[] = $base;
            continue;
        }

        $base['legacy_is_active'] = $entry['legacy_is_active'];
        $base['published_price'] = $entry['published_price'];
        $base['old_price'] = $entry['old_price'];
        $base['identity_status'] = 'orphan_legacy';
        $base['diagnostic_code'] = 'legacy_without_relational';
        $legacyOrphans[] = $base;
    }

    return [
        'product_id' => $productId,
        'variants' => $variants,
        'legacy_orphans' => $legacyOrphans,
        'legacy_unresolved' => $legacyUnresolved,
        'diagnostics' => $legacyDiagnostics,
    ];
}

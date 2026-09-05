<?php

declare(strict_types=1);

if (!defined('TELVORA_MANAGER_REQUEST')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/supplier_offer_service.php';
require_once __DIR__ . '/product_variant_identity_service.php';

final class PricePublicationException extends RuntimeException
{
    public function __construct(public readonly int $httpStatus, string $message)
    {
        parent::__construct($message);
    }
}

function pricePublicationFetchOne(PDO $pdo, string $sql, array $parameters, int $status, string $message): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    $row = $statement->fetch();
    if (!is_array($row)) {
        throw new PricePublicationException($status, $message);
    }
    return $row;
}

function pricePublicationLegacyMinor(mixed $value, bool $allowNull = false): ?int
{
    if ($allowNull && $value === null) {
        return null;
    }
    if (is_int($value)) {
        return supplierOfferMinorUnits((string)$value);
    }
    if (!is_float($value) || !is_finite($value) || $value <= 0) {
        return null;
    }
    $scaled = $value * 100;
    $rounded = round($scaled);
    if (!is_finite($scaled) || abs($scaled - $rounded) > 0.000001 || $rounded > 999999999999) {
        return null;
    }
    return (int)$rounded;
}

function pricePublicationCountry(PDOStatement $weightStatement, array $variant, int $productId): array
{
    if (array_is_list($variant)) {
        throw new PricePublicationException(422, 'Структура вариантов товара не поддерживает безопасную публикацию');
    }
    $keys = array_keys($variant);
    sort($keys, SORT_STRING);
    if ($keys !== ['country', 'is_active', 'old_price', 'price']) {
        throw new PricePublicationException(422, 'Структура вариантов товара изменилась после классификации');
    }
    if (!is_string($variant['country']) || !mb_check_encoding($variant['country'], 'UTF-8')) {
        throw new PricePublicationException(422, 'Страна сборки варианта некорректна');
    }
    $country = preg_replace('/\A\s+|\s+\z/u', '', $variant['country']);
    $controlMatch = is_string($country) ? preg_match('/[\x00-\x1F\x7F]/u', $country) : false;
    if (!is_string($country) || $country === '' || mb_strlen($country, 'UTF-8') > 100 || $controlMatch !== 0) {
        throw new PricePublicationException(422, 'Страна сборки варианта некорректна');
    }
    if (!is_bool($variant['is_active'])) {
        throw new PricePublicationException(422, 'Статус legacy-варианта некорректен');
    }
    $priceMinor = pricePublicationLegacyMinor($variant['price']);
    if ($priceMinor === null || $priceMinor > 999999999999) {
        throw new PricePublicationException(422, 'Цена legacy-варианта некорректна');
    }
    $oldPriceMinor = pricePublicationLegacyMinor($variant['old_price'], true);
    if ($variant['old_price'] !== null && ($oldPriceMinor === null || $oldPriceMinor > 999999999999)) {
        throw new PricePublicationException(422, 'Старая цена legacy-варианта некорректна');
    }
    $weightStatement->execute([':value' => $country]);
    $weight = $weightStatement->fetchColumn();
    if (!is_string($weight) || $weight === '') {
        throw new RuntimeException("Unable to calculate collation weight for product $productId");
    }
    return [
        'country' => $country,
        'weight' => $weight,
        'price_minor' => $priceMinor,
        'is_active' => $variant['is_active']
    ];
}

function pricePublicationResolveLegacyVariant(PDO $pdo, array $product, array $relationalVariant): array
{
    try {
        return productVariantIdentityResolve($pdo, $product, $relationalVariant, true);
    } catch (ProductVariantIdentityException $error) {
        throw new PricePublicationException(422, $error->getMessage());
    }

}

function pricePublicationRules(PDO $pdo, bool $lock): array
{
    $sql = "
        SELECT id, name, priority, category_scope, purchase_price_min,
               purchase_price_max, markup_percent, minimum_margin,
               rounding_strategy, rounding_parameters, additional_scope,
               valid_from, valid_until, is_active, updated_at
        FROM pricing_rules
        WHERE is_active = 1
          AND (valid_from IS NULL OR valid_from <= CURRENT_TIMESTAMP)
          AND (valid_until IS NULL OR valid_until >= CURRENT_TIMESTAMP)
          AND additional_scope IS NULL
        ORDER BY priority ASC, (category_scope IS NOT NULL) DESC, id ASC
        LIMIT 200" . ($lock ? ' FOR UPDATE' : '');
    return $pdo->query($sql)->fetchAll();
}

function pricePublicationContext(PDO $pdo, int $offerId, bool $lock): array
{
    $suffix = $lock ? ' FOR UPDATE' : '';
    $offer = pricePublicationFetchOne($pdo, "
        SELECT id, supplier_id, product_variant_id, supplier_sku,
               supplier_product_name, purchase_price, currency_code,
               availability_status, source_import_row_id, imported_at,
               is_active, updated_at
        FROM supplier_offers WHERE id = :id$suffix
    ", [':id' => $offerId], 404, 'Предложение поставщика не найдено');
    $supplier = pricePublicationFetchOne($pdo,
        "SELECT id, name, is_active, updated_at FROM suppliers WHERE id = :id$suffix",
        [':id' => $offer['supplier_id']], 409, 'Поставщик предложения недоступен'
    );
    $variant = pricePublicationFetchOne($pdo, "
        SELECT id, product_id, variant_key, assembly_country, display_name,
               classification_status, is_active, updated_at
        FROM product_variants WHERE id = :id$suffix
    ", [':id' => $offer['product_variant_id']], 409, 'Relational-вариант предложения недоступен');
    $product = pricePublicationFetchOne($pdo, "
        SELECT id, name, category, price, old_price, variants, is_active, updated_at
        FROM products WHERE id = :id$suffix
    ", [':id' => $variant['product_id']], 409, 'Товар предложения недоступен');

    $blocking = [];
    if (!(bool)$offer['is_active']) $blocking[] = 'Предложение поставщика неактивно';
    if (!(bool)$supplier['is_active']) $blocking[] = 'Поставщик неактивен';
    if (!(bool)$variant['is_active']) $blocking[] = 'Relational-вариант неактивен';
    if ($offer['currency_code'] !== 'RUB') $blocking[] = 'Публикация поддерживается только для RUB';
    $purchaseMinor = supplierOfferMinorUnits($offer['purchase_price']);
    if ($purchaseMinor === null) $blocking[] = 'Закупочная цена предложения некорректна';
    if (!is_string($offer['supplier_sku']) || trim($offer['supplier_sku']) === '') {
        $blocking[] = 'У предложения отсутствует supplier SKU';
    }

    $sourceRow = null;
    $sourceJob = null;
    if ($offer['source_import_row_id'] === null) {
        $blocking[] = 'У предложения отсутствует подтверждённый источник импорта';
    } else {
        try {
            $sourceRow = pricePublicationFetchOne($pdo, "
                SELECT id, import_job_id, supplier_sku, status, matched_product_id,
                       matched_product_variant_id
                FROM supplier_import_rows WHERE id = :id$suffix
            ", [':id' => $offer['source_import_row_id']], 409, 'Строка-источник предложения недоступна');
            $sourceJob = pricePublicationFetchOne($pdo, "
                SELECT id, supplier_id, status, created_at, finished_at
                FROM supplier_import_jobs WHERE id = :id$suffix
            ", [':id' => $sourceRow['import_job_id']], 409, 'Import job источника недоступен');
            if (
                (int)$sourceJob['supplier_id'] !== (int)$offer['supplier_id'] ||
                (int)$sourceRow['matched_product_id'] !== (int)$product['id'] ||
                (int)$sourceRow['matched_product_variant_id'] !== (int)$variant['id'] ||
                $sourceRow['status'] !== 'matched' ||
                $sourceJob['status'] !== 'ready_for_review' ||
                $sourceRow['supplier_sku'] !== $offer['supplier_sku']
            ) {
                $blocking[] = 'Источник предложения больше не подтверждает текущее сопоставление';
            }
            $newerStmt = $pdo->prepare("
                SELECT r.id
                FROM supplier_import_rows r
                INNER JOIN supplier_import_jobs j ON j.id = r.import_job_id
                WHERE j.supplier_id = :supplier_id
                  AND r.supplier_sku = :supplier_sku
                  AND (j.created_at > :created_at OR (j.created_at = :created_at AND j.id > :job_id))
                ORDER BY j.created_at DESC, j.id DESC, r.id DESC
                LIMIT 1" . ($lock ? ' FOR UPDATE' : '')
            );
            $newerStmt->execute([
                ':supplier_id' => $offer['supplier_id'],
                ':supplier_sku' => $offer['supplier_sku'],
                ':created_at' => $sourceJob['created_at'],
                ':job_id' => $sourceJob['id']
            ]);
            if ($newerStmt->fetchColumn() !== false) {
                $blocking[] = 'Для supplier SKU существует более новый staging source';
            }
        } catch (PricePublicationException $error) {
            $blocking[] = $error->getMessage();
        }
    }

    try {
        $legacy = pricePublicationResolveLegacyVariant($pdo, $product, $variant);
    } catch (PricePublicationException $error) {
        $legacy = null;
        $blocking[] = $error->getMessage();
    }
    if (is_array($legacy) && !$legacy['target']['is_active']) {
        $blocking[] = 'Legacy-вариант товара неактивен';
    }

    $offerForPricing = $offer + ['category' => $product['category']];
    $rules = pricePublicationRules($pdo, $lock);
    $applicableRules = supplierPricingApplicableRules($offerForPricing, $rules);
    $calculation = supplierPricingCalculate($offerForPricing, $applicableRules);
    if (!($calculation['calculable'] ?? false) || !is_array($calculation['rule'] ?? null)) {
        $blocking[] = 'Однозначный поддерживаемый Candidate сейчас недоступен';
    }
    $candidateMinor = isset($calculation['candidate_retail_price'])
        ? supplierOfferMinorUnits((string)$calculation['candidate_retail_price']) : null;
    $marginMinor = isset($calculation['expected_margin'])
        ? supplierOfferMinorUnits((string)$calculation['expected_margin'], true) : null;
    $marginPercentScaled = isset($calculation['expected_margin_percent'])
        ? supplierPricingDecimalScaled((string)$calculation['expected_margin_percent'], 4) : null;
    if ($candidateMinor === null || $candidateMinor > 999999999999) {
        $blocking[] = 'Candidate не помещается в live price DECIMAL(12,2)';
    }
    if ($purchaseMinor !== null && ($candidateMinor === null || $candidateMinor < $purchaseMinor || $marginMinor === null)) {
        $blocking[] = 'Candidate нарушает ограничение неотрицательной маржи';
    }
    if ($marginPercentScaled === null || $marginPercentScaled > 999999999) {
        $blocking[] = 'Процент маржи не помещается в audit DECIMAL(9,4)';
    }

    $currentMinor = is_array($legacy) ? $legacy['target']['price_minor'] : null;
    $deltaMinor = $candidateMinor !== null && $currentMinor !== null ? $candidateMinor - $currentMinor : null;
    $deltaPercent = $deltaMinor !== null && $currentMinor > 0
        ? intdiv($deltaMinor * 10000, $currentMinor) : null;
    $warnings = array_values(array_unique(array_map('strval', $calculation['warnings'] ?? [])));
    if (!(bool)$product['is_active']) {
        $warnings[] = 'Товар неактивен: цена будет записана в черновик, товар останется скрытым';
    }
    if ($deltaPercent !== null && abs($deltaPercent) >= 5000) {
        $warnings[] = 'Изменение составляет 50% или более; требуется особенно внимательная проверка';
    }
    if ($currentMinor !== null && supplierOfferMinorUnits((string)$product['price']) !== $currentMinor) {
        $warnings[] = 'products.price не совпадает с ценой выбранного legacy-варианта и останется без изменения';
    }

    $tokenState = [
        'offer' => $offer,
        'supplier' => $supplier,
        'variant' => $variant,
        'product_id' => $product['id'],
        'product_price' => $product['price'],
        'product_old_price' => $product['old_price'],
        'product_is_active' => (bool)$product['is_active'],
        'product_updated_at' => $product['updated_at'],
        'legacy_hash' => is_array($legacy) ? $legacy['raw_hash'] : null,
        'source_row' => $sourceRow,
        'source_job' => $sourceJob,
        'rule' => $calculation['rule'] ?? null,
        'candidate' => $calculation['candidate_retail_price'] ?? null
    ];
    $tokenJson = json_encode($tokenState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($tokenJson)) {
        throw new RuntimeException('Unable to encode price publication snapshot');
    }

    return [
        'can_publish' => $blocking === [],
        'blocking_reasons' => array_values(array_unique($blocking)),
        'warnings' => array_values(array_unique($warnings)),
        'snapshot_token' => hash('sha256', $tokenJson),
        'offer' => [
            'id' => (int)$offer['id'],
            'supplier_id' => (int)$supplier['id'],
            'supplier_name' => (string)$supplier['name'],
            'supplier_sku' => $offer['supplier_sku'],
            'purchase_price' => (string)$offer['purchase_price'],
            'currency_code' => (string)$offer['currency_code'],
            'imported_at' => (string)$offer['imported_at'],
            'source_import_row_id' => $sourceRow === null ? null : (int)$sourceRow['id'],
            'source_import_job_id' => $sourceJob === null ? null : (int)$sourceJob['id']
        ],
        'product' => [
            'id' => (int)$product['id'],
            'name' => (string)$product['name'],
            'base_price' => (string)$product['price'],
            'base_old_price' => $product['old_price']
        ],
        'variant' => [
            'id' => (int)$variant['id'],
            'variant_key' => (string)$variant['variant_key'],
            'assembly_country' => $variant['assembly_country'],
            'display_name' => $variant['display_name'],
            'current_live_price' => $currentMinor === null ? null : supplierOfferMoney($currentMinor)
        ],
        'pricing' => $calculation,
        'delta_amount' => $deltaMinor === null
            ? null : ($deltaMinor < 0 ? '-' : '') . supplierOfferMoney(abs($deltaMinor)),
        'delta_percent' => $deltaPercent === null
            ? null : ($deltaPercent < 0 ? '-' : '') . intdiv(abs($deltaPercent), 100) . '.' . str_pad((string)(abs($deltaPercent) % 100), 2, '0', STR_PAD_LEFT),
        '_internal' => [
            'product' => $product,
            'variant' => $variant,
            'legacy' => $legacy,
            'candidate_minor' => $candidateMinor,
            'current_minor' => $currentMinor,
            'margin_minor' => $marginMinor
        ]
    ];
}

function pricePublicationPublicResult(array $context): array
{
    unset($context['_internal']);
    return $context;
}

function pricePublicationJsonNumber(int $minor): int|float
{
    return $minor % 100 === 0 ? intdiv($minor, 100) : (float)supplierOfferMoney($minor);
}

function pricePublicationPublish(PDO $pdo, int $offerId, string $expectedToken, ?string $comment): array
{
    $pdo->beginTransaction();
    try {
        $context = pricePublicationContext($pdo, $offerId, true);
        if (!hash_equals($context['snapshot_token'], $expectedToken)) {
            throw new PricePublicationException(409, 'Данные изменились после проверки. Выполните preflight повторно');
        }
        if (!$context['can_publish']) {
            throw new PricePublicationException(422, 'Текущий Candidate нельзя безопасно опубликовать');
        }
        $internal = $context['_internal'];
        if ($internal['current_minor'] === $internal['candidate_minor']) {
            $pdo->commit();
            return ['status' => 'already_current', 'audit_id' => null] + pricePublicationPublicResult($context);
        }

        $variants = $internal['legacy']['variants'];
        $targetIndex = $internal['legacy']['target_index'];
        $variants[$targetIndex]['price'] = pricePublicationJsonNumber($internal['candidate_minor']);
        $encodedVariants = json_encode($variants, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($encodedVariants)) {
            throw new RuntimeException('Unable to encode updated legacy variants');
        }
        $roundTrip = json_decode($encodedVariants, true, 512, JSON_THROW_ON_ERROR);
        if (
            !is_array($roundTrip) ||
            !isset($roundTrip[$targetIndex]) ||
            pricePublicationLegacyMinor($roundTrip[$targetIndex]['price'] ?? null) !== $internal['candidate_minor']
        ) {
            throw new RuntimeException('Updated legacy price failed round-trip validation');
        }

        $updateStmt = $pdo->prepare('UPDATE products SET variants = :variants WHERE id = :id');
        $updateStmt->execute([':variants' => $encodedVariants, ':id' => $context['product']['id']]);
        if ($updateStmt->rowCount() !== 1) {
            throw new RuntimeException('Product variants update did not affect exactly one row');
        }

        $rule = $context['pricing']['rule'];
        $auditStmt = $pdo->prepare("
            INSERT INTO product_price_publication_audit (
                product_id, product_variant_id, supplier_id, supplier_offer_id,
                supplier_sku, pricing_rule_id, source_import_row_id,
                source_import_job_id, variant_key, assembly_country,
                old_live_price, new_live_price, purchase_price, currency_code,
                margin_amount, margin_percent, source_type, admin_actor, admin_comment
            ) VALUES (
                :product_id, :product_variant_id, :supplier_id, :supplier_offer_id,
                :supplier_sku, :pricing_rule_id, :source_import_row_id,
                :source_import_job_id, :variant_key, :assembly_country,
                :old_live_price, :new_live_price, :purchase_price, :currency_code,
                :margin_amount, :margin_percent, 'supplier_pricing', 'admin_session', :admin_comment
            )
        ");
        $auditStmt->execute([
            ':product_id' => $context['product']['id'],
            ':product_variant_id' => $context['variant']['id'],
            ':supplier_id' => $context['offer']['supplier_id'],
            ':supplier_offer_id' => $context['offer']['id'],
            ':supplier_sku' => $context['offer']['supplier_sku'],
            ':pricing_rule_id' => $rule['id'],
            ':source_import_row_id' => $context['offer']['source_import_row_id'],
            ':source_import_job_id' => $context['offer']['source_import_job_id'],
            ':variant_key' => $context['variant']['variant_key'],
            ':assembly_country' => $context['variant']['assembly_country'],
            ':old_live_price' => supplierOfferMoney($internal['current_minor']),
            ':new_live_price' => supplierOfferMoney($internal['candidate_minor']),
            ':purchase_price' => $context['offer']['purchase_price'],
            ':currency_code' => $context['offer']['currency_code'],
            ':margin_amount' => supplierOfferMoney($internal['margin_minor']),
            ':margin_percent' => $context['pricing']['expected_margin_percent'],
            ':admin_comment' => $comment
        ]);
        $auditId = (int)$pdo->lastInsertId();

        $verifyStmt = $pdo->prepare('SELECT variants FROM products WHERE id = :id');
        $verifyStmt->execute([':id' => $context['product']['id']]);
        $storedVariants = $verifyStmt->fetchColumn();
        if (!is_string($storedVariants)) {
            throw new RuntimeException('Unable to verify stored product variants');
        }
        $stored = json_decode($storedVariants, true, 512, JSON_THROW_ON_ERROR);
        if (
            !is_array($stored) ||
            $stored !== $roundTrip ||
            pricePublicationLegacyMinor($stored[$targetIndex]['price'] ?? null) !== $internal['candidate_minor']
        ) {
            throw new RuntimeException('Stored product price verification failed');
        }
        $pdo->commit();
        return [
            'status' => 'published',
            'audit_id' => $auditId,
            'published_price' => supplierOfferMoney($internal['candidate_minor'])
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function pricePublicationHistory(PDO $pdo, int $page, int $pageSize): array
{
    $offset = ($page - 1) * $pageSize;
    if ($offset > 5000000) {
        throw new PricePublicationException(400, 'Некорректная страница истории');
    }
    $total = (int)$pdo->query('SELECT COUNT(*) FROM product_price_publication_audit')->fetchColumn();
    $stmt = $pdo->query("
        SELECT a.id, a.product_id, a.product_variant_id, a.supplier_id,
               a.supplier_offer_id, a.supplier_sku, a.pricing_rule_id,
               a.source_import_row_id, a.source_import_job_id, a.variant_key,
               a.assembly_country, a.old_live_price, a.new_live_price,
               a.purchase_price, a.currency_code, a.margin_amount,
               a.margin_percent, a.source_type, a.admin_actor,
               a.admin_comment, a.created_at, p.name AS product_name,
               s.name AS supplier_name, pr.name AS pricing_rule_name
        FROM product_price_publication_audit a
        INNER JOIN products p ON p.id = a.product_id
        INNER JOIN suppliers s ON s.id = a.supplier_id
        INNER JOIN pricing_rules pr ON pr.id = a.pricing_rule_id
        ORDER BY a.id DESC
        LIMIT $pageSize OFFSET $offset
    ");
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        foreach ([
            'id', 'product_id', 'product_variant_id', 'supplier_id',
            'supplier_offer_id', 'pricing_rule_id', 'source_import_row_id',
            'source_import_job_id'
        ] as $key) {
            $row[$key] = $row[$key] === null ? null : (int)$row[$key];
        }
        $rows[] = $row;
    }
    return [
        'page' => $page,
        'page_size' => $pageSize,
        'pages' => max(1, (int)ceil($total / $pageSize)),
        'total' => $total,
        'history' => $rows
    ];
}

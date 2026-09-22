<?php

/** Product deletion policy and transactional dependency cleanup. */
final class ProductDeleteBlockedException extends RuntimeException
{
    /** @var list<string> */
    public array $reasons;

    /** @param list<string> $reasons */
    public function __construct(array $reasons)
    {
        $this->reasons = $reasons;
        parent::__construct(
            'Товар нельзя физически удалить: ' . implode('; ', $reasons)
            . '. Сначала скройте или деактивируйте его.'
        );
    }
}

function productDeleteTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
    $stmt->execute([':table' => $table]);
    return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
}

function productDeleteColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column');
    $stmt->execute([':table' => $table, ':column' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

/** @return list<int> */
function productDeleteVariantIds(PDO $pdo, int $productId): array
{
    if (!productDeleteTableExists($pdo, 'product_variants')) return [];
    $stmt = $pdo->prepare('SELECT id FROM product_variants WHERE product_id = :product_id ORDER BY id');
    $stmt->execute([':product_id' => $productId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** @param list<int> $variantIds */
function productDeleteCountByIds(PDO $pdo, string $table, string $productColumn, int $productId, array $variantIds, string $variantColumn = 'product_variant_id'): int
{
    if (!productDeleteTableExists($pdo, $table)) return 0;
    $parts = [];
    $params = [':product_id' => $productId];
    if (productDeleteColumnExists($pdo, $table, $productColumn)) $parts[] = "{$productColumn} = :product_id";
    if ($variantIds !== [] && productDeleteColumnExists($pdo, $table, $variantColumn)) {
        $placeholders = [];
        foreach ($variantIds as $index => $variantId) {
            $key = ':variant_' . $index;
            $placeholders[] = $key;
            $params[$key] = $variantId;
        }
        $parts[] = $variantColumn . ' IN (' . implode(',', $placeholders) . ')';
    }
    if ($parts === []) return 0;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE ' . implode(' OR ', $parts));
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/** @param list<int> $ids */
function productDeleteIn(PDO $pdo, string $table, string $column, array $ids): void
{
    if ($ids === [] || !productDeleteTableExists($pdo, $table)) return;
    $placeholders = [];
    $params = [];
    foreach (array_values($ids) as $index => $id) {
        $key = ':id_' . $index;
        $placeholders[] = $key;
        $params[$key] = $id;
    }
    $stmt = $pdo->prepare('DELETE FROM `' . $table . '` WHERE `' . $column . '` IN (' . implode(',', $placeholders) . ')');
    $stmt->execute($params);
}

function productDelete(PDO $pdo, int $productId): void
{
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id, is_active, publication_status FROM products WHERE id = :id FOR UPDATE');
        $lock->execute([':id' => $productId]);
        $product = $lock->fetch(PDO::FETCH_ASSOC);
        if (!is_array($product)) throw new InvalidArgumentException('Товар не найден. Обновите список и повторите попытку.');

        $publicationReasons = [];
        if ((int)$product['is_active'] !== 0 || (string)$product['publication_status'] !== 'draft') {
            $publicationReasons[] = 'товар должен быть неактивным и иметь статус draft';
        }
        if (productDeleteTableExists($pdo, 'seo_publication_jobs')) {
            $activeJobs = $pdo->prepare("SELECT COUNT(*) FROM seo_publication_jobs WHERE product_id = :product_id AND status IN ('queued', 'running')");
            $activeJobs->execute([':product_id' => $productId]);
            if ((int)$activeJobs->fetchColumn() > 0) $publicationReasons[] = 'есть активные SEO-задачи';
        }
        if ($publicationReasons !== []) throw new ProductDeleteBlockedException($publicationReasons);

        $variantIds = productDeleteVariantIds($pdo, $productId);
        $reasons = [];

        $orderItemCount = 0;
        if (productDeleteTableExists($pdo, 'order_items')) {
            $parts = [];
            $params = [':product_id' => $productId];
            if (productDeleteColumnExists($pdo, 'order_items', 'product_id')) $parts[] = 'product_id = :product_id';
            if ($variantIds !== [] && productDeleteColumnExists($pdo, 'order_items', 'product_variant_id')) {
                $variantPlaceholders = [];
                foreach ($variantIds as $index => $variantId) {
                    $key = ':order_variant_' . $index;
                    $variantPlaceholders[] = $key;
                    $params[$key] = $variantId;
                }
                $parts[] = 'product_variant_id IN (' . implode(',', $variantPlaceholders) . ')';
            }
            if ($parts !== []) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE ' . implode(' OR ', $parts));
                $stmt->execute($params);
                $orderItemCount = (int)$stmt->fetchColumn();
            }
        }
        if ($orderItemCount > 0) $reasons[] = 'есть позиции в заказах';

        if (productDeleteCountByIds($pdo, 'supplier_import_rows', 'matched_product_id', $productId, $variantIds, 'matched_product_variant_id') > 0) {
            $reasons[] = 'есть строки истории импорта';
        }
        if (productDeleteTableExists($pdo, 'supplier_import_rows') && productDeleteTableExists($pdo, 'supplier_product_matches')) {
            $matchParts = ['spm.product_id = :match_product_id'];
            $matchParams = [':match_product_id' => $productId];
            if ($variantIds !== [] && productDeleteColumnExists($pdo, 'supplier_product_matches', 'product_variant_id')) {
                $matchPlaceholders = [];
                foreach ($variantIds as $index => $variantId) {
                    $key = ':match_variant_' . $index;
                    $matchPlaceholders[] = $key;
                    $matchParams[$key] = $variantId;
                }
                $matchParts[] = 'spm.product_variant_id IN (' . implode(',', $matchPlaceholders) . ')';
            }
            $matchStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM supplier_import_rows sir '
                . 'JOIN supplier_product_matches spm ON spm.id = sir.match_id '
                . 'WHERE ' . implode(' OR ', $matchParts)
            );
            $matchStmt->execute($matchParams);
            if ((int)$matchStmt->fetchColumn() > 0 && !in_array('есть строки истории импорта', $reasons, true)) {
                $reasons[] = 'есть строки истории импорта';
            }
        }
        if (productDeleteCountByIds($pdo, 'product_price_publication_audit', 'product_id', $productId, $variantIds) > 0) {
            $reasons[] = 'есть история публикации цен';
        }
        if ($reasons !== []) throw new ProductDeleteBlockedException($reasons);

        productDeleteIn($pdo, 'supplier_offers', 'product_variant_id', $variantIds);
        productDeleteIn($pdo, 'product_variant_price_overrides', 'product_variant_id', $variantIds);
        productDeleteIn($pdo, 'supplier_product_matches', 'product_id', [$productId]);
        productDeleteIn($pdo, 'supplier_product_matches', 'product_variant_id', $variantIds);
        productDeleteIn($pdo, 'product_variants', 'id', $variantIds);

        if (productDeleteTableExists($pdo, 'seo_publication_jobs')) {
            $deleteJobs = $pdo->prepare('DELETE FROM seo_publication_jobs WHERE product_id = :product_id');
            $deleteJobs->execute([':product_id' => $productId]);
        }

        $stmt = $pdo->prepare('DELETE FROM products WHERE id = :id');
        $stmt->execute([':id' => $productId]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Товар не был удалён.');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

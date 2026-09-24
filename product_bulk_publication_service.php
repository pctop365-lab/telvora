<?php

declare(strict_types=1);

require_once __DIR__ . '/seo_publication_service.php';

function productBulkPublicationFilters(mixed $input): array
{
    if (!is_array($input) || array_diff(array_keys($input), ['search', 'brand', 'category', 'country']) !== []) {
        throw new SeoPublicationStateException(400, 'Некорректные фильтры товаров');
    }
    $filters = [];
    foreach (['search', 'brand', 'category', 'country'] as $key) {
        $value = $input[$key] ?? '';
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 500) {
            throw new SeoPublicationStateException(400, 'Некорректный фильтр: ' . $key);
        }
        $filters[$key] = $value;
    }
    return $filters;
}

// Mirror the existing admin table's literal substring and exact category/country
// comparisons. SQL LIKE/collation would treat %, _ and accents differently.
function productBulkPublicationTrim(string $value): string
{
    // JavaScript trim also removes Unicode separators and the BOM.
    return (string)preg_replace('/\A[\p{Z}\x{0009}-\x{000D}\x{FEFF}]+|[\p{Z}\x{0009}-\x{000D}\x{FEFF}]+\z/u', '', $value);
}

function productBulkPublicationMatches(array $product, array $filters): bool
{
    $query = mb_strtolower(productBulkPublicationTrim($filters['search']), 'UTF-8');
    if ($query !== '') {
        $found = false;
        foreach (['name', 'series', 'category', 'screen_size', 'country', 'brand'] as $field) {
            $value = (string)($product[$field] ?? '');
            if ($field === 'brand') $value = productBulkPublicationTrim($value);
            if (str_contains(mb_strtolower($value, 'UTF-8'), $query)) $found = true;
        }
        if (!$found) return false;
    }
    foreach (['category' => 'Все категории', 'country' => 'Все страны'] as $field => $all) {
        if ($filters[$field] !== '' && $filters[$field] !== $all && $filters[$field] !== ($product[$field] ?? '')) return false;
    }
    $brand = mb_strtolower(productBulkPublicationTrim((string)($product['brand'] ?? '')), 'UTF-8');
    return $filters['brand'] === '' || $filters['brand'] === 'Все бренды' ||
        ($filters['brand'] === 'Без бренда' ? $brand === '' : $brand === $filters['brand']);
}

function productBulkPublicationSkipReason(array $product): ?string
{
    if (in_array($product['publication_status'], ['pending_publish', 'pending_unpublish'], true)) {
        return 'Для товара уже выполняется операция публикации';
    }
    if ((int)$product['is_active'] !== 0 || $product['publication_status'] === 'published') {
        return 'Товар уже опубликован';
    }
    if (!in_array($product['publication_status'], ['draft', 'publish_failed'], true)) {
        return 'Текущее состояние товара не допускает публикацию';
    }
    return null;
}

function productBulkPublicationPrepare(PDO $pdo, array $filters): array
{
    $filters = productBulkPublicationFilters($filters);
    // No DOM IDs or page limit: the database supplies the entire filtered set.
    $products = $pdo->query('SELECT id, name, series, category, screen_size, country, brand,
        is_active, publication_status, publication_revision FROM products ORDER BY id ASC')->fetchAll();
    $entries = [];
    $eligible = 0;
    foreach ($products as $product) {
        if (!productBulkPublicationMatches($product, $filters)) continue;
        $entry = ['id' => (int)$product['id'], 'name' => $product['name'],
            'revision' => (int)$product['publication_revision'], 'reason' => productBulkPublicationSkipReason($product)];
        if ($entry['reason'] === null) {
            try {
                // Read-only readiness check used by the individual request.
                productActivationValidateCandidate($pdo, $entry['id']);
                $eligible++;
            } catch (ProductActivationException $error) {
                $entry['reason'] = $error->getMessage();
            }
        }
        $entries[] = $entry;
    }
    return ['filters' => $filters, 'eligible' => $eligible, 'total' => count($entries), 'entries' => $entries];
}

function productBulkPublicationConfirm(PDO $pdo, array $snapshot): array
{
    $result = ['published' => 0, 'queued' => 0, 'skipped' => 0, 'errors' => 0, 'rows' => []];
    $statement = $pdo->prepare('SELECT id, name, series, category, screen_size, country, brand,
        is_active, publication_status, publication_revision FROM products WHERE id = :id');
    foreach ($snapshot['entries'] as $entry) {
        $row = ['id' => $entry['id'], 'name' => $entry['name'], 'status' => 'skipped', 'reason' => $entry['reason']];
        if ($entry['reason'] === null) {
            try {
                $statement->execute([':id' => $entry['id']]);
                $product = $statement->fetch();
                if (!is_array($product)) {
                    $row['reason'] = 'Товар удалён';
                } elseif (!productBulkPublicationMatches($product, $snapshot['filters'])) {
                    $row['reason'] = 'Товар больше не соответствует выбранным фильтрам';
                } elseif (($reason = productBulkPublicationSkipReason($product)) !== null) {
                    $row['reason'] = $reason;
                } else {
                    // Each call owns its transaction and repeats readiness checks.
                    // Expected revision prevents races with individual publish/hide.
                    $publication = seoPublicationRequestPublish($pdo, $entry['id'], $entry['revision']);
                    if ($publication['result'] === 'queued') {
                        $row['status'] = 'queued';
                        $row['reason'] = null;
                        $row['job_id'] = (int)$publication['job']['id'];
                    } else {
                        $row['reason'] = 'Запрос уже обработан; новая публикация не создана';
                    }
                }
            } catch (SeoPublicationStateException|SeoPublicationJobException|ProductActivationException $error) {
                $row['status'] = $error->httpStatus >= 500 ? 'errors' : 'skipped';
                $row['reason'] = $error->getMessage();
            } catch (Throwable $error) {
                error_log('Bulk product publication failed: ' . $error->getMessage());
                $row['status'] = 'errors';
                $row['reason'] = 'Не удалось передать товар на публикацию';
            }
        }
        $result[$row['status']]++;
        $result['rows'][] = $row;
    }
    return $result;
}

function productBulkPublicationCreatePreview(PDO $pdo, array &$session, array $filters): array
{
    $snapshot = productBulkPublicationPrepare($pdo, $filters);
    $stored = array_filter($session['product_publication_snapshots'] ?? [],
        static fn(array $item): bool => $item['expires'] >= time());
    if (count($stored) >= 5) array_shift($stored);
    $id = bin2hex(random_bytes(32));
    $stored[$id] = ['expires' => time() + 900, 'snapshot' => $snapshot];
    $session['product_publication_snapshots'] = $stored;
    return ['preview_id' => $id, 'eligible' => $snapshot['eligible'], 'total' => $snapshot['total']];
}

function productBulkPublicationConfirmPreview(PDO $pdo, array &$session, string $id): array
{
    $stored = $session['product_publication_snapshots'][$id] ?? null;
    if (!is_array($stored) || $stored['expires'] < time()) {
        throw new SeoPublicationStateException(409, 'Проверка устарела. Повторите подготовку публикации');
    }
    // products.php retains the PHP session lock through this operation. Requests
    // from other sessions are serialized by the existing per-product service.
    if (!isset($stored['result'])) {
        $stored['result'] = productBulkPublicationConfirm($pdo, $stored['snapshot']);
        $session['product_publication_snapshots'][$id] = $stored;
    }
    return $stored['result'];
}

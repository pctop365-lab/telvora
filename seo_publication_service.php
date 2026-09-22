<?php

declare(strict_types=1);

require_once __DIR__ . '/product_activation_service.php';
require_once __DIR__ . '/seo_publication_job_service.php';

final class SeoPublicationStateException extends RuntimeException
{
    public function __construct(public readonly int $httpStatus, string $message)
    {
        parent::__construct($message);
    }
}

function seoPublicationProductStatus(array $product): string
{
    $status = $product['publication_status'] ?? null;
    if (!is_string($status) || $status === '') {
        throw new SeoPublicationStateException(500, 'Состояние публикации товара не настроено');
    }
    return $status;
}

function seoPublicationRevision(array $product): int
{
    $revision = filter_var($product['publication_revision'] ?? null, FILTER_VALIDATE_INT);
    if ($revision === false || $revision < 0) {
        throw new SeoPublicationStateException(500, 'Revision публикации товара некорректна');
    }
    return (int)$revision;
}

/**
 * Pure state decision used by the transactional service and fixture tests.
 * No database, filesystem, shell or network operation is performed here.
 */
function seoPublicationTransition(array $product, string $operation, int $expectedRevision, ?array $activeJob): array
{
    seoPublicationAssertOperation($operation);
    $status = seoPublicationProductStatus($product);
    $revision = seoPublicationRevision($product);
    if ($expectedRevision !== $revision) {
        throw new SeoPublicationStateException(409, 'Состояние товара уже изменилось. Обновите страницу.');
    }

    if ($activeJob !== null) {
        $sameOperation = (string)($activeJob['operation'] ?? '') === $operation;
        $sameRevision = (int)($activeJob['requested_revision'] ?? -1) === $revision;
        $activeStatus = in_array((string)($activeJob['status'] ?? ''), ['queued', 'running'], true);
        if ($sameOperation && $sameRevision && $activeStatus) {
            return [
                'kind' => 'idempotent',
                'status' => $status,
                'is_active' => (int)$product['is_active'],
                'revision' => $revision,
                'job_id' => (int)$activeJob['id'],
            ];
        }
    }

    if ($operation === 'publish') {
        if ($status === 'published' && (int)$product['is_active'] === 1) {
            return ['kind' => 'noop', 'status' => 'published', 'is_active' => 1, 'revision' => $revision, 'job_id' => null];
        }
        if ($status === 'pending_unpublish' && (int)$product['is_active'] === 1) {
            return [
                'kind' => 'reversal', 'status' => 'published', 'is_active' => 1,
                'revision' => $revision + 1, 'job_id' => null,
                'supersede_job_id' => $activeJob === null ? null : (int)$activeJob['id'],
            ];
        }
        if (in_array($status, ['draft', 'publish_failed'], true) && (int)$product['is_active'] === 0) {
            return ['kind' => 'enqueue', 'status' => 'pending_publish', 'is_active' => 0, 'revision' => $revision + 1, 'job_id' => null];
        }
    } else {
        if (in_array($status, ['draft', 'publish_failed'], true) && (int)$product['is_active'] === 0) {
            return ['kind' => 'noop', 'status' => 'draft', 'is_active' => 0, 'revision' => $revision, 'job_id' => null];
        }
        if ($status === 'pending_publish' && (int)$product['is_active'] === 0) {
            return [
                'kind' => 'reversal', 'status' => 'draft', 'is_active' => 0,
                'revision' => $revision + 1, 'job_id' => null,
                'supersede_job_id' => $activeJob === null ? null : (int)$activeJob['id'],
            ];
        }
        if (in_array($status, ['published', 'unpublish_failed'], true) && (int)$product['is_active'] === 1) {
            return ['kind' => 'enqueue', 'status' => 'pending_unpublish', 'is_active' => 1, 'revision' => $revision + 1, 'job_id' => null];
        }
    }

    throw new SeoPublicationStateException(409, 'Переход состояния публикации недопустим');
}

function seoPublicationFinalizationDecision(array $product, int $requestedRevision, string $operation): array
{
    seoPublicationAssertOperation($operation);
    $revision = seoPublicationRevision($product);
    if ($revision !== $requestedRevision) {
        throw new SeoPublicationStateException(409, 'SEO-задача устарела');
    }
    $status = seoPublicationProductStatus($product);
    $expected = $operation === 'publish' ? 'pending_publish' : 'pending_unpublish';
    if ($status !== $expected) {
        throw new SeoPublicationStateException(409, 'Состояние товара не соответствует SEO-задаче');
    }
    return [
        'status' => $operation === 'publish' ? 'published' : 'draft',
        'is_active' => $operation === 'publish' ? 1 : 0,
        'revision' => $revision,
    ];
}

function seoPublicationIsLockConflict(PDOException $error): bool
{
    $driverCode = (int)($error->errorInfo[1] ?? 0);
    return $driverCode === 1205 || $driverCode === 1213;
}

function seoPublicationFailureInvariant(string $status): bool
{
    return ($status === 'publish_failed') || ($status === 'unpublish_failed');
}

function seoPublicationProductLock(PDO $pdo, int $productId): array
{
    seoPublicationAssertProductId($productId);
    $stmt = $pdo->prepare(
        'SELECT id, is_active, publication_status, publication_revision
           FROM products WHERE id = :id LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([':id' => $productId]);
    $product = $stmt->fetch();
    if (!is_array($product)) throw new SeoPublicationStateException(404, 'Товар не найден');
    return $product;
}

function seoPublicationRunTransaction(PDO $pdo, callable $callback, int $maxAttempts = 3): mixed
{
    if ($maxAttempts < 1) throw new InvalidArgumentException('Некорректное число попыток транзакции');
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $pdo->beginTransaction();
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($error instanceof PDOException && seoPublicationIsLockConflict($error) && $attempt < $maxAttempts) {
                continue;
            }
            throw $error;
        }
    }
    throw new LogicException('SEO publication transaction retry loop exhausted');
}

function seoPublicationRequest(PDO $pdo, int $productId, string $operation, int $expectedRevision, ?string $batchId = null): array
{
    seoPublicationAssertOperation($operation);
    $batchId ??= seoPublicationNewBatchId();
    seoPublicationAssertBatchId($batchId);

    return seoPublicationRunTransaction($pdo, static function () use ($pdo, $productId, $operation, $expectedRevision, $batchId): array {
        $product = seoPublicationProductLock($pdo, $productId);
        $activeJob = seoPublicationJobFindActive($pdo, $productId, seoPublicationRevision($product));
        $decision = seoPublicationTransition($product, $operation, $expectedRevision, $activeJob);

        if ($decision['kind'] === 'idempotent') {
            return ['result' => 'idempotent', 'job' => seoPublicationJobGet($pdo, (int)$decision['job_id']), 'product' => $product];
        }
        if ($decision['kind'] === 'noop') {
            return ['result' => 'noop', 'job' => null, 'product' => $product];
        }

        if ($decision['kind'] === 'reversal') {
            if (!empty($decision['supersede_job_id'])) seoPublicationJobMarkSuperseded($pdo, (int)$decision['supersede_job_id']);
            $stmt = $pdo->prepare(
                'UPDATE products
                    SET publication_status = :status, publication_revision = :revision
                  WHERE id = :id'
            );
            $stmt->execute([
                ':status' => $decision['status'],
                ':revision' => $decision['revision'],
                ':id' => $productId,
            ]);
            return ['result' => 'reversed', 'job' => null, 'product' => array_replace($product, ['publication_status' => $decision['status'], 'publication_revision' => $decision['revision']])];
        }

        if ($operation === 'publish') productActivationValidateCandidate($pdo, $productId);
        $stmt = $pdo->prepare(
            'UPDATE products
                SET publication_status = :status, publication_revision = :revision
              WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $decision['status'],
            ':revision' => $decision['revision'],
            ':id' => $productId,
        ]);
        $job = seoPublicationJobCreate($pdo, $productId, $operation, $decision['revision'], $batchId);
        return ['result' => 'queued', 'job' => $job, 'product' => array_replace($product, ['publication_status' => $decision['status'], 'publication_revision' => $decision['revision']])];
    });
}

function seoPublicationRequestPublish(PDO $pdo, int $productId, int $expectedRevision, ?string $batchId = null): array
{
    return seoPublicationRequest($pdo, $productId, 'publish', $expectedRevision, $batchId);
}

function seoPublicationRequestUnpublish(PDO $pdo, int $productId, int $expectedRevision, ?string $batchId = null): array
{
    return seoPublicationRequest($pdo, $productId, 'unpublish', $expectedRevision, $batchId);
}

function seoPublicationJobProductLocator(PDO $pdo, int $jobId): int
{
    if ($jobId <= 0) throw new SeoPublicationStateException(400, 'Некорректный ID SEO-задачи');
    $stmt = $pdo->prepare('SELECT product_id FROM seo_publication_jobs WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $jobId]);
    $productId = $stmt->fetchColumn();
    if ($productId === false) throw new SeoPublicationStateException(404, 'SEO-задача не найдена');
    seoPublicationAssertProductId((int)$productId);
    return (int)$productId;
}

function seoPublicationJobLock(PDO $pdo, int $jobId): array
{
    $stmt = $pdo->prepare('SELECT * FROM seo_publication_jobs WHERE id = :id LIMIT 1 FOR UPDATE');
    $stmt->execute([':id' => $jobId]);
    $job = $stmt->fetch();
    if (!is_array($job)) throw new SeoPublicationStateException(404, 'SEO-задача не найдена');
    return $job;
}

function seoPublicationFinalize(PDO $pdo, int $jobId, string $operation): array
{
    seoPublicationAssertOperation($operation);
    return seoPublicationRunTransaction($pdo, static function () use ($pdo, $jobId, $operation): array {
        // The locator is deliberately non-locking. Authoritative validation
        // happens only after the product and exact job rows are locked.
        $locatedProductId = seoPublicationJobProductLocator($pdo, $jobId);
        $product = seoPublicationProductLock($pdo, $locatedProductId);
        $job = seoPublicationJobLock($pdo, $jobId);
        if ((int)$job['product_id'] !== (int)$product['id']) {
            throw new SeoPublicationStateException(409, 'SEO-задача не соответствует товару');
        }
        if ((string)$job['operation'] !== $operation || (string)$job['status'] !== 'running') {
            throw new SeoPublicationStateException(409, 'SEO-задача не готова к финализации');
        }
        $decision = seoPublicationFinalizationDecision($product, (int)$job['requested_revision'], $operation);
        $stmt = $pdo->prepare(
            'UPDATE products SET is_active = :is_active, publication_status = :status WHERE id = :id'
        );
        $stmt->execute([
            ':is_active' => $decision['is_active'],
            ':status' => $decision['status'],
            ':id' => (int)$product['id'],
        ]);
        $jobUpdate = $pdo->prepare(
            "UPDATE seo_publication_jobs
                SET status = 'completed', completed_at = CURRENT_TIMESTAMP
              WHERE id = :id AND status = 'running'"
        );
        $jobUpdate->execute([':id' => $jobId]);
        if ($jobUpdate->rowCount() !== 1) {
            throw new SeoPublicationStateException(409, 'SEO job changed concurrently');
        }
        return ['job' => $job + ['status' => 'completed'], 'product' => $product + $decision];
    });
}

function seoPublicationFinalizePublish(PDO $pdo, int $jobId): array
{
    return seoPublicationFinalize($pdo, $jobId, 'publish');
}

function seoPublicationFinalizeUnpublish(PDO $pdo, int $jobId): array
{
    return seoPublicationFinalize($pdo, $jobId, 'unpublish');
}

function seoPublicationMarkFailed(PDO $pdo, int $jobId, string $message): void
{
    if ($message === '') throw new SeoPublicationStateException(400, 'Failure message is required');
    seoPublicationRunTransaction($pdo, static function () use ($pdo, $jobId, $message): void {
        $productId = seoPublicationJobProductLocator($pdo, $jobId);
        $product = seoPublicationProductLock($pdo, $productId);
        $job = seoPublicationJobLock($pdo, $jobId);
        if ((int)$job['product_id'] !== (int)$product['id']) {
            throw new SeoPublicationStateException(409, 'SEO job does not belong to product');
        }
        $operation = (string)$job['operation'];
        seoPublicationAssertOperation($operation);
        if ((string)$job['status'] !== 'running') {
            throw new SeoPublicationStateException(409, 'SEO job is not running');
        }
        if (seoPublicationRevision($product) !== (int)$job['requested_revision']) {
            throw new SeoPublicationStateException(409, 'SEO job is stale');
        }
        $expected = $operation === 'publish' ? 'pending_publish' : 'pending_unpublish';
        if (seoPublicationProductStatus($product) !== $expected) {
            throw new SeoPublicationStateException(409, 'Product state does not match SEO job');
        }
        $status = $operation === 'publish' ? 'publish_failed' : 'unpublish_failed';
        $stmt = $pdo->prepare('UPDATE products SET publication_status = :status, is_active = :is_active WHERE id = :id');
        $stmt->execute([':status' => $status, ':is_active' => $operation === 'publish' ? 0 : 1, ':id' => (int)$product['id']]);
        $jobUpdate = $pdo->prepare(
            "UPDATE seo_publication_jobs
                SET status = 'failed', last_error = :last_error, completed_at = CURRENT_TIMESTAMP
              WHERE id = :id AND status = 'running'"
        );
        $jobUpdate->execute([':last_error' => $message, ':id' => $jobId]);
        if ($jobUpdate->rowCount() !== 1) {
            throw new SeoPublicationStateException(409, 'SEO job changed concurrently');
        }
    });
}

function seoPublicationMarkPublishFailed(PDO $pdo, int $jobId, string $message): void
{
    seoPublicationMarkFailed($pdo, $jobId, $message);
}

function seoPublicationMarkUnpublishFailed(PDO $pdo, int $jobId, string $message): void
{
    seoPublicationMarkFailed($pdo, $jobId, $message);
}

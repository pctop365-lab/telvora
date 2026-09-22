<?php

declare(strict_types=1);

final class SeoPublicationJobException extends RuntimeException
{
    public function __construct(public readonly int $httpStatus, string $message)
    {
        parent::__construct($message);
    }
}

const SEO_PUBLICATION_JOB_OPERATIONS = ['publish', 'unpublish'];
const SEO_PUBLICATION_JOB_STATUSES = ['queued', 'running', 'completed', 'failed', 'superseded'];

function seoPublicationAssertOperation(string $operation): void
{
    if (!in_array($operation, SEO_PUBLICATION_JOB_OPERATIONS, true)) {
        throw new SeoPublicationJobException(400, 'Недопустимая операция SEO-публикации');
    }
}

function seoPublicationAssertJobStatus(string $status): void
{
    if (!in_array($status, SEO_PUBLICATION_JOB_STATUSES, true)) {
        throw new SeoPublicationJobException(400, 'Недопустимый статус SEO-задачи');
    }
}

function seoPublicationAssertProductId(int $productId): void
{
    if ($productId <= 0) {
        throw new SeoPublicationJobException(400, 'Некорректный ID товара');
    }
}

function seoPublicationAssertBatchId(string $batchId): void
{
    if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $batchId) !== 1) {
        throw new SeoPublicationJobException(400, 'Некорректный batch ID');
    }
}

function seoPublicationNewBatchId(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
        . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-'
        . substr($hex, 20, 12);
}

function seoPublicationJobFindActive(PDO $pdo, int $productId, int $revision, ?string $operation = null): ?array
{
    seoPublicationAssertProductId($productId);
    if ($operation !== null) seoPublicationAssertOperation($operation);
    if ($revision < 0) throw new SeoPublicationJobException(400, 'Некорректная revision');

    $sql = "SELECT id, product_id, operation, requested_revision, status, batch_id,
                snapshot_hash, release_sha, package_sha256, backup_reference,
                attempt_count, last_error, created_at, started_at, completed_at, updated_at
           FROM seo_publication_jobs
          WHERE product_id = :product_id
            AND requested_revision = :requested_revision
            AND status IN ('queued', 'running')";
    $params = [
        ':product_id' => $productId,
        ':requested_revision' => $revision,
    ];
    if ($operation !== null) {
        $sql .= ' AND operation = :operation';
        $params[':operation'] = $operation;
    }
    $sql .= ' ORDER BY id DESC LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function seoPublicationJobFindByIntent(PDO $pdo, int $productId, int $revision, string $operation): ?array
{
    seoPublicationAssertProductId($productId);
    seoPublicationAssertOperation($operation);
    if ($revision < 0) throw new SeoPublicationJobException(400, 'РќРµРєРѕСЂСЂРµРєС‚РЅР°СЏ revision');
    $stmt = $pdo->prepare(
        'SELECT * FROM seo_publication_jobs
          WHERE product_id = :product_id
            AND requested_revision = :requested_revision
            AND operation = :operation
          LIMIT 1'
    );
    $stmt->execute([
        ':product_id' => $productId,
        ':requested_revision' => $revision,
        ':operation' => $operation,
    ]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function seoPublicationJobCreate(PDO $pdo, int $productId, string $operation, int $revision, string $batchId): array
{
    seoPublicationAssertProductId($productId);
    seoPublicationAssertOperation($operation);
    seoPublicationAssertBatchId($batchId);
    if ($revision < 0) throw new SeoPublicationJobException(400, 'Некорректная revision');

    $existing = seoPublicationJobFindActive($pdo, $productId, $revision, $operation);
    if ($existing !== null) return $existing;

    $stmt = $pdo->prepare(
        'INSERT INTO seo_publication_jobs
            (product_id, operation, requested_revision, status, batch_id)
         VALUES (:product_id, :operation, :requested_revision, \'queued\', :batch_id)'
    );
    try {
        $stmt->execute([
            ':product_id' => $productId,
            ':operation' => $operation,
            ':requested_revision' => $revision,
            ':batch_id' => $batchId,
        ]);
    } catch (PDOException $error) {
        $driverCode = (int)($error->errorInfo[1] ?? 0);
        if ($driverCode !== 1062) throw $error;
        $existing = seoPublicationJobFindByIntent($pdo, $productId, $revision, $operation);
        if ($existing === null) throw $error;
        return $existing;
    }
    $id = (int)$pdo->lastInsertId();
    return seoPublicationJobGet($pdo, $id);
}

function seoPublicationJobGet(PDO $pdo, int $jobId): array
{
    if ($jobId <= 0) throw new SeoPublicationJobException(400, 'Некорректный ID SEO-задачи');
    $stmt = $pdo->prepare('SELECT * FROM seo_publication_jobs WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $jobId]);
    $row = $stmt->fetch();
    if (!is_array($row)) throw new SeoPublicationJobException(404, 'SEO-задача не найдена');
    return $row;
}

function seoPublicationJobMarkSuperseded(PDO $pdo, int $jobId): void
{
    if ($jobId <= 0) throw new SeoPublicationJobException(400, 'Некорректный ID SEO-задачи');
    $stmt = $pdo->prepare(
        "UPDATE seo_publication_jobs
            SET status = 'superseded', completed_at = CURRENT_TIMESTAMP
          WHERE id = :id AND status IN ('queued', 'running')"
    );
    $stmt->execute([':id' => $jobId]);
}

function seoPublicationJobRequeueFailed(PDO $pdo, int $jobId): array
{
    $job = seoPublicationJobGet($pdo, $jobId);
    if ((string)$job['status'] !== 'failed') {
        throw new SeoPublicationJobException(409, 'Повторить можно только неуспешную SEO-задачу');
    }
    $stmt = $pdo->prepare(
        "UPDATE seo_publication_jobs
            SET status = 'queued', last_error = NULL, completed_at = NULL
          WHERE id = :id AND status = 'failed'"
    );
    $stmt->execute([':id' => $jobId]);
    return seoPublicationJobGet($pdo, $jobId);
}

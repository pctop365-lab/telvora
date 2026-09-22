<?php

declare(strict_types=1);

require_once __DIR__ . '/seo_publication_service.php';

function seoPublicationClaimQueuedBatch(PDO $pdo, int $limit = 100, ?string $batchId = null): array
{
    $limit = max(1, min(100, $limit));
    $batchId ??= seoPublicationNewBatchId();
    seoPublicationAssertBatchId($batchId);

    return seoPublicationRunTransaction($pdo, static function () use ($pdo, $limit, $batchId): array {
        $sql = "SELECT j.*
                  FROM seo_publication_jobs j
                  JOIN products p ON p.id = j.product_id
                 WHERE j.status = 'queued'
                   AND j.requested_revision = p.publication_revision
                   AND ((j.operation = 'publish' AND p.publication_status = 'pending_publish')
                     OR (j.operation = 'unpublish' AND p.publication_status = 'pending_unpublish'))
                 ORDER BY j.product_id ASC, j.id ASC
                 LIMIT {$limit}
                 FOR UPDATE";
        $jobs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if ($jobs === []) return ['status' => 'NO_WORK', 'batch_id' => $batchId, 'jobs' => []];

        $update = $pdo->prepare(
            "UPDATE seo_publication_jobs
                SET batch_id = :batch_id, status = 'running', started_at = CURRENT_TIMESTAMP,
                    attempt_count = attempt_count + 1
              WHERE id = :id AND status = 'queued'"
        );
        foreach ($jobs as &$job) {
            $update->execute([':batch_id' => $batchId, ':id' => (int)$job['id']]);
            if ($update->rowCount() !== 1) throw new RuntimeException('SEO job changed while claiming batch');
            $job['batch_id'] = $batchId;
            $job['status'] = 'running';
        }
        unset($job);
        return ['status' => 'CLAIMED', 'batch_id' => $batchId, 'jobs' => $jobs];
    });
}

function seoPublicationBatchJobs(PDO $pdo, string $batchId): array
{
    seoPublicationAssertBatchId($batchId);
    $stmt = $pdo->prepare('SELECT * FROM seo_publication_jobs WHERE batch_id = :batch_id ORDER BY product_id ASC, id ASC');
    $stmt->execute([':batch_id' => $batchId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function seoPublicationValidateBatchStillCurrent(PDO $pdo, string $batchId): array
{
    $jobs = seoPublicationBatchJobs($pdo, $batchId);
    foreach ($jobs as $job) {
        if ((string)$job['status'] !== 'running') throw new SeoPublicationStateException(409, 'SEO batch contains a non-running job');
        $product = seoPublicationProductLock($pdo, (int)$job['product_id']);
        if ((int)$product['publication_revision'] !== (int)$job['requested_revision']) {
            throw new SeoPublicationStateException(409, 'SEO batch contains a stale job');
        }
    }
    return $jobs;
}

function seoPublicationBatchFail(PDO $pdo, string $batchId, string $message): void
{
    foreach (seoPublicationBatchJobs($pdo, $batchId) as $job) {
        if ((string)$job['status'] !== 'running') continue;
        try { seoPublicationMarkFailed($pdo, (int)$job['id'], $message); } catch (SeoPublicationStateException) { /* stale jobs are not modified */ }
    }
}

function seoPublicationBatchComplete(PDO $pdo, string $batchId): array
{
    $jobs = seoPublicationValidateBatchStillCurrent($pdo, $batchId);
    $completed = [];
    foreach ($jobs as $job) {
        $completed[] = ((string)$job['operation'] === 'publish')
            ? seoPublicationFinalizePublish($pdo, (int)$job['id'])
            : seoPublicationFinalizeUnpublish($pdo, (int)$job['id']);
    }
    return $completed;
}

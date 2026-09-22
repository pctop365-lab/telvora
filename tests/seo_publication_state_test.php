<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/seo_publication_service.php';

function seoStateAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("FAIL $message");
    }
    echo "PASS $message\n";
}

function seoStateProduct(string $status, int $active, int $revision = 10): array
{
    return [
        'id' => 7,
        'is_active' => $active,
        'publication_status' => $status,
        'publication_revision' => $revision,
    ];
}

$publish = seoPublicationTransition(seoStateProduct('draft', 0), 'publish', 10, null);
seoStateAssert($publish['kind'] === 'enqueue', 'draft publish enqueues a job');
seoStateAssert($publish['status'] === 'pending_publish', 'draft publish enters pending_publish');
seoStateAssert($publish['revision'] === 11, 'accepted publish increments revision');

$duplicate = seoPublicationTransition(
    seoStateProduct('pending_publish', 0, 11),
    'publish',
    11,
    ['id' => 42, 'operation' => 'publish', 'requested_revision' => 11, 'status' => 'queued']
);
seoStateAssert($duplicate['kind'] === 'idempotent', 'duplicate publish is idempotent');
seoStateAssert($duplicate['revision'] === 11, 'duplicate publish does not increment revision');
seoStateAssert($duplicate['job_id'] === 42, 'duplicate publish returns existing job');

$publishedNoop = seoPublicationTransition(seoStateProduct('published', 1), 'publish', 10, null);
seoStateAssert($publishedNoop['kind'] === 'noop', 'published publish is a Phase 1A no-op');

$unpublish = seoPublicationTransition(seoStateProduct('published', 1), 'unpublish', 10, null);
seoStateAssert($unpublish['kind'] === 'enqueue', 'published unpublish enqueues a job');
seoStateAssert($unpublish['status'] === 'pending_unpublish', 'published unpublish enters pending_unpublish');
seoStateAssert($unpublish['revision'] === 11, 'accepted unpublish increments revision');

$draftUnpublish = seoPublicationTransition(seoStateProduct('draft', 0), 'unpublish', 10, null);
seoStateAssert($draftUnpublish['kind'] === 'noop', 'draft unpublish is a no-op');
seoStateAssert($draftUnpublish['revision'] === 10, 'draft unpublish does not increment revision');

$reversePublish = seoPublicationTransition(
    seoStateProduct('pending_publish', 0, 11),
    'unpublish',
    11,
    ['id' => 42, 'operation' => 'publish', 'requested_revision' => 11, 'status' => 'running']
);
seoStateAssert($reversePublish['kind'] === 'reversal', 'pending publish can be reversed');
seoStateAssert($reversePublish['status'] === 'draft', 'publish reversal returns to draft');
seoStateAssert($reversePublish['supersede_job_id'] === 42, 'publish reversal supersedes old job');
seoStateAssert($reversePublish['revision'] === 12, 'publish reversal increments revision');

$reverseUnpublish = seoPublicationTransition(
    seoStateProduct('pending_unpublish', 1, 11),
    'publish',
    11,
    ['id' => 43, 'operation' => 'unpublish', 'requested_revision' => 11, 'status' => 'queued']
);
seoStateAssert($reverseUnpublish['kind'] === 'reversal', 'pending unpublish can be reversed');
seoStateAssert($reverseUnpublish['status'] === 'published', 'unpublish reversal returns to published');
seoStateAssert($reverseUnpublish['supersede_job_id'] === 43, 'unpublish reversal supersedes old job');

try {
    seoPublicationTransition(seoStateProduct('draft', 0, 10), 'publish', 9, null);
    throw new RuntimeException('FAIL stale revision was accepted');
} catch (SeoPublicationStateException $error) {
    seoStateAssert($error->httpStatus === 409, 'stale expected revision is rejected');
}

try {
    seoPublicationTransition(seoStateProduct('draft', 0), 'delete', 10, null);
    throw new RuntimeException('FAIL invalid operation was accepted');
} catch (SeoPublicationJobException $error) {
    seoStateAssert($error->httpStatus === 400, 'invalid operation is rejected');
}

try {
    seoPublicationAssertJobStatus('invalid');
    throw new RuntimeException('FAIL invalid job status was accepted');
} catch (SeoPublicationJobException $error) {
    seoStateAssert($error->httpStatus === 400, 'invalid job status is rejected');
}

$publishFinalize = seoPublicationFinalizationDecision(seoStateProduct('pending_publish', 0, 11), 11, 'publish');
seoStateAssert($publishFinalize['status'] === 'published', 'publish finalization changes status');
seoStateAssert($publishFinalize['is_active'] === 1, 'publish finalization activates product');
seoStateAssert($publishFinalize['revision'] === 11, 'publish finalization preserves revision');

$unpublishFinalize = seoPublicationFinalizationDecision(seoStateProduct('pending_unpublish', 1, 11), 11, 'unpublish');
seoStateAssert($unpublishFinalize['status'] === 'draft', 'unpublish finalization changes status');
seoStateAssert($unpublishFinalize['is_active'] === 0, 'unpublish finalization deactivates product');
seoStateAssert($unpublishFinalize['revision'] === 11, 'unpublish finalization preserves revision');

try {
    seoPublicationFinalizationDecision(seoStateProduct('pending_publish', 0, 12), 11, 'publish');
    throw new RuntimeException('FAIL stale job was allowed to finalize');
} catch (SeoPublicationStateException $error) {
    seoStateAssert($error->httpStatus === 409, 'stale job cannot finalize');
}

try {
    seoPublicationFinalizationDecision(seoStateProduct('draft', 0, 11), 11, 'publish');
    throw new RuntimeException('FAIL wrong product state was allowed to finalize');
} catch (SeoPublicationStateException $error) {
    seoStateAssert($error->httpStatus === 409, 'wrong product state cannot finalize');
}

foreach (['publish_failed', 'unpublish_failed'] as $failure) {
    seoStateAssert(seoPublicationFailureInvariant($failure), "$failure invariant is valid");
}

seoStateAssert(seoPublicationFailureInvariant('publish_failed'), 'publish failure remains inactive by contract');
seoStateAssert(seoPublicationFailureInvariant('unpublish_failed'), 'unpublish failure preserves active state by contract');

echo "PASS SEO publication state fixtures\n";

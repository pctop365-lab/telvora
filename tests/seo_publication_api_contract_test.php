<?php

declare(strict_types=1);

$migrationFiles = glob(dirname(__DIR__) . '/database/migrations/*seo_publication*.sql');
if ($migrationFiles === []) {
    throw new RuntimeException('FAIL SEO publication migration is missing');
}
$migration = file_get_contents($migrationFiles[0]);
if (!is_string($migration)) throw new RuntimeException('FAIL cannot read migration');

function seoContractAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("FAIL $message");
    echo "PASS $message\n";
}

seoContractAssert(str_contains($migration, 'publication_status'), 'migration declares publication_status');
seoContractAssert(str_contains($migration, 'publication_revision'), 'migration declares publication_revision');
seoContractAssert(str_contains($migration, 'idx_products_publication'), 'migration declares publication index');
seoContractAssert(str_contains($migration, 'uq_seo_publication_job_intent'), 'migration declares unique publication intent key');
seoContractAssert((bool)preg_match('/UNIQUE\s+KEY\s+uq_seo_publication_job_intent\s*\(\s*product_id\s*,\s*requested_revision\s*,\s*operation\s*\)/is', $migration), 'unique key covers product/revision/operation');
seoContractAssert((bool)preg_match('/product_id\s+INT\s+UNSIGNED\s+NOT\s+NULL/i', $migration), 'job product_id is NOT NULL');
seoContractAssert(str_contains($migration, 'ON DELETE RESTRICT'), 'job product foreign key is restrictive');
seoContractAssert(str_contains($migration, "'published'") || str_contains($migration, "= 'published'"), 'migration backfills active products as published');
seoContractAssert(str_contains($migration, "'draft'") || str_contains($migration, "= 'draft'"), 'migration backfills inactive products as draft');

$activation = file_get_contents(dirname(__DIR__) . '/product_activation_service.php');
if (!is_string($activation)) throw new RuntimeException('FAIL cannot read activation service');
seoContractAssert(str_contains($activation, 'productActivationValidateCandidate'), 'validation-only activation API exists');
seoContractAssert(str_contains($activation, 'function productActivationRun'), 'existing activation API remains');

$publication = file_get_contents(dirname(__DIR__) . '/seo_publication_service.php');
if (!is_string($publication)) throw new RuntimeException('FAIL cannot read publication service');
seoContractAssert((bool)preg_match('/function\s+seoPublicationMarkFailed\s*\(\s*PDO\s+\$pdo\s*,\s*int\s+\$jobId\s*,\s*string\s+\$message\s*\)/', $publication), 'failure helper is bound to exact job id');
seoContractAssert(str_contains($publication, 'function seoPublicationRunTransaction'), 'publication transaction helper exists');
seoContractAssert(str_contains($publication, '1205') && str_contains($publication, '1213'), 'publication transactions handle lock conflicts');
seoContractAssert((bool)preg_match('/SELECT product_id FROM seo_publication_jobs.*?seoPublicationProductLock/is', $publication), 'job locator precedes product lock');
seoContractAssert((bool)preg_match('/seoPublicationProductLock\(\$pdo.*?SELECT \* FROM seo_publication_jobs.*?FOR UPDATE/is', $publication), 'product lock precedes job lock');
seoContractAssert(!str_contains($publication, 'function seoPublicationMarkFailed(PDO $pdo, int $productId'), 'old product-based failure API is removed');
seoContractAssert(substr_count($publication, "WHERE id = :id AND status = 'running'") >= 2, 'finalization and failure update exact running job');
seoContractAssert(str_contains($publication, 'if ($jobUpdate->rowCount() !== 1)'), 'product/job transitions fail atomically on lost job update');

$publicApi = file_get_contents(dirname(__DIR__) . '/products.php');
if (!is_string($publicApi)) throw new RuntimeException('FAIL cannot read products endpoint');
seoContractAssert((bool)preg_match('/WHERE\s+is_active\s*=\s*1/i', $publicApi), 'public API keeps is_active gate');
seoContractAssert(!str_contains($publicApi, "publication_status = 1"), 'public API has no invalid publication status gate');
seoContractAssert(str_contains($publicApi, "'request_publish', 'request_unpublish'"), 'publication request actions are registered');
seoContractAssert(str_contains($publicApi, "empty(\$_SESSION['telvora_admin'])"), 'publication request actions remain behind admin session');
seoContractAssert((bool)preg_match('/if \(in_array\(\$action, \[\x27request_publish\x27, \x27request_unpublish\x27\].*?\$_SERVER\x5b\x27REQUEST_METHOD\x27\x5d.*?POST/is', $publicApi), 'publication request actions require POST');
seoContractAssert(str_contains($publicApi, "\$_SESSION['csrf_token']") && str_contains($publicApi, "HTTP_X_CSRF_TOKEN"), 'publication request actions use existing CSRF mechanism');
seoContractAssert(str_contains($publicApi, "expected_revision") && str_contains($publicApi, "FILTER_VALIDATE_INT"), 'publication actions validate expected revision and IDs');
seoContractAssert(str_contains($publicApi, 'seoPublicationRequestPublish') && str_contains($publicApi, 'seoPublicationRequestUnpublish'), 'publication actions delegate to state service');
seoContractAssert(str_contains($publicApi, "in_array(\$action, ['request_publish', 'request_unpublish'], true)"), 'publication operation is selected only from the fixed action allowlist');
seoContractAssert(str_contains($publicApi, 'publication_status,') && str_contains($publicApi, 'publication_revision,'), 'admin list selects publication state fields');
seoContractAssert(str_contains($publicApi, 'productActivationRun($pdo, $id'), 'legacy is_active activation path remains');
seoContractAssert(str_contains($publicApi, "'publication_status' =>") && str_contains($publicApi, "'publication_revision' =>"), 'publication response serializes state fields');

echo "PASS SEO publication API contract\n";

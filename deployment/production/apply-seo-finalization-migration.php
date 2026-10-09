<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--apply') {
    fwrite(STDERR, "Usage: php deployment/production/apply-seo-finalization-migration.php --apply\n");
    exit(2);
}
define('TELVORA_PUBLICATION_WORKER_LIBRARY', true);
require_once dirname(__DIR__, 2) . '/seo_publication_worker.php';
$pdo = seoPublicationWorkerPdo();
$sql = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/20261009_015_seo_publication_finalization_audit.sql');
if (!is_string($sql)) throw new RuntimeException('Missing audit migration');
$pdo->exec($sql);
$columns = array_column($pdo->query('SHOW COLUMNS FROM seo_publication_finalization_audit')->fetchAll(PDO::FETCH_ASSOC), 'Field');
foreach (['evidence_key','source','release_sha','package_sha256','snapshot_hash','audit_json'] as $column) {
    if (!in_array($column, $columns, true)) throw new RuntimeException('Audit migration verification failed');
}
echo "SEO finalization audit migration verified\n";

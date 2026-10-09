<?php
declare(strict_types=1);

require_once __DIR__ . '/seo_publication_batch_service.php';

function seoReleaseRequire(bool $condition, string $message): void
{
    if (!$condition) throw new SeoPublicationStateException(409, $message);
}

function seoReleaseJsonFile(string $path): array
{
    $text = file_get_contents($path);
    if ($text === false) throw new RuntimeException('Cannot read release evidence: ' . $path);
    $data = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $text), true, 512, JSON_THROW_ON_ERROR);
    seoReleaseRequire(is_array($data), 'Invalid release evidence');
    return $data;
}

function seoReleaseFingerprint(array $row): string
{
    ksort($row);
    return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
}

/** Capture exact intents in the same consistent snapshot used for build input. */
function seoReleaseCaptureIntents(PDO $pdo, array $jobs): array
{
    $intents = [];
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id');
    foreach ($jobs as $job) {
        $stmt->execute([':id' => (int)$job['product_id']]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        seoReleaseRequire(is_array($p), 'Intent product missing');
        seoPublicationFinalizationDecision($p, (int)$job['requested_revision'], (string)$job['operation']);
        $intents[] = [
            'product_id' => (int)$p['id'], 'job_id' => (int)$job['id'],
            'operation' => $job['operation'], 'revision' => (int)$job['requested_revision'],
            'slug' => $p['slug'], 'category' => $p['category'], 'resolution' => $p['resolution'],
            'batch_id' => $job['batch_id'], 'job_status' => $job['status'],
            'product_updated_at' => $p['updated_at'], 'job_updated_at' => $job['status'] === 'running' ? null : $job['updated_at'],
            'is_active' => (int)$p['is_active'], 'product_fingerprint' => seoReleaseFingerprint($p),
            'job_fields' => $job['status'] === 'queued' ? array_intersect_key($job, array_flip(['attempt_count','started_at','completed_at','snapshot_hash','release_sha','package_sha256','backup_reference','last_error'])) : null,
        ];
    }
    return $intents;
}

function seoReleaseSafePath(string $path): void
{
    seoReleaseRequire($path !== '' && !str_contains($path, '\\') && !str_contains($path, "\0") && !str_starts_with($path, '/') && !preg_match('~(^|/)\.\.?(/|$)|^[A-Za-z]:~', $path), 'Unsafe package path');
}

function seoReleaseReadProduction(string $root, string $relative): string
{
    seoReleaseSafePath($relative);
    $file = realpath($root . '/' . $relative);
    $canonical = realpath($root);
    seoReleaseRequire($canonical !== false && $file !== false && str_starts_with($file, $canonical . DIRECTORY_SEPARATOR) && is_file($file), 'Missing or escaped production file: ' . $relative);
    $data = file_get_contents($file);
    seoReleaseRequire(is_string($data), 'Unreadable production file');
    return $data;
}

/** No redirects, authenticated endpoint, or caller-controlled hostname. */
function seoReleasePublicGet(string $path): array
{
    seoReleaseRequire((bool)preg_match('~^/catalog/(oled|qled|led|8k)/[a-z0-9]+(?:-[a-z0-9]+)*$~D', $path), 'Invalid public product path');
    $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 30, 'ignore_errors' => true, 'follow_location' => 0, 'header' => "Cache-Control: no-cache\r\n"], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $body = file_get_contents('https://telvora.ru' . $path, false, $context);
    $headers = $http_response_header ?? [];
    seoReleaseRequire(is_string($body) && isset($headers[0]) && (bool)preg_match('~^HTTP/\S+ (\d{3})~', $headers[0], $m), 'Public HTTP check failed');
    return ['status' => (int)$m[1], 'body' => $body];
}

/** Verify the archive itself, not just a mutable extracted manifest/active record. */
function seoReleaseVerifyEvidence(array $options, array $intents, callable $http): array
{
    foreach (['release_sha' => 40, 'package_sha256' => 64, 'snapshot_hash' => 64] as $key => $length) {
        seoReleaseRequire(isset($options[$key]) && (bool)preg_match('/^[a-f0-9]{' . $length . '}$/D', $options[$key]), 'Invalid ' . $key);
    }
    seoReleaseRequire(($options['smoke_result'] ?? '') === 'PASS', 'Deployment/smoke has not succeeded');
    seoReleaseRequire(is_file($options['archive']) && hash_equals($options['package_sha256'], hash_file('sha256', $options['archive'])), 'Package SHA-256 mismatch');
    $archive = new PharData($options['archive']);
    $manifestBytes = $archive['deployment-manifest.json']->getContent();
    $manifest = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
    seoReleaseRequire(hash_equals(hash('sha256', $manifestBytes), hash_file('sha256', $options['package_root'] . '/deployment-manifest.json')), 'Extracted manifest differs from archive');
    $active = seoReleaseJsonFile($options['active_record']);
    seoReleaseRequire($manifest['commitSha'] === $options['release_sha'] && $active['commit'] === $options['release_sha'], 'Release SHA mismatch');
    seoReleaseRequire($manifest['snapshotHash'] === $options['snapshot_hash'] && $active['snapshotHash'] === $options['snapshot_hash'], 'Snapshot mismatch');
    seoReleaseRequire($active['releaseId'] === $manifest['releaseId'] && $manifest['releaseId'] === substr($options['release_sha'], 0, 12) . '-' . substr($options['snapshot_hash'], 0, 12), 'Release ID mismatch');
    $checksumMap = [];
    foreach (preg_split('/\r?\n/', trim($archive['checksums.sha256']->getContent())) as $line) {
        seoReleaseRequire((bool)preg_match('/^([a-f0-9]{64})  (.+)$/D', $line, $m), 'Invalid archive checksums');
        seoReleaseSafePath($m[2]);
        seoReleaseRequire(!isset($checksumMap[$m[2]]), 'Duplicate checksum');
        $checksumMap[$m[2]] = $m[1];
        seoReleaseRequire(isset($archive[$m[2]]) && hash_equals($m[1], hash('sha256', $archive[$m[2]]->getContent())), 'Archive member mismatch');
    }
    seoReleaseRequire(isset($checksumMap['deployment-manifest.json'], $checksumMap['snapshot.json']), 'Missing metadata checksums');
    $snapshot = json_decode($archive['snapshot.json']->getContent(), true, 512, JSON_THROW_ON_ERROR);
    seoReleaseRequire($snapshot['snapshotHash'] === $options['snapshot_hash'], 'Package snapshot mismatch');
    if (($options['source'] ?? '') === 'release') {
        seoReleaseRequire(isset($manifest['publicationIntents']) && $manifest['publicationIntents'] === $intents, 'Intents not bound to package');
    }
    $expectedManaged = $manifest['managedFiles']; $expectedManaged[] = '.htaccess';
    $expectedManaged = array_values(array_unique($expectedManaged)); sort($expectedManaged);
    $actualManaged = $active['managedFiles']; sort($actualManaged);
    seoReleaseRequire($expectedManaged === $actualManaged, 'Active managed inventory mismatch');
    $actualChecks = array_keys($active['productionChecksums']); sort($actualChecks);
    seoReleaseRequire($actualChecks === $expectedManaged, 'Partial active package');
    foreach ($expectedManaged as $file) {
        $member = $file === '.htaccess' ? 'production.htaccess' : 'payload/' . $file;
        seoReleaseRequire(isset($checksumMap[$member]), 'Missing managed checksum');
        $bytes = seoReleaseReadProduction($options['document_root'], $file);
        seoReleaseRequire(hash_equals($checksumMap[$member], hash('sha256', $bytes)) && hash_equals($checksumMap[$member], $active['productionChecksums'][$file]), 'Production SHA-256 mismatch: ' . $file);
    }
    // A newer activation invalidates the old evidence even if some files are unchanged.
    foreach (array_merge(glob($options['staging_root'] . '/*/*/active-release.json') ?: [], glob($options['staging_root'] . '/*/active-release.json') ?: []) as $record) {
        $r = seoReleaseJsonFile($record);
        seoReleaseRequire(strcmp($r['activatedAt'], $active['activatedAt']) <= 0, 'A newer production release exists');
        seoReleaseRequire($r['activatedAt'] !== $active['activatedAt'] || ($r['releaseId'] === $active['releaseId'] && $r['commit'] === $active['commit'] && $r['snapshotHash'] === $active['snapshotHash']), 'Ambiguous production activation timestamp');
    }
    $sitemap = seoReleaseReadProduction($options['document_root'], 'sitemap.xml');
    $seenProducts = []; $seenJobs = [];
    foreach ($intents as $intent) {
        seoReleaseRequire((int)$intent['product_id'] > 0 && (int)$intent['job_id'] > 0 && (int)$intent['revision'] >= 0, 'Invalid intent identity');
        seoReleaseRequire(!isset($seenProducts[$intent['product_id']]) && !isset($seenJobs[$intent['job_id']]), 'Duplicate intent');
        $seenProducts[$intent['product_id']] = true; $seenJobs[$intent['job_id']] = true;
        $path = $intent['path'];
        seoReleaseRequire((bool)preg_match('~^/catalog/(oled|qled|led|8k)/[a-z0-9]+(?:-[a-z0-9]+)*$~D', $path) && basename($path) === $intent['slug'], 'Intent route mismatch');
        $file = '_prerender/product-' . $intent['slug'] . '.html';
        $public = $http($path);
        if ($intent['operation'] === 'publish') {
            seoReleaseRequire(in_array($path, $manifest['productRoutes'], true) && ($manifest['prerenderFiles'][$path] ?? '') === '/' . $file, 'Product missing from package');
            seoReleaseRequire(str_contains($sitemap, '<loc>https://telvora.ru' . $path . '</loc>'), 'Product missing from sitemap');
            $html = seoReleaseReadProduction($options['document_root'], $file);
            seoReleaseRequire($public['status'] === 200 && hash_equals(hash('sha256', $html), hash('sha256', $public['body'])), 'Public page differs from active release');
            seoReleaseRequire(!isset($intent['html_sha256']) || hash_equals($intent['html_sha256'], hash('sha256', $html)), 'Pinned page SHA-256 mismatch');
            seoReleaseRequire((bool)preg_match('~<script[^>]*id="telvora-prerender"[^>]*>(.*?)</script>~s', $html, $m), 'Missing prerender identity');
            $data = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
            seoReleaseRequire(($data['path'] ?? '') === $path && count($data['products'] ?? []) === 1 && (int)$data['products'][0]['id'] === (int)$intent['product_id'] && $data['products'][0]['slug'] === $intent['slug'], 'Prerender product mismatch');
        } else {
            seoReleaseRequire($intent['operation'] === 'unpublish' && !in_array($path, $manifest['productRoutes'], true) && !str_contains($sitemap, '<loc>https://telvora.ru' . $path . '</loc>') && !file_exists($options['document_root'] . '/' . $file) && $public['status'] === 404, 'Unpublish not proven');
        }
    }
    return ['active' => $active, 'manifest' => $manifest];
}

/** All validations and audit share the same transaction. Caller holds deploy flock. */
function seoReleaseFinalize(PDO $pdo, array $options, array $intents, bool $apply, callable $verify): array
{
    seoReleaseRequire(in_array($options['source'] ?? '', ['release','reconciliation'], true), 'Invalid finalization source');
    seoReleaseRequire(!$pdo->inTransaction(), 'Nested finalization transaction forbidden');
    usort($intents, static fn($a, $b) => (int)$a['product_id'] <=> (int)$b['product_id']);
    $key = hash('sha256', json_encode([$options['source'], $options['release_sha'], $options['package_sha256'], $options['snapshot_hash'], $intents], JSON_THROW_ON_ERROR));
    if (!$apply) $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    try {
        $proof = $verify(); // under both transaction and deployment lock
        $auditQuery = $pdo->prepare('SELECT * FROM seo_publication_finalization_audit WHERE evidence_key = :key');
        $auditQuery->execute([':key' => $key]); $existing = $auditQuery->fetch(PDO::FETCH_ASSOC);
        $audit = []; $changes = [];
        $lock = $apply ? ' FOR UPDATE' : '';
        foreach ($intents as $i) {
            $q = $pdo->prepare('SELECT * FROM products WHERE id = :id' . $lock);
            $q->execute([':id' => (int)$i['product_id']]); $p = $q->fetch(PDO::FETCH_ASSOC);
            seoReleaseRequire(is_array($p) && (int)$p['publication_revision'] === (int)$i['revision'] && $p['slug'] === $i['slug'], 'Product revision/identity changed');
            // Lock all intents for this product, including later tasks. Product FK +
            // the existing request path's product-first lock serialize new requests.
            $q = $pdo->prepare('SELECT * FROM seo_publication_jobs WHERE product_id = :id ORDER BY id' . $lock);
            $q->execute([':id' => (int)$i['product_id']]); $all = $q->fetchAll(PDO::FETCH_ASSOC); $job = null;
            foreach ($all as $j) {
                if ((int)$j['id'] === (int)$i['job_id']) $job = $j;
                else seoReleaseRequire((int)$j['id'] < (int)$i['job_id'] && !in_array($j['status'], ['queued', 'running'], true) && (int)$j['requested_revision'] <= (int)$i['revision'], 'Newer or competing task exists');
            }
            seoReleaseRequire(is_array($job) && $job['operation'] === $i['operation'] && (int)$job['requested_revision'] === (int)$i['revision'] && $job['batch_id'] === $i['batch_id'], 'Task identity changed');
            $target = $i['operation'] === 'publish' ? 'published' : 'draft';
            $active = $i['operation'] === 'publish' ? 1 : 0;
            if ($job['status'] === 'completed') {
                seoReleaseRequire(is_array($existing) && $p['publication_status'] === $target && (int)$p['is_active'] === $active && $job['release_sha'] === $options['release_sha'] && $job['package_sha256'] === $options['package_sha256'] && $job['snapshot_hash'] === $options['snapshot_hash'], 'Completion belongs to another release');
                continue;
            }
            seoReleaseRequire($existing === false, 'Partial finalization audit');
            seoReleaseRequire($job['status'] === $i['job_status'] && in_array($job['status'], ['queued', 'running'], true), 'Task state changed');
            seoPublicationFinalizationDecision($p, (int)$i['revision'], $i['operation']);
            seoReleaseRequire((int)$p['is_active'] === (int)$i['is_active'] && $p['updated_at'] === $i['product_updated_at'] && ($i['job_updated_at'] === null || $job['updated_at'] === $i['job_updated_at']), 'Product/task changed since capture');
            seoReleaseRequire(!isset($i['product_fingerprint']) || hash_equals($i['product_fingerprint'], seoReleaseFingerprint($p)), 'Product content changed since capture');
            foreach ($i['job_fields'] ?? [] as $field => $value) seoReleaseRequire(array_key_exists($field, $job) && $job[$field] === $value, 'Task metadata changed since capture');
            if ($job['status'] === 'running') seoReleaseRequire($job['snapshot_hash'] === $options['snapshot_hash'], 'Running task snapshot mismatch');
            $audit[] = ['product_id' => (int)$p['id'], 'job_id' => (int)$job['id'], 'operation' => $i['operation'], 'before_product' => $p, 'before_job' => $job, 'after_publication_status' => $target, 'after_is_active' => $active, 'after_job_status' => 'completed'];
            $changes[] = [$i, $target, $active];
        }
        // Check evidence again just before writes (HTTP checks are intentionally strict).
        $verify();
        if ($apply && $changes !== []) {
            $pUpdate = $pdo->prepare('UPDATE products SET publication_status = :status, is_active = :active WHERE id = :id AND publication_revision = :revision AND publication_status = :before');
            $jUpdate = $pdo->prepare("UPDATE seo_publication_jobs SET status = 'completed', completed_at = CURRENT_TIMESTAMP, release_sha = :release, package_sha256 = :package, snapshot_hash = :snapshot, backup_reference = :backup WHERE id = :id AND status = :before AND requested_revision = :revision");
            foreach ($changes as [$i, $target, $active]) {
                $pUpdate->execute([':status' => $target, ':active' => $active, ':id' => $i['product_id'], ':revision' => $i['revision'], ':before' => $i['operation'] === 'publish' ? 'pending_publish' : 'pending_unpublish']);
                seoReleaseRequire($pUpdate->rowCount() === 1, 'Product compare-and-set failed');
                $jUpdate->execute([':release' => $options['release_sha'], ':package' => $options['package_sha256'], ':snapshot' => $options['snapshot_hash'], ':backup' => $proof['active']['backupReference'], ':id' => $i['job_id'], ':before' => $i['job_status'], ':revision' => $i['revision']]);
                seoReleaseRequire($jUpdate->rowCount() === 1, 'Task compare-and-set failed');
            }
            $q = $pdo->prepare('INSERT INTO seo_publication_finalization_audit (evidence_key,source,release_sha,package_sha256,snapshot_hash,audit_json) VALUES (:key,:source,:release,:package,:snapshot,:audit)');
            $q->execute([':key' => $key, ':source' => $options['source'], ':release' => $options['release_sha'], ':package' => $options['package_sha256'], ':snapshot' => $options['snapshot_hash'], ':audit' => json_encode(['active_record' => $options['active_record'], 'backup_reference' => $proof['active']['backupReference'], 'transitions' => $audit], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        }
        if ($apply) $pdo->commit(); else $pdo->rollBack();
        return ['status' => !$apply ? 'DRY_RUN' : ($changes === [] ? 'ALREADY_COMPLETED' : 'COMPLETED'), 'evidence_key' => $key, 'verified_jobs' => count($intents), 'changed_jobs' => $apply ? count($changes) : 0, 'would_change_jobs' => count($changes)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

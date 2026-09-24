<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/product_bulk_publication_service.php';

// Called only after the existing runner verifies the disposable database identity.
function productBulkPublicationMysqlCases(PDO $pdo): void
{
    $pdo->exec("ALTER TABLE products ADD series VARCHAR(100) NOT NULL DEFAULT '',
        ADD brand VARCHAR(100) NULL, ADD country VARCHAR(100) NULL");
    $sql = file_get_contents(dirname(__DIR__) . '/database/migrations/20260922_013_seo_publication_state.sql');
    foreach (preg_split('/;\s*(?:\r?\n|\z)/', $sql) as $statement) {
        $statement = trim((string)preg_replace('/\A(?:\s*--[^\r\n]*(?:\r?\n|\z))+/', '', $statement));
        if ($statement !== '') $pdo->exec($statement);
    }
    $variants = [];
    for ($id = 10; $id <= 14; $id++) {
        $pdo->prepare("INSERT INTO products(id,slug,name,series,brand,country,category,screen_size,price,variants,is_active)
            VALUES(?,?,?,'Q TEST',? ,?,'OLED','55',0,JSON_ARRAY(),0)")
            ->execute([$id, 'bulk-product-' . $id, 'Bulk publication fixture ' . $id,
                $id === 11 ? 'Samsung' : ($id === 12 ? null : ' LG '), $id === 11 ? 'Польша' : 'Россия']);
        $variants[$id] = productVariantAdd($pdo, $id, 'Russia')['product_variant_id'];
        if ($id !== 13) productVariantPriceSet($pdo, $variants[$id], true, '10000.00', null);
        if ($id === 14) productVariantSetActive($pdo, $variants[$id], false);
    }
    $filters = ['search' => 'Bulk publication fixture', 'brand' => 'Все бренды', 'category' => 'Все категории', 'country' => 'Все страны'];
    $snapshot = productBulkPublicationPrepare($pdo, $filters);
    pipelineAssert('products: five matched, three ready, two not ready', $snapshot['total'] === 5 && $snapshot['eligible'] === 3);
    pipelineAssert('products: invalid rows have readiness reasons', $snapshot['entries'][3]['reason'] !== null && $snapshot['entries'][4]['reason'] !== null);
    pipelineAssert('products: preview does not create jobs or revisions',
        (int)$pdo->query('SELECT COUNT(*) FROM seo_publication_jobs')->fetchColumn() === 0 &&
        (int)$pdo->query('SELECT SUM(publication_revision) FROM products')->fetchColumn() === 0);
    foreach ([
        ['brand' => 'lg', 'total' => 3], ['brand' => 'Без бренда', 'total' => 1],
        ['country' => 'Польша', 'total' => 1], ['category' => 'QLED', 'total' => 0],
        ['search' => ' q test ', 'total' => 5], ['search' => 'россия', 'total' => 4],
        ['search' => '55', 'total' => 5], ['search' => '%', 'total' => 0],
    ] as $case) {
        $expected = $case['total']; unset($case['total']);
        $filtered = productBulkPublicationPrepare($pdo, array_replace($filters, $case));
        pipelineAssert('server matches active filters ' . json_encode($case, JSON_UNESCAPED_UNICODE), $filtered['total'] === $expected);
    }
    $session = [];
    $preview = productBulkPublicationCreatePreview($pdo, $session, $filters);
    $result = productBulkPublicationConfirmPreview($pdo, $session, $preview['preview_id']);
    pipelineAssert('products: three accepted via individual service, two skipped', $result['queued'] === 3 && $result['skipped'] === 2 && $result['errors'] === 0, $result);
    pipelineAssert('products: enqueue does not bypass final publication',
        $result['published'] === 0 && (int)$pdo->query("SELECT COUNT(*) FROM products WHERE id BETWEEN 10 AND 14 AND is_active=0 AND publication_status='pending_publish'")->fetchColumn() === 3);
    $jobsBefore = $pdo->query('SELECT * FROM seo_publication_jobs ORDER BY id')->fetchAll();
    pipelineAssert('same HTTP action session snapshot returns original result', productBulkPublicationConfirmPreview($pdo, $session, $preview['preview_id']) === $result);
    $replay = productBulkPublicationConfirm($pdo, $snapshot);
    pipelineAssert('duplicate request from another session creates no new jobs', $replay['queued'] === 0 && $replay['skipped'] === 5 && $pdo->query('SELECT * FROM seo_publication_jobs ORDER BY id')->fetchAll() === $jobsBefore);
    foreach ($jobsBefore as $job) {
        // Synthetic worker completion only; never run deployment/build workers.
        $pdo->prepare("UPDATE seo_publication_jobs SET status='running' WHERE id=?")->execute([$job['id']]);
        seoPublicationFinalize($pdo, (int)$job['id'], 'publish');
    }
    pipelineAssert('existing finalizer publishes exactly three ready products', (int)$pdo->query('SELECT COUNT(*) FROM products WHERE id BETWEEN 10 AND 14 AND is_active=1')->fetchColumn() === 3);
    $published = productBulkPublicationPrepare($pdo, $filters);
    pipelineAssert('already published products are skipped without revisions', $published['eligible'] === 0 && $published['entries'][0]['reason'] === 'Товар уже опубликован');
    $individual = seoPublicationRequestPublish($pdo, 10, 1);
    pipelineAssert('individual publish on published product remains no-op', $individual['result'] === 'noop');
    $hide = seoPublicationRequestUnpublish($pdo, 10, 1);
    pipelineAssert('individual hide remains working', $hide['result'] === 'queued' && $hide['product']['publication_revision'] === 2);
    $pdo->prepare("UPDATE seo_publication_jobs SET status='running' WHERE id=?")->execute([$hide['job']['id']]);
    seoPublicationFinalize($pdo, (int)$hide['job']['id'], 'unpublish');
    $stale = productBulkPublicationPrepare($pdo, $filters);
    $individual = seoPublicationRequestPublish($pdo, 10, 2);
    $afterIndividual = productBulkPublicationConfirm($pdo, $stale);
    pipelineAssert('individual publish wins stale bulk without duplicate intent', $individual['result'] === 'queued' && $afterIndividual['queued'] === 0 &&
        (int)$pdo->query("SELECT COUNT(*) FROM seo_publication_jobs WHERE product_id=10 AND requested_revision=3 AND operation='publish'")->fetchColumn() === 1);

    // Readiness and active filters are checked again when confirming a snapshot.
    productVariantPriceSet($pdo, $variants[13], true, '10000.00', null);
    $ready = productBulkPublicationPrepare($pdo, $filters);
    productVariantSetActive($pdo, $variants[13], false);
    $notReady = productBulkPublicationConfirm($pdo, $ready);
    pipelineAssert('readiness changes are rejected by existing service', $notReady['queued'] === 0 && $notReady['skipped'] === 5);
    productVariantSetActive($pdo, $variants[13], true);
    $ready = productBulkPublicationPrepare($pdo, $filters);
    $pdo->exec("UPDATE products SET name='Outside current search' WHERE id=13");
    pipelineAssert('changed search membership is skipped at confirmation', productBulkPublicationConfirm($pdo, $ready)['queued'] === 0);
    $session['product_publication_snapshots'][$preview['preview_id']]['expires'] = time() - 1;
    try {
        productBulkPublicationConfirmPreview($pdo, $session, $preview['preview_id']);
        throw new RuntimeException('FAIL expired bulk preview was accepted');
    } catch (SeoPublicationStateException $error) {
        pipelineAssert('expired previews fail closed', $error->httpStatus === 409);
    }
    $pdo->exec("UPDATE products SET name='Bulk publication fixture 13' WHERE id=13");
    $raceSnapshot = productBulkPublicationPrepare($pdo, $filters);
    $workers = [];
    try {
        foreach ([['operation' => 'product_bulk', 'snapshot' => $raceSnapshot],
            ['operation' => 'product_publish', 'product_id' => 13, 'revision' => 0]] as $request) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), __DIR__ . '/import_price_concurrency_worker.php'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot start product concurrency worker');
            $workers[] = ['process' => $process, 'pipes' => $pipes, 'request' => $request];
            stream_set_timeout($pipes[1], 15);
            pipelineAssert('product race worker ready', trim((string)fgets($pipes[1])) === 'READY');
        }
        $pdo->beginTransaction();
        $pdo->query('SELECT id FROM products WHERE id=13 FOR UPDATE')->fetch();
        foreach ($workers as $worker) {
            fwrite($worker['pipes'][0], json_encode($worker['request'], JSON_THROW_ON_ERROR) . "\n");
            fflush($worker['pipes'][0]);
            pipelineAssert('product race request started', trim((string)fgets($worker['pipes'][1])) === 'START');
        }
        $pdo->commit();
        foreach ($workers as $worker) {
            $outcome = json_decode((string)fgets($worker['pipes'][1]), true, 512, JSON_THROW_ON_ERROR);
            pipelineAssert('product race completed safely', is_array($outcome) && ($outcome['errors'] ?? 0) === 0, $outcome);
        }
        pipelineAssert('concurrent individual/bulk creates exactly one job and revision',
            (int)$pdo->query('SELECT COUNT(*) FROM seo_publication_jobs WHERE product_id=13')->fetchColumn() === 1 &&
            (int)$pdo->query('SELECT publication_revision FROM products WHERE id=13')->fetchColumn() === 1);
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($workers as $worker) {
            foreach ($worker['pipes'] as $pipe) fclose($pipe);
            proc_terminate($worker['process']);
            proc_close($worker['process']);
        }
    }
    echo "PASS bulk product publication integration\n";
}

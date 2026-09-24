<?php

declare(strict_types=1);

// Invoked only by the existing guarded, isolated Stage12L MySQL runner.
require_once dirname(__DIR__) . '/import_price_preview_service.php';

function importPricePreviewMysqlCases(PDO $pdo): void
{
    $pdo->exec("INSERT INTO products(id,slug,name,category,price,variants,is_active)
        VALUES(3,'bulk-test','Bulk test','OLED',0,JSON_ARRAY(),0)");
    $pdo->exec("INSERT INTO suppliers(id,name,internal_code,is_active) VALUES(2,'Bulk supplier','BULK',1)");
    $pdo->exec("INSERT INTO supplier_import_profiles(id,supplier_id,name) VALUES(2,2,'Bulk profile')");
    $rows = [];
    $variantIds = [];
    foreach (['Indonesia', 'Poland', 'Japan', 'China'] as $index => $country) {
        $variant = productVariantAdd($pdo, 3, $country);
        $variantIds[] = $variant['product_variant_id'];
        $pdo->prepare("INSERT INTO supplier_product_matches(supplier_id,supplier_sku,product_id,product_variant_id,match_method,status,is_active)
            VALUES(2,?,3,?,'manual','matched',1)")->execute(['BULK-' . $index, $variant['product_variant_id']]);
        $rows[] = pipelineRow($index + 1, 'BULK-' . $index, $country, '10000.00');
    }
    // Minimum net profit, not markup, determines the candidate (10% expenses).
    $pdo->exec("UPDATE pricing_rules SET markup_percent=1, minimum_margin=5000 WHERE id=1");
    $jobId = pipelineImport($pdo, 2, 2, $rows, 'bulk-six.csv');
    $pdo->prepare("INSERT INTO supplier_import_rows(import_job_id,source_row_number,supplier_sku,raw_product_name,purchase_price,currency_code,status)
        VALUES(?,5,'UNMATCHED','Unmatched TV',10000,'RUB','unmatched'),
              (?,6,'NO-PRICE','No price TV',NULL,'RUB','validation_error')")->execute([$jobId, $jobId]);

    $preview = importPricePreview($pdo, $jobId);
    pipelineAssert('A: all six import rows survive without two offers', $preview['total'] === 6 && count($preview['offers']) === 6);
    $page1 = importPricePreview($pdo, $jobId, 1, 4);
    $page2 = importPricePreview($pdo, $jobId, 2, 4);
    pipelineAssert('B: pagination counts invalid rows', $page1['pages'] === 2 && $page2['total'] === 6 && count($page2['offers']) === 2);
    pipelineAssert('C: unmapped row has no candidate and cannot confirm',
        $preview['offers'][4]['cannot_confirm'] && $preview['offers'][4]['id'] === null &&
        in_array('Не сопоставлено с вариантом товара', $preview['offers'][4]['blocking_reasons'], true) &&
        !isset($preview['offers'][4]['pricing']['candidate_retail_price']));
    pipelineAssert('missing purchase has explicit reason', in_array('Нет корректной закупочной цены', $preview['offers'][5]['blocking_reasons'], true));
    $firstOffer = $preview['offers'][0]['id'];
    $individual = pricePublicationContext($pdo, $firstOffer, false);
    $previewPricing = $preview['offers'][0]['pricing'];
    $individualPricing = $individual['pricing'];
    unset($previewPricing['warnings'], $individualPricing['warnings']);
    pipelineAssert('E: preview uses individual rules and economics', $previewPricing === $individualPricing);
    pipelineAssert('minimum net profit candidate is rounded up to 900', $individual['pricing']['candidate_retail_price'] === '16900.00' && $individual['pricing']['price_before_rounding'] === '16666.67');

    $pdo->exec("UPDATE pricing_rules SET rounding_strategy='unsupported' WHERE id=1");
    $unsupported = importPriceBulkPrepare($pdo, $jobId);
    pipelineAssert('E: unsupported rounding blocks both individual and bulk', $unsupported['eligible'] === 0 && !pricePublicationContext($pdo, $firstOffer, false)['can_publish']);
    $pdo->exec("UPDATE pricing_rules SET rounding_strategy='none',is_active=0 WHERE id=1");
    $noRule = importPricePreview($pdo, $jobId);
    pipelineAssert('missing rule never removes rows or invents a candidate', count($noRule['offers']) === 6 && $noRule['offers'][0]['cannot_confirm'] && !isset($noRule['offers'][0]['pricing']['candidate_retail_price']));
    $pdo->exec('UPDATE pricing_rules SET is_active=1 WHERE id=1');

    $snapshot = importPriceBulkPrepare($pdo, $jobId);
    pipelineAssert('bulk prepares all four valid changes', $snapshot['eligible'] === 4 && $snapshot['total'] === 6);
    $staleIndividual = pricePublicationContext($pdo, $firstOffer, false);
    $beforeAudit = (int)$pdo->query('SELECT COUNT(*) FROM product_price_publication_audit')->fetchColumn();
    $beforeOffers = $pdo->query('SELECT id,purchase_price FROM supplier_offers ORDER BY id')->fetchAll();
    $result = importPriceBulkConfirm($pdo, $snapshot);
    pipelineAssert('D: bulk applies four variants of same product, skips two', $result['applied'] === 4 && $result['skipped'] === 2 && $result['errors'] === 0, $result);
    pipelineAssert('H: one audit per applied row with original source and economics',
        (int)$pdo->query('SELECT COUNT(*) FROM product_price_publication_audit')->fetchColumn() === $beforeAudit + 4 &&
        (int)$pdo->query("SELECT COUNT(*) FROM product_price_publication_audit WHERE product_id=3 AND source_import_job_id=$jobId AND new_live_price=16900 AND purchase_price=10000 AND pricing_rule_id=1")->fetchColumn() === 4);
    pipelineAssert('bulk preserves supplier purchase prices and inactive product',
        $beforeOffers === $pdo->query('SELECT id,purchase_price FROM supplier_offers ORDER BY id')->fetchAll() &&
        (int)$pdo->query('SELECT is_active FROM products WHERE id=3')->fetchColumn() === 0);
    $repeat = importPriceBulkConfirm($pdo, $snapshot);
    pipelineAssert('F: replay never republishes', $repeat['applied'] === 0 && $repeat['skipped'] === 6 &&
        (int)$pdo->query('SELECT COUNT(*) FROM product_price_publication_audit')->fetchColumn() === $beforeAudit + 4, $repeat);
    try {
        pricePublicationPublish($pdo, $firstOffer, $staleIndividual['snapshot_token'], null);
        throw new RuntimeException('FAIL stale individual published after bulk');
    } catch (PricePublicationException $error) {
        pipelineAssert('stale individual after bulk is rejected', $error->httpStatus === 409);
    }
    $current = pricePublicationContext($pdo, $firstOffer, false);
    $unchanged = pricePublicationPublish($pdo, $firstOffer, $current['snapshot_token'], null);
    pipelineAssert('I: unchanged individual is no-op and bulk eligible is zero',
        $unchanged['status'] === 'already_current' && importPriceBulkPrepare($pdo, $jobId)['eligible'] === 0 &&
        (int)$pdo->query('SELECT COUNT(*) FROM product_price_publication_audit')->fetchColumn() === $beforeAudit + 4);

    $pdo->exec('UPDATE pricing_rules SET minimum_margin=6000 WHERE id=1');
    $nextSnapshot = importPriceBulkPrepare($pdo, $jobId);
    $current = pricePublicationContext($pdo, $firstOffer, false);
    $published = pricePublicationPublish($pdo, $firstOffer, $current['snapshot_token'], 'individual after bulk');
    pipelineAssert('G: individual still publishes and audits', $published['status'] === 'published' && $published['published_price'] === '17900.00');
    $afterIndividual = importPriceBulkConfirm($pdo, $nextSnapshot);
    pipelineAssert('individual first: bulk skips stale target but publishes siblings', $afterIndividual['applied'] === 3 && $afterIndividual['skipped'] === 3, $afterIndividual);

    $pdo->exec('UPDATE pricing_rules SET minimum_margin=7000 WHERE id=1');
    $staleRules = importPriceBulkPrepare($pdo, $jobId);
    $pdo->exec('UPDATE pricing_rules SET minimum_margin=8000 WHERE id=1');
    $rejected = importPriceBulkConfirm($pdo, $staleRules);
    pipelineAssert('changed rules cannot publish unapproved candidate', $rejected['applied'] === 0 && $rejected['skipped'] === 6);
    $staleMapping = importPriceBulkPrepare($pdo, $jobId);
    $pdo->exec("UPDATE supplier_import_rows SET matched_product_variant_id=NULL WHERE import_job_id=$jobId AND source_row_number=1");
    $mappingResult = importPriceBulkConfirm($pdo, $staleMapping);
    pipelineAssert('mapping changes are revalidated independently', $mappingResult['applied'] === 3 && $mappingResult['skipped'] === 3, $mappingResult);
    pipelineAssert('unmapped existing offer does not show false candidate', !isset(importPricePreview($pdo, $jobId)['offers'][0]['pricing']['candidate_retail_price']));
    $pdo->prepare('UPDATE supplier_import_rows SET matched_product_variant_id=? WHERE import_job_id=? AND source_row_number=1')->execute([$variantIds[0], $jobId]);
    $pdo->exec('UPDATE pricing_rules SET minimum_margin=9000 WHERE id=1');
    $concurrentSnapshot = importPriceBulkPrepare($pdo, $jobId);
    $concurrentIndividual = pricePublicationContext($pdo, $firstOffer, false);
    $auditBeforeRace = (int)$pdo->query('SELECT COUNT(*) FROM product_price_publication_audit')->fetchColumn();
    $workers = [];
    try {
        foreach ([['operation' => 'bulk', 'snapshot' => $concurrentSnapshot],
            ['operation' => 'individual', 'offer_id' => $firstOffer, 'token' => $concurrentIndividual['snapshot_token']]] as $request) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), __DIR__ . '/import_price_concurrency_worker.php'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot start concurrency worker');
            $workers[] = ['process' => $process, 'pipes' => $pipes, 'request' => $request];
            stream_set_timeout($pipes[1], 15);
            pipelineAssert('concurrent worker connected to guarded local DB', trim((string)fgets($pipes[1])) === 'READY');
        }
        // Queue both processes against the same locked offer before releasing it.
        $pdo->beginTransaction();
        $pdo->query("SELECT id FROM supplier_offers WHERE id=$firstOffer FOR UPDATE")->fetch();
        foreach ($workers as $worker) {
            fwrite($worker['pipes'][0], json_encode($worker['request'], JSON_THROW_ON_ERROR) . "\n");
            fflush($worker['pipes'][0]);
            pipelineAssert('concurrent request started', trim((string)fgets($worker['pipes'][1])) === 'START');
        }
        $pdo->commit();
        foreach ($workers as $worker) {
            $outcome = json_decode((string)fgets($worker['pipes'][1]), true, 512, JSON_THROW_ON_ERROR);
            pipelineAssert('concurrent publication completed safely', is_array($outcome) && ($outcome['errors'] ?? 0) === 0, $outcome);
        }
        pipelineAssert('real individual/bulk race creates exactly four publications',
            (int)$pdo->query('SELECT COUNT(*) FROM product_price_publication_audit')->fetchColumn() === $auditBeforeRace + 4);
        pipelineAssert('racing target is published only once',
            (int)$pdo->query("SELECT COUNT(*) FROM product_price_publication_audit WHERE supplier_offer_id=$firstOffer AND new_live_price=21900")->fetchColumn() === 1);
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($workers as $worker) {
            foreach ($worker['pipes'] as $pipe) fclose($pipe);
            proc_terminate($worker['process']);
            proc_close($worker['process']);
        }
    }
    // Eligible offers only appear beyond both the UI page and the bulk scan chunk.
    $largeJob = pipelineImport($pdo, 2, 2, [], 'bulk-multiple-pages.csv');
    $insert = $pdo->prepare("INSERT INTO supplier_import_rows(import_job_id,source_row_number,raw_product_name,currency_code,status)
        VALUES(?,?,'Unmapped paginated row','RUB','unmatched')");
    for ($number = 1; $number <= 501; $number++) $insert->execute([$largeJob, $number]);
    foreach ($rows as $index => &$row) $row['source_row_number'] = 502 + $index;
    unset($row);
    $counters = ['total' => 0, 'matched' => 0, 'unmatched' => 0, 'errors' => 0];
    supplierStageInsertChunk($pdo, $largeJob, 2, array_map('supplierStagePrepareRow', $rows), $counters);
    $pdo->beginTransaction();
    supplierOfferPublishAnalysis($pdo, $largeJob, true);
    $pdo->commit();
    $pdo->exec('UPDATE pricing_rules SET minimum_margin=10000 WHERE id=1');
    $largePreview = importPricePreview($pdo, $largeJob);
    pipelineAssert('505 rows have 11 UI pages, including an entirely invalid first page',
        $largePreview['total'] === 505 && $largePreview['pages'] === 11 &&
        count(array_filter($largePreview['offers'], static fn(array $row): bool => !$row['cannot_confirm'])) === 0);
    $largeSnapshot = importPriceBulkPrepare($pdo, $largeJob);
    pipelineAssert('bulk preparation includes valid rows beyond scan chunk 500', $largeSnapshot['total'] === 505 && $largeSnapshot['eligible'] === 4);
    $largeResult = importPriceBulkConfirm($pdo, $largeSnapshot);
    pipelineAssert('bulk publishes off-page changes and reports every skipped row',
        $largeResult['applied'] === 4 && $largeResult['skipped'] === 501 && $largeResult['errors'] === 0);
    pipelineAssert('older import retains rows when offers advance to newer source', count(importPricePreview($pdo, $jobId)['offers']) === 6);
    echo "PASS import price preview and bulk cases A-I\n";
}

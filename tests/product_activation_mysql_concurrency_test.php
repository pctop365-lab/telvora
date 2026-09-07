<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/product_activation_service.php';
require_once dirname(__DIR__) . '/product_variant_mutation_service.php';

const TEST_DSN = 'mysql:host=127.0.0.1;port=3307;dbname=telvora_stage12lc_test;charset=utf8mb4';
const TEST_USER = 'telvora_stage12lc';
const TEST_PASSWORD_FILE = 'C:/Users/ASRock/Telvora-MySQL-Test/private/test-password.txt';
const TEST_PHP = 'C:/Users/ASRock/Telvora-MySQL-Test/php/php.exe';
const TEST_INI = 'C:/Users/ASRock/Telvora-MySQL-Test/php/php.ini';

final class MysqlTestBarrierStatement extends PDOStatement
{
    protected function __construct(private readonly string $barrierDir) {}

    public function execute(?array $params = null): bool
    {
        try {
            $result = parent::execute($params);
        } catch (PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0) === 1213) mysqlTestSignal("{$this->barrierDir}/saw_1213");
            throw $error;
        }
        if (
            str_contains($this->queryString, 'FROM product_variants') &&
            str_contains($this->queryString, 'FOR UPDATE') &&
            !is_file("{$this->barrierDir}/hook_used")
        ) {
            mysqlTestSignal("{$this->barrierDir}/hook_used");
            mysqlTestSignal("{$this->barrierDir}/candidate_locked");
            mysqlTestWait("{$this->barrierDir}/allow_product");
        }
        return $result;
    }
}

function mysqlTestPdo(?string $barrierDir = null): PDO
{
    $password = trim((string)file_get_contents(TEST_PASSWORD_FILE));
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if ($barrierDir !== null) $options[PDO::ATTR_STATEMENT_CLASS] = [MysqlTestBarrierStatement::class, [$barrierDir]];
    return new PDO(TEST_DSN, TEST_USER, $password, $options);
}

function mysqlTestAssert(string $name, bool $condition, mixed $detail = null): void
{
    if (!$condition) throw new RuntimeException("FAIL $name: " . json_encode($detail, JSON_UNESCAPED_UNICODE));
    echo "PASS $name\n";
}

function mysqlTestWait(string $path, int $timeoutMs = 8000): void
{
    $deadline = microtime(true) + ($timeoutMs / 1000);
    while (!is_file($path)) {
        if (microtime(true) >= $deadline) throw new RuntimeException("Barrier timeout: $path");
        usleep(10000);
    }
}

function mysqlTestSignal(string $path): void
{
    if (file_put_contents($path, '1', LOCK_EX) === false) throw new RuntimeException("Cannot signal: $path");
}

function mysqlTestSpawn(string $mode, string $dir, int $productId, int $variantId): array
{
    $command = [TEST_PHP, '-c', TEST_INI, __FILE__, '--worker', $mode, $dir, (string)$productId, (string)$variantId];
    $pipes = [];
    $process = proc_open($command, [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Unable to start worker');
    fclose($pipes[0]);
    return [$process, $pipes];
}

function mysqlTestFinish(array $worker, int $timeoutMs = 12000): array
{
    [$process, $pipes] = $worker;
    $deadline = microtime(true) + ($timeoutMs / 1000);
    do {
        $status = proc_get_status($process);
        if (!$status['running']) break;
        if (microtime(true) >= $deadline) {
            proc_terminate($process);
            throw new RuntimeException('Worker timeout');
        }
        usleep(10000);
    } while (true);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit === -1) $exit = (int)$status['exitcode'];
    return [$exit, $stdout, $stderr];
}

function mysqlTestLegacy(string $country, int $price, bool $active = true): array
{
    return ['country'=>$country, 'price'=>$price, 'old_price'=>null, 'is_active'=>$active];
}

function mysqlTestSeed(PDO $pdo, int $productId, array $legacy, array $countries, bool $active = false): array
{
    $pdo->prepare('INSERT INTO products (id, name, variants, is_active) VALUES (?, ?, ?, ?)')
        ->execute([$productId, "Synthetic $productId", json_encode($legacy, JSON_UNESCAPED_UNICODE), $active ? 1 : 0]);
    $stmt = $pdo->prepare('INSERT INTO product_variants (product_id, variant_key, assembly_country, display_name, is_active) VALUES (?, ?, ?, ?, 1)');
    $ids = [];
    foreach ($countries as $country) {
        $stmt->execute([$productId, 'legacy-country-sha256-' . hash('sha256', $country), $country, $country]);
        $ids[] = (int)$pdo->lastInsertId();
    }
    return $ids;
}

function mysqlTestActivate(PDO $pdo, int $productId, ?string $newName = null): int
{
    try {
        productActivationRun($pdo, $productId, static function () use ($pdo, $productId, $newName): void {
            $stmt = $pdo->prepare('UPDATE products SET is_active = 1, name = COALESCE(:name, name) WHERE id = :id');
            $stmt->execute([':name'=>$newName, ':id'=>$productId]);
        });
        return 200;
    } catch (ProductActivationException $error) {
        return $error->httpStatus;
    }
}

function mysqlTestWorker(array $argv): never
{
    [,,$mode,$dir,$productRaw,$variantRaw] = $argv;
    $productId = (int)$productRaw; $variantId = (int)$variantRaw;
    $pdo = ($mode === 'activate_deadlock' || $mode === 'variant_disable_deadlock') ? mysqlTestPdo($dir) : mysqlTestPdo();
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
    try {
        if ($mode === 'disable') {
            $pdo->beginTransaction();
            $pdo->prepare('SELECT id FROM product_variants WHERE id = ? FOR UPDATE')->execute([$variantId]);
            mysqlTestSignal("$dir/locked");
            mysqlTestWait("$dir/release");
            $pdo->prepare('UPDATE product_variants SET is_active = 0 WHERE id = ?')->execute([$variantId]);
            $pdo->commit();
            mysqlTestSignal("$dir/done");
        } elseif ($mode === 'stage9') {
            $pdo->beginTransaction();
            $pdo->prepare('SELECT id FROM product_variants WHERE id = ? FOR UPDATE')->execute([$variantId]);
            mysqlTestSignal("$dir/locked");
            mysqlTestWait("$dir/release");
            $stmt = $pdo->prepare('SELECT variants FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$productId]);
            $legacy = json_decode((string)$stmt->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
            $legacy[0]['price'] = 299900;
            $pdo->prepare('UPDATE products SET variants = ? WHERE id = ?')->execute([json_encode($legacy, JSON_UNESCAPED_UNICODE), $productId]);
            $pdo->commit();
            mysqlTestSignal("$dir/done");
        } elseif ($mode === 'identity') {
            $pdo->beginTransaction();
            $pdo->prepare('SELECT id FROM product_variants WHERE id = ? FOR UPDATE')->execute([$variantId]);
            mysqlTestSignal("$dir/locked");
            mysqlTestWait("$dir/release");
            $stmt = $pdo->prepare('SELECT variants FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$productId]);
            $legacy = json_decode((string)$stmt->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
            $legacy[0]['country'] = 'Changed';
            $pdo->prepare('UPDATE products SET variants = ? WHERE id = ?')->execute([json_encode($legacy, JSON_UNESCAPED_UNICODE), $productId]);
            $pdo->commit();
            mysqlTestSignal("$dir/done");
        } elseif ($mode === 'activate_split') {
            $preflight = productActivationReadyCandidate($pdo, $productId);
            mysqlTestSignal("$dir/preflight");
            mysqlTestWait("$dir/go");
            try {
                $pdo->beginTransaction();
                productActivationLockAndValidate($pdo, $productId, $preflight);
                $pdo->prepare('UPDATE products SET is_active = 1 WHERE id = ?')->execute([$productId]);
                $pdo->commit();
                file_put_contents("$dir/result", '200');
            } catch (ProductActivationException $error) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                file_put_contents("$dir/result", (string)$error->httpStatus);
            }
        } elseif ($mode === 'activate_hold') {
            try {
                productActivationRun($pdo, $productId, static function () use ($pdo, $productId, $dir): void {
                    mysqlTestSignal("$dir/callback");
                    mysqlTestWait("$dir/go");
                    $pdo->prepare('UPDATE products SET is_active = 1 WHERE id = ?')->execute([$productId]);
                });
                file_put_contents("$dir/result", '200');
            } catch (ProductActivationException $error) {
                file_put_contents("$dir/result", (string)$error->httpStatus);
            }
        } elseif ($mode === 'activate' || $mode === 'activate_deadlock') {
            $started = microtime(true);
            $status = mysqlTestActivate($pdo, $productId);
            file_put_contents("$dir/result", $status . '|' . (microtime(true) - $started));
        } elseif ($mode === 'deadlock_reverse') {
            $pdo->beginTransaction();
            $pdo->prepare('SELECT id FROM products WHERE id = ? FOR UPDATE')->execute([$productId]);
            $pdo->prepare("UPDATE products SET name = CONCAT(name, ' reverse') WHERE id = ?")->execute([$productId]);
            mysqlTestSignal("$dir/product_locked");
            mysqlTestWait("$dir/candidate_locked");
            mysqlTestSignal("$dir/requesting_candidate");
            $pdo->prepare('SELECT id FROM product_variants WHERE id = ? FOR UPDATE')->execute([$variantId]);
            $pdo->commit();
            mysqlTestSignal("$dir/reverse_done");
        } elseif ($mode === 'hold') {
            $pdo->beginTransaction();
            $pdo->prepare('SELECT id FROM product_variants WHERE id = ? FOR UPDATE')->execute([$variantId]);
            mysqlTestSignal("$dir/locked");
            mysqlTestWait("$dir/release", 15000);
            $pdo->rollBack();
        } elseif (str_starts_with($mode, 'variant_add_')) {
            $country = match ($mode) { 'variant_add_a'=>'Poland', 'variant_add_b'=>'China', default=>'Korea' };
            mysqlTestSignal("$dir/started");
            try { productVariantAdd($pdo,$productId,$country); $status=200; }
            catch (ProductVariantMutationException $error) { $status=$error->httpStatus; }
            file_put_contents("$dir/result",(string)$status);
        } elseif ($mode === 'variant_disable' || $mode === 'variant_enable' || $mode === 'variant_disable_deadlock') {
            mysqlTestSignal("$dir/started");
            $started=microtime(true);
            try { productVariantSetActive($pdo,$variantId,$mode==='variant_enable'); $status=200; }
            catch (ProductVariantMutationException $error) { $status=$error->httpStatus; }
            file_put_contents("$dir/result",$status.'|'.(microtime(true)-$started));
        }
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        file_put_contents("$dir/error", $error::class . ':' . $error->getCode());
        exit(1);
    }
    exit(0);
}

if (($argv[1] ?? '') === '--worker') mysqlTestWorker($argv);

$pdo = mysqlTestPdo();
$identity = $pdo->query('SELECT VERSION() version, @@port port, DATABASE() db, CURRENT_USER() authenticated_user, @@datadir datadir, @@transaction_isolation isolation_level')->fetch();
mysqlTestAssert(
    'target database identity',
    (int)$identity['port'] === 3307 &&
    $identity['db'] === 'telvora_stage12lc_test' &&
    str_starts_with($identity['authenticated_user'], 'telvora_stage12lc@') &&
    str_contains(str_replace('\\', '/', strtolower($identity['datadir'])), '/users/asrock/telvora-mysql-test/data/'),
    $identity
);
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
$pdo->exec('DROP TABLE IF EXISTS product_variants');
$pdo->exec('DROP TABLE IF EXISTS products');
$pdo->exec("CREATE TABLE products (
    id INT UNSIGNED NOT NULL, name VARCHAR(255) NOT NULL, variants JSON NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0, PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE product_variants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, product_id INT UNSIGNED NOT NULL,
    variant_key VARCHAR(191) NOT NULL, assembly_country VARCHAR(100) DEFAULT NULL,
    display_name VARCHAR(255) DEFAULT NULL, classification_status VARCHAR(50) NOT NULL DEFAULT 'requires_classification',
    classification_evidence JSON DEFAULT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id), UNIQUE KEY uq_product_variants_product_key (product_id, variant_key),
    KEY idx_product_variants_product_active (product_id, is_active),
    CONSTRAINT fk_test_variant_product FOREIGN KEY (product_id) REFERENCES products(id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$indexes = $pdo->query("SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) columns_list FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='product_variants' GROUP BY INDEX_NAME ORDER BY INDEX_NAME")->fetchAll();
mysqlTestAssert('actual test indexes', count($indexes) === 3, $indexes);

$ids = mysqlTestSeed($pdo, 1, [mysqlTestLegacy('Russia',271400),mysqlTestLegacy('Poland',0),mysqlTestLegacy('China',0)], ['Russia','Poland','China']);
mysqlTestAssert('271400 plus drafts activates', mysqlTestActivate($pdo, 1) === 200 && (int)$pdo->query('SELECT is_active FROM products WHERE id=1')->fetchColumn() === 1);
mysqlTestSeed($pdo, 2, [mysqlTestLegacy('Russia',0)], ['Russia']);
mysqlTestAssert('all-zero remains hidden', mysqlTestActivate($pdo, 2) === 409 && (int)$pdo->query('SELECT is_active FROM products WHERE id=2')->fetchColumn() === 0);

function runBlockedScenario(PDO $pdo, int $productId, int $variantId, string $blockMode): int
{
    $dir = sys_get_temp_dir() . '/telvora_stage12lc_' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create barrier directory');
    $blocker = mysqlTestSpawn($blockMode, $dir, $productId, $variantId);
    mysqlTestWait("$dir/locked");
    $activation = mysqlTestSpawn('activate_split', $dir, $productId, $variantId);
    mysqlTestWait("$dir/preflight");
    mysqlTestSignal("$dir/go");
    usleep(100000);
    mysqlTestSignal("$dir/release");
    mysqlTestWait("$dir/result");
    [$aExit,,$aErr] = mysqlTestFinish($activation);
    [$bExit,,$bErr] = mysqlTestFinish($blocker);
    if ($aExit !== 0 || $bExit !== 0) throw new RuntimeException("Worker failed: $aErr $bErr");
    $result = (int)file_get_contents("$dir/result");
    foreach (glob("$dir/*") ?: [] as $file) unlink($file);
    rmdir($dir);
    return $result;
}

$candidateIds = mysqlTestSeed($pdo, 3, [mysqlTestLegacy('Russia',271400)], ['Russia']);
mysqlTestAssert('candidate disabled after preflight conflicts', runBlockedScenario($pdo,3,$candidateIds[0],'disable') === 409 && (int)$pdo->query('SELECT is_active FROM products WHERE id=3')->fetchColumn() === 0);

$stageIds = mysqlTestSeed($pdo, 4, [mysqlTestLegacy('Russia',271400)], ['Russia']);
mysqlTestAssert('Stage9-like writer permits safe activation', runBlockedScenario($pdo,4,$stageIds[0],'stage9') === 200);
$stageProduct = $pdo->query('SELECT variants,is_active FROM products WHERE id=4')->fetch();
mysqlTestAssert('Stage9 JSON is not lost', json_decode($stageProduct['variants'],true)[0]['price'] === 299900 && (int)$stageProduct['is_active'] === 1, $stageProduct);

$identityIds = mysqlTestSeed($pdo, 5, [mysqlTestLegacy('Russia',271400)], ['Russia']);
mysqlTestAssert('identity changed after preflight conflicts', runBlockedScenario($pdo,5,$identityIds[0],'identity') === 409 && (int)$pdo->query('SELECT is_active FROM products WHERE id=5')->fetchColumn() === 0);

$concurrentIds = mysqlTestSeed($pdo, 6, [mysqlTestLegacy('Russia',271400)], ['Russia']);
$dir = sys_get_temp_dir() . '/telvora_stage12lc_' . bin2hex(random_bytes(6)); mkdir($dir,0700);
$first = mysqlTestSpawn('activate_hold',$dir,6,$concurrentIds[0]); mysqlTestWait("$dir/callback");
$secondDir = $dir . '_second'; mkdir($secondDir,0700);
$second = mysqlTestSpawn('activate',$secondDir,6,$concurrentIds[0]);
mysqlTestSignal("$dir/go"); mysqlTestWait("$dir/result"); mysqlTestWait("$secondDir/result");
[$exit1] = mysqlTestFinish($first); [$exit2] = mysqlTestFinish($second);
mysqlTestAssert('two concurrent activations succeed consistently', $exit1===0 && $exit2===0 && trim(file_get_contents("$dir/result"))==='200' && str_starts_with(trim(file_get_contents("$secondDir/result")),'200|') && (int)$pdo->query('SELECT is_active FROM products WHERE id=6')->fetchColumn()===1);
foreach ([$dir,$secondDir] as $cleanup) { foreach (glob("$cleanup/*") ?: [] as $file) unlink($file); rmdir($cleanup); }

$timeoutIds = mysqlTestSeed($pdo, 7, [mysqlTestLegacy('Russia',271400)], ['Russia']);
$dir = sys_get_temp_dir() . '/telvora_stage12lc_' . bin2hex(random_bytes(6)); mkdir($dir,0700);
$holder = mysqlTestSpawn('hold',$dir,7,$timeoutIds[0]); mysqlTestWait("$dir/locked");
$runnerDir=$dir.'_runner'; mkdir($runnerDir,0700); $runner=mysqlTestSpawn('activate',$runnerDir,7,$timeoutIds[0]);
mysqlTestWait("$runnerDir/result",7000); $timeoutResult=explode('|',trim(file_get_contents("$runnerDir/result")));
mysqlTestSignal("$dir/release"); [$runnerExit]=mysqlTestFinish($runner); [$holderExit]=mysqlTestFinish($holder);
mysqlTestAssert('real 1205 is retried then returned as 409', $runnerExit===0 && $holderExit===0 && $timeoutResult[0]==='409' && (float)$timeoutResult[1]>=1.8 && (int)$pdo->query('SELECT is_active FROM products WHERE id=7')->fetchColumn()===0, $timeoutResult);
foreach ([$dir,$runnerDir] as $cleanup) { foreach (glob("$cleanup/*") ?: [] as $file) unlink($file); rmdir($cleanup); }

$deadlockIds = mysqlTestSeed($pdo, 9, [mysqlTestLegacy('Russia',271400)], ['Russia']);
$dir = sys_get_temp_dir() . '/telvora_stage12lc_' . bin2hex(random_bytes(6)); mkdir($dir,0700);
$reverse = mysqlTestSpawn('deadlock_reverse',$dir,9,$deadlockIds[0]); mysqlTestWait("$dir/product_locked");
$activation = mysqlTestSpawn('activate_deadlock',$dir,9,$deadlockIds[0]); mysqlTestWait("$dir/candidate_locked");
mysqlTestWait("$dir/requesting_candidate"); mysqlTestSignal("$dir/allow_product");
mysqlTestWait("$dir/result"); mysqlTestWait("$dir/reverse_done");
[$activationExit,,$activationError]=mysqlTestFinish($activation); [$reverseExit,,$reverseError]=mysqlTestFinish($reverse);
mysqlTestAssert('real 1213 reaches activation PDO and is retried', is_file("$dir/saw_1213") && $activationExit===0 && $reverseExit===0 && str_starts_with(trim(file_get_contents("$dir/result")),'200|') && (int)$pdo->query('SELECT is_active FROM products WHERE id=9')->fetchColumn()===1, [$activationError,$reverseError]);
foreach (glob("$dir/*") ?: [] as $file) unlink($file); rmdir($dir);

mysqlTestSeed($pdo, 8, [mysqlTestLegacy('Russia',271400)], ['Russia'], true);
$pdo->prepare('UPDATE products SET is_active=0 WHERE id=8')->execute();
mysqlTestAssert('ordinary deactivation works', (int)$pdo->query('SELECT is_active FROM products WHERE id=8')->fetchColumn()===0);
$pdo->prepare('UPDATE products SET is_active=1,name=? WHERE id=8')->execute(['Updated active product']);
mysqlTestAssert('ordinary active update works', $pdo->query('SELECT name FROM products WHERE id=8')->fetchColumn()==='Updated active product');

$addIds=mysqlTestSeed($pdo,10,[mysqlTestLegacy('Russia',271400)],['Russia']);
$added=productVariantAdd($pdo,10,'Poland');
$addLegacy=json_decode((string)$pdo->query('SELECT variants FROM products WHERE id=10')->fetchColumn(),true);
mysqlTestAssert('ordinary variant add', $added['is_active']===true && count($addLegacy)===2 && $addLegacy[1]['country']==='Poland' && $addLegacy[1]['price']===0 && $addLegacy[1]['old_price']===null && $addLegacy[1]['is_active']===true);
try { productVariantAdd($pdo,10,'Poland'); $duplicateStatus=200; } catch (ProductVariantMutationException $error) { $duplicateStatus=$error->httpStatus; }
mysqlTestAssert('duplicate variant key conflicts', $duplicateStatus===409 && (int)$pdo->query('SELECT COUNT(*) FROM product_variants WHERE product_id=10')->fetchColumn()===2);

mysqlTestSeed($pdo,11,[mysqlTestLegacy('Russia',271400)],['Russia']);
$sameA=sys_get_temp_dir().'/telvora_stage12lc_'.bin2hex(random_bytes(5)); $sameB=$sameA.'_b'; mkdir($sameA); mkdir($sameB);
$wa=mysqlTestSpawn('variant_add_same',$sameA,11,0); $wb=mysqlTestSpawn('variant_add_same',$sameB,11,0);
mysqlTestWait("$sameA/result"); mysqlTestWait("$sameB/result"); mysqlTestFinish($wa); mysqlTestFinish($wb);
$sameStatuses=[(int)file_get_contents("$sameA/result"),(int)file_get_contents("$sameB/result")]; sort($sameStatuses);
mysqlTestAssert('concurrent duplicate add has one winner', $sameStatuses===[200,409] && (int)$pdo->query('SELECT COUNT(*) FROM product_variants WHERE product_id=11')->fetchColumn()===2,$sameStatuses);
foreach([$sameA,$sameB] as $cleanup){foreach(glob("$cleanup/*")?:[] as $file)unlink($file);rmdir($cleanup);}

mysqlTestSeed($pdo,12,[mysqlTestLegacy('Russia',271400)],['Russia']);
$diffA=sys_get_temp_dir().'/telvora_stage12lc_'.bin2hex(random_bytes(5)); $diffB=$diffA.'_b'; mkdir($diffA); mkdir($diffB);
$wa=mysqlTestSpawn('variant_add_a',$diffA,12,0); $wb=mysqlTestSpawn('variant_add_b',$diffB,12,0);
mysqlTestWait("$diffA/result"); mysqlTestWait("$diffB/result"); mysqlTestFinish($wa); mysqlTestFinish($wb);
mysqlTestAssert('parallel different adds preserve both', trim(file_get_contents("$diffA/result"))==='200' && trim(file_get_contents("$diffB/result"))==='200' && count(json_decode((string)$pdo->query('SELECT variants FROM products WHERE id=12')->fetchColumn(),true))===3);
foreach([$diffA,$diffB] as $cleanup){foreach(glob("$cleanup/*")?:[] as $file)unlink($file);rmdir($cleanup);}

$setIds=mysqlTestSeed($pdo,14,[mysqlTestLegacy('Russia',271400),mysqlTestLegacy('Poland',280000)],['Russia','Poland'],true);
mysqlTestAssert('disable with alternate ready variant', productVariantSetActive($pdo,$setIds[0],false)['is_active']===false);
$setLegacy=json_decode((string)$pdo->query('SELECT variants FROM products WHERE id=14')->fetchColumn(),true);
mysqlTestAssert('set active updates relational and legacy', $setLegacy[0]['is_active']===false && (int)$pdo->query("SELECT is_active FROM product_variants WHERE id={$setIds[0]}")->fetchColumn()===0);
mysqlTestAssert('variant re-enable', productVariantSetActive($pdo,$setIds[0],true)['is_active']===true);
try { productVariantSetActive($pdo,$setIds[1],false); productVariantSetActive($pdo,$setIds[0],false); $lastStatus=200; } catch(ProductVariantMutationException $error){$lastStatus=$error->httpStatus;}
mysqlTestAssert('last ready variant of active product protected', $lastStatus===409 && (int)$pdo->query('SELECT is_active FROM products WHERE id=14')->fetchColumn()===1);

$addActivationIds=mysqlTestSeed($pdo,15,[mysqlTestLegacy('Russia',271400)],['Russia']);
$activationDir=sys_get_temp_dir().'/telvora_stage12lc_'.bin2hex(random_bytes(5)); $addDir=$activationDir.'_add'; mkdir($activationDir);mkdir($addDir);
$activationWorker=mysqlTestSpawn('activate_hold',$activationDir,15,$addActivationIds[0]);mysqlTestWait("$activationDir/callback");
$addWorker=mysqlTestSpawn('variant_add_a',$addDir,15,0);mysqlTestWait("$addDir/started");mysqlTestSignal("$activationDir/go");
mysqlTestWait("$activationDir/result");mysqlTestWait("$addDir/result");mysqlTestFinish($activationWorker);mysqlTestFinish($addWorker);
mysqlTestAssert('variant add concurrent with activation', trim(file_get_contents("$activationDir/result"))==='200' && trim(file_get_contents("$addDir/result"))==='200' && count(json_decode((string)$pdo->query('SELECT variants FROM products WHERE id=15')->fetchColumn(),true))===2);
foreach([$activationDir,$addDir] as $cleanup){foreach(glob("$cleanup/*")?:[] as $file)unlink($file);rmdir($cleanup);}

$setActivationIds=mysqlTestSeed($pdo,16,[mysqlTestLegacy('Russia',271400)],['Russia']);
$activationDir=sys_get_temp_dir().'/telvora_stage12lc_'.bin2hex(random_bytes(5));$setDir=$activationDir.'_set';mkdir($activationDir);mkdir($setDir);
$activationWorker=mysqlTestSpawn('activate_hold',$activationDir,16,$setActivationIds[0]);mysqlTestWait("$activationDir/callback");
$setWorker=mysqlTestSpawn('variant_disable',$setDir,16,$setActivationIds[0]);mysqlTestWait("$setDir/started");mysqlTestSignal("$activationDir/go");
mysqlTestWait("$activationDir/result");mysqlTestWait("$setDir/result");mysqlTestFinish($activationWorker);mysqlTestFinish($setWorker);
mysqlTestAssert('variant disable concurrent with activation preserves invariant', trim(file_get_contents("$activationDir/result"))==='200' && str_starts_with(trim(file_get_contents("$setDir/result")),'409|') && (int)$pdo->query('SELECT is_active FROM products WHERE id=16')->fetchColumn()===1);
foreach([$activationDir,$setDir] as $cleanup){foreach(glob("$cleanup/*")?:[] as $file)unlink($file);rmdir($cleanup);}

$toggleIds=mysqlTestSeed($pdo,17,[mysqlTestLegacy('Russia',271400)],['Russia']);
$offDir=sys_get_temp_dir().'/telvora_stage12lc_'.bin2hex(random_bytes(5));$onDir=$offDir.'_on';mkdir($offDir);mkdir($onDir);
$off=mysqlTestSpawn('variant_disable',$offDir,17,$toggleIds[0]);$on=mysqlTestSpawn('variant_enable',$onDir,17,$toggleIds[0]);mysqlTestWait("$offDir/result");mysqlTestWait("$onDir/result");mysqlTestFinish($off);mysqlTestFinish($on);
$toggle=$pdo->query("SELECT pv.is_active relational_active,p.variants FROM product_variants pv JOIN products p ON p.id=pv.product_id WHERE pv.id={$toggleIds[0]}")->fetch();
mysqlTestAssert('concurrent enable disable remains synchronized',(bool)$toggle['relational_active']===json_decode($toggle['variants'],true)[0]['is_active']);
foreach([$offDir,$onDir] as $cleanup){foreach(glob("$cleanup/*")?:[] as $file)unlink($file);rmdir($cleanup);}

$mutationTimeoutIds=mysqlTestSeed($pdo,18,[mysqlTestLegacy('Russia',271400)],['Russia']);
$holdDir=sys_get_temp_dir().'/telvora_stage12lc_'.bin2hex(random_bytes(5));$setDir=$holdDir.'_set';mkdir($holdDir);mkdir($setDir);
$holder=mysqlTestSpawn('hold',$holdDir,18,$mutationTimeoutIds[0]);mysqlTestWait("$holdDir/locked");$setter=mysqlTestSpawn('variant_disable',$setDir,18,$mutationTimeoutIds[0]);mysqlTestWait("$setDir/result",7000);
$timeoutParts=explode('|',trim(file_get_contents("$setDir/result")));mysqlTestSignal("$holdDir/release");mysqlTestFinish($setter);mysqlTestFinish($holder);
mysqlTestAssert('mutation real 1205 bounded retry conflict',$timeoutParts[0]==='409' && (float)$timeoutParts[1]>=1.8);
foreach([$holdDir,$setDir] as $cleanup){foreach(glob("$cleanup/*")?:[] as $file)unlink($file);rmdir($cleanup);}

$mutationDeadlockIds=mysqlTestSeed($pdo,19,[mysqlTestLegacy('Russia',271400)],['Russia']);
$dir=sys_get_temp_dir().'/telvora_stage12lc_'.bin2hex(random_bytes(5));mkdir($dir);
$reverse=mysqlTestSpawn('deadlock_reverse',$dir,19,$mutationDeadlockIds[0]);mysqlTestWait("$dir/product_locked");$setter=mysqlTestSpawn('variant_disable_deadlock',$dir,19,$mutationDeadlockIds[0]);mysqlTestWait("$dir/candidate_locked");mysqlTestWait("$dir/requesting_candidate");mysqlTestSignal("$dir/allow_product");mysqlTestWait("$dir/result");mysqlTestWait("$dir/reverse_done");mysqlTestFinish($setter);mysqlTestFinish($reverse);
mysqlTestAssert('mutation real 1213 rollback and retry',is_file("$dir/saw_1213") && str_starts_with(trim(file_get_contents("$dir/result")),'200|'));
foreach(glob("$dir/*")?:[] as $file)unlink($file);rmdir($dir);

echo 'ENV ' . json_encode($identity, JSON_UNESCAPED_SLASHES) . "\n";
$pdo->exec('DROP TABLE product_variants');
$pdo->exec('DROP TABLE products');
echo "PASS real MySQL concurrency suite\n";

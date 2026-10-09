<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/seo_publication_release_service.php';

// This test cannot connect to production, even via environment overrides.
$host = getenv('TELVORA_TEST_DB_HOST') ?: '127.0.0.1';
$name = getenv('TELVORA_TEST_DB_NAME') ?: 'telvora_release_test';
if (!in_array($host, ['127.0.0.1','localhost'], true) || $name !== 'telvora_release_test') throw new RuntimeException('Refusing non-disposable release test database');
$dsn = 'mysql:host=' . $host . ';port=' . (int)(getenv('TELVORA_TEST_DB_PORT') ?: 3307) . ';charset=utf8mb4';
$user = getenv('TELVORA_TEST_DB_USER') ?: 'root'; $password = getenv('TELVORA_TEST_DB_PASSWORD') ?: '';
$server = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$server->exec('CREATE DATABASE IF NOT EXISTS telvora_release_test');
$dsn .= ';dbname=telvora_release_test';
$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$second = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('DROP TABLE IF EXISTS seo_publication_finalization_audit');
$pdo->exec('DROP TABLE IF EXISTS seo_publication_jobs');
$pdo->exec('DROP TABLE IF EXISTS products');
$pdo->exec("CREATE TABLE products (id INT UNSIGNED PRIMARY KEY, slug VARCHAR(100) NOT NULL, category VARCHAR(20) NOT NULL DEFAULT 'OLED', resolution VARCHAR(20) NOT NULL DEFAULT '4K', name VARCHAR(100) NOT NULL DEFAULT 'Fixture', is_active TINYINT NOT NULL, publication_status VARCHAR(32) NOT NULL, publication_revision BIGINT UNSIGNED NOT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
$migration = file_get_contents(dirname(__DIR__) . '/database/migrations/20260922_013_seo_publication_state.sql');
$pdo->exec(substr($migration, strpos($migration, 'CREATE TABLE seo_publication_jobs')));
$pdo->exec(file_get_contents(dirname(__DIR__) . '/database/migrations/20261009_015_seo_publication_finalization_audit.sql'));

$temporary = sys_get_temp_dir() . '/telvora-release-test-' . bin2hex(random_bytes(6));
mkdir($temporary); mkdir($temporary.'/staging'); mkdir($temporary.'/staging/run'); mkdir($temporary.'/staging/run/live'); mkdir($temporary.'/pkg'); mkdir($temporary.'/doc'); mkdir($temporary.'/doc/_prerender');
$release = str_repeat('a',40); $snapshot = str_repeat('b',64); $releaseId = substr($release,0,12).'-'.substr($snapshot,0,12);
$options = ['source'=>'release','release_sha'=>$release,'snapshot_hash'=>$snapshot,'package_sha256'=>'','archive'=>$temporary.'/release.tar','package_root'=>$temporary.'/pkg','active_record'=>$temporary.'/staging/run/live/active-release.json','document_root'=>$temporary.'/doc','staging_root'=>$temporary.'/staging','smoke_result'=>'PASS'];

function releaseTestAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException('FAIL '.$message); echo 'PASS '.$message,"\n"; }
function releaseTestState(PDO $pdo): string { return json_encode([$pdo->query('SELECT * FROM products ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM seo_publication_jobs ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM seo_publication_finalization_audit ORDER BY id')->fetchAll()]); }
function releaseTestSeed(PDO $pdo): array {
    $pdo->exec('DELETE FROM seo_publication_finalization_audit'); $pdo->exec('DELETE FROM seo_publication_jobs'); $pdo->exec('DELETE FROM products');
    $pdo->exec("INSERT INTO products (id,slug,is_active,publication_status,publication_revision) VALUES (1,'product-a',1,'pending_publish',1),(2,'product-b',1,'pending_publish',1),(3,'outside-allowlist',1,'published',0)");
    $batch=seoPublicationNewBatchId();
    $q=$pdo->prepare("INSERT INTO seo_publication_jobs (id,product_id,operation,requested_revision,status,batch_id) VALUES (:id,:p,'publish',1,'queued',:batch)");
    foreach([1,2] as $id)$q->execute([':id'=>$id,':p'=>$id,':batch'=>$batch]);
    $intents=seoReleaseCaptureIntents($pdo,$pdo->query('SELECT * FROM seo_publication_jobs ORDER BY id')->fetchAll());
    foreach($intents as &$i)$i['path']='/catalog/oled/'.$i['slug']; unset($i);
    return $intents;
}
function releaseTestPackage(array &$options, array $intents): void {
    $routes=[]; $files=[]; $contents=[];
    foreach(glob($options['document_root'].'/_prerender/*.html') as $ownedFixture)unlink($ownedFixture);
    foreach($intents as $i){if($i['operation']==='unpublish')continue;$routes[]=$i['path']; $file='_prerender/product-'.$i['slug'].'.html'; $files[$i['path']]='/'.$file; $contents['payload/'.$file]='<h1>Fixture</h1><script id="telvora-prerender" type="application/json">'.json_encode(['path'=>$i['path'],'products'=>[['id'=>$i['product_id'],'slug'=>$i['slug']]]]).'</script>';}
    $contents['payload/sitemap.xml']='<urlset>'.implode('',array_map(static fn($p)=>'<url><loc>https://telvora.ru'.$p.'</loc></url>',$routes)).'</urlset>';
    $contents['payload/index.html']='<h1>Home</h1>';
    $contents['production.htaccess']='fixture htaccess';
    $managed=array_map(static fn($p)=>substr($p,8),array_keys(array_filter($contents,static fn($p)=>str_starts_with($p,'payload/'),ARRAY_FILTER_USE_KEY)));
    $m=['releaseId'=>substr($options['release_sha'],0,12).'-'.substr($options['snapshot_hash'],0,12),'commitSha'=>$options['release_sha'],'snapshotHash'=>$options['snapshot_hash'],'productRoutes'=>$routes,'prerenderFiles'=>$files,'managedFiles'=>$managed,'publicationIntents'=>$intents];
    $contents['deployment-manifest.json']=json_encode($m); $contents['snapshot.json']=json_encode(['snapshotHash'=>$options['snapshot_hash']]);
    $checks=[]; foreach($contents as $f=>$bytes)$checks[]=hash('sha256',$bytes).'  '.$f;
    $contents['checksums.sha256']=implode("\n",$checks)."\n";
    $options['archive']=dirname($options['archive']).'/release-'.bin2hex(random_bytes(5)).'.tar';
    $tar=new PharData($options['archive']);
    foreach($contents as $f=>$bytes){$tar[$f]=$bytes;if(str_starts_with($f,'payload/'))file_put_contents($options['document_root'].'/'.substr($f,8),$bytes);}
    file_put_contents($options['document_root'].'/.htaccess',$contents['production.htaccess']);
    file_put_contents($options['package_root'].'/deployment-manifest.json',$contents['deployment-manifest.json']);
    unset($tar); $options['package_sha256']=hash_file('sha256',$options['archive']);
    $managed[]='.htaccess'; $productionChecks=[];foreach($managed as $f)$productionChecks[$f]=hash_file('sha256',$options['document_root'].'/'.$f);
    file_put_contents($options['active_record'],json_encode(['releaseId'=>$m['releaseId'],'commit'=>$options['release_sha'],'snapshotHash'=>$options['snapshot_hash'],'activatedAt'=>'2026-10-09T00:00:00Z','managedFiles'=>$managed,'productionChecksums'=>$productionChecks,'backupReference'=>'fixture-static-backup']));
}
function releaseTestReject(PDO $pdo, callable $callback, string $message): void {
    $before=releaseTestState($pdo); $failed=false;
    try{$callback();}catch(Throwable $e){$failed=true;}
    releaseTestAssert($failed && releaseTestState($pdo)===$before,$message.' (no partial writes)');
}

try {
    $intents=releaseTestSeed($pdo); releaseTestPackage($options,$intents);
    $http=static function($path)use(&$options){return ['status'=>200,'body'=>file_get_contents($options['document_root'].'/_prerender/product-'.basename($path).'.html')];};
    $verify=static fn()=>seoReleaseVerifyEvidence($options,$intents,$http);
    $before=releaseTestState($pdo); $result=seoReleaseFinalize($pdo,$options,$intents,false,$verify);
    releaseTestAssert($result['status']==='DRY_RUN'&&$result['would_change_jobs']===2&&releaseTestState($pdo)===$before,'dry-run changes neither products, jobs nor audit');
    $outside=$pdo->query('SELECT * FROM products WHERE id=3')->fetch();
    $result=seoReleaseFinalize($pdo,$options,$intents,true,$verify);
    releaseTestAssert($result['changed_jobs']===2&&(int)$pdo->query("SELECT COUNT(*) FROM products WHERE publication_status='published'")->fetchColumn()===3,'successful exact finalization');
    releaseTestAssert($outside===$pdo->query('SELECT * FROM products WHERE id=3')->fetch(),'outside allowlist untouched');
    releaseTestAssert((int)$pdo->query('SELECT COUNT(*) FROM seo_publication_finalization_audit')->fetchColumn()===1,'audit commits atomically with transitions');
    $before=releaseTestState($pdo);$again=seoReleaseFinalize($pdo,$options,$intents,true,$verify);
    releaseTestAssert($again['status']==='ALREADY_COMPLETED'&&releaseTestState($pdo)===$before,'repeat finalization is a no-op');
    $intents=releaseTestSeed($pdo);releaseTestPackage($options,$intents);
    $pdo->exec('UPDATE products SET publication_revision=2 WHERE id=2');
    releaseTestReject($pdo,static fn()=>seoReleaseFinalize($pdo,$options,$intents,true,static fn()=>seoReleaseVerifyEvidence($options,$intents,$http)),'revision mismatch rolls back entire allowlist');
    $intents=releaseTestSeed($pdo);releaseTestPackage($options,$intents);
    $inserted=false;
    $raceVerify=static function()use($options,$intents,$http,$second,&$inserted){
        $proof=seoReleaseVerifyEvidence($options,$intents,$http);
        if(!$inserted){$second->exec("INSERT INTO seo_publication_jobs (id,product_id,operation,requested_revision,status,batch_id) VALUES (3,1,'unpublish',2,'queued','11111111-1111-4111-8111-111111111111')");$inserted=true;}
        return $proof;
    };
    $beforeProducts=$pdo->query('SELECT * FROM products ORDER BY id')->fetchAll();
    try{seoReleaseFinalize($pdo,$options,$intents,true,$raceVerify);throw new RuntimeException('new task accepted');}catch(SeoPublicationStateException $e){}
    releaseTestAssert($inserted&&$beforeProducts===$pdo->query('SELECT * FROM products ORDER BY id')->fetchAll()&&(int)$pdo->query("SELECT COUNT(*) FROM seo_publication_jobs WHERE status='completed'")->fetchColumn()===0,'new task after evidence verification is rejected');
    $intents=releaseTestSeed($pdo);releaseTestPackage($options,$intents);
    foreach(['release_sha','package_sha256','snapshot_hash'] as $key){$bad=$options;$bad[$key]=str_repeat('c',strlen($bad[$key]));releaseTestReject($pdo,static fn()=>seoReleaseFinalize($pdo,$bad,$intents,true,static fn()=>seoReleaseVerifyEvidence($bad,$intents,$http)), $key.' mismatch rejected');}
    $bad=$options;$bad['smoke_result']='FAIL';
    releaseTestReject($pdo,static fn()=>seoReleaseFinalize($pdo,$bad,$intents,true,static fn()=>seoReleaseVerifyEvidence($bad,$intents,$http)),'failed deployment/smoke rejected');
    mkdir($options['staging_root'].'/other');
    $other=seoReleaseJsonFile($options['active_record']);$other['releaseId']='other-release';
    file_put_contents($options['staging_root'].'/other/active-release.json',json_encode($other));
    releaseTestReject($pdo,static fn()=>seoReleaseFinalize($pdo,$options,$intents,true,static fn()=>seoReleaseVerifyEvidence($options,$intents,$http)),'different activation in the same second rejected');
    $other['activatedAt']='2026-10-09T00:00:01Z';file_put_contents($options['staging_root'].'/other/active-release.json',json_encode($other));
    releaseTestReject($pdo,static fn()=>seoReleaseFinalize($pdo,$options,$intents,true,static fn()=>seoReleaseVerifyEvidence($options,$intents,$http)),'newer active release rejected');
    unlink($options['staging_root'].'/other/active-release.json');rmdir($options['staging_root'].'/other');
    $page=$options['document_root'].'/_prerender/product-product-b.html';$bytes=file_get_contents($page);unlink($page);
    releaseTestReject($pdo,static fn()=>seoReleaseFinalize($pdo,$options,$intents,true,static fn()=>seoReleaseVerifyEvidence($options,$intents,$http)),'partially published package rejected');file_put_contents($page,$bytes);
    $badHttp=static fn($path)=>['status'=>200,'body'=>'a different page'];
    releaseTestReject($pdo,static fn()=>seoReleaseFinalize($pdo,$options,$intents,true,static fn()=>seoReleaseVerifyEvidence($options,$intents,$badHttp)),'HTTP 200 with wrong HTML rejected');
    $record=seoReleaseJsonFile($options['active_record']);$record['productionChecksums']['index.html']=str_repeat('d',64);file_put_contents($options['active_record'],json_encode($record));
    releaseTestReject($pdo,static fn()=>seoReleaseFinalize($pdo,$options,$intents,true,static fn()=>seoReleaseVerifyEvidence($options,$intents,$http)),'active record hash mismatch rejected');
    releaseTestPackage($options,$intents);
    $reconcile=$options;$reconcile['source']='reconciliation';
    $result=seoReleaseFinalize($pdo,$reconcile,$intents,true,static fn()=>seoReleaseVerifyEvidence($reconcile,$intents,$http));
    releaseTestAssert($result['changed_jobs']===2&&(int)$pdo->query('SELECT SUM(attempt_count) FROM seo_publication_jobs')->fetchColumn()===0&&$pdo->query('SELECT started_at FROM seo_publication_jobs LIMIT 1')->fetchColumn()===null,'reconciliation preserves queued task history');
    $before=releaseTestState($pdo);$result=seoReleaseFinalize($pdo,$reconcile,$intents,true,static fn()=>seoReleaseVerifyEvidence($reconcile,$intents,$http));
    releaseTestAssert($result['status']==='ALREADY_COMPLETED'&&releaseTestState($pdo)===$before,'repeat reconciliation is safe');
    $intents=releaseTestSeed($pdo);releaseTestPackage($options,$intents);
    $gzipPath=dirname($options['archive']).'/gzip-'.bin2hex(random_bytes(5)).'.tar.gz';file_put_contents($gzipPath,gzencode(file_get_contents($options['archive'])));$options['archive']=$gzipPath;$options['package_sha256']=hash_file('sha256',$options['archive']);
    $result=seoReleaseFinalize($pdo,$options,$intents,false,static fn()=>seoReleaseVerifyEvidence($options,$intents,$http));
    releaseTestAssert($result['would_change_jobs']===2,'production gzip TAR evidence verified');
    $pdo->exec("CREATE TRIGGER reject_release_audit BEFORE INSERT ON seo_publication_finalization_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture audit failure'");
    releaseTestReject($pdo,static fn()=>seoReleaseFinalize($pdo,$options,$intents,true,static fn()=>seoReleaseVerifyEvidence($options,$intents,$http)),'audit failure rolls back products and jobs');
    $pdo->exec('DROP TRIGGER reject_release_audit');
    $pdo->exec("UPDATE seo_publication_jobs SET status='running',snapshot_hash='".$options['snapshot_hash']."',attempt_count=1,started_at=CURRENT_TIMESTAMP");
    $intents=seoReleaseCaptureIntents($pdo,$pdo->query('SELECT * FROM seo_publication_jobs ORDER BY id')->fetchAll());foreach($intents as &$i)$i['path']='/catalog/oled/'.$i['slug'];unset($i);
    releaseTestPackage($options,$intents);
    $result=seoReleaseFinalize($pdo,$options,$intents,true,static fn()=>seoReleaseVerifyEvidence($options,$intents,$http));
    releaseTestAssert($result['changed_jobs']===2,'running autopublish intents finalized against package snapshot');
    $intents=releaseTestSeed($pdo);
    $pdo->exec("UPDATE products SET publication_status='pending_unpublish' WHERE id IN (1,2)");$pdo->exec("UPDATE seo_publication_jobs SET operation='unpublish'");
    $intents=seoReleaseCaptureIntents($pdo,$pdo->query('SELECT * FROM seo_publication_jobs ORDER BY id')->fetchAll());foreach($intents as &$i)$i['path']='/catalog/oled/'.$i['slug'];unset($i);
    releaseTestPackage($options,$intents);$notFound=static fn($path)=>['status'=>404,'body'=>'Not found'];
    $result=seoReleaseFinalize($pdo,$options,$intents,true,static fn()=>seoReleaseVerifyEvidence($options,$intents,$notFound));
    releaseTestAssert($result['changed_jobs']===2&&(int)$pdo->query("SELECT COUNT(*) FROM products WHERE publication_status='draft' AND is_active=0")->fetchColumn()===2,'unpublish requires package/sitemap/file absence and HTTP 404');
    echo "PASS release finalization MySQL integration suite\n";
} finally {
    if($pdo->inTransaction())$pdo->rollBack();
    // Only this randomly named, verified test directory is removed.
    if(str_starts_with($temporary,sys_get_temp_dir().'/telvora-release-test-')){
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($temporary);
    }
}

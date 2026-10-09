<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/seo_publication_release_service.php';
$host=getenv('TELVORA_TEST_DB_HOST');$name=getenv('TELVORA_TEST_DB_NAME');
if(!in_array($host,['127.0.0.1','localhost'],true)||$name!=='telvora_phase2b_test')throw new RuntimeException('Refusing non-disposable capture test database');
$pdo=new PDO('mysql:host='.$host.';port='.(int)getenv('TELVORA_TEST_DB_PORT').';dbname='.$name.';charset=utf8mb4',(string)getenv('TELVORA_TEST_DB_USER'),(string)getenv('TELVORA_TEST_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function captureState(PDO $pdo): string {return json_encode([$pdo->query('SELECT * FROM products ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM seo_publication_jobs ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM seo_publication_finalization_audit')->fetchAll()]);}
function captureRun(string $command): array {
    $process=proc_open([PHP_BINARY,dirname(__DIR__).'/seo_publication_worker.php',$command],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),getenv());
    if(!is_resource($process))throw new RuntimeException('Cannot start worker test');
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
    if($code!==0)throw new RuntimeException('Worker test failed: '.$err.$out);
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
$before=captureState($pdo);$snapshot=captureRun('capture');
if($before!==captureState($pdo))throw new RuntimeException('Capture changed database');
if(count($snapshot['publication_intents'])!==3||count($snapshot['products'])!==2)throw new RuntimeException('Captured wrong intent/content set');
$pending=array_values(array_filter($snapshot['products'],static fn($p)=>(int)$p['id']===5))[0];
if((float)($pending['storefront_variants'][0]['price']??0)!==100000.0)throw new RuntimeException('Private snapshot lost authoritative variant price');
foreach($snapshot['publication_intents'] as $i)if($i['job_status']!=='queued'||!isset($i['product_fingerprint'],$i['job_fields']))throw new RuntimeException('Capture missing exact identity guards');
echo "PASS worker capture is read-only, preserves exact intents and authoritative storefront variant prices\n";
$prepared=captureRun('prepare');$file=$prepared['snapshot_file'];$snapshot=seoReleaseJsonFile($file);
if($prepared['status']!=='PREPARED'||count($snapshot['publication_intents'])!==3)throw new RuntimeException('Prepared batch lost intents');
foreach($snapshot['publication_intents'] as $i){$j=seoPublicationJobGet($pdo,$i['job_id']);if($i['job_status']!=='running'||$j['snapshot_hash']!==$snapshot['snapshot_hash'])throw new RuntimeException('Prepared job identity/hash mismatch');}
echo "PASS worker prepare binds running batch snapshot and publication intents\n";
unlink($file);

<?php
declare(strict_types=1);
// CLI only. Keep this script and SQL outside the public DocumentRoot on hosting.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
if (!in_array($argv[1] ?? '',['--check','--apply'],true)) { fwrite(STDERR,"Usage: php apply-migration.php --check|--apply\n"); exit(2); }
try {
    $config=getenv('TELVORA_RUNTIME_CONFIG') ?: dirname(__DIR__,2).'/runtime_config.php';
    if(!is_file($config))throw new RuntimeException('Runtime configuration file missing');
    require_once $config;
    $secrets=require telvoraSecretsFile();
    $pdo=new PDO('mysql:host='.$secrets['db_host'].';dbname='.$secrets['db_name'].';charset=utf8mb4',$secrets['db_user'],$secrets['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $lock=$pdo->query("SELECT GET_LOCK(CONCAT(DATABASE(),':customer_014'),10)")->fetchColumn();
    if((int)$lock!==1)throw new RuntimeException('Migration lock unavailable');
    $tables=$pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('customers','customer_auth_limits','customer_tokens')")->fetchAll(PDO::FETCH_COLUMN);
    $column=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='orders' AND column_name='customer_id'")->fetchColumn();
    $verify=static function(PDO $pdo):void {
        $columns=$pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='customers'")->fetchAll(PDO::FETCH_COLUMN);
        if(array_diff(['id','login','login_normalized','password_hash','full_name','phone','email','email_verified_at','address','auth_version','created_at','updated_at'],$columns))throw new RuntimeException('Customer columns incomplete');
        $unique=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='customers' AND index_name='uq_customer_login' AND non_unique=0 AND column_name='login_normalized'")->fetchColumn();
        $fk=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name='orders' AND column_name='customer_id' AND referenced_table_name='customers' AND referenced_column_name='id'")->fetchColumn();
        if($unique!==1 || $fk!==1)throw new RuntimeException('Customer constraints incomplete');
    };
    if(count($tables)===3 && $column===1) { $verify($pdo); echo "ALREADY_APPLIED_AND_VERIFIED\n"; exit; }
    if($tables!==[] || $column!==0)throw new RuntimeException('Partial migration detected; stop and inspect schema, do not rerun blindly');
    $engine=$pdo->query("SELECT engine FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='orders'")->fetchColumn();
    if($engine!=='InnoDB')throw new RuntimeException('orders must exist and use InnoDB');
    $columns=$pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='orders'")->fetchAll(PDO::FETCH_COLUMN);
    if(array_diff(['id','order_number','customer_name','phone','email','address','created_at','status','total','delivery_quote_status'],$columns))throw new RuntimeException('Existing order schema is incompatible');
    if(($argv[1] ?? '')==='--check'){echo "PREFLIGHT_OK\n";exit;}
    $sql=__DIR__.'/20260927_014_customer_accounts.sql';
    if(!is_file($sql))$sql=dirname(__DIR__,2).'/database/migrations/20260927_014_customer_accounts.sql';
    if(!is_file($sql))throw new RuntimeException('Migration SQL missing');
    $pdo->exec((string)file_get_contents($sql));
    $verify($pdo);
    echo 'APPLIED_AND_VERIFIED sha256='.hash_file('sha256',$sql)."\n";
} catch(Throwable $error) {
    // SQL exceptions may contain PII/connection information: never print them.
    fwrite(STDERR,$error instanceof PDOException ? "MIGRATION_DATABASE_ERROR: inspect schema privately; MySQL DDL is not transactional.\n" : $error->getMessage()."\n");
    exit(1);
}

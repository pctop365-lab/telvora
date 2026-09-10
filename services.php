<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://telvora.ru');
require_once __DIR__ . '/service_catalog_service.php';
function servicePublicSecrets(): array { $f=dirname(__DIR__,2).'/telvora_runtime/telvora_secrets.php'; $v=is_readable($f)?require $f:null; if(!is_array($v)) throw new RuntimeException(); return $v; }
try {
    $s=servicePublicSecrets();
    $pdo=new PDO('mysql:host='.$s['db_host'].';dbname='.$s['db_name'].';charset=utf8mb4',$s['db_user'],$s['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    echo json_encode(['success'=>true,'services'=>serviceCatalogList($pdo)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch(Throwable) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Каталог услуг временно недоступен'],JSON_UNESCAPED_UNICODE); }

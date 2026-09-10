<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/service_catalog_service.php';

const SERVICE_DSN='mysql:host=127.0.0.1;port=3307;dbname=telvora_stage12lc_test;charset=utf8mb4';
const SERVICE_USER='telvora_stage12lc';
const SERVICE_PASSWORD_FILE='C:/Users/ASRock/Telvora-MySQL-Test/private/test-password.txt';
function ok(string $name,bool $condition):void{if(!$condition)throw new RuntimeException("FAIL {$name}");echo "PASS {$name}\n";}

$password=trim((string)file_get_contents(SERVICE_PASSWORD_FILE));
$pdo=new PDO(SERVICE_DSN,SERVICE_USER,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS order_services; DROP TABLE IF EXISTS service_catalog; DROP TABLE IF EXISTS order_items; DROP TABLE IF EXISTS orders; SET FOREIGN_KEY_CHECKS=1');
$pdo->exec('CREATE TABLE orders(id INT NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE order_items(id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,order_id INT NOT NULL,CONSTRAINT fk_test_item_order FOREIGN KEY(order_id) REFERENCES orders(id)) ENGINE=InnoDB');
$preflight=(string)file_get_contents(dirname(__DIR__).'/database/migrations/preflight_20260909_011_service_catalog.sql');
foreach(array_filter(array_map('trim',explode(';',$preflight))) as $query)ok('migration preflight has no blocker',$pdo->query($query)->fetchAll()===[]);
$migration=(string)file_get_contents(dirname(__DIR__).'/database/migrations/20260909_011_service_catalog.sql');
$pdo->exec($migration);

$catalog=serviceCatalogList($pdo);
ok('separate service catalog has 13 entries',count($catalog)===13);
ok('quote-required setup has no fixed price',count(array_filter($catalog,fn($s)=>$s['service_key']==='tv-setup'&&$s['price']===null))===1);
$overlaps=$pdo->query('SELECT a.id FROM service_catalog a JOIN service_catalog b ON a.category=b.category AND a.id<b.id AND a.min_screen_size<=b.max_screen_size AND b.min_screen_size<=a.max_screen_size')->fetchAll();
ok('migration verify finds no overlapping inclusive ranges',$overlaps===[]);
foreach(['mounting','pixel_test'] as $category){
  $ranges=array_values(array_filter($catalog,fn($s)=>$s['category']===$category&&$s['price']!==null));
  for($size=1;$size<=130;$size++)ok("{$category} boundary {$size}",count(array_filter($ranges,fn($s)=>serviceCatalogCompatible($s,$size)))<=1);
}

$items=[['name'=>'TV A','screen_size'=>'55″','quantity'=>1],['name'=>'TV B','screen_size'=>'86″','quantity'=>1]];
$mount55=(int)$pdo->query("SELECT id FROM service_catalog WHERE service_key='wall-mount-55-64'")->fetchColumn();
$pixel86=(int)$pdo->query("SELECT id FROM service_catalog WHERE service_key='pixel-test-83-86'")->fetchColumn();
$resolved=serviceCatalogResolve($pdo,[['service_id'=>$mount55,'target_item_index'=>0,'quantity'=>1],['service_id'=>$pixel86,'target_item_index'=>1,'quantity'=>1]],$items);
ok('different services bind to different televisions',$resolved[0]['television_name']==='TV A'&&$resolved[1]['television_name']==='TV B');
ok('server prices ignore client price',serviceCatalogTotal($resolved)===18000.0);
try{serviceCatalogResolve($pdo,[['service_id'=>$mount55,'target_item_index'=>1,'quantity'=>1]],$items);ok('incompatible diagonal rejected',false);}catch(ServiceCatalogException){ok('incompatible diagonal rejected',true);}
try{serviceCatalogResolve($pdo,[['service_id'=>$mount55,'target_item_index'=>0,'quantity'=>2]],$items);ok('excess quantity rejected',false);}catch(ServiceCatalogException){ok('excess quantity rejected',true);}

$pdo->exec('INSERT INTO orders VALUES(NULL)');$orderId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items(order_id) VALUES(?)')->execute([$orderId]);$orderItemId=(int)$pdo->lastInsertId();
$snapshot=$resolved[0];
$stmt=$pdo->prepare('INSERT INTO order_services(order_id,order_item_id,service_id,service_key,service_name,service_category,television_name,screen_size,unit_price,quantity,total,metadata) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
$stmt->execute([$orderId,$orderItemId,$snapshot['service_id'],$snapshot['service_key'],$snapshot['name'],$snapshot['category'],$snapshot['television_name'],$snapshot['screen_size'],$snapshot['unit_price'],$snapshot['quantity'],$snapshot['total'],$snapshot['metadata']]);
$pdo->exec("UPDATE service_catalog SET price=99999,name='Changed later' WHERE id={$mount55}");
$stored=$pdo->query("SELECT service_name,unit_price,total,metadata FROM order_services WHERE order_id={$orderId}")->fetch();
ok('historical snapshot is immutable',$stored['service_name']===$snapshot['name']&&(float)$stored['unit_price']===10000.0&&(float)$stored['total']===10000.0);
ok('snapshot keeps tariff boundaries',json_decode($stored['metadata'],true)['min_screen_size']===55);
ok('legacy order without services remains valid',(int)$pdo->query("SELECT COUNT(*) FROM orders LEFT JOIN order_services ON order_services.order_id=orders.id WHERE orders.id={$orderId} OR order_services.id IS NULL")->fetchColumn()>=1);
echo "SERVICE MYSQL ACCEPTANCE PASSED\n";

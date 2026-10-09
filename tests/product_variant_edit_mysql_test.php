<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/product_variant_mutation_service.php';
require_once dirname(__DIR__).'/admin_variant_list_service.php';
require_once dirname(__DIR__).'/storefront_product_service.php';
$host=getenv('TELVORA_TEST_DB_HOST')?:'127.0.0.1';
$name=getenv('TELVORA_TEST_DB_NAME')?:'telvora_variant_edit_test';
if (!in_array($host,['127.0.0.1','localhost'],true)||$name!=='telvora_variant_edit_test') throw new RuntimeException('Refusing non-disposable variant database');
$dsn='mysql:host='.$host.';port='.(int)(getenv('TELVORA_TEST_DB_PORT')?:3307).';charset=utf8mb4';
$server=new PDO($dsn,getenv('TELVORA_TEST_DB_USER')?:'root',getenv('TELVORA_TEST_DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$server->exec('CREATE DATABASE IF NOT EXISTS telvora_variant_edit_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo=new PDO($dsn.';dbname='.$name,getenv('TELVORA_TEST_DB_USER')?:'root',getenv('TELVORA_TEST_DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) $pdo->exec('DROP TABLE `'.str_replace('`','``',$table).'`');
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE products (id INT UNSIGNED PRIMARY KEY, name VARCHAR(100), is_active TINYINT NOT NULL DEFAULT 0, publication_status VARCHAR(32) DEFAULT 'draft', price DECIMAL(12,2) DEFAULT 0,old_price DECIMAL(12,2) NULL, variants JSON NOT NULL) ENGINE=InnoDB");
$pdo->exec(file_get_contents(dirname(__DIR__).'/database/migrations/20260831_001_supplier_variant_infrastructure.sql'));
$pdo->exec(file_get_contents(dirname(__DIR__).'/database/migrations/20260908_008_product_variant_price_overrides.sql'));
$pdo->exec('CREATE TABLE order_items (id INT PRIMARY KEY,product_variant_id BIGINT UNSIGNED, variant_snapshot VARCHAR(100), price_at_order DECIMAL(12,2)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE product_price_publication_audit (id INT PRIMARY KEY,product_variant_id BIGINT UNSIGNED NOT NULL, history_text TEXT, FOREIGN KEY(product_variant_id) REFERENCES product_variants(id)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE additional_variant_history (id INT PRIMARY KEY,variant_ref BIGINT UNSIGNED NOT NULL, FOREIGN KEY(variant_ref) REFERENCES product_variants(id)) ENGINE=InnoDB');
$pdo->exec("INSERT INTO products(id,name,variants) VALUES (1,'Sony fixture','[]'),(2,'Unrelated fixture','[]')");
function editAssert(bool $ok,string $label):void { if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label,"\n"; }
function editProduct(PDO $pdo,int $id=1):array {$q=$pdo->prepare('SELECT * FROM products WHERE id=?');$q->execute([$id]);return $q->fetch();}
function editPublic(PDO $pdo):array {return attachStorefrontVariants($pdo,[editProduct($pdo)])[0]['storefront_variants'];}
function editHistory(PDO $pdo):string {
 $data=[];foreach(['supplier_product_matches','supplier_import_rows','supplier_offers','order_items','product_price_publication_audit','product_variant_price_overrides','additional_variant_history'] as $t)$data[$t]=$pdo->query('SELECT * FROM '.$t.' ORDER BY 1')->fetchAll();
 return json_encode($data,JSON_THROW_ON_ERROR);
}
function editReject(callable $operation,int $code,string $label):void {try{$operation();throw new RuntimeException('Expected rejection');}catch(ProductVariantMutationException $e){editAssert($e->httpStatus===$code,$label);}}
$one=productVariantAdd($pdo,1,'Уточняется','K-65XR95M2');$id=$one['product_variant_id'];
$two=productVariantAdd($pdo,1,'Китай');$other=$two['product_variant_id'];
editAssert(count(adminVariantListFetch($pdo,1)['variants'])===2,'create then reopen variants');
productVariantPriceSet($pdo,$id,true,'80000','90000');
productVariantPriceSet($pdo,$other,true,'100000');
$pdo->exec("INSERT INTO suppliers(name,internal_code) VALUES ('Fixture supplier','fixture')");
$pdo->exec("INSERT INTO supplier_import_jobs(supplier_id,original_filename) VALUES (1,'fixture.csv')");
$q=$pdo->prepare("INSERT INTO supplier_product_matches(supplier_id,supplier_sku,product_id,product_variant_id,match_method,status) VALUES (1,'sony-sku',1,?,'manual','confirmed')");$q->execute([$id]);
$q=$pdo->prepare("INSERT INTO supplier_import_rows(import_job_id,source_row_number,status,matched_product_id,matched_product_variant_id,match_id) VALUES (1,1,'matched',1,?,1)");$q->execute([$id]);
$q=$pdo->prepare("INSERT INTO supplier_offers(supplier_id,product_variant_id,supplier_product_name,purchase_price,currency_code,availability_status,stock_quantity,source_import_row_id,source_updated_at) VALUES (1,?,'Sony source title',70000,'RUB','in_stock',5,1,NOW())");$q->execute([$id]);
$q=$pdo->prepare("INSERT INTO order_items VALUES (1,?,'Уточняется',75000)");$q->execute([$id]);
$q=$pdo->prepare("INSERT INTO product_price_publication_audit VALUES (1,?,'Historical price publication')");$q->execute([$id]);
$q=$pdo->prepare("INSERT INTO additional_variant_history VALUES (1,?)");$q->execute([$id]);
$history=editHistory($pdo);
$result=productVariantRename($pdo,1,$id,'Япония','Уточняется');
editAssert($result['product_variant_id']===$id && editHistory($pdo)===$history,'rename keeps variant ID, supplier mappings, offer stock/prices and order history');
$list=adminVariantListFetch($pdo,1);
editAssert($list['variants'][0]['assembly_country']==='Япония'&&$list['variants'][0]['identity_ready'],'reopen reads renamed exact identity');
$public=editPublic($pdo);
editAssert($public[0]['country']==='Япония'&&$public[0]['product_variant_id']===$id&&$public[0]['price']===80000&&$public[0]['availability']['orderable'],'catalog/detail serializer exposes renamed label, same price and supplier availability');
$before=editProduct($pdo);
editReject(static fn()=>productVariantRename($pdo,1,$id,'Китай','Япония'),409,'duplicate name rejected');
editReject(static fn()=>productVariantRename($pdo,1,$other,'япония','Китай'),409,'collation-equivalent duplicate rejected');
editReject(static fn()=>productVariantRename($pdo,1,$id,'Европа','Уточняется'),409,'stale rename rejected');
editReject(static fn()=>productVariantRename($pdo,2,$id,'Европа','Япония'),404,'cross-product rename rejected');
editReject(static fn()=>productVariantRename($pdo,1,$id,'','Япония'),400,'empty name rejected');
editAssert(editProduct($pdo)===$before&&editHistory($pdo)===$history,'rejected renames leave state unchanged');
$variantBefore=$pdo->query('SELECT * FROM product_variants WHERE id='.(int)$id)->fetch();
$pdo->exec("CREATE TRIGGER reject_variant_json BEFORE UPDATE ON products FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture JSON write failure'");
try { productVariantRename($pdo,1,$id,'Европа','Япония'); throw new RuntimeException('Expected JSON failure'); }
catch (PDOException $e) { editAssert($e->getCode()==='45000','JSON write failure propagated'); }
finally { $pdo->exec('DROP TRIGGER reject_variant_json'); }
editAssert($pdo->query('SELECT * FROM product_variants WHERE id='.(int)$id)->fetch()===$variantBefore&&editProduct($pdo)===$before&&editHistory($pdo)===$history,'rename rolls back relational name/key when legacy write fails');
$pdo->exec('UPDATE products SET is_active=1 WHERE id=1');
$result=productVariantSetActive($pdo,$id,false,1,true);
editAssert($result['removal_mode']==='disabled'&&$result['references']['supplier_product_matches.product_variant_id']===1&&$result['references']['order_items.product_variant_id']===1&&$result['references']['additional_variant_history.variant_ref']===1,'archive checks mappings, non-FK order references and additional foreign keys');
editAssert((int)$result['references']['supplier_offer_summary'][0]['stock_quantity']===5&&(float)$result['references']['supplier_offer_summary'][0]['min_purchase_price']===70000.0,'archive inspects supplier stock and purchase prices by currency');
editAssert(editHistory($pdo)===$history,'archive preserves every supplier, stock, price and history row');
$public=editPublic($pdo);
editAssert(count($public)===1&&$public[0]['product_variant_id']===$other&&$public[0]['price']===100000,'catalog/detail recompute selection and displayed price from remaining variant');
$list=adminVariantListFetch($pdo,1);
editAssert(!$list['variants'][0]['relational_is_active']&&!$list['variants'][0]['legacy_is_active'],'reopen shows synchronized disabled variant');
$repeat=productVariantSetActive($pdo,$id,false,1,true);
editAssert($repeat['is_active']===false&&editHistory($pdo)===$history,'repeated archive is safe');
editReject(static fn()=>productVariantSetActive($pdo,$other,false,1,true),409,'last ready variant of active product protected');
editAssert(count(editPublic($pdo))===1,'failed last archive keeps storefront ready variant');
editReject(static fn()=>productVariantSetActive($pdo,$other,false,2,true),404,'cross-product archive rejected');
$pdo->exec("UPDATE products SET is_active=0,publication_status='draft' WHERE id=1");
productVariantSetActive($pdo,$other,false,1,true);
editAssert(editPublic($pdo)===[],'last unlinked variant may be archived on inactive product; storefront has no eligible variants');
editAssert(count(adminVariantListFetch($pdo,1)['variants'])===2&&editHistory($pdo)===$history,'both archived IDs remain accessible and no orphaned histories');
productVariantSetActive($pdo,$other,true);
editAssert(count(editPublic($pdo))===1,'existing enable action restores archived variant');
$draft=productVariantAdd($pdo,2,'Не определено');
productVariantRename($pdo,2,$draft['product_variant_id'],'Европа','Не определено');
productVariantSetActive($pdo,$draft['product_variant_id'],false,2,true);
editAssert(!adminVariantListFetch($pdo,2)['variants'][0]['relational_is_active'],'create rename and archive zero-price draft');
echo "PASS variant edit MySQL suite (local disposable schema only)\n";

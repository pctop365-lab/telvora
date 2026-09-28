<?php
declare(strict_types=1);
// Fresh synthetic database on loopback ONLY; never loads application production secrets.
require_once dirname(__DIR__).'/customer_service.php';
function checkCustomer(string $name, bool $ok): void { if(!$ok)throw new RuntimeException('FAIL '.$name); echo 'PASS '.$name."\n"; }
function customerHttp(string $path, ?array $body=null, string &$cookie='', ?string $csrf=null): array {
    $headers="Content-Type: application/json\r\n";
    if($cookie!=='')$headers.='Cookie: '.$cookie."\r\n";
    if($csrf!==null)$headers.='X-CSRF-Token: '.$csrf."\r\n";
    $context=stream_context_create(['http'=>['method'=>$body===null?'GET':'POST','header'=>$headers,'content'=>$body===null?'':json_encode($body),'ignore_errors'=>true,'timeout'=>10]]);
    $response=@file_get_contents('http://127.0.0.1:4186'.$path,false,$context);
    $status=0; $responseHeaders=$http_response_header ?? [];
    foreach($responseHeaders as $header){
        if(preg_match('/^HTTP\/\S+ (\d+)/',$header,$match))$status=(int)$match[1];
        if(preg_match('/^Set-Cookie: ((?:TELVORA_CUSTOMER|PHPSESSID)=[^;]+)/i',$header,$match))$cookie=$match[1];
    }
    return [$status,json_decode((string)$response,true) ?? [],$responseHeaders];
}
function customerPost(string $action,array $data,string &$cookie,string $csrf):array{return customerHttp('/customer.php',['action'=>$action]+$data,$cookie,$csrf);}

$rootPassword=trim((string)file_get_contents('C:/Users/ASRock/Telvora-MySQL-Test/private/root-password.txt'));
$root=new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4','root',$rootPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$suffix=bin2hex(random_bytes(5));$db='telvora_customer_test_'.$suffix;$user='tc_'.$suffix;$password=bin2hex(random_bytes(24));$adminPassword=bin2hex(random_bytes(24));
$runtime=sys_get_temp_dir().'/telvora-customer-'.$suffix;
mkdir($runtime,0700,true);$process=null;$pipes=[];
try {
    $root->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $root->exec("CREATE USER '$user'@'127.0.0.1' IDENTIFIED BY '$password'");$root->exec("GRANT ALL ON `$db`.* TO '$user'@'127.0.0.1'");
    $pdo=new PDO("mysql:host=127.0.0.1;port=3307;dbname=$db;charset=utf8mb4",$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE orders(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_number VARCHAR(64),customer_name VARCHAR(200),phone VARCHAR(32),email VARCHAR(254),address VARCHAR(1000),delivery_time VARCHAR(100),delivery_method VARCHAR(30),payment_method VARCHAR(30),comment TEXT,subtotal DECIMAL(12,2),delivery_price DECIMAL(12,2) NULL,delivery_quote_status VARCHAR(30),delivery_details JSON,total DECIMAL(12,2),status VARCHAR(100),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE order_items(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id INT UNSIGNED NOT NULL,product_id INT UNSIGNED,product_variant_id BIGINT UNSIGNED,supplier_offer_id_at_order BIGINT UNSIGNED,availability_status_at_order VARCHAR(30),expected_arrival_at_order DATETIME,product_name VARCHAR(255),quantity INT,price DECIMAL(12,2)) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO orders(customer_name,phone,email,address) VALUES('Synthetic legacy','+79990000001','fixture@example.invalid','Original legacy address')");
    $pdo->exec(file_get_contents(dirname(__DIR__).'/database/migrations/20260909_011_service_catalog.sql'));
    $pdo->exec(file_get_contents(dirname(__DIR__).'/database/migrations/20260927_014_customer_accounts.sql'));
    checkCustomer('migration preserves legacy guest', $pdo->query('SELECT customer_id FROM orders WHERE id=1')->fetchColumn()===null);
    $pdo->exec("CREATE TABLE products(id INT UNSIGNED PRIMARY KEY,slug VARCHAR(255),name VARCHAR(255),screen_size VARCHAR(50),variants JSON,is_active TINYINT DEFAULT 1) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE product_variants(id BIGINT UNSIGNED PRIMARY KEY,product_id INT UNSIGNED,variant_key VARCHAR(191),assembly_country VARCHAR(100),manufacturer_part_number VARCHAR(191) NULL,display_name VARCHAR(255),is_active TINYINT DEFAULT 1) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE product_variant_price_overrides(product_variant_id BIGINT UNSIGNED PRIMARY KEY,manual_price DECIMAL(12,2),manual_old_price DECIMAL(12,2),is_active TINYINT,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE suppliers(id BIGINT UNSIGNED PRIMARY KEY,is_active TINYINT) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE supplier_offers(id BIGINT UNSIGNED PRIMARY KEY,product_variant_id BIGINT UNSIGNED,supplier_id BIGINT UNSIGNED,availability_status VARCHAR(30),stock_quantity INT,expected_arrival_at DATETIME,source_updated_at DATETIME,imported_at DATETIME,is_active TINYINT) ENGINE=InnoDB");
    $variants=json_encode([['country'=>'Россия','price'=>100000,'old_price'=>null,'is_active'=>true]],JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT INTO products VALUES(1,'synthetic-tv','Synthetic TV','55',?,1)")->execute([$variants]);
    $pdo->prepare("INSERT INTO product_variants VALUES(1,1,?,'Россия',NULL,'Россия',1)")->execute(['legacy-country-sha256-'.hash('sha256','Россия')]);
    $pdo->exec("INSERT INTO suppliers VALUES(1,1); INSERT INTO supplier_offers VALUES(1,1,1,'in_stock',100,NULL,NOW(),NOW(),1)");
    $secretsFile=$runtime.'/stage12le-test-secrets.php';
    file_put_contents($runtime.'/api_order_rate_limit.json','{}');
    file_put_contents($secretsFile,'<?php return '.var_export(['db_host'=>'127.0.0.1;port=3307','db_name'=>$db,'db_user'=>$user,'db_password'=>$password,'admin_password'=>$adminPassword,'telegram_bot_token'=>'isolated-no-network','telegram_chat_id'=>'isolated-no-network'],true).';');
    $environment=array_merge(getenv(),['TELVORA_HTTP_TEST_MODE'=>'stage12le-isolated','TELVORA_HTTP_TEST_SECRETS_FILE'=>$secretsFile,'TELVORA_HTTP_TEST_RUNTIME_DIR'=>$runtime]);
    $ini=getenv('TELVORA_TEST_PHP_INI') ?: 'C:/Users/ASRock/Telvora-MySQL-Test/php/php.ini';
    $process=proc_open([PHP_BINARY,'-c',$ini,'-S','127.0.0.1:4186','-t',dirname(__DIR__)],[0=>['pipe','r'],1=>['file',$runtime.'/server.log','a'],2=>['file',$runtime.'/server.log','a']],$pipes,dirname(__DIR__),$environment);
    if(!is_resource($process))throw new RuntimeException('Cannot start isolated server');fclose($pipes[0]);
    $a='';$deadline=microtime(true)+5;
    do {usleep(50000);[$status,$session,$headers]=customerHttp('/customer.php',null,$a);}while($status===0 && microtime(true)<$deadline);
    checkCustomer('anonymous session and mail explicitly disabled',$status===200 && $session['customer']===null && $session['recovery_available']===false);
    checkCustomer('separate HttpOnly SameSite customer cookie',str_starts_with($a,'TELVORA_CUSTOMER=') && str_contains(strtolower(implode(' ',$headers)),'httponly') && str_contains(strtolower(implode(' ',$headers)),'samesite=lax'));
    $csrf=$session['csrf_token'];$anonymousCookie=$a;
    checkCustomer('registration rejects missing CSRF',customerHttp('/customer.php',['action'=>'register','login'=>'FixtureOne','password'=>'fixture-secret-one','password_repeat'=>'fixture-secret-one'],$a)[0]===403);
    checkCustomer('password repeat mismatch',customerPost('register',['login'=>'FixtureOne','password'=>'fixture-secret-one','password_repeat'=>'wrong'],$a,$csrf)[0]===422);
    [$status,$result]=customerPost('register',['login'=>'FixtureOne','password'=>'fixture-secret-one','password_repeat'=>'fixture-secret-one'],$a,$csrf);
    checkCustomer('registration and session rotation',$status===200 && $a!==$anonymousCookie);$id=(int)$result['customer']['id'];$csrf=$result['csrf_token'];
    $hash=$pdo->query("SELECT password_hash FROM customers WHERE id=$id")->fetchColumn();checkCustomer('native password hash only',password_verify('fixture-secret-one',$hash) && $hash!=='fixture-secret-one');
    checkCustomer('case-insensitive duplicate login',customerPost('register',['login'=>'fixtureone','password'=>'fixture-secret-one','password_repeat'=>'fixture-secret-one'],$a,$csrf)[0]===409);
    checkCustomer('guest profile denied',customerPost('profile',['full_name'=>'x'],$anonymousCookie,$session['csrf_token'])[0]===403);
    $b='';[, $bSession]=customerHttp('/customer.php',null,$b);$bCsrf=$bSession['csrf_token'];
    checkCustomer('anonymous history denied',customerHttp('/customer.php?action=orders',null,$b)[0]===401);
    checkCustomer('anonymous profile denied',customerPost('profile',['full_name'=>'x'],$b,$bCsrf)[0]===401);
    [, $other]=customerPost('register',['login'=>'8 (999) 000-00-02','password'=>'fixture-secret-two','password_repeat'=>'fixture-secret-two'],$b,$bCsrf);$bCsrf=$other['csrf_token'];$otherId=(int)$other['customer']['id'];
    checkCustomer('normalized phone duplicate',customerPost('register',['login'=>'+79990000002','password'=>'fixture-secret-two','password_repeat'=>'fixture-secret-two'],$b,$bCsrf)[0]===409);
    $profile=['full_name'=>'Synthetic recipient','phone'=>'+79990000001','email'=>'fixture@example.invalid','address'=>'Profile address'];
    [$status,$result]=customerPost('profile',$profile+['customer_id'=>$otherId],$a,$csrf);
    checkCustomer('profile saved only for session owner',$status===200 && $pdo->query("SELECT full_name FROM customers WHERE id=$otherId")->fetchColumn()==='');
    $order=['customer_name'=>'Order recipient','phone'=>'+79990000001','email'=>'','address'=>'Order address','delivery_method'=>'pickup','payment_method'=>'cash','items'=>[['product_id'=>1,'product_variant_id'=>1,'quantity'=>1]],'customer_id'=>$otherId];
    $guest='';[$status,$guestOrder]=customerHttp('/api.php',$order,$guest);
    if ($status!==200) echo 'Checkout diagnostic: HTTP '.$status.' '.($guestOrder['message'] ?? 'no JSON')."\n";
    checkCustomer('guest checkout without email',$status===200 && ($guestOrder['success'] ?? false));
    $guestId=(int)$guestOrder['order_id'];checkCustomer('browser customer_id ignored for guest',$pdo->query("SELECT customer_id FROM orders WHERE id=$guestId")->fetchColumn()===null);
    checkCustomer('authenticated checkout requires CSRF',customerHttp('/api.php',$order,$a)[0]===403);
    [$status,$first]=customerHttp('/api.php',$order,$a,$csrf);checkCustomer('authenticated checkout',$status===200 && ($first['success'] ?? false));$firstId=(int)$first['order_id'];
    $firstOrderNumber=(string)$first['order_number'];
    [$status,$tracked]=customerHttp('/manager.php?action=track_order',['order_number'=>$firstOrderNumber,'phone'=>'+79990000001'],$guest);
    checkCustomer('public tracking accepts generated order number and matching phone',$status===200 && ($tracked['success'] ?? false) && (int)$tracked['order']['id']===$firstId);
    [$status,$invalidTracking]=customerHttp('/manager.php?action=track_order',['order_number'=>'8','phone'=>'+79990000001'],$guest);
    checkCustomer('public tracking rejects legacy numeric order format',$status===400 && !($invalidTracking['success'] ?? true));
    [$status,$missingTracking]=customerHttp('/manager.php?action=track_order',['order_number'=>'TLV-20990101-9999','phone'=>'+79990000001'],$guest);
    checkCustomer('public tracking hides missing or mismatched order',$status===404 && !($missingTracking['success'] ?? true));
    checkCustomer('order linked by session not supplied id',(int)$pdo->query("SELECT customer_id FROM orders WHERE id=$firstId")->fetchColumn()===$id);
    checkCustomer('unchecked checkout leaves profile intact',$pdo->query("SELECT address FROM customers WHERE id=$id")->fetchColumn()==='Profile address');
    [$status,$second]=customerHttp('/api.php',$order+['save_profile'=>true],$a,$csrf);checkCustomer('checked checkout saves profile',$status===200 && $pdo->query("SELECT address FROM customers WHERE id=$id")->fetchColumn()==='Order address');
    customerPost('profile',array_merge($profile,['address'=>'Changed later']),$a,$csrf);
    checkCustomer('old order snapshot unchanged',$pdo->query("SELECT address FROM orders WHERE id=$firstId")->fetchColumn()==='Order address');
    $beforeCount=(int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $root->exec("CREATE TRIGGER `$db`.fixture_fail_item BEFORE INSERT ON `$db`.order_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic failure'");
    [$status]=customerHttp('/api.php',array_merge($order,['save_profile'=>true,'address'=>'Must roll back']),$a,$csrf);
    $pdo->exec('DROP TRIGGER fixture_fail_item');
    checkCustomer('checkout failure rolls back order and profile',$status===500 && (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn()===$beforeCount && $pdo->query("SELECT address FROM customers WHERE id=$id")->fetchColumn()==='Changed later');
    [, $history]=customerHttp('/customer.php?action=orders&customer_id='.$otherId,null,$a);
    checkCustomer('own history includes exactly linked orders',count($history['orders'])===2);
    [, $otherHistory]=customerHttp('/customer.php?action=orders&customer_id='.$id.'&order_id='.$firstId,null,$b);
    checkCustomer('foreign order and customer ids cannot expose orders',$otherHistory['orders']===[]);
    checkCustomer('guest and legacy orders not linked by matching contacts',(int)$pdo->query('SELECT COUNT(*) FROM orders WHERE customer_id IS NULL')->fetchColumn()===2);
    $customerAdminCookie=$a;
    checkCustomer('customer denied admin list',customerHttp('/manager.php?action=customers_list',null,$customerAdminCookie)[0]===401);
    checkCustomer('customer denied admin card',customerHttp('/manager.php?action=customer_detail&id='.$id,null,$customerAdminCookie)[0]===401);
    $admin='';[, $adminLogin]=customerHttp('/manager.php',['action'=>'login','password'=>$adminPassword],$admin);
    checkCustomer('existing admin login works',($adminLogin['success'] ?? false) && str_starts_with($admin,'PHPSESSID='));
    [, $list]=customerHttp('/manager.php?action=customers_list&q=Fixture',null,$admin);checkCustomer('admin search',count($list['customers'])===1);
    [, $detail]=customerHttp('/manager.php?action=customer_detail&id='.$id,null,$admin);checkCustomer('admin customer card and linked orders',count($detail['orders'])===2 && (int)$detail['customer']['id']===$id);
    $insertPage=$pdo->prepare('INSERT INTO customers(login,login_normalized,password_hash) VALUES(?,?,?)');
    for($i=0;$i<21;$i++)$insertPage->execute(['PageFixture'.$i,'pagefixture'.$i,$hash]);
    [, $pageOne]=customerHttp('/manager.php?action=customers_list&q=PageFixture&page=1',null,$admin);
    [, $pageTwo]=customerHttp('/manager.php?action=customers_list&q=PageFixture&page=2',null,$admin);
    checkCustomer('admin pagination is bounded and disjoint',count($pageOne['customers'])===20 && count($pageTwo['customers'])===1 && array_intersect(array_column($pageOne['customers'],'id'),array_column($pageTwo['customers'],'id'))===[]);
    try { $insertPage->execute(['Duplicate','fixtureone',$hash]); checkCustomer('database unique key',false); } catch(PDOException $error) { checkCustomer('database unique key',($error->errorInfo[1] ?? 0)===1062); }
    [, $adminCustomer]=customerHttp('/customer.php',null,$admin);checkCustomer('admin cookie does not authenticate customer',$adminCustomer['customer']===null);
    checkCustomer('unconfigured recovery does not send',customerPost('reset_request',['login'=>'FixtureOne'],$a,$csrf)[0]===503);
    // Token lifecycle tested with locally inserted synthetic hashes; no email is sent.
    $token=bin2hex(random_bytes(32));
    $insert=$pdo->prepare("INSERT INTO customer_tokens VALUES(?,?,'verify',?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))");$insert->execute([hash('sha256',$token),$id,$profile['email']]);
    checkCustomer('verification token consumed',customerPost('verify_token',['token'=>$token],$a,$csrf)[0]===200);
    checkCustomer('verification token single use',customerPost('verify_token',['token'=>$token],$a,$csrf)[0]===422);
    $expired=bin2hex(random_bytes(32));$insert->execute([hash('sha256',$expired),$id,$profile['email']]);$pdo->exec("UPDATE customer_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE)");
    checkCustomer('expired verification rejected',customerPost('verify_token',['token'=>$expired],$a,$csrf)[0]===422);
    $reset=bin2hex(random_bytes(32));$pdo->prepare("INSERT INTO customer_tokens VALUES(?,?,'reset',?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))")->execute([hash('sha256',$reset),$id,$profile['email']]);
    checkCustomer('password reset',customerPost('reset_token',['token'=>$reset,'password'=>'fixture-new-secret','password_repeat'=>'fixture-new-secret'],$a,$csrf)[0]===200);
    checkCustomer('reset revokes existing sessions',customerHttp('/customer.php?action=orders',null,$a)[0]===401);
    [, $fresh]=customerHttp('/customer.php',null,$a);$csrf=$fresh['csrf_token'];
    checkCustomer('reset token single use',customerPost('reset_token',['token'=>$reset,'password'=>'fixture-new-secret','password_repeat'=>'fixture-new-secret'],$a,$csrf)[0]===422);
    checkCustomer('wrong password rejected',customerPost('login',['login'=>'FIXTUREONE','password'=>'incorrect-password'],$a,$csrf)[0]===401);
    [$status,$logged,$loginHeaders]=customerPost('login',['login'=>'FIXTUREONE','password'=>'fixture-new-secret','remember_me'=>true],$a,$csrf);checkCustomer('login with normalized nickname and new password',$status===200);$csrf=$logged['csrf_token'];
    checkCustomer('remembered login sets persistent HttpOnly cookie',count(array_filter($loginHeaders,fn($header)=>str_starts_with(strtolower($header),'set-cookie: telvora_customer=') && str_contains(strtolower($header),'expires=') && str_contains(strtolower($header),'httponly'))) > 0);
    [, $rememberedSession]=customerHttp('/customer.php',null,$a);checkCustomer('remembered session survives another request',(int)$rememberedSession['customer']['id']===$id);
    customerPost('profile',array_merge($profile,['email'=>'changed@example.invalid']),$a,$csrf);
    checkCustomer('email change clears verification',$pdo->query("SELECT email_verified_at FROM customers WHERE id=$id")->fetchColumn()===null);
    checkCustomer('email change deletes prior links',(int)$pdo->query("SELECT COUNT(*) FROM customer_tokens WHERE customer_id=$id")->fetchColumn()===0);
    $oldCookie=$a;customerPost('logout',[],$a,$csrf);checkCustomer('logout revokes prior session',customerHttp('/customer.php?action=orders',null,$oldCookie)[0]===401);
    [, $fresh]=customerHttp('/customer.php',null,$a);$csrf=$fresh['csrf_token'];
    for($i=0;$i<11;$i++)[$status]=customerPost('login',['login'=>'NoSuchFixture','password'=>'incorrect-password'],$a,$csrf);
    checkCustomer('login rate limit enforced',$status===429);
    checkCustomer('normalization phone and nickname',customerLogin(' TeSt.Name ')==='test.name' && customerLogin('8 (999) 000-00-01')===customerLogin('+7 999 000 00 01'));
    echo "CUSTOMER HTTP ACCEPTANCE PASSED\n";
} finally {
    if(is_resource($process)){proc_terminate($process);proc_close($process);}
    // Only this run's generated identifier is ever removed; other schemas are untouched.
    if(preg_match('/^telvora_customer_test_[a-f0-9]{10}$/D',$db))$root->exec("DROP DATABASE IF EXISTS `$db`");
    $root->exec("DROP USER IF EXISTS '$user'@'127.0.0.1'");
    foreach(glob($runtime.'/*') ?: [] as $file)unlink($file);rmdir($runtime);
}

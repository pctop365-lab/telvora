<?php

declare(strict_types=1);

const HTTP_TEST_DSN = 'mysql:host=127.0.0.1;port=3307;dbname=telvora_stage12lc_test;charset=utf8mb4';
const HTTP_TEST_USER = 'telvora_stage12lc';
const HTTP_TEST_PASSWORD_FILE = 'C:/Users/ASRock/Telvora-MySQL-Test/private/test-password.txt';
const HTTP_TEST_PHP = 'C:/Users/ASRock/Telvora-MySQL-Test/php/php.exe';
const HTTP_TEST_INI = 'C:/Users/ASRock/Telvora-MySQL-Test/php/php.ini';
const HTTP_TEST_URL = 'http://127.0.0.1:4181';

function httpTestAssert(string $name, bool $condition): void
{
    if (!$condition) throw new RuntimeException("FAIL $name");
    echo "PASS $name\n";
}

function httpTestRequest(string $path, array $payload, ?string $cookie = null, ?string $csrf = null): array
{
    $headers = ["Content-Type: application/json", "Accept: application/json"];
    if ($cookie !== null) $headers[] = "Cookie: $cookie";
    if ($csrf !== null) $headers[] = "X-CSRF-Token: $csrf";
    $context = stream_context_create(['http'=>[
        'method'=>'POST', 'header'=>implode("\r\n", $headers),
        'content'=>json_encode($payload, JSON_THROW_ON_ERROR), 'ignore_errors'=>true, 'timeout'=>5,
    ]]);
    $body = file_get_contents(HTTP_TEST_URL . $path, false, $context);
    $responseHeaders = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $match);
    return [(int)($match[1] ?? 0), json_decode((string)$body, true), $responseHeaders];
}

$password = trim((string)file_get_contents(HTTP_TEST_PASSWORD_FILE));
$pdo = new PDO(HTTP_TEST_DSN, HTTP_TEST_USER, $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$identity = $pdo->query("SELECT @@version version, @@port port, DATABASE() db, CURRENT_USER() user, @@datadir datadir")->fetch();
httpTestAssert('isolated host/port/database/user', (int)$identity['port'] === 3307 && $identity['db'] === 'telvora_stage12lc_test' && str_starts_with((string)$identity['user'], HTTP_TEST_USER . '@'));

$runtime = sys_get_temp_dir() . '/telvora-stage12le-http-' . bin2hex(random_bytes(6));
if (!mkdir($runtime, 0700, true)) throw new RuntimeException('Unable to create test runtime');
$secretsFile = $runtime . '/stage12le-test-secrets.php';
$adminPassword = bin2hex(random_bytes(18));
file_put_contents($secretsFile, '<?php return ' . var_export(['admin_password'=>$adminPassword,'db_host'=>'127.0.0.1;port=3307','db_name'=>'telvora_stage12lc_test','db_user'=>HTTP_TEST_USER,'db_password'=>$password], true) . ';');

$process = null; $pipes = []; $renamed = false;
try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec('DROP TABLE IF EXISTS product_variant_price_overrides');
    $pdo->exec('DROP TABLE IF EXISTS product_variants');
    $pdo->exec('DROP TABLE IF EXISTS products');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("CREATE TABLE products (id BIGINT UNSIGNED PRIMARY KEY, name VARCHAR(255) NOT NULL, variants JSON NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE product_variants (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_id BIGINT UNSIGNED NOT NULL, variant_key VARCHAR(255) NOT NULL, assembly_country VARCHAR(120) COLLATE utf8mb4_unicode_ci NOT NULL, display_name VARCHAR(255), classification_status VARCHAR(40), classification_evidence TEXT, is_active TINYINT(1) NOT NULL DEFAULT 1, UNIQUE KEY uq_product_variant_key(product_id,variant_key), UNIQUE KEY uq_product_country(product_id,assembly_country), CONSTRAINT fk_http_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE product_variant_price_overrides (product_variant_id BIGINT UNSIGNED PRIMARY KEY, manual_price DECIMAL(12,2) NOT NULL, manual_old_price DECIMAL(12,2) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, CONSTRAINT fk_http_price_variant FOREIGN KEY(product_variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT) ENGINE=InnoDB");
    $legacy = json_encode([['country'=>'Россия','price'=>271400,'old_price'=>null,'is_active'=>true]], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $pdo->prepare('INSERT INTO products(id,name,variants,is_active) VALUES(5,?,?,1)')->execute(['Synthetic HTTP', $legacy]);
    $pdo->prepare('INSERT INTO product_variants(product_id,variant_key,assembly_country,display_name,is_active) VALUES(5,?,?,?,1)')->execute(['legacy-country-sha256-'.hash('sha256','Россия'),'Россия','Россия']);

    $environment = array_merge($_ENV, ['TELVORA_HTTP_TEST_MODE'=>'stage12le-isolated','TELVORA_HTTP_TEST_SECRETS_FILE'=>$secretsFile,'TELVORA_HTTP_TEST_RUNTIME_DIR'=>$runtime]);
    $process = proc_open([HTTP_TEST_PHP,'-c',HTTP_TEST_INI,'-S','127.0.0.1:4181','-t',dirname(__DIR__)], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__), $environment);
    if (!is_resource($process)) throw new RuntimeException('Unable to start HTTP server');
    fclose($pipes[0]);
    $deadline = microtime(true)+5;
    do { usleep(50000); [$status] = httpTestRequest('/products.php',['action'=>'variant_add','product_id'=>5,'assembly_country'=>'Китай']); } while ($status===0 && microtime(true)<$deadline);
    httpTestAssert('no admin session is 401', $status===401);

    [$status,$login,$headers] = httpTestRequest('/manager.php',['action'=>'login','password'=>$adminPassword]);
    httpTestAssert('authenticated login', $status===200 && ($login['success']??false)===true && is_string($login['csrf_token']??null));
    $setCookie = implode("\n", array_filter($headers, static fn($header)=>stripos($header,'Set-Cookie:')===0));
    preg_match('/PHPSESSID=([^;]+)/', $setCookie, $cookieMatch);
    $cookie = 'PHPSESSID=' . ($cookieMatch[1] ?? ''); $csrf = $login['csrf_token'];
    httpTestAssert('secure SameSite cookie attributes', str_contains(strtolower($setCookie),'secure') && str_contains(strtolower($setCookie),'httponly') && str_contains(strtolower($setCookie),'samesite=none') && ($cookieMatch[1]??'')!=='');
    httpTestAssert('missing csrf is 403', httpTestRequest('/products.php',['action'=>'variant_add','product_id'=>5,'assembly_country'=>'Китай'],$cookie)[0]===403);
    httpTestAssert('wrong csrf is 403', httpTestRequest('/products.php',['action'=>'variant_add','product_id'=>5,'assembly_country'=>'Китай'],$cookie,'wrong')[0]===403);
    httpTestAssert('invalid add payload is 400', httpTestRequest('/products.php',['action'=>'variant_add','product_id'=>5,'assembly_country'=>''],$cookie,$csrf)[0]===400);
    httpTestAssert('variant add succeeds', httpTestRequest('/products.php',['action'=>'variant_add','product_id'=>5,'assembly_country'=>'Китай'],$cookie,$csrf)[0]===200);
    httpTestAssert('variant add persisted canonical draft', (int)$pdo->query("SELECT COUNT(*) FROM product_variants WHERE product_id=5 AND assembly_country='Китай' AND is_active=1")->fetchColumn()===1 && str_contains((string)$pdo->query('SELECT variants FROM products WHERE id=5')->fetchColumn(),'Китай'));
    httpTestAssert('duplicate add is 409', httpTestRequest('/products.php',['action'=>'variant_add','product_id'=>5,'assembly_country'=>'Китай'],$cookie,$csrf)[0]===409);
    httpTestAssert('missing product add is 404', httpTestRequest('/products.php',['action'=>'variant_add','product_id'=>999,'assembly_country'=>'Польша'],$cookie,$csrf)[0]===404);
    $chinaId=(int)$pdo->query("SELECT id FROM product_variants WHERE assembly_country='Китай'")->fetchColumn();
    httpTestAssert('disable succeeds', httpTestRequest('/products.php',['action'=>'variant_set_active','product_variant_id'=>$chinaId,'is_active'=>false],$cookie,$csrf)[0]===200);
    httpTestAssert('enable succeeds', httpTestRequest('/products.php',['action'=>'variant_set_active','product_variant_id'=>$chinaId,'is_active'=>true],$cookie,$csrf)[0]===200);
    httpTestAssert('missing variant is 404', httpTestRequest('/products.php',['action'=>'variant_set_active','product_variant_id'=>999999,'is_active'=>false],$cookie,$csrf)[0]===404);
    httpTestRequest('/products.php',['action'=>'variant_set_active','product_variant_id'=>$chinaId,'is_active'=>false],$cookie,$csrf);
    $russiaId=(int)$pdo->query("SELECT id FROM product_variants WHERE assembly_country='Россия'")->fetchColumn();
    httpTestAssert('manual price requires positive value', httpTestRequest('/products.php',['action'=>'variant_price_set_manual','product_variant_id'=>$russiaId,'price'=>0],$cookie,$csrf)[0]===400);
    httpTestAssert('manual price succeeds', httpTestRequest('/products.php',['action'=>'variant_price_set_manual','product_variant_id'=>$russiaId,'price'=>'280000.00','old_price'=>'290000.00'],$cookie,$csrf)[0]===200);
    httpTestAssert('manual price persists separately from legacy JSON', (string)$pdo->query("SELECT manual_price FROM product_variant_price_overrides WHERE product_variant_id=$russiaId AND is_active=1")->fetchColumn()==='280000.00' && !str_contains((string)$pdo->query('SELECT variants FROM products WHERE id=5')->fetchColumn(),'280000'));
    httpTestAssert('automatic mode requires confirmation endpoint and succeeds', httpTestRequest('/products.php',['action'=>'variant_price_set_automatic','product_variant_id'=>$russiaId],$cookie,$csrf)[0]===200);
    httpTestAssert('automatic mode disables override', (int)$pdo->query("SELECT is_active FROM product_variant_price_overrides WHERE product_variant_id=$russiaId")->fetchColumn()===0);
    httpTestAssert('last ready active variant is 409', httpTestRequest('/products.php',['action'=>'variant_set_active','product_variant_id'=>$russiaId,'is_active'=>false],$cookie,$csrf)[0]===409);
    $pdo->exec('RENAME TABLE product_variant_price_overrides TO product_variant_price_overrides_http_fault'); $renamed=true;
    [$status,$errorBody] = httpTestRequest('/products.php',['action'=>'variant_price_set_manual','product_variant_id'=>$russiaId,'price'=>'281000.00'], $cookie, $csrf);
    httpTestAssert('safe 500 hides SQL details', $status===500 && ($errorBody['success']??null)===false && is_string($errorBody['message']??null) && !str_contains(json_encode($errorBody),'SQLSTATE'));
    $pdo->exec('RENAME TABLE product_variant_price_overrides_http_fault TO product_variant_price_overrides'); $renamed=false;
    echo "PASS original products.php HTTP integration\n";
} finally {
    if ($renamed) $pdo->exec('RENAME TABLE product_variant_price_overrides_http_fault TO product_variant_price_overrides');
    if (is_resource($process)) { proc_terminate($process); foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($process); }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0'); $pdo->exec('DROP TABLE IF EXISTS product_variant_price_overrides'); $pdo->exec('DROP TABLE IF EXISTS product_variants'); $pdo->exec('DROP TABLE IF EXISTS products'); $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    foreach (glob($runtime.'/*') ?: [] as $file) unlink($file); @rmdir($runtime);
}

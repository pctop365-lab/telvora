<?php
declare(strict_types=1);

require_once __DIR__ . '/runtime_config.php';

final class CustomerError extends RuntimeException {
    public function __construct(string $message, public readonly int $status = 422) { parent::__construct($message); }
}

function customerSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) throw new RuntimeException('Session already active');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('TELVORA_CUSTOMER');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!telvoraRuntimeIsIsolatedHttpTest(),'httponly'=>true,'samesite'=>'Lax']);
    session_start();
    $now = time();
    if (isset($_SESSION['customer_id']) && ($now - ($_SESSION['last_seen'] ?? 0) > 7200 || $now - ($_SESSION['signed_in'] ?? 0) > 604800)) {
        $_SESSION = []; session_regenerate_id(true);
    }
    $_SESSION['last_seen'] = $now;
    $_SESSION['customer_csrf'] ??= bin2hex(random_bytes(32));
    header('Cache-Control: no-store');
}

function customerCsrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['customer_csrf'] ?? '', $token) || $token === '') {
        throw new CustomerError('Обновите страницу и повторите действие.', 403);
    }
}

function customerText(array $data, string $key, int $limit): string {
    $value = $data[$key] ?? '';
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit || preg_match('/[\x00-\x1F\x7F]/u', $value)) throw new CustomerError('Проверьте введённые данные.');
    return trim($value);
}

function customerLogin(string $value): string {
    $value = trim($value);
    if (preg_match('/^[+\d][\d ()-]+$/D', $value)) {
        $digits = preg_replace('/\D/', '', $value);
        if (strlen($digits) === 11 && $digits[0] === '8') $digits = '7' . substr($digits, 1);
        if (strlen($digits) < 10 || strlen($digits) > 15) throw new CustomerError('Телефон для входа должен содержать 10–15 цифр.');
        return '+' . $digits;
    }
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{2,31}$/D', $value)) throw new CustomerError('Никнейм: 3–32 латинских символа, первая — буква; допустимы цифры, точка, _ и -. Или укажите телефон.');
    return strtolower($value);
}

function customerPassword(array $data): string {
    $password = $data['password'] ?? null;
    if (!is_string($password) || !mb_check_encoding($password, 'UTF-8') || mb_strlen($password) < 10 || strlen($password) > 72 || str_contains($password, "\0")) throw new CustomerError('Пароль: минимум 10 символов и максимум 72 байта.');
    if (!is_string($data['password_repeat'] ?? null) || !hash_equals($password, $data['password_repeat'])) throw new CustomerError('Пароли не совпадают.');
    return $password;
}

function customerPublic(array $row): array {
    return array_intersect_key($row, array_flip(['id','login','full_name','phone','email','email_verified_at','address','created_at']));
}

function customerCurrent(PDO $pdo): ?array {
    if (empty($_SESSION['customer_id'])) return null;
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id=?');
    $stmt->execute([$_SESSION['customer_id']]); $row = $stmt->fetch();
    if (!$row || (int)$row['auth_version'] !== (int)($_SESSION['auth_version'] ?? 0)) {
        $_SESSION = ['customer_csrf'=>bin2hex(random_bytes(32))]; session_regenerate_id(true); return null;
    }
    return $row;
}

function customerSignIn(array $row): void {
    session_regenerate_id(true);
    $_SESSION = ['customer_id'=>(int)$row['id'],'auth_version'=>(int)$row['auth_version'], 'signed_in'=>time(),'last_seen'=>time(),'customer_csrf'=>bin2hex(random_bytes(32))];
}

// Atomic counters across sessions/processes; only digests, never raw IPs or logins.
function customerLimit(PDO $pdo, string $scope, string $identity, int $limit): void {
    $now = time(); $bucket = hash('sha256', $scope . ':' . $identity);
    $pdo->prepare('DELETE FROM customer_auth_limits WHERE expires_at < ?')->execute([$now]);
    $pdo->prepare('INSERT INTO customer_auth_limits(bucket,attempts,expires_at) VALUES(?,1,?) ON DUPLICATE KEY UPDATE attempts=attempts+1')->execute([$bucket,$now+900]);
    $stmt = $pdo->prepare('SELECT attempts FROM customer_auth_limits WHERE bucket=?'); $stmt->execute([$bucket]);
    if ((int)$stmt->fetchColumn() > $limit) throw new CustomerError('Слишком много попыток. Повторите через 15 минут.', 429);
}

function customerProfileInput(array $data): array {
    $profile = ['full_name'=>customerText($data,'full_name',200),'phone'=>customerText($data,'phone',32),'email'=>customerText($data,'email',254),'address'=>customerText($data,'address',1000)];
    if ($profile['email'] !== '' && !filter_var($profile['email'], FILTER_VALIDATE_EMAIL)) throw new CustomerError('Проверьте email.');
    $digits = preg_replace('/\D/', '', $profile['phone']);
    if ($profile['phone'] !== '' && (strlen($digits)<10 || strlen($digits)>15)) throw new CustomerError('Проверьте телефон.');
    return $profile;
}

function customerSaveProfile(PDO $pdo, int $id, array $profile): void {
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare('SELECT email FROM customers WHERE id=? FOR UPDATE'); $stmt->execute([$id]);
        $changed=$stmt->fetchColumn()!==$profile['email'];
        $pdo->prepare('UPDATE customers SET email_verified_at=IF(BINARY email=BINARY ?,email_verified_at,NULL),full_name=?,phone=?,email=?,address=? WHERE id=?')->execute([$profile['email'],$profile['full_name'],$profile['phone'],$profile['email'],$profile['address'],$id]);
        if ($changed) $pdo->prepare('DELETE FROM customer_tokens WHERE customer_id=?')->execute([$id]);
        if ($ownTransaction) $pdo->commit();
    } catch (Throwable $error) { if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function customerOrders(PDO $pdo, int $id, int $page): array {
    $page = max(1,min(100000,$page)); $offset = ($page-1)*20;
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM orders WHERE customer_id=?'); $stmt->execute([$id]); $total=(int)$stmt->fetchColumn();
    $stmt=$pdo->prepare("SELECT id,order_number,customer_name,phone,email,address,created_at,status,total,delivery_quote_status FROM orders WHERE customer_id=? ORDER BY id DESC LIMIT 20 OFFSET $offset");
    $stmt->execute([$id]); $orders=$stmt->fetchAll();
    foreach ($orders as &$order) {
        if ($order['delivery_quote_status']==='pending') $order['total']=null;
        $items=$pdo->prepare('SELECT product_name,quantity,price FROM order_items WHERE order_id=? ORDER BY id'); $items->execute([$order['id']]); $order['items']=$items->fetchAll();
        $services=$pdo->prepare('SELECT service_name,quantity,total FROM order_services WHERE order_id=? ORDER BY id'); $services->execute([$order['id']]); $order['services']=$services->fetchAll();
    }
    return ['orders'=>$orders,'total'=>$total,'page'=>$page];
}

function customerMailFrom(array $secrets): ?string {
    $from=$secrets['customer_mail_from'] ?? $secrets['callback_mail_from'] ?? '';
    return is_string($from) && !preg_match('/[\r\n]/',$from) && filter_var($from,FILTER_VALIDATE_EMAIL) && function_exists('mail') ? $from : null;
}

function customerSendToken(PDO $pdo, array $row, string $purpose, string $from): void {
    $token=bin2hex(random_bytes(32)); $hash=hash('sha256',$token);
    $pdo->prepare('DELETE FROM customer_tokens WHERE customer_id=? AND purpose=?')->execute([$row['id'],$purpose]);
    $pdo->prepare('INSERT INTO customer_tokens(token_hash,customer_id,purpose,email,expires_at) VALUES(?,?,?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE))')->execute([$hash,$row['id'],$purpose,$row['email']]);
    // Fragment keeps the token out of HTTP access logs and Referer headers.
    $url='https://telvora.ru/account#'.$purpose.'='.$token;
    $subject=$purpose==='verify'?'Подтверждение email TELVORA':'Восстановление доступа TELVORA';
    $sent=@mail($row['email'],'=?UTF-8?B?'.base64_encode($subject).'?=',"$subject\n\n$url\n\nСсылка действует 30 минут и используется один раз. Если вы не запрашивали это письмо, проигнорируйте его.",['From'=>'TELVORA <'.$from.'>','Content-Type'=>'text/plain; charset=UTF-8']);
    if (!$sent) { $pdo->prepare('DELETE FROM customer_tokens WHERE token_hash=?')->execute([$hash]); throw new CustomerError('Не удалось отправить письмо. Попробуйте позже.',503); }
}

function customerConsumeToken(PDO $pdo, array $data, string $purpose): void {
    $token=customerText($data,'token',64);
    if (!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new CustomerError('Ссылка недействительна или истекла.');
    $password=$purpose==='reset'?customerPassword($data):null;
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare('SELECT * FROM customer_tokens WHERE token_hash=? AND purpose=? AND expires_at>UTC_TIMESTAMP() FOR UPDATE'); $stmt->execute([hash('sha256',$token),$purpose]); $link=$stmt->fetch();
        if (!$link) throw new CustomerError('Ссылка недействительна или истекла.');
        $stmt=$pdo->prepare('SELECT * FROM customers WHERE id=? FOR UPDATE'); $stmt->execute([$link['customer_id']]); $row=$stmt->fetch();
        if (!$row || $row['email']!==$link['email'] || ($purpose==='reset' && !$row['email_verified_at'])) throw new CustomerError('Ссылка недействительна или истекла.');
        if ($purpose==='verify') $pdo->prepare('UPDATE customers SET email_verified_at=UTC_TIMESTAMP() WHERE id=?')->execute([$row['id']]);
        else $pdo->prepare('UPDATE customers SET password_hash=?,auth_version=auth_version+1 WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$row['id']]);
        $pdo->prepare('DELETE FROM customer_tokens WHERE customer_id=? AND purpose=?')->execute([$row['id'],$purpose]);
        $pdo->commit();
    } catch (Throwable $error) { if($pdo->inTransaction())$pdo->rollBack(); throw $error; }
}

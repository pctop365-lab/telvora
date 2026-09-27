<?php
declare(strict_types=1);
ini_set('display_errors','0');
require_once __DIR__.'/customer_service.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    customerSession();
    $secrets=require telvoraSecretsFile();
    $pdo=new PDO('mysql:host='.$secrets['db_host'].';dbname='.$secrets['db_name'].';charset=utf8mb4',$secrets['db_user'],$secrets['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $method=$_SERVER['REQUEST_METHOD'] ?? '';
    $data=[];
    if ($method==='POST') {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>16384) throw new CustomerError('Запрос слишком большой.',413);
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''),'application/json')) throw new CustomerError('Ожидается JSON.',415);
        $data=json_decode(file_get_contents('php://input'),true,16,JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new CustomerError('Некорректный запрос.',400);
        customerCsrf();
    } elseif ($method!=='GET') throw new CustomerError('Метод не поддерживается.',405);
    $action=$method==='POST'?($data['action'] ?? ''):($_GET['action'] ?? 'session');
    $readActions=['session','orders'];
    if ($method==='GET' && !in_array($action,$readActions,true)) throw new CustomerError('Используйте POST.',405);
    $row=customerCurrent($pdo); $result=[];
    $from=customerMailFrom($secrets);
    if ($action==='session') {
        $result=['customer'=>$row?customerPublic($row):null,'csrf_token'=>$_SESSION['customer_csrf'],'recovery_available'=>$from!==null];
    } elseif ($action==='register' || $action==='login') {
        customerLimit($pdo,$action.'-ip',$_SERVER['REMOTE_ADDR'] ?? '',$action==='login'?30:10);
        $login=customerLogin(customerText($data,'login',64));
        customerLimit($pdo,$action.'-login',$login,10);
        if ($action==='register') {
            $hash=password_hash(customerPassword($data),PASSWORD_DEFAULT);
            try { $pdo->prepare('INSERT INTO customers(login,login_normalized,password_hash) VALUES(?,?,?)')->execute([trim($data['login']),$login,$hash]); }
            catch (PDOException $error) { if (($error->errorInfo[1] ?? 0)===1062) throw new CustomerError('Этот логин уже занят.',409); throw $error; }
        }
        $stmt=$pdo->prepare('SELECT * FROM customers WHERE login_normalized=?'); $stmt->execute([$login]); $row=$stmt->fetch();
        $password=$data['password'] ?? '';
        if (!is_string($password) || strlen($password)>72 || !password_verify($password,$row['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.') || !$row) throw new CustomerError('Неверный логин или пароль.',401);
        if (password_needs_rehash($row['password_hash'],PASSWORD_DEFAULT)) $pdo->prepare('UPDATE customers SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$row['id']]);
        customerSignIn($row);
        $result=['customer'=>customerPublic($row),'csrf_token'=>$_SESSION['customer_csrf']];
    } elseif ($action==='logout') {
        $_SESSION=[]; session_regenerate_id(true); $_SESSION['customer_csrf']=bin2hex(random_bytes(32));
        $result=['customer'=>null,'csrf_token'=>$_SESSION['customer_csrf']];
    } elseif ($action==='reset_request') {
        if (!$from) throw new CustomerError('Восстановление по email пока не подключено.',503);
        customerLimit($pdo,'mail-ip',$_SERVER['REMOTE_ADDR'] ?? '',5);
        $login=customerLogin(customerText($data,'login',64)); customerLimit($pdo,'mail-login',$login,3);
        $stmt=$pdo->prepare('SELECT * FROM customers WHERE login_normalized=? AND email_verified_at IS NOT NULL'); $stmt->execute([$login]); $target=$stmt->fetch();
        if ($target) { try { customerSendToken($pdo,$target,'reset',$from); } catch (CustomerError) { /* Same public response prevents account enumeration. */ } }
        $result=['message'=>'Если у аккаунта есть подтверждённый email, на него будет отправлена ссылка.'];
    } elseif ($action==='verify_token' || $action==='reset_token') {
        customerLimit($pdo,'token-ip',$_SERVER['REMOTE_ADDR'] ?? '',20);
        customerConsumeToken($pdo,$data,$action==='verify_token'?'verify':'reset');
        $result=['message'=>$action==='verify_token'?'Email подтверждён.':'Пароль изменён. Войдите с новым паролем.'];
    } else {
        if (!$row) throw new CustomerError('Войдите в аккаунт.',401);
        if ($action==='profile') {
            customerSaveProfile($pdo,(int)$row['id'],customerProfileInput($data));
            $result=['customer'=>customerPublic(customerCurrent($pdo))];
        } elseif ($action==='orders') {
            $result=customerOrders($pdo,(int)$row['id'],(int)($_GET['page'] ?? 1));
        } elseif ($action==='verify_request') {
            if (!$from) throw new CustomerError('Отправка писем пока не подключена.',503);
            if ($row['email']==='') throw new CustomerError('Сначала сохраните email в профиле.');
            customerLimit($pdo,'verify-customer',(string)$row['id'],3);
            customerLimit($pdo,'mail-ip',$_SERVER['REMOTE_ADDR'] ?? '',5);
            customerSendToken($pdo,$row,'verify',$from); $result=['message'=>'Письмо для подтверждения отправлено.'];
        } else throw new CustomerError('Неизвестное действие.',404);
    }
    echo json_encode(['success'=>true]+$result,JSON_UNESCAPED_UNICODE);
} catch (CustomerError $error) {
    http_response_code($error->status);
    if($error->status===429)header('Retry-After: 900');
    echo json_encode(['success'=>false,'message'=>$error->getMessage()],JSON_UNESCAPED_UNICODE);
} catch (JsonException) {
    http_response_code(400); echo json_encode(['success'=>false,'message'=>'Некорректный JSON'],JSON_UNESCAPED_UNICODE);
} catch (Throwable) {
    http_response_code(503); echo json_encode(['success'=>false,'message'=>'Сервис временно недоступен.'],JSON_UNESCAPED_UNICODE);
}

<?php

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'None'
]);

session_start();

header('Content-Type: application/json; charset=utf-8');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$allowedOrigins = [
    'https://telvora.ru',
];

if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/*
 * ВРЕМЕННЫЙ пароль администратора.
 * Потом обязательно заменим его на более безопасную авторизацию.
 */
$secretsFile = dirname(__DIR__, 2) . '/telvora_runtime/telvora_secrets.php';

if (!is_file($secretsFile) || !is_readable($secretsFile)) {
    http_response_code(500);
    exit(json_encode(['success' => false, 'message' => 'Сервис временно недоступен'], JSON_UNESCAPED_UNICODE));
}

$secrets = require $secretsFile;

$ADMIN_PASSWORD = $secrets['admin_password'] ?? '';
$dbHost = $secrets['db_host'] ?? '';
$dbName = $secrets['db_name'] ?? '';
$dbUser = $secrets['db_user'] ?? '';
$dbPass = $secrets['db_password'] ?? '';

if (
    !is_string($ADMIN_PASSWORD) || $ADMIN_PASSWORD === '' ||
    !is_string($dbHost) || $dbHost === '' ||
    !is_string($dbName) || $dbName === '' ||
    !is_string($dbUser) || $dbUser === '' ||
    !is_string($dbPass) || $dbPass === ''
) {
    http_response_code(500);
    exit(json_encode(['success' => false, 'message' => 'Сервис временно недоступен'], JSON_UNESCAPED_UNICODE));
}
try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка подключения к базе данных'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function adminLoginRateLimitFile(): string
{
    return dirname(__DIR__, 2)
        . '/telvora_runtime/admin_login_rate_limit.json';
}

function processAdminLoginAttempt(
    string $password,
    string $expectedPassword
): array {
    $maxFailures = 5;
    $blockSeconds = 15 * 60;
    $now = time();

    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($remoteAddress) || $remoteAddress === '') {
        $remoteAddress = 'unknown';
    }

    $clientKey = hash('sha256', $remoteAddress);
    $handle = @fopen(adminLoginRateLimitFile(), 'c+');

    if ($handle === false) {
        return ['status' => 'state_error'];
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return ['status' => 'state_error'];
        }

        rewind($handle);
        $rawState = stream_get_contents($handle);

        if ($rawState === false) {
            return ['status' => 'state_error'];
        }

        if ($rawState === '') {
            $state = [];
        } else {
            $state = json_decode($rawState, true);
            if (!is_array($state)) {
                return ['status' => 'state_error'];
            }
        }

        foreach ($state as $key => $entry) {
            if (
                !is_array($entry) ||
                !isset($entry['failures'], $entry['last_failure']) ||
                !is_int($entry['failures']) ||
                !is_int($entry['last_failure'])
            ) {
                return ['status' => 'state_error'];
            }

            if (($now - $entry['last_failure']) >= $blockSeconds) {
                unset($state[$key]);
            }
        }

        $entry = $state[$clientKey] ?? [
            'failures' => 0,
            'last_failure' => 0
        ];

        if ($entry['failures'] >= $maxFailures) {
            $retryAfter = $blockSeconds - ($now - $entry['last_failure']);

            if ($retryAfter > 0) {
                return [
                    'status' => 'blocked',
                    'retry_after' => $retryAfter
                ];
            }

            unset($state[$clientKey]);
        }

        if (!hash_equals($expectedPassword, $password)) {
            $currentFailures = $state[$clientKey]['failures'] ?? 0;

            $state[$clientKey] = [
                'failures' => $currentFailures + 1,
                'last_failure' => $now
            ];

            $status = 'invalid';
        } else {
            unset($state[$clientKey]);
            $status = 'success';
        }

        $encoded = json_encode($state);
        if ($encoded === false) {
            return ['status' => 'state_error'];
        }

        rewind($handle);
        if (!ftruncate($handle, 0)) {
            return ['status' => 'state_error'];
        }

        $written = fwrite($handle, $encoded);

        if (
            $written === false ||
            $written !== strlen($encoded) ||
            !fflush($handle)
        ) {
            return ['status' => 'state_error'];
        }

        return ['status' => $status];
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? $_GET['action'] ?? '';

/*
 * Вход администратора
 */
if ($action === 'login') {
    $password = $data['password'] ?? '';

    if (!is_string($password)) {
        $password = '';
    }

    $loginAttempt = processAdminLoginAttempt(
        $password,
        $ADMIN_PASSWORD
    );

    if ($loginAttempt['status'] === 'state_error') {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'message' => 'Не удалось выполнить вход. Попробуйте позже.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($loginAttempt['status'] === 'blocked') {
        $retryAfter = max(
            1,
            (int)($loginAttempt['retry_after'] ?? 900)
        );

        header('Retry-After: ' . $retryAfter);
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => 'Слишком много попыток. Попробуйте позже.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($loginAttempt['status'] === 'invalid') {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Неверный пароль'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['telvora_admin'] = true;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    echo json_encode([
        'success' => true,
        'csrf_token' => $_SESSION['csrf_token'],
        'message' => 'Вход выполнен'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
 * Проверка авторизации
 */
/*
 * Публичное отслеживание заказа
 * Возвращаем только ID заказа и текущий статус.
 */
if ($action === 'track_order') {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        http_response_code(405);

        echo json_encode([
            'success' => false,
            'message' => 'Метод не поддерживается'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $orderNumber = trim(
        (string)($data['order_number'] ?? '')
    );

    $phone = trim(
        (string)($data['phone'] ?? '')
    );

    if (
        $orderNumber === '' ||
        !ctype_digit($orderNumber) ||
        $phone === ''
    ) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Введите корректный номер заказа и телефон'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $normalizePhone = static function (string $value): string {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (strlen($digits) === 10) {
            $digits = '7' . $digits;
        } elseif (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        }

        return $digits;
    };

    $requestedPhone = $normalizePhone($phone);
    $orderId = (int)$orderNumber;

    $stmt = $pdo->prepare("
        SELECT id, status, phone
        FROM orders
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        ':id' => $orderId
    ]);

    $order = $stmt->fetch();

    $storedPhone = is_array($order)
        ? $normalizePhone((string)($order['phone'] ?? ''))
        : '';

    if (
        !$order ||
        $requestedPhone === '' ||
        $storedPhone === '' ||
        !hash_equals($storedPhone, $requestedPhone)
    ) {
        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Заказ не найден или данные не совпадают'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    echo json_encode([
        'success' => true,
        'order' => [
            'id' => (int)$order['id'],
            'status' => $order['status']
        ]
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if (empty($_SESSION['telvora_admin'])) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Требуется авторизация'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
 * Выход
 */
$csrfProtectedActions = [
    'logout',
    'update_status',
    'supplier_create',
    'supplier_update',
    'supplier_set_active'
];

if (in_array($action, $csrfProtectedActions, true)) {
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $requestToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (
        !is_string($sessionToken) || $sessionToken === '' ||
        !is_string($requestToken) || $requestToken === '' ||
        !hash_equals($sessionToken, $requestToken)
    ) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Request verification failed'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function sendManagerJson(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function requireManagerMethod(string $expectedMethod): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $expectedMethod) {
        header('Allow: ' . $expectedMethod);
        sendManagerJson(405, [
            'success' => false,
            'message' => 'Метод не поддерживается'
        ]);
    }
}

function validateSupplierInput(array $data): array
{
    $nameValue = $data['name'] ?? '';
    $codeValue = $data['internal_code'] ?? '';

    if (!is_string($nameValue) || !is_string($codeValue)) {
        sendManagerJson(400, [
            'success' => false,
            'message' => 'Введите корректные данные поставщика'
        ]);
    }

    $name = trim($nameValue);
    $internalCode = trim($codeValue);
    if (function_exists('mb_strlen')) {
        $nameLength = mb_strlen($name, 'UTF-8');
    } else {
        $characterCount = preg_match_all('/./us', $name, $matches);
        $nameLength = $characterCount === false ? 256 : $characterCount;
    }

    if ($name === '' || $nameLength > 255) {
        sendManagerJson(400, [
            'success' => false,
            'message' => 'Название поставщика обязательно и не должно превышать 255 символов'
        ]);
    }

    if (
        $internalCode === '' ||
        strlen($internalCode) > 100 ||
        preg_match('/\A[a-z0-9][a-z0-9_-]*\z/D', $internalCode) !== 1
    ) {
        sendManagerJson(400, [
            'success' => false,
            'message' => 'Код обязателен: используйте до 100 строчных латинских букв, цифр, дефисов или подчёркиваний'
        ]);
    }

    return [
        'name' => $name,
        'internal_code' => $internalCode
    ];
}

function requireSupplierId(array $data): int
{
    $rawId = $data['id'] ?? null;

    if (
        !is_int($rawId) &&
        (!is_string($rawId) || preg_match('/\A[1-9][0-9]*\z/D', $rawId) !== 1)
    ) {
        sendManagerJson(400, [
            'success' => false,
            'message' => 'Некорректный идентификатор поставщика'
        ]);
    }

    $id = filter_var($rawId, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1]
    ]);

    if ($id === false) {
        sendManagerJson(400, [
            'success' => false,
            'message' => 'Некорректный идентификатор поставщика'
        ]);
    }

    return (int)$id;
}

function requireSupplierActiveValue(array $data): int
{
    $value = $data['is_active'] ?? null;

    if ($value === true || $value === 1 || $value === '1') {
        return 1;
    }

    if ($value === false || $value === 0 || $value === '0') {
        return 0;
    }

    sendManagerJson(400, [
        'success' => false,
        'message' => 'Некорректный статус поставщика'
    ]);
}

function isSupplierCodeDuplicate(Throwable $error): bool
{
    if (
        !$error instanceof PDOException ||
        (string)$error->getCode() !== '23000'
    ) {
        return false;
    }

    $driverCode = $error->errorInfo[1] ?? null;

    return (int)$driverCode === 1062;
}

function prepareSupplierForResponse(array $supplier): array
{
    return [
        'id' => (int)$supplier['id'],
        'name' => (string)$supplier['name'],
        'internal_code' => (string)$supplier['internal_code'],
        'is_active' => (bool)$supplier['is_active'],
        'created_at' => (string)$supplier['created_at'],
        'updated_at' => (string)$supplier['updated_at']
    ];
}

if ($action === 'logout') {
    unset($_SESSION['csrf_token']);

    $_SESSION = [];
    session_destroy();

    echo json_encode([
        'success' => true
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if ($action === 'suppliers_list') {
    requireManagerMethod('GET');

    try {
        $stmt = $pdo->prepare("
            SELECT id, name, internal_code, is_active, created_at, updated_at
            FROM suppliers
            ORDER BY name ASC, id ASC
        ");
        $stmt->execute();

        $suppliers = array_map(
            'prepareSupplierForResponse',
            $stmt->fetchAll()
        );

        sendManagerJson(200, [
            'success' => true,
            'count' => count($suppliers),
            'suppliers' => $suppliers
        ]);
    } catch (Throwable $error) {
        sendManagerJson(500, [
            'success' => false,
            'message' => 'Не удалось загрузить поставщиков'
        ]);
    }
}

if ($action === 'supplier_create') {
    requireManagerMethod('POST');
    $supplier = validateSupplierInput($data);
    $isActive = requireSupplierActiveValue($data);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO suppliers (name, internal_code, is_active)
            VALUES (:name, :internal_code, :is_active)
        ");
        $stmt->execute([
            ':name' => $supplier['name'],
            ':internal_code' => $supplier['internal_code'],
            ':is_active' => $isActive
        ]);

        sendManagerJson(201, [
            'success' => true,
            'message' => 'Поставщик создан',
            'supplier_id' => (int)$pdo->lastInsertId()
        ]);
    } catch (Throwable $error) {
        if (isSupplierCodeDuplicate($error)) {
            sendManagerJson(409, [
                'success' => false,
                'message' => 'Поставщик с таким внутренним кодом уже существует'
            ]);
        }

        sendManagerJson(500, [
            'success' => false,
            'message' => 'Не удалось создать поставщика'
        ]);
    }
}

if ($action === 'supplier_update') {
    requireManagerMethod('POST');
    $supplierId = requireSupplierId($data);
    $supplier = validateSupplierInput($data);
    $isActive = requireSupplierActiveValue($data);

    try {
        $stmt = $pdo->prepare("
            UPDATE suppliers
            SET name = :name,
                internal_code = :internal_code,
                is_active = :is_active
            WHERE id = :id
        ");
        $stmt->execute([
            ':name' => $supplier['name'],
            ':internal_code' => $supplier['internal_code'],
            ':is_active' => $isActive,
            ':id' => $supplierId
        ]);

        if ($stmt->rowCount() === 0) {
            $existsStmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = :id");
            $existsStmt->execute([':id' => $supplierId]);

            if (!$existsStmt->fetch()) {
                sendManagerJson(404, [
                    'success' => false,
                    'message' => 'Поставщик не найден'
                ]);
            }
        }

        sendManagerJson(200, [
            'success' => true,
            'message' => 'Поставщик обновлён'
        ]);
    } catch (Throwable $error) {
        if (isSupplierCodeDuplicate($error)) {
            sendManagerJson(409, [
                'success' => false,
                'message' => 'Поставщик с таким внутренним кодом уже существует'
            ]);
        }

        sendManagerJson(500, [
            'success' => false,
            'message' => 'Не удалось обновить поставщика'
        ]);
    }
}

if ($action === 'supplier_set_active') {
    requireManagerMethod('POST');
    $supplierId = requireSupplierId($data);
    $isActive = requireSupplierActiveValue($data);

    try {
        $stmt = $pdo->prepare("
            UPDATE suppliers
            SET is_active = :is_active
            WHERE id = :id
        ");
        $stmt->execute([
            ':is_active' => $isActive,
            ':id' => $supplierId
        ]);

        if ($stmt->rowCount() === 0) {
            $existsStmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = :id");
            $existsStmt->execute([':id' => $supplierId]);

            if (!$existsStmt->fetch()) {
                sendManagerJson(404, [
                    'success' => false,
                    'message' => 'Поставщик не найден'
                ]);
            }
        }

        sendManagerJson(200, [
            'success' => true,
            'message' => $isActive
                ? 'Поставщик включён'
                : 'Поставщик отключён'
        ]);
    } catch (Throwable $error) {
        sendManagerJson(500, [
            'success' => false,
            'message' => 'Не удалось изменить статус поставщика'
        ]);
    }
}

/*
 * Получение списка заказов
 */
if ($action === 'orders') {

    $stmt = $pdo->query("
        SELECT
            id,
            customer_name,
            phone,
            email,
            address,
            delivery_method,
            payment_method,
            comment,
            total,
            status,
            created_at
        FROM orders
        ORDER BY id DESC
    ");

    $orders = $stmt->fetchAll();

    foreach ($orders as &$order) {

        $itemStmt = $pdo->prepare("
            SELECT
                product_name,
                quantity,
                price
            FROM order_items
            WHERE order_id = :order_id
            ORDER BY id ASC
        ");

        $itemStmt->execute([
            ':order_id' => $order['id']
        ]);

        $order['items'] = $itemStmt->fetchAll();
    }

    echo json_encode([
        'success' => true,
        'orders' => $orders
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
 * Изменение статуса заказа
 */
if ($action === 'update_status') {

    $orderId = (int)($data['order_id'] ?? 0);
    $status = trim($data['status'] ?? '');

    $allowedStatuses = [
        'Новый',
        'Принят',
        'В обработке',
        'Передан в доставку',
        'Выполнен',
        'Отменён'
    ];

    if ($orderId <= 0 || !in_array($status, $allowedStatuses, true)) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Некорректные данные'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE orders
        SET status = :status
        WHERE id = :id
    ");

    $stmt->execute([
        ':status' => $status,
        ':id' => $orderId
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Статус изменён'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

echo json_encode([
    'success' => false,
    'message' => 'Неизвестное действие'
], JSON_UNESCAPED_UNICODE);

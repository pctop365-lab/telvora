<?php

ini_set('display_errors', '0');
ini_set('log_errors', '1');
define('TELVORA_MANAGER_REQUEST', true);

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

$maxManagerRequestBytes = 16384;
$declaredContentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
$queryAction = $_GET['action'] ?? '';
$isSupplierImportMultipartRequest = is_string($queryAction) && in_array(
    $queryAction,
    ['supplier_import_preview', 'supplier_import_stage'],
    true
);

if (
    !$isSupplierImportMultipartRequest &&
    is_string($declaredContentLength) &&
    ctype_digit($declaredContentLength) &&
    (int)$declaredContentLength > $maxManagerRequestBytes
) {
    http_response_code(413);
    echo json_encode([
        'success' => false,
        'message' => 'Данные запроса слишком велики'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$inputStream = $isSupplierImportMultipartRequest
    ? false
    : @fopen('php://input', 'rb');
$rawRequestBody = $isSupplierImportMultipartRequest
    ? ''
    : ($inputStream === false
        ? false
        : @stream_get_contents($inputStream, $maxManagerRequestBytes + 1));

if (is_resource($inputStream)) {
    fclose($inputStream);
}

if (
    !$isSupplierImportMultipartRequest &&
    ($rawRequestBody === false || strlen($rawRequestBody) > $maxManagerRequestBytes)
) {
    http_response_code($rawRequestBody === false ? 400 : 413);
    echo json_encode([
        'success' => false,
        'message' => $rawRequestBody === false
            ? 'Не удалось прочитать данные запроса'
            : 'Данные запроса слишком велики'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = $isSupplierImportMultipartRequest
    ? $_POST
    : ($rawRequestBody === '' ? [] : json_decode($rawRequestBody, true));
$requestJsonIsValid = !$isSupplierImportMultipartRequest &&
    is_array($data) && json_last_error() === JSON_ERROR_NONE;

if (!is_array($data)) {
    $data = [];
}

$action = $isSupplierImportMultipartRequest
    ? $queryAction
    : ($data['action'] ?? $queryAction);

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
    'supplier_set_active',
    'supplier_import_profile_create',
    'supplier_import_profile_update',
    'supplier_import_profile_set_active',
    'supplier_availability_mapping_create',
    'supplier_availability_mapping_update',
    'supplier_availability_mapping_set_active',
    'supplier_import_preview',
    'supplier_import_stage',
    'supplier_import_row_set_match',
    'supplier_import_row_create_product',
    'supplier_import_job_publish_offers',
    'pricing_rule_create',
    'pricing_rule_update',
    'pricing_rule_set_active',
    'supplier_offer_price_publish'
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
    $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

    if ($encodedPayload === false) {
        error_log('manager.php JSON encoding failed: ' . json_last_error_msg());
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo '{"success":false,"message":"Response encoding failed"}';
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo $encodedPayload;
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

function requirePositiveManagerId(mixed $value, string $label): int
{
    if (
        !is_int($value) &&
        (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1)
    ) {
        sendManagerJson(400, [
            'success' => false,
            'message' => 'Некорректный идентификатор: ' . $label
        ]);
    }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        sendManagerJson(400, [
            'success' => false,
            'message' => 'Некорректный идентификатор: ' . $label
        ]);
    }
    return (int)$id;
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

function requireSupplierImportProfileId(array $data): int
{
    $rawId = $data['id'] ?? null;

    if (
        !is_int($rawId) &&
        (!is_string($rawId) || preg_match('/\A[1-9][0-9]*\z/D', $rawId) !== 1)
    ) {
        sendManagerJson(400, [
            'success' => false,
            'message' => 'Некорректный идентификатор профиля импорта'
        ]);
    }

    $id = filter_var($rawId, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1]
    ]);

    if ($id === false) {
        sendManagerJson(400, [
            'success' => false,
            'message' => 'Некорректный идентификатор профиля импорта'
        ]);
    }

    return (int)$id;
}

function requireSupplierImportProfileJsonRequest(
    bool $requestJsonIsValid,
    string $rawRequestBody
): void {
    if (!$requestJsonIsValid) {
        sendManagerJson(400, [
            'success' => false,
            'message' => 'Тело запроса должно содержать корректный JSON-объект'
        ]);
    }

    if (strlen($rawRequestBody) > 16384) {
        sendManagerJson(413, [
            'success' => false,
            'message' => 'Данные профиля слишком велики'
        ]);
    }
}

function requireOnlyPayloadKeys(array $data, array $allowedKeys): void
{
    foreach (array_keys($data) as $key) {
        if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
            sendManagerJson(400, [
                'success' => false,
                'message' => 'Запрос содержит неподдерживаемые поля'
            ]);
        }
    }
}

function requirePricingRuleJsonRequest(bool $requestJsonIsValid): void
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (
        !$requestJsonIsValid ||
        !is_string($contentType) ||
        preg_match('/\Aapplication\/json(?:\s*;|\z)/iD', $contentType) !== 1
    ) {
        sendManagerJson(400, [
            'success' => false,
            'message' => 'Тело запроса должно быть корректным JSON-объектом'
        ]);
    }
}

function pricingRuleStringLength(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    $count = preg_match_all('/./us', $value, $matches);
    return $count === false ? PHP_INT_MAX : $count;
}

function normalizePricingRuleDecimal(
    mixed $value,
    int $scale,
    int $maxWholeDigits,
    string $label
): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_string($value) || preg_match('/\A(\d+)(?:\.(\d{1,' . $scale . '}))?\z/D', $value, $matches) !== 1) {
        sendManagerJson(400, ['success' => false, 'message' => "Некорректное значение: $label"]);
    }
    $whole = ltrim($matches[1], '0');
    $whole = $whole === '' ? '0' : $whole;
    if (strlen($whole) > $maxWholeDigits) {
        sendManagerJson(400, ['success' => false, 'message' => "Значение слишком велико: $label"]);
    }
    $fraction = str_pad($matches[2] ?? '', $scale, '0');
    return $whole . '.' . $fraction;
}

function pricingRuleScaledInteger(string $value, int $scale): int
{
    [$whole, $fraction] = explode('.', $value, 2);
    return ((int)$whole * (10 ** $scale)) + (int)$fraction;
}

function normalizePricingRuleDate(mixed $value, string $label): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/D', $value) !== 1) {
        sendManagerJson(400, ['success' => false, 'message' => "Некорректная дата: $label"]);
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (
        $date === false ||
        (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) ||
        $date->format('Y-m-d\TH:i') !== $value
    ) {
        sendManagerJson(400, ['success' => false, 'message' => "Некорректная дата: $label"]);
    }
    return $date->format('Y-m-d H:i:00');
}

function validatePricingRuleInput(array $data): array
{
    $nameValue = $data['name'] ?? null;
    if (!is_string($nameValue)) {
        sendManagerJson(400, ['success' => false, 'message' => 'Введите название правила']);
    }
    $name = trim($nameValue);
    $nameControlMatch = preg_match('/[\x00-\x1F\x7F]/u', $name);
    if ($name === '' || pricingRuleStringLength($name) > 255 || $nameControlMatch !== 0) {
        sendManagerJson(400, ['success' => false, 'message' => 'Название должно содержать от 1 до 255 символов без управляющих знаков']);
    }

    $priorityValue = $data['priority'] ?? null;
    if (!is_int($priorityValue) || $priorityValue < 0 || $priorityValue > 100000) {
        sendManagerJson(400, ['success' => false, 'message' => 'Приоритет должен быть целым числом от 0 до 100000']);
    }

    $categoryValue = $data['category_scope'] ?? null;
    if ($categoryValue !== null && !is_string($categoryValue)) {
        sendManagerJson(400, ['success' => false, 'message' => 'Некорректная категория']);
    }
    $category = $categoryValue === null ? null : trim($categoryValue);
    $category = $category === '' ? null : $category;
    $categoryControlMatch = $category === null ? 0 : preg_match('/[\x00-\x1F\x7F]/u', $category);
    if ($category !== null && (pricingRuleStringLength($category) > 100 || $categoryControlMatch !== 0)) {
        sendManagerJson(400, ['success' => false, 'message' => 'Категория не должна превышать 100 символов или содержать управляющие знаки']);
    }

    $minimum = normalizePricingRuleDecimal($data['purchase_price_min'] ?? null, 2, 13, 'минимальная закупочная цена');
    $maximum = normalizePricingRuleDecimal($data['purchase_price_max'] ?? null, 2, 13, 'максимальная закупочная цена');
    if ($minimum !== null && $maximum !== null && pricingRuleScaledInteger($minimum, 2) > pricingRuleScaledInteger($maximum, 2)) {
        sendManagerJson(400, ['success' => false, 'message' => 'Минимальная закупочная цена не может превышать максимальную']);
    }

    $markup = normalizePricingRuleDecimal($data['markup_percent'] ?? null, 4, 5, 'наценка');
    if ($markup !== null && pricingRuleScaledInteger($markup, 4) > 100000000) {
        sendManagerJson(400, ['success' => false, 'message' => 'Наценка не должна превышать 10000%']);
    }
    $margin = normalizePricingRuleDecimal($data['minimum_margin'] ?? null, 2, 13, 'минимальная маржа');

    $validFrom = normalizePricingRuleDate($data['valid_from'] ?? null, 'действует с');
    $validUntil = normalizePricingRuleDate($data['valid_until'] ?? null, 'действует до');
    if ($validFrom !== null && $validUntil !== null && strcmp($validFrom, $validUntil) > 0) {
        sendManagerJson(400, ['success' => false, 'message' => 'Начало действия не может быть позже окончания']);
    }
    if (!array_key_exists('is_active', $data) || !is_bool($data['is_active'])) {
        sendManagerJson(400, ['success' => false, 'message' => 'Некорректный статус правила']);
    }

    return [
        'name' => $name,
        'priority' => $priorityValue,
        'category_scope' => $category,
        'purchase_price_min' => $minimum,
        'purchase_price_max' => $maximum,
        'markup_percent' => $markup,
        'minimum_margin' => $margin,
        'valid_from' => $validFrom,
        'valid_until' => $validUntil,
        'is_active' => $data['is_active'] ? 1 : 0
    ];
}

function pricingRuleIsSupported(array $rule): bool
{
    $rounding = trim((string)($rule['rounding_strategy'] ?? ''));
    return ($rounding === '' || $rounding === 'none') &&
        ($rule['rounding_parameters'] ?? null) === null &&
        ($rule['additional_scope'] ?? null) === null;
}

function preparePricingRuleForResponse(array $rule): array
{
    $supported = pricingRuleIsSupported($rule);
    return [
        'id' => (int)$rule['id'],
        'name' => (string)$rule['name'],
        'priority' => (int)$rule['priority'],
        'category_scope' => $rule['category_scope'],
        'purchase_price_min' => $rule['purchase_price_min'],
        'purchase_price_max' => $rule['purchase_price_max'],
        'markup_percent' => $rule['markup_percent'],
        'minimum_margin' => $rule['minimum_margin'],
        'rounding_strategy' => $rule['rounding_strategy'],
        'valid_from' => $rule['valid_from'],
        'valid_until' => $rule['valid_until'],
        'is_active' => (bool)$rule['is_active'],
        'created_at' => (string)$rule['created_at'],
        'updated_at' => (string)$rule['updated_at'],
        'supported_by_stage6' => $supported,
        'warning' => $supported ? null : 'Правило содержит неподдерживаемое округление или дополнительный scope'
    ];
}

function isPricingRuleNameDuplicate(Throwable $error): bool
{
    return $error instanceof PDOException &&
        (string)$error->getCode() === '23000' &&
        (int)($error->errorInfo[1] ?? 0) === 1062;
}

function requireSupplierImportProfileSupplierId(array $data): int
{
    return requireSupplierId(['id' => $data['supplier_id'] ?? null]);
}

function supplierImportProfileStringLength(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    $characterCount = preg_match_all('/./us', $value, $matches);

    return $characterCount === false ? PHP_INT_MAX : $characterCount;
}

function validateSupplierImportProfileInput(array $data): array
{
    $allowedPayloadKeys = [
        'action',
        'id',
        'supplier_id',
        'name',
        'sheet_name',
        'header_row_number',
        'column_mapping',
        'parser_options',
        'arrival_date_format',
        'is_active'
    ];

    requireOnlyPayloadKeys($data, $allowedPayloadKeys);

    $encodedPayload = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($encodedPayload === false || strlen($encodedPayload) > 16384) {
        sendManagerJson(413, [
            'success' => false,
            'message' => 'Данные профиля слишком велики'
        ]);
    }

    $nameValue = $data['name'] ?? null;
    if (!is_string($nameValue)) {
        sendManagerJson(400, [
            'success' => false,
            'message' => 'Введите корректное название профиля'
        ]);
    }

    $name = trim($nameValue);
    if ($name === '' || supplierImportProfileStringLength($name) > 255) {
        sendManagerJson(400, [
            'success' => false,
            'message' => 'Название профиля обязательно и не должно превышать 255 символов'
        ]);
    }

    $sheetNameValue = $data['sheet_name'] ?? null;
    if ($sheetNameValue !== null && !is_string($sheetNameValue)) {
        sendManagerJson(400, [
            'success' => false,
            'message' => 'Введите корректное название листа'
        ]);
    }

    $sheetName = is_string($sheetNameValue) ? trim($sheetNameValue) : '';
    $hasForbiddenSheetPathSeparator =
        str_contains($sheetName, '/') || str_contains($sheetName, '\\');
    $sheetControlMatch = preg_match('/[\x00-\x1F\x7F]/u', $sheetName);

    if (
        supplierImportProfileStringLength($sheetName) > 255 ||
        $hasForbiddenSheetPathSeparator ||
        $sheetControlMatch !== 0
    ) {
        sendManagerJson(400, [
            'success' => false,
            'message' => 'Название листа не должно быть путём, содержать управляющие знаки или превышать 255 символов'
        ]);
    }

    $headerRowNumber = $data['header_row_number'] ?? null;
    if (!is_int($headerRowNumber) || $headerRowNumber < 0 || $headerRowNumber > 1048576) {
        sendManagerJson(400, [
            'success' => false,
            'message' => 'Номер строки заголовков должен быть целым числом от 0 до 1048576'
        ]);
    }

    $mappingValue = $data['column_mapping'] ?? null;
    if (!is_array($mappingValue) || ($mappingValue !== [] && array_is_list($mappingValue))) {
        sendManagerJson(400, [
            'success' => false,
            'message' => 'Некорректная карта колонок'
        ]);
    }

    $allowedMappingKeys = [
        'supplier_sku',
        'product_name',
        'purchase_price',
        'currency_code',
        'availability',
        'arrival_info',
        'model',
        'assembly_country',
        'market_region',
        'certification_supply_type'
    ];
    $columnMapping = [];

    foreach ($mappingValue as $key => $value) {
        if (!is_string($key) || !in_array($key, $allowedMappingKeys, true)) {
            sendManagerJson(400, [
                'success' => false,
                'message' => 'Карта колонок содержит неподдерживаемый ключ'
            ]);
        }

        if (!is_string($value)) {
            sendManagerJson(400, [
                'success' => false,
                'message' => 'Названия колонок должны быть строками'
            ]);
        }

        $columnName = trim($value);
        if (
            $columnName === '' ||
            supplierImportProfileStringLength($columnName) > 50 ||
            preg_match('/[\x00-\x1F\x7F]/u', $columnName) === 1
        ) {
            sendManagerJson(400, [
                'success' => false,
                'message' => 'Название колонки должно содержать от 1 до 50 символов без управляющих знаков'
            ]);
        }

        $columnMapping[$key] = $columnName;
    }

    $parserOptionsValue = $data['parser_options'] ?? null;
    if (!is_array($parserOptionsValue) || ($parserOptionsValue !== [] && array_is_list($parserOptionsValue))) {
        sendManagerJson(400, [
            'success' => false,
            'message' => 'Некорректные настройки парсера'
        ]);
    }

    $allowedParserOptionKeys = [
        'trim_values',
        'skip_empty_rows',
        'decimal_separator',
        'default_currency_code'
    ];

    foreach (array_keys($parserOptionsValue) as $key) {
        if (!is_string($key) || !in_array($key, $allowedParserOptionKeys, true)) {
            sendManagerJson(400, [
                'success' => false,
                'message' => 'Настройки парсера содержат неподдерживаемый ключ'
            ]);
        }
    }

    $trimValues = $parserOptionsValue['trim_values'] ?? true;
    $skipEmptyRows = $parserOptionsValue['skip_empty_rows'] ?? true;
    $decimalSeparator = $parserOptionsValue['decimal_separator'] ?? '.';
    $defaultCurrencyCode = $parserOptionsValue['default_currency_code'] ?? null;

    if (!is_bool($trimValues) || !is_bool($skipEmptyRows)) {
        sendManagerJson(400, [
            'success' => false,
            'message' => 'Флаги настроек парсера должны быть логическими значениями'
        ]);
    }

    if (!is_string($decimalSeparator) || !in_array($decimalSeparator, ['.', ','], true)) {
        sendManagerJson(400, [
            'success' => false,
            'message' => 'Разделитель дробной части должен быть точкой или запятой'
        ]);
    }

    if ($defaultCurrencyCode !== null) {
        if (!is_string($defaultCurrencyCode)) {
            sendManagerJson(400, [
                'success' => false,
                'message' => 'Некорректный код валюты'
            ]);
        }

        $defaultCurrencyCode = strtoupper(trim($defaultCurrencyCode));
        if (preg_match('/\A[A-Z]{3}\z/D', $defaultCurrencyCode) !== 1) {
            sendManagerJson(400, [
                'success' => false,
                'message' => 'Код валюты должен состоять из трёх латинских букв'
            ]);
        }
    }

    require_once __DIR__ . '/supplier_availability_service.php';
    try {
        $arrivalDateFormat = supplierAvailabilityValidateDateFormat($data['arrival_date_format'] ?? null);
    } catch (InvalidArgumentException $error) {
        sendManagerJson(400, ['success' => false, 'message' => $error->getMessage()]);
    }

    $parserOptions = [
        'trim_values' => $trimValues,
        'skip_empty_rows' => $skipEmptyRows,
        'decimal_separator' => $decimalSeparator,
        'default_currency_code' => $defaultCurrencyCode
    ];

    return [
        'name' => $name,
        'sheet_name' => $sheetName === '' ? null : $sheetName,
        'header_row_number' => $headerRowNumber,
        'column_mapping' => $columnMapping,
        'parser_options' => $parserOptions,
        'arrival_date_format' => $arrivalDateFormat,
        'arrival_date_format_provided' => array_key_exists('arrival_date_format', $data),
        'is_active' => requireSupplierActiveValue($data),
        'sku_column' => $columnMapping['supplier_sku'] ?? null,
        'product_name_column' => $columnMapping['product_name'] ?? null,
        'purchase_price_column' => $columnMapping['purchase_price'] ?? null,
        'stock_column' => $columnMapping['availability'] ?? null,
        'arrival_column' => $columnMapping['arrival_info'] ?? null,
        'variant_region_column' => $columnMapping['market_region'] ?? null
    ];
}

function requireSupplierExists(PDO $pdo, int $supplierId): void
{
    $stmt = $pdo->prepare('SELECT id FROM suppliers WHERE id = :id');
    $stmt->execute([':id' => $supplierId]);

    if (!$stmt->fetch()) {
        sendManagerJson(404, [
            'success' => false,
            'message' => 'Поставщик не найден'
        ]);
    }
}

function prepareSupplierImportProfileForResponse(array $profile): array
{
    $columnMapping = json_decode((string)($profile['column_mapping'] ?? ''), true);
    $parserOptions = json_decode((string)($profile['parser_options'] ?? ''), true);

    if (!is_array($columnMapping)) {
        $columnMapping = [];
    }

    $legacyMapping = [
        'supplier_sku' => $profile['sku_column'] ?? null,
        'product_name' => $profile['product_name_column'] ?? null,
        'purchase_price' => $profile['purchase_price_column'] ?? null,
        'availability' => $profile['stock_column'] ?? null,
        'arrival_info' => $profile['arrival_column'] ?? null,
        'market_region' => $profile['variant_region_column'] ?? null
    ];

    foreach ($legacyMapping as $key => $value) {
        if (!isset($columnMapping[$key]) && is_string($value) && $value !== '') {
            $columnMapping[$key] = $value;
        }
    }

    $safeColumnMapping = [];
    $allowedMappingKeys = [
        'supplier_sku', 'product_name', 'purchase_price', 'currency_code',
        'availability', 'arrival_info', 'model', 'assembly_country',
        'market_region', 'certification_supply_type'
    ];

    foreach ($allowedMappingKeys as $key) {
        $value = $columnMapping[$key] ?? null;
        if (is_string($value)) {
            $safeColumnMapping[$key] = $value;
        }
    }

    $safeParserOptions = [];
    if (is_array($parserOptions)) {
        foreach (['trim_values', 'skip_empty_rows'] as $key) {
            if (isset($parserOptions[$key]) && is_bool($parserOptions[$key])) {
                $safeParserOptions[$key] = $parserOptions[$key];
            }
        }

        if (isset($parserOptions['decimal_separator']) && in_array($parserOptions['decimal_separator'], ['.', ','], true)) {
            $safeParserOptions['decimal_separator'] = $parserOptions['decimal_separator'];
        }

        if (isset($parserOptions['default_currency_code']) && is_string($parserOptions['default_currency_code'])) {
            $safeParserOptions['default_currency_code'] = $parserOptions['default_currency_code'];
        }
    }

    return [
        'id' => (int)$profile['id'],
        'supplier_id' => (int)$profile['supplier_id'],
        'name' => (string)$profile['name'],
        'sheet_name' => $profile['sheet_name'] === null ? null : (string)$profile['sheet_name'],
        'header_row_number' => (int)$profile['header_row_number'],
        'column_mapping' => $safeColumnMapping,
        'parser_options' => $safeParserOptions,
        'arrival_date_format' => $profile['arrival_date_format'] === null ? null : (string)$profile['arrival_date_format'],
        'is_active' => (bool)$profile['is_active'],
        'created_at' => (string)$profile['created_at'],
        'updated_at' => (string)$profile['updated_at']
    ];
}

function supplierAvailabilityCollationHash(PDO $pdo, string $rawValue): string
{
    $stmt = $pdo->prepare("SELECT SHA2(WEIGHT_STRING(CONVERT(:raw_value USING utf8mb4) COLLATE utf8mb4_unicode_ci), 256)");
    $stmt->execute([':raw_value' => $rawValue]);
    $hash = $stmt->fetchColumn();
    if (!is_string($hash) || preg_match('/\A[0-9a-f]{64}\z/iD', $hash) !== 1) {
        throw new RuntimeException('unable to create supplier availability collation key');
    }
    return strtolower($hash);
}

function prepareSupplierAvailabilityMappingForResponse(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'profile_id' => (int)$row['import_profile_id'],
        'raw_value' => (string)$row['raw_value'],
        'normalized_status' => (string)$row['normalized_status'],
        'is_active' => (bool)$row['is_active'],
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at']
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

if ($action === 'supplier_import_preview') {
    requireManagerMethod('POST');

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (!is_string($contentType) || !str_starts_with(strtolower($contentType), 'multipart/form-data;')) {
        sendManagerJson(400, [
            'success' => false,
            'message' => 'Для предварительного просмотра требуется загрузка файла'
        ]);
    }

    requireOnlyPayloadKeys($data, ['supplier_id', 'profile_id']);
    $supplierId = requireSupplierImportProfileSupplierId($data);
    $profileId = requireSupplierImportProfileId([
        'id' => $data['profile_id'] ?? null
    ]);

    try {
        $profileStmt = $pdo->prepare("
            SELECT p.id, p.supplier_id, p.sheet_name, p.header_row_number,
                   p.column_mapping, p.parser_options
            FROM supplier_import_profiles p
            INNER JOIN suppliers s ON s.id = p.supplier_id
            WHERE p.id = :profile_id
              AND p.supplier_id = :supplier_id
              AND p.is_active = 1
            LIMIT 1
        ");
        $profileStmt->execute([
            ':profile_id' => $profileId,
            ':supplier_id' => $supplierId
        ]);
        $profileRow = $profileStmt->fetch();

        if (!is_array($profileRow)) {
            sendManagerJson(404, [
                'success' => false,
                'message' => 'Активный профиль импорта не найден'
            ]);
        }

        require_once __DIR__ . '/supplier_import_preview.php';

        $autoloadFile = dirname(__DIR__, 2)
            . '/telvora_vendor/vendor/autoload.php';
        if (!is_file($autoloadFile) || !is_readable($autoloadFile)) {
            error_log('supplier import preview: private Composer autoload is unavailable');
            sendManagerJson(500, [
                'success' => false,
                'message' => 'Предварительный просмотр временно недоступен'
            ]);
        }

        try {
            require_once $autoloadFile;
        } catch (Throwable $error) {
            error_log('supplier import preview autoload failed: ' . $error->getMessage());
            sendManagerJson(500, [
                'success' => false,
                'message' => 'Предварительный просмотр временно недоступен'
            ]);
        }

        if (
            !class_exists(PhpOffice\PhpSpreadsheet\IOFactory::class) ||
            !class_exists(ZipArchive::class) ||
            !class_exists(finfo::class)
        ) {
            error_log('supplier import preview: required PHP library or extension is unavailable');
            sendManagerJson(500, [
                'success' => false,
                'message' => 'Предварительный просмотр временно недоступен'
            ]);
        }

        $upload = supplierPreviewValidateUpload($_FILES);
        $profile = supplierPreviewValidateProfile($profileRow);
        $parsed = supplierPreviewParse($upload, $profile);

        sendManagerJson(200, [
            'success' => true,
            'preview' => [
                'supplier_id' => $supplierId,
                'profile_id' => $profileId,
                'original_filename' => $upload['original_filename'],
                'format' => $upload['extension'],
                'sheet_name' => $parsed['sheet_name'],
                'header_row_number' => $profile['header_row_number'],
                'detected_headers' => $parsed['detected_headers'],
                'mapping' => $profile['mapping_response'],
                'rows_scanned' => $parsed['rows_scanned'],
                'rows_skipped' => $parsed['rows_skipped'],
                'rows_with_errors' => $parsed['rows_with_errors'],
                'preview_truncated' => $parsed['preview_truncated'],
                'rows' => $parsed['rows']
            ]
        ]);
    } catch (SupplierImportPreviewException $error) {
        sendManagerJson($error->httpStatus, [
            'success' => false,
            'message' => $error->getMessage()
        ]);
    } catch (Throwable $error) {
        error_log('supplier import preview failed: ' . $error->getMessage());
        sendManagerJson(500, [
            'success' => false,
            'message' => 'Не удалось сформировать предварительный просмотр'
        ]);
    }
}

if ($action === 'supplier_import_stage') {
    requireManagerMethod('POST');

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (!is_string($contentType) || !str_starts_with(strtolower($contentType), 'multipart/form-data;')) {
        sendManagerJson(400, [
            'success' => false,
            'message' => 'Для создания импорта требуется загрузка файла'
        ]);
    }

    requireOnlyPayloadKeys($data, ['supplier_id', 'profile_id']);
    $supplierId = requireSupplierImportProfileSupplierId($data);
    $profileId = requireSupplierImportProfileId([
        'id' => $data['profile_id'] ?? null
    ]);

    try {
        $profileStmt = $pdo->prepare("
            SELECT p.id, p.supplier_id, p.sheet_name, p.header_row_number,
                   p.column_mapping, p.parser_options
            FROM supplier_import_profiles p
            INNER JOIN suppliers s ON s.id = p.supplier_id
            WHERE p.id = :profile_id
              AND p.supplier_id = :supplier_id
              AND p.is_active = 1
            LIMIT 1
        ");
        $profileStmt->execute([
            ':profile_id' => $profileId,
            ':supplier_id' => $supplierId
        ]);
        $profileRow = $profileStmt->fetch();
        if (!is_array($profileRow)) {
            sendManagerJson(404, [
                'success' => false,
                'message' => 'Активный профиль импорта не найден'
            ]);
        }

        require_once __DIR__ . '/supplier_import_preview.php';
        require_once __DIR__ . '/supplier_import_stage.php';
        $autoloadFile = dirname(__DIR__, 2) . '/telvora_vendor/vendor/autoload.php';
        if (!is_file($autoloadFile) || !is_readable($autoloadFile)) {
            error_log('supplier staging import: private Composer autoload is unavailable');
            sendManagerJson(500, [
                'success' => false,
                'message' => 'Создание импорта временно недоступно'
            ]);
        }
        try {
            require_once $autoloadFile;
        } catch (Throwable $error) {
            error_log('supplier staging import autoload failed: ' . $error->getMessage());
            sendManagerJson(500, [
                'success' => false,
                'message' => 'Создание импорта временно недоступно'
            ]);
        }
        if (
            !class_exists(PhpOffice\PhpSpreadsheet\IOFactory::class) ||
            !class_exists(ZipArchive::class) ||
            !class_exists(finfo::class)
        ) {
            error_log('supplier staging import: required PHP library or extension is unavailable');
            sendManagerJson(500, [
                'success' => false,
                'message' => 'Создание импорта временно недоступно'
            ]);
        }

        $upload = supplierPreviewValidateUpload($_FILES);
        $profile = supplierPreviewValidateProfile($profileRow);
        $pdo->beginTransaction();

        $jobStmt = $pdo->prepare("
            INSERT INTO supplier_import_jobs (
                supplier_id, import_profile_id, original_filename, status
            ) VALUES (
                :supplier_id, :profile_id, :original_filename, 'processing'
            )
        ");
        $jobStmt->execute([
            ':supplier_id' => $supplierId,
            ':profile_id' => $profileId,
            ':original_filename' => $upload['original_filename']
        ]);
        $jobId = (int)$pdo->lastInsertId();
        $rowBuffer = [];
        $counters = ['total' => 0, 'matched' => 0, 'unmatched' => 0, 'errors' => 0];
        $consumeRow = static function (array $row) use (
            $pdo,
            $jobId,
            $supplierId,
            &$rowBuffer,
            &$counters
        ): void {
            $rowBuffer[] = supplierStagePrepareRow($row);
            if (count($rowBuffer) >= 200) {
                supplierStageInsertChunk($pdo, $jobId, $supplierId, $rowBuffer, $counters);
                $rowBuffer = [];
            }
        };

        supplierPreviewParse($upload, $profile, $consumeRow, 0);
        supplierStageInsertChunk($pdo, $jobId, $supplierId, $rowBuffer, $counters);

        $finishStmt = $pdo->prepare("
            UPDATE supplier_import_jobs
            SET status = 'ready_for_review', rows_total = :rows_total,
                rows_matched = :rows_matched, rows_unmatched = :rows_unmatched,
                rows_errors = :rows_errors, finished_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $finishStmt->execute([
            ':rows_total' => $counters['total'],
            ':rows_matched' => $counters['matched'],
            ':rows_unmatched' => $counters['unmatched'],
            ':rows_errors' => $counters['errors'],
            ':id' => $jobId
        ]);
        $pdo->commit();

        sendManagerJson(201, [
            'success' => true,
            'message' => 'Импорт создан для проверки. Товары, цены и остатки не изменены.',
            'job_id' => $jobId,
            'counters' => $counters
        ]);
    } catch (SupplierImportPreviewException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendManagerJson($error->httpStatus, [
            'success' => false,
            'message' => $error->getMessage()
        ]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('supplier staging import failed: ' . $error->getMessage());
        sendManagerJson(500, [
            'success' => false,
            'message' => 'Не удалось создать staging-импорт'
        ]);
    }
}

if ($action === 'supplier_import_jobs_list') {
    requireManagerMethod('GET');
    $supplierId = requireSupplierImportProfileSupplierId([
        'supplier_id' => $_GET['supplier_id'] ?? null
    ]);
    try {
        requireSupplierExists($pdo, $supplierId);
        $stmt = $pdo->prepare("
            SELECT j.id, j.supplier_id, j.import_profile_id, j.original_filename,
                   j.status, j.rows_total, j.rows_matched, j.rows_unmatched,
                   j.rows_errors, j.created_at, j.finished_at,
                   p.name AS profile_name
            FROM supplier_import_jobs j
            LEFT JOIN supplier_import_profiles p ON p.id = j.import_profile_id
            WHERE j.supplier_id = :supplier_id
            ORDER BY j.created_at DESC, j.id DESC
            LIMIT 30
        ");
        $stmt->execute([':supplier_id' => $supplierId]);
        $jobs = array_map(static function (array $job): array {
            foreach (['id', 'supplier_id', 'import_profile_id', 'rows_total', 'rows_matched', 'rows_unmatched', 'rows_errors'] as $key) {
                $job[$key] = $job[$key] === null ? null : (int)$job[$key];
            }
            return $job;
        }, $stmt->fetchAll());
        sendManagerJson(200, ['success' => true, 'jobs' => $jobs]);
    } catch (Throwable $error) {
        error_log('supplier import jobs list failed: ' . $error->getMessage());
        sendManagerJson(500, ['success' => false, 'message' => 'Не удалось загрузить историю импортов']);
    }
}

if ($action === 'supplier_import_job_rows') {
    requireManagerMethod('GET');
    $jobId = requirePositiveManagerId($_GET['job_id'] ?? null, 'import job');
    $page = requirePositiveManagerId($_GET['page'] ?? '1', 'страница');
    $filter = $_GET['filter'] ?? 'all';
    $allowedFilters = ['all', 'matched', 'unmatched', 'review'];
    if (!is_string($filter) || !in_array($filter, $allowedFilters, true)) {
        sendManagerJson(400, ['success' => false, 'message' => 'Некорректный фильтр строк']);
    }
    $pageSize = 50;
    $offset = ($page - 1) * $pageSize;
    if ($offset > 5000000) {
        sendManagerJson(400, ['success' => false, 'message' => 'Некорректная страница']);
    }
    $whereByFilter = [
        'all' => '',
        'matched' => " AND r.status = 'matched'",
        'unmatched' => " AND r.status = 'unmatched'",
        'review' => " AND r.status IN ('needs_review', 'validation_error')"
    ];
    try {
        require_once __DIR__ . '/supplier_import_stage.php';
        $jobStmt = $pdo->prepare("
            SELECT j.id, j.supplier_id, j.import_profile_id, j.original_filename,
                   j.status, j.rows_total, j.rows_matched, j.rows_unmatched,
                   j.rows_errors, j.created_at, j.finished_at,
                   p.name AS profile_name, p.arrival_date_format,
                   s.name AS supplier_name
            FROM supplier_import_jobs j
            INNER JOIN suppliers s ON s.id = j.supplier_id
            LEFT JOIN supplier_import_profiles p ON p.id = j.import_profile_id
            WHERE j.id = :id LIMIT 1
        ");
        $jobStmt->execute([':id' => $jobId]);
        $job = $jobStmt->fetch();
        if (!is_array($job)) {
            sendManagerJson(404, ['success' => false, 'message' => 'Импорт не найден']);
        }
        $filterSql = $whereByFilter[$filter];
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM supplier_import_rows r WHERE r.import_job_id = :job_id$filterSql");
        $countStmt->execute([':job_id' => $jobId]);
        $total = (int)$countStmt->fetchColumn();
        $rowsStmt = $pdo->prepare("
            SELECT r.id, r.source_row_number, r.supplier_sku,
                   r.raw_product_name, r.normalized_model, r.purchase_price,
                   r.currency_code, r.raw_availability, r.raw_arrival_info,
                   r.detected_assembly_country, r.detected_market_region,
                   r.detected_certification_supply_type, r.status,
                   r.review_reason, r.matched_product_id,
                   r.matched_product_variant_id, r.match_id,
                   p.name AS matched_product_name,
                   pv.display_name AS matched_variant_name,
                   pv.variant_key AS matched_variant_key
            FROM supplier_import_rows r
            LEFT JOIN products p ON p.id = r.matched_product_id
            LEFT JOIN product_variants pv ON pv.id = r.matched_product_variant_id
            WHERE r.import_job_id = :job_id$filterSql
            ORDER BY r.source_row_number ASC, r.id ASC
            LIMIT $pageSize OFFSET $offset
        ");
        $rowsStmt->execute([':job_id' => $jobId]);
        require_once __DIR__ . '/supplier_availability_service.php';
        $availabilityMappings = $job['import_profile_id'] === null
            ? []
            : supplierAvailabilityLoadMappings($pdo, (int)$job['import_profile_id']);
        $normalizationProfile = ['arrival_date_format' => $job['arrival_date_format']];
        $rows = array_map(static function (array $row) use ($normalizationProfile, $availabilityMappings): array {
            foreach (['id', 'source_row_number', 'matched_product_id', 'matched_product_variant_id', 'match_id'] as $key) {
                $row[$key] = $row[$key] === null ? null : (int)$row[$key];
            }
            $reason = supplierStageDecodeReviewReason($row['review_reason']);
            unset($row['review_reason']);
            $row['errors'] = $reason['errors'];
            $row['warnings'] = $reason['warnings'];
            $row['availability_normalization'] = normalizeSupplierAvailability(
                $normalizationProfile,
                $row['raw_availability'],
                $row['raw_arrival_info'],
                null,
                $availabilityMappings
            );
            return $row;
        }, $rowsStmt->fetchAll());
        foreach (['id', 'supplier_id', 'import_profile_id', 'rows_total', 'rows_matched', 'rows_unmatched', 'rows_errors'] as $key) {
            $job[$key] = $job[$key] === null ? null : (int)$job[$key];
        }
        sendManagerJson(200, [
            'success' => true,
            'job' => $job,
            'filter' => $filter,
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'pages' => max(1, (int)ceil($total / $pageSize)),
            'rows' => $rows
        ]);
    } catch (Throwable $error) {
        error_log('supplier import job rows failed: ' . $error->getMessage());
        sendManagerJson(500, ['success' => false, 'message' => 'Не удалось загрузить строки импорта']);
    }
}

if ($action === 'supplier_import_product_search') {
    requireManagerMethod('GET');
    $query = $_GET['q'] ?? '';
    if (!is_string($query)) {
        $query = '';
    }
    $query = trim($query);
    if (mb_strlen($query, 'UTF-8') < 2 || mb_strlen($query, 'UTF-8') > 100) {
        sendManagerJson(400, ['success' => false, 'message' => 'Введите от 2 до 100 символов']);
    }
    $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query);
    try {
        $stmt = $pdo->prepare("
            SELECT id, name, series
            FROM products
            WHERE name LIKE :name_query ESCAPE '!'
               OR series LIKE :series_query ESCAPE '!'
            ORDER BY name ASC, id ASC
            LIMIT 20
        ");
        $searchPattern = '%' . $escaped . '%';
        $stmt->execute([
            ':name_query' => $searchPattern,
            ':series_query' => $searchPattern
        ]);
        $productsFound = $stmt->fetchAll();
        $productIds = array_map(static fn(array $product): int => (int)$product['id'], $productsFound);
        $variantsByProduct = [];
        if ($productIds !== []) {
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $variantStmt = $pdo->prepare("
                SELECT id, product_id, variant_key, display_name,
                       assembly_country, manufacturer_part_number
                FROM product_variants
                WHERE product_id IN ($placeholders) AND is_active = 1
                ORDER BY product_id ASC, display_name ASC, id ASC
            ");
            $variantStmt->execute($productIds);
            foreach ($variantStmt->fetchAll() as $variant) {
                $variant['id'] = (int)$variant['id'];
                $variant['product_id'] = (int)$variant['product_id'];
                $variantsByProduct[$variant['product_id']][] = $variant;
            }
        }
        $results = array_map(static function (array $product) use ($variantsByProduct): array {
            $product['id'] = (int)$product['id'];
            $product['variants'] = $variantsByProduct[$product['id']] ?? [];
            return $product;
        }, $productsFound);
        sendManagerJson(200, ['success' => true, 'results' => $results]);
    } catch (Throwable $error) {
        error_log('supplier import product search failed: ' . $error->getMessage());
        sendManagerJson(500, ['success' => false, 'message' => 'Не удалось выполнить поиск товаров']);
    }
}

if ($action === 'supplier_import_row_set_match') {
    requireManagerMethod('POST');
    if (!$requestJsonIsValid) {
        sendManagerJson(400, ['success' => false, 'message' => 'Некорректный JSON запроса']);
    }
    requireOnlyPayloadKeys($data, ['action', 'row_id', 'product_id', 'product_variant_id']);
    $rowId = requirePositiveManagerId($data['row_id'] ?? null, 'строка импорта');
    $productId = requirePositiveManagerId($data['product_id'] ?? null, 'товар');
    $variantValue = $data['product_variant_id'] ?? null;
    $variantId = ($variantValue === null || $variantValue === '')
        ? null
        : requirePositiveManagerId($variantValue, 'вариант товара');
    try {
        require_once __DIR__ . '/supplier_import_stage.php';
        $pdo->beginTransaction();
        $rowStmt = $pdo->prepare("
            SELECT r.id, r.import_job_id, r.supplier_sku, r.normalized_model,
                   r.status, j.supplier_id
            FROM supplier_import_rows r
            INNER JOIN supplier_import_jobs j ON j.id = r.import_job_id
            WHERE r.id = :id FOR UPDATE
        ");
        $rowStmt->execute([':id' => $rowId]);
        $row = $rowStmt->fetch();
        if (!is_array($row)) {
            $pdo->rollBack();
            sendManagerJson(404, ['success' => false, 'message' => 'Строка импорта не найдена']);
        }
        if ($row['status'] === 'validation_error') {
            $pdo->rollBack();
            sendManagerJson(409, ['success' => false, 'message' => 'Сначала исправьте ошибки данных строки']);
        }
        $productStmt = $pdo->prepare('SELECT id FROM products WHERE id = :id LIMIT 1');
        $productStmt->execute([':id' => $productId]);
        if (!$productStmt->fetch()) {
            $pdo->rollBack();
            sendManagerJson(404, ['success' => false, 'message' => 'Товар не найден']);
        }
        if ($variantId !== null) {
            $variantStmt = $pdo->prepare('SELECT id FROM product_variants WHERE id = :id AND product_id = :product_id LIMIT 1');
            $variantStmt->execute([':id' => $variantId, ':product_id' => $productId]);
            if (!$variantStmt->fetch()) {
                $pdo->rollBack();
                sendManagerJson(400, ['success' => false, 'message' => 'Вариант не принадлежит выбранному товару']);
            }
        }

        $matchId = null;
        $supplierSku = $row['supplier_sku'];
        if (is_string($supplierSku) && $supplierSku !== '') {
            $matchStmt = $pdo->prepare("
                SELECT id, product_id, product_variant_id
                FROM supplier_product_matches
                WHERE supplier_id = :supplier_id
                  AND BINARY supplier_sku = BINARY :supplier_sku
                LIMIT 1 FOR UPDATE
            ");
            $matchStmt->execute([
                ':supplier_id' => (int)$row['supplier_id'],
                ':supplier_sku' => $supplierSku
            ]);
            $existingMatch = $matchStmt->fetch();
            if (is_array($existingMatch)) {
                $existingProductId = $existingMatch['product_id'] === null ? null : (int)$existingMatch['product_id'];
                $existingVariantId = $existingMatch['product_variant_id'] === null ? null : (int)$existingMatch['product_variant_id'];
                if (
                    ($existingProductId !== null && $existingProductId !== $productId) ||
                    ($existingVariantId !== null && $existingVariantId !== $variantId)
                ) {
                    $pdo->rollBack();
                    sendManagerJson(409, ['success' => false, 'message' => 'Артикул поставщика уже связан с другим товаром или вариантом']);
                }
                $matchId = (int)$existingMatch['id'];
                $updateMatch = $pdo->prepare("
                    UPDATE supplier_product_matches
                    SET product_id = :product_id,
                        product_variant_id = :variant_id,
                        normalized_model = :normalized_model,
                        match_method = 'manual', confidence = 1.0000,
                        status = 'confirmed',
                        variant_confirmation_source = :variant_source,
                        reviewed_by = 'admin_session',
                        reviewed_at = CURRENT_TIMESTAMP, is_active = 1
                    WHERE id = :id
                ");
                $updateMatch->execute([
                    ':product_id' => $productId,
                    ':variant_id' => $variantId,
                    ':normalized_model' => $row['normalized_model'],
                    ':variant_source' => $variantId === null ? null : 'manual_admin',
                    ':id' => $matchId
                ]);
            } else {
                $insertMatch = $pdo->prepare("
                    INSERT INTO supplier_product_matches (
                        supplier_id, supplier_sku, normalized_model,
                        product_id, product_variant_id, match_method,
                        confidence, status, variant_confirmation_source,
                        reviewed_by, reviewed_at, is_active
                    ) VALUES (
                        :supplier_id, :supplier_sku, :normalized_model,
                        :product_id, :variant_id, 'manual', 1.0000,
                        'confirmed', :variant_source, 'admin_session',
                        CURRENT_TIMESTAMP, 1
                    )
                ");
                $insertMatch->execute([
                    ':supplier_id' => (int)$row['supplier_id'],
                    ':supplier_sku' => $supplierSku,
                    ':normalized_model' => $row['normalized_model'],
                    ':product_id' => $productId,
                    ':variant_id' => $variantId,
                    ':variant_source' => $variantId === null ? null : 'manual_admin'
                ]);
                $matchId = (int)$pdo->lastInsertId();
            }
        }

        $rowStatus = $variantId === null ? 'needs_review' : 'matched';
        $rowReason = $variantId === null
            ? supplierStageReviewReason([], ['Товар выбран, но вариант требует явного выбора'])
            : null;
        $updateRow = $pdo->prepare("
            UPDATE supplier_import_rows
            SET matched_product_id = :product_id,
                matched_product_variant_id = :variant_id,
                match_id = :match_id, status = :status,
                review_reason = :review_reason
            WHERE id = :id
        ");
        $updateRow->execute([
            ':product_id' => $productId,
            ':variant_id' => $variantId,
            ':match_id' => $matchId,
            ':status' => $rowStatus,
            ':review_reason' => $rowReason,
            ':id' => $rowId
        ]);

        $jobId = (int)$row['import_job_id'];
        $counterStmt = $pdo->prepare("
            SELECT COUNT(*) AS rows_total,
                   SUM(status = 'matched') AS rows_matched,
                   SUM(status IN ('unmatched', 'needs_review')) AS rows_unmatched,
                   SUM(status = 'validation_error') AS rows_errors
            FROM supplier_import_rows WHERE import_job_id = :job_id
        ");
        $counterStmt->execute([':job_id' => $jobId]);
        $counts = $counterStmt->fetch();
        $updateJob = $pdo->prepare("
            UPDATE supplier_import_jobs
            SET rows_total = :rows_total, rows_matched = :rows_matched,
                rows_unmatched = :rows_unmatched, rows_errors = :rows_errors
            WHERE id = :id
        ");
        $updateJob->execute([
            ':rows_total' => (int)$counts['rows_total'],
            ':rows_matched' => (int)$counts['rows_matched'],
            ':rows_unmatched' => (int)$counts['rows_unmatched'],
            ':rows_errors' => (int)$counts['rows_errors'],
            ':id' => $jobId
        ]);
        $pdo->commit();
        sendManagerJson(200, [
            'success' => true,
            'message' => $variantId === null
                ? 'Товар сохранён; выберите точный вариант'
                : 'Строка сопоставлена',
            'job_id' => $jobId
        ]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($error instanceof PDOException && (int)($error->errorInfo[1] ?? 0) === 1062) {
            sendManagerJson(409, ['success' => false, 'message' => 'Артикул поставщика уже имеет связь']);
        }
        error_log('supplier import manual match failed: ' . $error->getMessage());
        sendManagerJson(500, ['success' => false, 'message' => 'Не удалось сохранить сопоставление']);
    }
}

if ($action === 'supplier_import_row_create_product') {
    requireManagerMethod('POST');
    requirePricingRuleJsonRequest($requestJsonIsValid);
    requireOnlyPayloadKeys($data, ['action', 'row_id', 'category']);
    $rowId = requirePositiveManagerId($data['row_id'] ?? null, 'строка импорта');
    $category = $data['category'] ?? null;
    if (!is_string($category) || !in_array($category, ['OLED', 'QLED', 'LED', '8K'], true)) {
        sendManagerJson(400, ['success' => false, 'message' => 'Некорректная категория товара']);
    }
    try {
        $pdo->beginTransaction();
        $rowStmt = $pdo->prepare("
            SELECT r.id, r.import_job_id, r.supplier_sku, r.raw_product_name,
                   r.normalized_model, r.detected_assembly_country,
                   r.matched_product_id, r.matched_product_variant_id, r.match_id,
                   r.status, j.supplier_id
            FROM supplier_import_rows r
            INNER JOIN supplier_import_jobs j ON j.id = r.import_job_id
            WHERE r.id = :id FOR UPDATE
        ");
        $rowStmt->execute([':id' => $rowId]);
        $row = $rowStmt->fetch();
        if (!is_array($row)) {
            $pdo->rollBack();
            sendManagerJson(404, ['success' => false, 'message' => 'Строка импорта не найдена']);
        }
        if ($row['status'] === 'validation_error') {
            $pdo->rollBack();
            sendManagerJson(409, ['success' => false, 'message' => 'Сначала исправьте ошибки данных строки']);
        }
        if (!in_array($row['status'], ['unmatched', 'needs_review'], true) ||
            $row['matched_product_id'] !== null || $row['matched_product_variant_id'] !== null || $row['match_id'] !== null) {
            $pdo->rollBack();
            sendManagerJson(409, ['success' => false, 'message' => 'Строка уже сопоставлена или недоступна для создания карточки']);
        }
        $supplierSku = $row['supplier_sku'];
        if (!is_string($supplierSku) || trim($supplierSku) === '') {
            $pdo->rollBack();
            sendManagerJson(409, ['success' => false, 'message' => 'Для создания карточки требуется артикул поставщика']);
        }
        $rawName = is_string($row['raw_product_name']) ? trim($row['raw_product_name']) : '';
        $normalizedModel = is_string($row['normalized_model']) ? trim($row['normalized_model']) : '';
        $productName = $rawName !== '' ? $rawName : $normalizedModel;
        if ($productName === '') {
            $pdo->rollBack();
            sendManagerJson(409, ['success' => false, 'message' => 'В строке отсутствует название товара']);
        }
        $productName = mb_substr($productName, 0, 255, 'UTF-8');
        $series = mb_substr($normalizedModel, 0, 100, 'UTF-8');
        $assemblyCountry = is_string($row['detected_assembly_country'])
            ? trim($row['detected_assembly_country']) : '';
        $assemblyCountry = $assemblyCountry === '' ? null : mb_substr($assemblyCountry, 0, 100, 'UTF-8');
        $supplierId = (int)$row['supplier_id'];
        $jobId = (int)$row['import_job_id'];
        $slug = "draft-s{$supplierId}-r{$rowId}";

        $existingMatchStmt = $pdo->prepare("
            SELECT id FROM supplier_product_matches
            WHERE supplier_id = :supplier_id
              AND BINARY supplier_sku = BINARY :supplier_sku
            LIMIT 1 FOR UPDATE
        ");
        $existingMatchStmt->execute([':supplier_id' => $supplierId, ':supplier_sku' => $supplierSku]);
        if ($existingMatchStmt->fetchColumn() !== false) {
            $pdo->rollBack();
            sendManagerJson(409, ['success' => false, 'message' => 'Артикул поставщика уже имеет связь']);
        }
        $slugStmt = $pdo->prepare('SELECT id FROM products WHERE BINARY slug = BINARY :slug LIMIT 1 FOR UPDATE');
        $slugStmt->execute([':slug' => $slug]);
        if ($slugStmt->fetchColumn() !== false) {
            $pdo->rollBack();
            sendManagerJson(409, ['success' => false, 'message' => 'Черновик для этой строки уже существует']);
        }

        $productStmt = $pdo->prepare("
            INSERT INTO products (
                slug, name, series, country, category, screen_size, resolution,
                price, old_price, image, badge, rating, reviews, description,
                specs, highlights, variants, is_active
            ) VALUES (
                :slug, :name, :series, :country, :category, '', '',
                0, NULL, '', NULL, 0, 0, '', :specs, :highlights, :variants, 0
            )
        ");
        $emptyJson = json_encode([], JSON_UNESCAPED_UNICODE);
        $productStmt->execute([
            ':slug' => $slug, ':name' => $productName, ':series' => $series,
            ':country' => $assemblyCountry, ':category' => $category,
            ':specs' => $emptyJson, ':highlights' => $emptyJson, ':variants' => $emptyJson
        ]);
        $productId = (int)$pdo->lastInsertId();

        $evidence = json_encode([
            'source' => 'supplier_import', 'import_row_id' => $rowId, 'supplier_id' => $supplierId
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $variantStmt = $pdo->prepare("
            INSERT INTO product_variants (
                product_id, variant_key, assembly_country, market_region_id,
                certification_supply_type_id, manufacturer_part_number,
                display_name, classification_status, classification_evidence, is_active
            ) VALUES (
                :product_id, 'default', :assembly_country, NULL, NULL,
                :manufacturer_part_number, :display_name,
                'requires_classification', :classification_evidence, 0
            )
        ");
        $variantStmt->execute([
            ':product_id' => $productId, ':assembly_country' => $assemblyCountry,
            ':manufacturer_part_number' => $normalizedModel === '' ? null : mb_substr($normalizedModel, 0, 191, 'UTF-8'),
            ':display_name' => $assemblyCountry ?? 'Основной вариант',
            ':classification_evidence' => $evidence
        ]);
        $variantId = (int)$pdo->lastInsertId();

        $matchStmt = $pdo->prepare("
            INSERT INTO supplier_product_matches (
                supplier_id, supplier_sku, normalized_model, product_id,
                product_variant_id, match_method, confidence, status,
                variant_confirmation_source, reviewed_by, reviewed_at, is_active
            ) VALUES (
                :supplier_id, :supplier_sku, :normalized_model, :product_id,
                :variant_id, 'manual', 1.0000, 'confirmed',
                'manual_admin', 'admin_session', CURRENT_TIMESTAMP, 1
            )
        ");
        $matchStmt->execute([
            ':supplier_id' => $supplierId, ':supplier_sku' => $supplierSku,
            ':normalized_model' => $normalizedModel === '' ? null : $normalizedModel,
            ':product_id' => $productId, ':variant_id' => $variantId
        ]);
        $matchId = (int)$pdo->lastInsertId();

        $updateRow = $pdo->prepare("
            UPDATE supplier_import_rows
            SET matched_product_id = :product_id,
                matched_product_variant_id = :variant_id,
                match_id = :match_id, status = 'matched', review_reason = NULL
            WHERE id = :id
        ");
        $updateRow->execute([
            ':product_id' => $productId, ':variant_id' => $variantId,
            ':match_id' => $matchId, ':id' => $rowId
        ]);

        $counterStmt = $pdo->prepare("
            SELECT COUNT(*) AS rows_total,
                   SUM(status = 'matched') AS rows_matched,
                   SUM(status IN ('unmatched', 'needs_review')) AS rows_unmatched,
                   SUM(status = 'validation_error') AS rows_errors
            FROM supplier_import_rows WHERE import_job_id = :job_id
        ");
        $counterStmt->execute([':job_id' => $jobId]);
        $counts = $counterStmt->fetch();
        $updateJob = $pdo->prepare("
            UPDATE supplier_import_jobs
            SET rows_total = :rows_total, rows_matched = :rows_matched,
                rows_unmatched = :rows_unmatched, rows_errors = :rows_errors
            WHERE id = :id
        ");
        $updateJob->execute([
            ':rows_total' => (int)$counts['rows_total'], ':rows_matched' => (int)$counts['rows_matched'],
            ':rows_unmatched' => (int)$counts['rows_unmatched'], ':rows_errors' => (int)$counts['rows_errors'],
            ':id' => $jobId
        ]);
        $pdo->commit();
        sendManagerJson(200, [
            'success' => true, 'message' => 'Черновик товара создан',
            'product_id' => $productId, 'product_variant_id' => $variantId, 'job_id' => $jobId
        ]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException && (int)($error->errorInfo[1] ?? 0) === 1062) {
            sendManagerJson(409, ['success' => false, 'message' => 'Черновик или связь для этой строки уже существует']);
        }
        error_log('supplier import create draft product failed: ' . $error->getMessage());
        sendManagerJson(500, ['success' => false, 'message' => 'Не удалось создать черновик товара']);
    }
}

if ($action === 'supplier_import_job_offer_summary') {
    requireManagerMethod('GET');
    $jobId = requirePositiveManagerId($_GET['job_id'] ?? null, 'import job');
    try {
        require_once __DIR__ . '/supplier_offer_service.php';
        $analysis = supplierOfferPublishAnalysis($pdo, $jobId, false);
        if (!$analysis['found']) {
            sendManagerJson(404, ['success' => false, 'message' => 'Импорт не найден']);
        }
        if ($analysis['job_status'] !== 'ready_for_review') {
            sendManagerJson(409, ['success' => false, 'message' => 'Импорт ещё не готов к публикации предложений']);
        }
        sendManagerJson(200, ['success' => true] + $analysis);
    } catch (Throwable $error) {
        error_log('supplier offer publish summary failed: ' . $error->getMessage());
        sendManagerJson(500, ['success' => false, 'message' => 'Не удалось проверить предложения поставщика']);
    }
}

if ($action === 'supplier_import_job_publish_offers') {
    requireManagerMethod('POST');
    if (!$requestJsonIsValid) {
        sendManagerJson(400, ['success' => false, 'message' => 'Некорректный JSON запроса']);
    }
    requireOnlyPayloadKeys($data, ['action', 'job_id']);
    $jobId = requirePositiveManagerId($data['job_id'] ?? null, 'import job');
    try {
        require_once __DIR__ . '/supplier_offer_service.php';
        $pdo->beginTransaction();
        $analysis = supplierOfferPublishAnalysis($pdo, $jobId, true);
        if (!$analysis['found']) {
            $pdo->rollBack();
            sendManagerJson(404, ['success' => false, 'message' => 'Импорт не найден']);
        }
        if ($analysis['job_status'] !== 'ready_for_review') {
            $pdo->rollBack();
            sendManagerJson(409, ['success' => false, 'message' => 'Импорт ещё не готов к публикации предложений']);
        }
        if ((int)$analysis['summary']['eligible_rows'] === 0) {
            $pdo->rollBack();
            sendManagerJson(409, ['success' => false, 'message' => 'В импорте нет строк, готовых к публикации предложений']);
        }
        $pdo->commit();
        sendManagerJson(200, [
            'success' => true,
            'message' => 'Предложения поставщика обновлены. Цены товаров на сайте не изменены.',
            'job_id' => $jobId,
            'summary' => $analysis['summary']
        ]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('supplier offer publish failed: ' . $error->getMessage());
        sendManagerJson(500, ['success' => false, 'message' => 'Не удалось обновить предложения поставщика']);
    }
}

if ($action === 'supplier_offer_pricing_preview') {
    requireManagerMethod('GET');
    $jobId = requirePositiveManagerId($_GET['job_id'] ?? null, 'import job');
    $page = requirePositiveManagerId($_GET['page'] ?? '1', 'страница');
    $pageSize = 50;
    $offset = ($page - 1) * $pageSize;
    if ($offset > 5000000) {
        sendManagerJson(400, ['success' => false, 'message' => 'Некорректная страница']);
    }
    try {
        require_once __DIR__ . '/supplier_offer_service.php';
        $jobStmt = $pdo->prepare("
            SELECT j.id, j.supplier_id, s.name AS supplier_name
            FROM supplier_import_jobs j
            INNER JOIN suppliers s ON s.id = j.supplier_id
            WHERE j.id = :id LIMIT 1
        ");
        $jobStmt->execute([':id' => $jobId]);
        $job = $jobStmt->fetch();
        if (!is_array($job)) {
            sendManagerJson(404, ['success' => false, 'message' => 'Импорт не найден']);
        }
        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM supplier_offers o
            INNER JOIN supplier_import_rows r ON r.id = o.source_import_row_id
            WHERE r.import_job_id = :job_id
        ");
        $countStmt->execute([':job_id' => $jobId]);
        $total = (int)$countStmt->fetchColumn();
        $offersStmt = $pdo->prepare("
            SELECT o.id, o.supplier_id, o.product_variant_id, o.supplier_sku,
                   o.supplier_product_name, o.purchase_price, o.currency_code,
                   o.availability_status, o.stock_quantity, o.expected_arrival_at,
                   o.delivery_info, r.raw_availability, r.raw_arrival_info,
                   r.import_job_id AS source_import_job_id,
                   o.source_import_row_id, o.imported_at, o.is_active,
                   s.name AS supplier_name, p.id AS product_id,
                   p.name AS product_name, p.category,
                   pv.variant_key, pv.display_name AS variant_name
            FROM supplier_offers o
            INNER JOIN suppliers s ON s.id = o.supplier_id
            INNER JOIN product_variants pv ON pv.id = o.product_variant_id
            INNER JOIN products p ON p.id = pv.product_id
            INNER JOIN supplier_import_rows r ON r.id = o.source_import_row_id
            WHERE r.import_job_id = :job_id
            ORDER BY o.id ASC LIMIT $pageSize OFFSET $offset
        ");
        $offersStmt->execute([':job_id' => $jobId]);
        $rulesStmt = $pdo->prepare("
            SELECT id, name, priority, category_scope, purchase_price_min,
                   purchase_price_max, markup_percent, minimum_margin,
                   rounding_strategy, rounding_parameters
            FROM pricing_rules
            WHERE is_active = 1
              AND (valid_from IS NULL OR valid_from <= CURRENT_TIMESTAMP)
              AND (valid_until IS NULL OR valid_until >= CURRENT_TIMESTAMP)
              AND additional_scope IS NULL
            ORDER BY priority ASC, (category_scope IS NOT NULL) DESC, id ASC
            LIMIT 200
        ");
        $rulesStmt->execute();
        $activeRules = $rulesStmt->fetchAll();
        $offers = [];
        foreach ($offersStmt->fetchAll() as $offer) {
            $applicableRules = supplierPricingApplicableRules($offer, $activeRules);
            $calculation = supplierPricingCalculate($offer, $applicableRules);
            foreach (['id', 'supplier_id', 'product_variant_id', 'source_import_row_id', 'source_import_job_id', 'product_id'] as $key) {
                $offer[$key] = (int)$offer[$key];
            }
            $offer['stock_quantity'] = $offer['stock_quantity'] === null ? null : (int)$offer['stock_quantity'];
            $offer['is_active'] = (bool)$offer['is_active'];
            $offer['pricing'] = $calculation;
            $offers[] = $offer;
        }
        sendManagerJson(200, [
            'success' => true,
            'job_id' => $jobId,
            'page' => $page,
            'page_size' => $pageSize,
            'pages' => max(1, (int)ceil($total / $pageSize)),
            'total' => $total,
            'offers' => $offers
        ]);
    } catch (Throwable $error) {
        error_log('supplier offer pricing preview failed: ' . $error->getMessage());
        sendManagerJson(500, ['success' => false, 'message' => 'Не удалось рассчитать предварительные цены']);
    }
}

if ($action === 'supplier_offer_price_publish_preview') {
    requireManagerMethod('GET');
    $offerId = requirePositiveManagerId($_GET['supplier_offer_id'] ?? null, 'supplier offer');
    try {
        require_once __DIR__ . '/price_publication_service.php';
        $context = pricePublicationContext($pdo, $offerId, false);
        sendManagerJson(200, ['success' => true, 'preview' => pricePublicationPublicResult($context)]);
    } catch (PricePublicationException $error) {
        sendManagerJson($error->httpStatus, ['success' => false, 'message' => $error->getMessage()]);
    } catch (Throwable $error) {
        error_log('price publication preview failed: ' . $error->getMessage());
        sendManagerJson(500, ['success' => false, 'message' => 'Не удалось подготовить проверку изменения цены']);
    }
}

if ($action === 'supplier_offer_price_publish') {
    requireManagerMethod('POST');
    requirePricingRuleJsonRequest($requestJsonIsValid);
    requireOnlyPayloadKeys($data, ['action', 'supplier_offer_id', 'snapshot_token', 'confirm', 'comment']);
    $offerId = requirePositiveManagerId($data['supplier_offer_id'] ?? null, 'supplier offer');
    $snapshotToken = $data['snapshot_token'] ?? null;
    if (!is_string($snapshotToken) || preg_match('/\A[a-f0-9]{64}\z/D', $snapshotToken) !== 1) {
        sendManagerJson(400, ['success' => false, 'message' => 'Некорректный token проверки цены']);
    }
    if (($data['confirm'] ?? null) !== true) {
        sendManagerJson(400, ['success' => false, 'message' => 'Требуется явное подтверждение публикации цены']);
    }
    $commentValue = $data['comment'] ?? null;
    if ($commentValue !== null && !is_string($commentValue)) {
        sendManagerJson(400, ['success' => false, 'message' => 'Некорректный комментарий']);
    }
    $comment = is_string($commentValue) ? trim($commentValue) : null;
    $comment = $comment === '' ? null : $comment;
    $commentControlMatch = $comment === null ? 0 : preg_match('/[\x00-\x1F\x7F]/u', $comment);
    if ($comment !== null && (pricingRuleStringLength($comment) > 500 || $commentControlMatch !== 0)) {
        sendManagerJson(400, ['success' => false, 'message' => 'Комментарий не должен превышать 500 символов или содержать управляющие знаки']);
    }
    try {
        require_once __DIR__ . '/price_publication_service.php';
        $result = pricePublicationPublish($pdo, $offerId, $snapshotToken, $comment);
        sendManagerJson(200, [
            'success' => true,
            'message' => $result['status'] === 'already_current'
                ? 'Цена уже актуальна. Новая audit-запись не создана.'
                : 'Цена опубликована и записана в историю.',
            'result' => $result
        ]);
    } catch (PricePublicationException $error) {
        sendManagerJson($error->httpStatus, ['success' => false, 'message' => $error->getMessage()]);
    } catch (Throwable $error) {
        error_log('price publication failed: ' . $error->getMessage());
        sendManagerJson(500, ['success' => false, 'message' => 'Не удалось опубликовать цену']);
    }
}

if ($action === 'price_publication_history') {
    requireManagerMethod('GET');
    $page = requirePositiveManagerId($_GET['page'] ?? '1', 'страница');
    try {
        require_once __DIR__ . '/price_publication_service.php';
        sendManagerJson(200, ['success' => true] + pricePublicationHistory($pdo, $page, 20));
    } catch (PricePublicationException $error) {
        sendManagerJson($error->httpStatus, ['success' => false, 'message' => $error->getMessage()]);
    } catch (Throwable $error) {
        error_log('price publication history failed: ' . $error->getMessage());
        sendManagerJson(500, ['success' => false, 'message' => 'Не удалось загрузить историю публикаций цен']);
    }
}

if ($action === 'pricing_rules_list') {
    requireManagerMethod('GET');
    try {
        $rulesStmt = $pdo->query("
            SELECT id, name, priority, category_scope, purchase_price_min,
                   purchase_price_max, markup_percent, minimum_margin,
                   rounding_strategy, rounding_parameters, additional_scope,
                   valid_from, valid_until, is_active, created_at, updated_at
            FROM pricing_rules
            ORDER BY priority ASC, id ASC
            LIMIT 501
        ");
        $categoryStmt = $pdo->query("
            SELECT DISTINCT category
            FROM products
            WHERE category IS NOT NULL AND category <> ''
            ORDER BY category ASC
            LIMIT 100
        ");
        $ruleRows = $rulesStmt->fetchAll();
        $rulesTruncated = count($ruleRows) > 500;
        if ($rulesTruncated) {
            $ruleRows = array_slice($ruleRows, 0, 500);
        }
        sendManagerJson(200, [
            'success' => true,
            'rules' => array_map('preparePricingRuleForResponse', $ruleRows),
            'categories' => array_values(array_map(
                static fn(array $row): string => (string)$row['category'],
                $categoryStmt->fetchAll()
            )),
            'limit' => 500,
            'truncated' => $rulesTruncated
        ]);
    } catch (Throwable $error) {
        error_log('pricing rules list failed: ' . $error->getMessage());
        sendManagerJson(500, ['success' => false, 'message' => 'Не удалось загрузить правила ценообразования']);
    }
}

if ($action === 'pricing_rule_create') {
    requireManagerMethod('POST');
    requirePricingRuleJsonRequest($requestJsonIsValid);
    requireOnlyPayloadKeys($data, [
        'action', 'name', 'priority', 'category_scope', 'purchase_price_min',
        'purchase_price_max', 'markup_percent', 'minimum_margin', 'valid_from',
        'valid_until', 'is_active'
    ]);
    $input = validatePricingRuleInput($data);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO pricing_rules
                (name, priority, category_scope, purchase_price_min,
                 purchase_price_max, markup_percent, minimum_margin,
                 rounding_strategy, rounding_parameters, additional_scope,
                 valid_from, valid_until, is_active)
            VALUES
                (:name, :priority, :category_scope, :purchase_price_min,
                 :purchase_price_max, :markup_percent, :minimum_margin,
                 'none', NULL, NULL, :valid_from, :valid_until, :is_active)
        ");
        $stmt->execute([
            ':name' => $input['name'],
            ':priority' => $input['priority'],
            ':category_scope' => $input['category_scope'],
            ':purchase_price_min' => $input['purchase_price_min'],
            ':purchase_price_max' => $input['purchase_price_max'],
            ':markup_percent' => $input['markup_percent'],
            ':minimum_margin' => $input['minimum_margin'],
            ':valid_from' => $input['valid_from'],
            ':valid_until' => $input['valid_until'],
            ':is_active' => $input['is_active']
        ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        sendManagerJson(201, ['success' => true, 'id' => $id, 'message' => 'Правило создано']);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (isPricingRuleNameDuplicate($error)) {
            sendManagerJson(409, ['success' => false, 'message' => 'Правило с таким названием уже существует']);
        }
        error_log('pricing rule create failed: ' . $error->getMessage());
        sendManagerJson(500, ['success' => false, 'message' => 'Не удалось создать правило']);
    }
}

if ($action === 'pricing_rule_update') {
    requireManagerMethod('POST');
    requirePricingRuleJsonRequest($requestJsonIsValid);
    requireOnlyPayloadKeys($data, [
        'action', 'id', 'updated_at', 'name', 'priority', 'category_scope',
        'purchase_price_min', 'purchase_price_max', 'markup_percent',
        'minimum_margin', 'valid_from', 'valid_until', 'is_active'
    ]);
    $id = requirePositiveManagerId($data['id'] ?? null, 'правило');
    $expectedUpdatedAt = $data['updated_at'] ?? null;
    if (!is_string($expectedUpdatedAt) || $expectedUpdatedAt === '') {
        sendManagerJson(400, ['success' => false, 'message' => 'Не указана версия правила']);
    }
    $input = validatePricingRuleInput($data);
    try {
        $pdo->beginTransaction();
        $lockStmt = $pdo->prepare("
            SELECT id, updated_at, rounding_strategy, rounding_parameters, additional_scope
            FROM pricing_rules WHERE id = :id FOR UPDATE
        ");
        $lockStmt->execute([':id' => $id]);
        $existing = $lockStmt->fetch();
        if (!is_array($existing)) {
            $pdo->rollBack();
            sendManagerJson(404, ['success' => false, 'message' => 'Правило не найдено']);
        }
        if (!hash_equals((string)$existing['updated_at'], $expectedUpdatedAt)) {
            $pdo->rollBack();
            sendManagerJson(409, ['success' => false, 'message' => 'Правило уже изменено. Обновите список и повторите действие']);
        }
        if (!pricingRuleIsSupported($existing)) {
            $pdo->rollBack();
            sendManagerJson(409, ['success' => false, 'message' => 'Это правило содержит настройки, которые Stage 8 не редактирует']);
        }
        $stmt = $pdo->prepare("
            UPDATE pricing_rules SET
                name = :name, priority = :priority, category_scope = :category_scope,
                purchase_price_min = :purchase_price_min,
                purchase_price_max = :purchase_price_max,
                markup_percent = :markup_percent, minimum_margin = :minimum_margin,
                rounding_strategy = 'none', rounding_parameters = NULL,
                additional_scope = NULL, valid_from = :valid_from,
                valid_until = :valid_until, is_active = :is_active
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':name' => $input['name'],
            ':priority' => $input['priority'],
            ':category_scope' => $input['category_scope'],
            ':purchase_price_min' => $input['purchase_price_min'],
            ':purchase_price_max' => $input['purchase_price_max'],
            ':markup_percent' => $input['markup_percent'],
            ':minimum_margin' => $input['minimum_margin'],
            ':valid_from' => $input['valid_from'],
            ':valid_until' => $input['valid_until'],
            ':is_active' => $input['is_active']
        ]);
        $pdo->commit();
        sendManagerJson(200, ['success' => true, 'message' => 'Правило обновлено']);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (isPricingRuleNameDuplicate($error)) {
            sendManagerJson(409, ['success' => false, 'message' => 'Правило с таким названием уже существует']);
        }
        error_log('pricing rule update failed: ' . $error->getMessage());
        sendManagerJson(500, ['success' => false, 'message' => 'Не удалось обновить правило']);
    }
}

if ($action === 'pricing_rule_set_active') {
    requireManagerMethod('POST');
    requirePricingRuleJsonRequest($requestJsonIsValid);
    requireOnlyPayloadKeys($data, ['action', 'id', 'updated_at', 'is_active']);
    $id = requirePositiveManagerId($data['id'] ?? null, 'правило');
    $expectedUpdatedAt = $data['updated_at'] ?? null;
    if (!is_string($expectedUpdatedAt) || $expectedUpdatedAt === '' || !is_bool($data['is_active'] ?? null)) {
        sendManagerJson(400, ['success' => false, 'message' => 'Некорректные данные статуса правила']);
    }
    try {
        $pdo->beginTransaction();
        $lockStmt = $pdo->prepare("
            SELECT id, updated_at, rounding_strategy, rounding_parameters, additional_scope
            FROM pricing_rules WHERE id = :id FOR UPDATE
        ");
        $lockStmt->execute([':id' => $id]);
        $existing = $lockStmt->fetch();
        if (!is_array($existing)) {
            $pdo->rollBack();
            sendManagerJson(404, ['success' => false, 'message' => 'Правило не найдено']);
        }
        if (!hash_equals((string)$existing['updated_at'], $expectedUpdatedAt)) {
            $pdo->rollBack();
            sendManagerJson(409, ['success' => false, 'message' => 'Правило уже изменено. Обновите список и повторите действие']);
        }
        if ($data['is_active'] && !pricingRuleIsSupported($existing)) {
            $pdo->rollBack();
            sendManagerJson(409, ['success' => false, 'message' => 'Неподдерживаемое правило нельзя активировать через Stage 8']);
        }
        $stmt = $pdo->prepare('UPDATE pricing_rules SET is_active = :is_active WHERE id = :id');
        $stmt->execute([':id' => $id, ':is_active' => $data['is_active'] ? 1 : 0]);
        $pdo->commit();
        sendManagerJson(200, ['success' => true, 'message' => $data['is_active'] ? 'Правило активировано' : 'Правило деактивировано']);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('pricing rule status update failed: ' . $error->getMessage());
        sendManagerJson(500, ['success' => false, 'message' => 'Не удалось изменить статус правила']);
    }
}

if ($action === 'supplier_availability_mappings_list') {
    requireManagerMethod('GET');
    $profileId = requirePositiveManagerId($_GET['profile_id'] ?? null, 'профиль импорта');
    try {
        require_once __DIR__ . '/supplier_availability_service.php';
        $profileStmt = $pdo->prepare('SELECT id, supplier_id, name, arrival_date_format FROM supplier_import_profiles WHERE id = :id LIMIT 1');
        $profileStmt->execute([':id' => $profileId]);
        $profile = $profileStmt->fetch();
        if (!is_array($profile)) {
            sendManagerJson(404, ['success' => false, 'message' => 'Профиль импорта не найден']);
        }
        $mappings = array_map('prepareSupplierAvailabilityMappingForResponse', supplierAvailabilityLoadMappings($pdo, $profileId));
        sendManagerJson(200, [
            'success' => true,
            'profile' => [
                'id' => (int)$profile['id'],
                'supplier_id' => (int)$profile['supplier_id'],
                'name' => (string)$profile['name'],
                'arrival_date_format' => $profile['arrival_date_format']
            ],
            'mappings' => $mappings
        ]);
    } catch (Throwable $error) {
        error_log('supplier availability mappings list failed: ' . $error->getMessage());
        sendManagerJson(500, ['success' => false, 'message' => 'Не удалось загрузить правила наличия']);
    }
}

if ($action === 'supplier_availability_mapping_create') {
    requireManagerMethod('POST');
    requirePricingRuleJsonRequest($requestJsonIsValid);
    requireOnlyPayloadKeys($data, ['action', 'profile_id', 'raw_value', 'normalized_status', 'is_active']);
    $profileId = requirePositiveManagerId($data['profile_id'] ?? null, 'профиль импорта');
    if (!is_bool($data['is_active'] ?? null)) {
        sendManagerJson(400, ['success' => false, 'message' => 'Некорректный статус правила наличия']);
    }
    try {
        require_once __DIR__ . '/supplier_availability_service.php';
        $rawValue = supplierAvailabilityValidateRawMapping($data['raw_value'] ?? null);
        $status = supplierAvailabilityValidateStatus($data['normalized_status'] ?? null);
        $pdo->beginTransaction();
        $profileStmt = $pdo->prepare('SELECT id FROM supplier_import_profiles WHERE id = :id FOR UPDATE');
        $profileStmt->execute([':id' => $profileId]);
        if (!$profileStmt->fetch()) {
            $pdo->rollBack();
            sendManagerJson(404, ['success' => false, 'message' => 'Профиль импорта не найден']);
        }
        if (count(supplierAvailabilityLoadMappings($pdo, $profileId, true)) >= SUPPLIER_AVAILABILITY_MAPPING_LIMIT) {
            $pdo->rollBack();
            sendManagerJson(409, ['success' => false, 'message' => 'Достигнут лимит правил наличия для профиля']);
        }
        $stmt = $pdo->prepare("INSERT INTO supplier_availability_mappings
            (import_profile_id, raw_value, raw_value_hash, collation_weight_hash, normalized_status, is_active)
            VALUES (:profile_id, :raw_value, :raw_hash, :collation_hash, :normalized_status, :is_active)");
        $stmt->execute([
            ':profile_id' => $profileId,
            ':raw_value' => $rawValue,
            ':raw_hash' => hash('sha256', $rawValue),
            ':collation_hash' => supplierAvailabilityCollationHash($pdo, $rawValue),
            ':normalized_status' => $status,
            ':is_active' => $data['is_active'] ? 1 : 0
        ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        sendManagerJson(201, ['success' => true, 'message' => 'Правило наличия создано', 'mapping_id' => $id]);
    } catch (InvalidArgumentException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendManagerJson(400, ['success' => false, 'message' => $error->getMessage()]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException && (int)($error->errorInfo[1] ?? 0) === 1062) {
            sendManagerJson(409, ['success' => false, 'message' => 'Такое или эквивалентное по collation значение уже настроено']);
        }
        error_log('supplier availability mapping create failed: ' . $error->getMessage());
        sendManagerJson(500, ['success' => false, 'message' => 'Не удалось создать правило наличия']);
    }
}

if ($action === 'supplier_availability_mapping_update') {
    requireManagerMethod('POST');
    requirePricingRuleJsonRequest($requestJsonIsValid);
    requireOnlyPayloadKeys($data, ['action', 'id', 'updated_at', 'raw_value', 'normalized_status', 'is_active']);
    $id = requirePositiveManagerId($data['id'] ?? null, 'правило наличия');
    $expectedUpdatedAt = $data['updated_at'] ?? null;
    if (!is_string($expectedUpdatedAt) || $expectedUpdatedAt === '' || !is_bool($data['is_active'] ?? null)) {
        sendManagerJson(400, ['success' => false, 'message' => 'Некорректные данные правила наличия']);
    }
    try {
        require_once __DIR__ . '/supplier_availability_service.php';
        $rawValue = supplierAvailabilityValidateRawMapping($data['raw_value'] ?? null);
        $status = supplierAvailabilityValidateStatus($data['normalized_status'] ?? null);
        $pdo->beginTransaction();
        $lockStmt = $pdo->prepare('SELECT id, updated_at FROM supplier_availability_mappings WHERE id = :id FOR UPDATE');
        $lockStmt->execute([':id' => $id]);
        $existing = $lockStmt->fetch();
        if (!is_array($existing)) {
            $pdo->rollBack();
            sendManagerJson(404, ['success' => false, 'message' => 'Правило наличия не найдено']);
        }
        if (!hash_equals((string)$existing['updated_at'], $expectedUpdatedAt)) {
            $pdo->rollBack();
            sendManagerJson(409, ['success' => false, 'message' => 'Правило уже изменено. Обновите список']);
        }
        $stmt = $pdo->prepare("UPDATE supplier_availability_mappings SET
            raw_value = :raw_value, raw_value_hash = :raw_hash,
            collation_weight_hash = :collation_hash, normalized_status = :normalized_status,
            is_active = :is_active WHERE id = :id");
        $stmt->execute([
            ':id' => $id,
            ':raw_value' => $rawValue,
            ':raw_hash' => hash('sha256', $rawValue),
            ':collation_hash' => supplierAvailabilityCollationHash($pdo, $rawValue),
            ':normalized_status' => $status,
            ':is_active' => $data['is_active'] ? 1 : 0
        ]);
        $pdo->commit();
        sendManagerJson(200, ['success' => true, 'message' => 'Правило наличия обновлено']);
    } catch (InvalidArgumentException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendManagerJson(400, ['success' => false, 'message' => $error->getMessage()]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException && (int)($error->errorInfo[1] ?? 0) === 1062) {
            sendManagerJson(409, ['success' => false, 'message' => 'Такое или эквивалентное по collation значение уже настроено']);
        }
        error_log('supplier availability mapping update failed: ' . $error->getMessage());
        sendManagerJson(500, ['success' => false, 'message' => 'Не удалось обновить правило наличия']);
    }
}

if ($action === 'supplier_availability_mapping_set_active') {
    requireManagerMethod('POST');
    requirePricingRuleJsonRequest($requestJsonIsValid);
    requireOnlyPayloadKeys($data, ['action', 'id', 'updated_at', 'is_active']);
    $id = requirePositiveManagerId($data['id'] ?? null, 'правило наличия');
    $expectedUpdatedAt = $data['updated_at'] ?? null;
    if (!is_string($expectedUpdatedAt) || $expectedUpdatedAt === '' || !is_bool($data['is_active'] ?? null)) {
        sendManagerJson(400, ['success' => false, 'message' => 'Некорректные данные статуса правила']);
    }
    try {
        $pdo->beginTransaction();
        $lockStmt = $pdo->prepare('SELECT id, updated_at FROM supplier_availability_mappings WHERE id = :id FOR UPDATE');
        $lockStmt->execute([':id' => $id]);
        $existing = $lockStmt->fetch();
        if (!is_array($existing)) {
            $pdo->rollBack();
            sendManagerJson(404, ['success' => false, 'message' => 'Правило наличия не найдено']);
        }
        if (!hash_equals((string)$existing['updated_at'], $expectedUpdatedAt)) {
            $pdo->rollBack();
            sendManagerJson(409, ['success' => false, 'message' => 'Правило уже изменено. Обновите список']);
        }
        $stmt = $pdo->prepare('UPDATE supplier_availability_mappings SET is_active = :is_active WHERE id = :id');
        $stmt->execute([':id' => $id, ':is_active' => $data['is_active'] ? 1 : 0]);
        $pdo->commit();
        sendManagerJson(200, ['success' => true, 'message' => 'Статус правила наличия изменён']);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('supplier availability mapping status failed: ' . $error->getMessage());
        sendManagerJson(500, ['success' => false, 'message' => 'Не удалось изменить статус правила наличия']);
    }
}

if ($action === 'supplier_import_profiles_list') {
    requireManagerMethod('GET');
    $supplierId = requireSupplierImportProfileSupplierId([
        'supplier_id' => $_GET['supplier_id'] ?? null
    ]);

    try {
        requireSupplierExists($pdo, $supplierId);

        $stmt = $pdo->prepare("
            SELECT id, supplier_id, name, sheet_name, header_row_number,
                   sku_column, product_name_column, purchase_price_column,
                   stock_column, arrival_column, variant_region_column,
                   column_mapping, parser_options, arrival_date_format, is_active,
                   created_at, updated_at
            FROM supplier_import_profiles
            WHERE supplier_id = :supplier_id
            ORDER BY name ASC, id ASC
        ");
        $stmt->execute([':supplier_id' => $supplierId]);

        $profiles = array_map(
            'prepareSupplierImportProfileForResponse',
            $stmt->fetchAll()
        );

        sendManagerJson(200, [
            'success' => true,
            'count' => count($profiles),
            'profiles' => $profiles
        ]);
    } catch (Throwable $error) {
        sendManagerJson(500, [
            'success' => false,
            'message' => 'Не удалось загрузить профили импорта'
        ]);
    }
}

if ($action === 'supplier_import_profile_create') {
    requireManagerMethod('POST');
    requireSupplierImportProfileJsonRequest($requestJsonIsValid, $rawRequestBody);
    $supplierId = requireSupplierImportProfileSupplierId($data);
    $profile = validateSupplierImportProfileInput($data);

    try {
        requireSupplierExists($pdo, $supplierId);

        $stmt = $pdo->prepare("
            INSERT INTO supplier_import_profiles (
                supplier_id, name, sheet_name, header_row_number,
                sku_column, product_name_column, purchase_price_column,
                stock_column, arrival_column, variant_region_column,
                column_mapping, parser_options, arrival_date_format, is_active
            ) VALUES (
                :supplier_id, :name, :sheet_name, :header_row_number,
                :sku_column, :product_name_column, :purchase_price_column,
                :stock_column, :arrival_column, :variant_region_column,
                :column_mapping, :parser_options, :arrival_date_format, :is_active
            )
        ");
        $stmt->execute([
            ':supplier_id' => $supplierId,
            ':name' => $profile['name'],
            ':sheet_name' => $profile['sheet_name'],
            ':header_row_number' => $profile['header_row_number'],
            ':sku_column' => $profile['sku_column'],
            ':product_name_column' => $profile['product_name_column'],
            ':purchase_price_column' => $profile['purchase_price_column'],
            ':stock_column' => $profile['stock_column'],
            ':arrival_column' => $profile['arrival_column'],
            ':variant_region_column' => $profile['variant_region_column'],
            ':column_mapping' => json_encode($profile['column_mapping'], JSON_UNESCAPED_UNICODE),
            ':parser_options' => json_encode($profile['parser_options'], JSON_UNESCAPED_UNICODE),
            ':arrival_date_format' => $profile['arrival_date_format'],
            ':is_active' => $profile['is_active']
        ]);

        sendManagerJson(201, [
            'success' => true,
            'message' => 'Профиль импорта создан',
            'profile_id' => (int)$pdo->lastInsertId()
        ]);
    } catch (Throwable $error) {
        if (isSupplierCodeDuplicate($error)) {
            sendManagerJson(409, [
                'success' => false,
                'message' => 'Профиль с таким названием уже существует у поставщика'
            ]);
        }

        sendManagerJson(500, [
            'success' => false,
            'message' => 'Не удалось создать профиль импорта'
        ]);
    }
}

if ($action === 'supplier_import_profile_update') {
    requireManagerMethod('POST');
    requireSupplierImportProfileJsonRequest($requestJsonIsValid, $rawRequestBody);
    $profileId = requireSupplierImportProfileId($data);
    $supplierId = requireSupplierImportProfileSupplierId($data);
    $profile = validateSupplierImportProfileInput($data);

    try {
        requireSupplierExists($pdo, $supplierId);

        $stmt = $pdo->prepare("
            UPDATE supplier_import_profiles
            SET name = :name,
                sheet_name = :sheet_name,
                header_row_number = :header_row_number,
                sku_column = :sku_column,
                product_name_column = :product_name_column,
                purchase_price_column = :purchase_price_column,
                stock_column = :stock_column,
                arrival_column = :arrival_column,
                variant_region_column = :variant_region_column,
                column_mapping = :column_mapping,
                parser_options = :parser_options,
                arrival_date_format = CASE
                    WHEN :arrival_date_format_provided = 1 THEN :arrival_date_format
                    ELSE arrival_date_format
                END,
                is_active = :is_active
            WHERE id = :id AND supplier_id = :supplier_id
        ");
        $stmt->execute([
            ':name' => $profile['name'],
            ':sheet_name' => $profile['sheet_name'],
            ':header_row_number' => $profile['header_row_number'],
            ':sku_column' => $profile['sku_column'],
            ':product_name_column' => $profile['product_name_column'],
            ':purchase_price_column' => $profile['purchase_price_column'],
            ':stock_column' => $profile['stock_column'],
            ':arrival_column' => $profile['arrival_column'],
            ':variant_region_column' => $profile['variant_region_column'],
            ':column_mapping' => json_encode($profile['column_mapping'], JSON_UNESCAPED_UNICODE),
            ':parser_options' => json_encode($profile['parser_options'], JSON_UNESCAPED_UNICODE),
            ':arrival_date_format' => $profile['arrival_date_format'],
            ':arrival_date_format_provided' => $profile['arrival_date_format_provided'] ? 1 : 0,
            ':is_active' => $profile['is_active'],
            ':id' => $profileId,
            ':supplier_id' => $supplierId
        ]);

        if ($stmt->rowCount() === 0) {
            $existsStmt = $pdo->prepare("
                SELECT id
                FROM supplier_import_profiles
                WHERE id = :id AND supplier_id = :supplier_id
            ");
            $existsStmt->execute([
                ':id' => $profileId,
                ':supplier_id' => $supplierId
            ]);

            if (!$existsStmt->fetch()) {
                sendManagerJson(404, [
                    'success' => false,
                    'message' => 'Профиль импорта не найден'
                ]);
            }
        }

        sendManagerJson(200, [
            'success' => true,
            'message' => 'Профиль импорта обновлён'
        ]);
    } catch (Throwable $error) {
        if (isSupplierCodeDuplicate($error)) {
            sendManagerJson(409, [
                'success' => false,
                'message' => 'Профиль с таким названием уже существует у поставщика'
            ]);
        }

        sendManagerJson(500, [
            'success' => false,
            'message' => 'Не удалось обновить профиль импорта'
        ]);
    }
}

if ($action === 'supplier_import_profile_set_active') {
    requireManagerMethod('POST');
    requireSupplierImportProfileJsonRequest($requestJsonIsValid, $rawRequestBody);
    requireOnlyPayloadKeys($data, [
        'action', 'id', 'supplier_id', 'is_active'
    ]);
    $profileId = requireSupplierImportProfileId($data);
    $supplierId = requireSupplierImportProfileSupplierId($data);
    $isActive = requireSupplierActiveValue($data);

    try {
        requireSupplierExists($pdo, $supplierId);

        $stmt = $pdo->prepare("
            UPDATE supplier_import_profiles
            SET is_active = :is_active
            WHERE id = :id AND supplier_id = :supplier_id
        ");
        $stmt->execute([
            ':is_active' => $isActive,
            ':id' => $profileId,
            ':supplier_id' => $supplierId
        ]);

        if ($stmt->rowCount() === 0) {
            $existsStmt = $pdo->prepare("
                SELECT id
                FROM supplier_import_profiles
                WHERE id = :id AND supplier_id = :supplier_id
            ");
            $existsStmt->execute([
                ':id' => $profileId,
                ':supplier_id' => $supplierId
            ]);

            if (!$existsStmt->fetch()) {
                sendManagerJson(404, [
                    'success' => false,
                    'message' => 'Профиль импорта не найден'
                ]);
            }
        }

        sendManagerJson(200, [
            'success' => true,
            'message' => $isActive
                ? 'Профиль импорта включён'
                : 'Профиль импорта отключён'
        ]);
    } catch (Throwable $error) {
        sendManagerJson(500, [
            'success' => false,
            'message' => 'Не удалось изменить статус профиля импорта'
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

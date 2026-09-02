<?php

ini_set('display_errors', '0');
ini_set('log_errors', '1');

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

if (
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

$inputStream = @fopen('php://input', 'rb');
$rawRequestBody = $inputStream === false
    ? false
    : @stream_get_contents($inputStream, $maxManagerRequestBytes + 1);

if (is_resource($inputStream)) {
    fclose($inputStream);
}

if ($rawRequestBody === false || strlen($rawRequestBody) > $maxManagerRequestBytes) {
    http_response_code($rawRequestBody === false ? 400 : 413);
    echo json_encode([
        'success' => false,
        'message' => $rawRequestBody === false
            ? 'Не удалось прочитать данные запроса'
            : 'Данные запроса слишком велики'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = $rawRequestBody === '' ? [] : json_decode($rawRequestBody, true);
$requestJsonIsValid = is_array($data) && json_last_error() === JSON_ERROR_NONE;

if (!is_array($data)) {
    $data = [];
}

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
    'supplier_set_active',
    'supplier_import_profile_create',
    'supplier_import_profile_update',
    'supplier_import_profile_set_active'
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
        'is_active' => (bool)$profile['is_active'],
        'created_at' => (string)$profile['created_at'],
        'updated_at' => (string)$profile['updated_at']
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
                   column_mapping, parser_options, is_active,
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
                column_mapping, parser_options, is_active
            ) VALUES (
                :supplier_id, :name, :sheet_name, :header_row_number,
                :sku_column, :product_name_column, :purchase_price_column,
                :stock_column, :arrival_column, :variant_region_column,
                :column_mapping, :parser_options, :is_active
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

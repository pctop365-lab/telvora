<?php

header('Content-Type: application/json; charset=utf-8');

function telvoraApiSecretsFile(): string
{
    return dirname(__DIR__, 2) .
        '/telvora_runtime/telvora_secrets.php';
}

function loadTelvoraApiSecrets(): array
{
    static $secrets = null;

    if (is_array($secrets)) {
        return $secrets;
    }

    $file = telvoraApiSecretsFile();

    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException('Private configuration is unavailable.');
    }

    try {
        $loaded = @require $file;
    } catch (Throwable $e) {
        throw new RuntimeException('Private configuration is unavailable.');
    }

    if (!is_array($loaded)) {
        throw new RuntimeException('Private configuration is unavailable.');
    }

    $secrets = $loaded;

    return $secrets;
}

function requireTelvoraApiSecret(string $key): string
{
    $secrets = loadTelvoraApiSecrets();

    if (
        !array_key_exists($key, $secrets) ||
        !is_string($secrets[$key]) ||
        trim($secrets[$key]) === ''
    ) {
        throw new RuntimeException('Private configuration is unavailable.');
    }

    return $secrets[$key];
}

class OrderValidationException extends RuntimeException
{
}

const TELVORA_FREE_DELIVERY_THRESHOLD = 50000.0;
const TELVORA_DELIVERY_FEE = 1990.0;
const TELVORA_MAX_ITEM_QUANTITY = 100;

try {
    $telegramBotToken =
        requireTelvoraApiSecret('telegram_bot_token');
    $telegramChatId =
        requireTelvoraApiSecret('telegram_chat_id');
    $dbHost = requireTelvoraApiSecret('db_host');
    $dbName = requireTelvoraApiSecret('db_name');
    $dbUser = requireTelvoraApiSecret('db_user');
    $dbPass = requireTelvoraApiSecret('db_password');
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Сервис временно недоступен'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// Telegram

function sendTelegramMessage(
    string $botToken,
    string $chatId,
    string $message
): void {
    $url = 'https://api.telegram.org/bot' . $botToken . '/sendMessage';

    $postData = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML',
    ];

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);

    curl_exec($ch);
    curl_close($ch);
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$allowedOrigins = [
    'https://telvora.ru',
];

if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Метод не поддерживается'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

function apiOrderRateLimitFile(): string
{
    return dirname(__DIR__, 2) . '/telvora_runtime/api_order_rate_limit.json';
}

function consumeApiOrderRateLimit(): array
{
    $now = time();
    $window = 600;
    $key = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');

    $h = @fopen(apiOrderRateLimitFile(), 'r+');
    if ($h === false) {
        return ['status' => 'state_error'];
    }

    try {
        if (!flock($h, LOCK_EX) || !rewind($h)) {
            return ['status' => 'state_error'];
        }

        $raw = stream_get_contents($h);
        $state = $raw === '' ? [] : json_decode($raw, true);

        if (!is_array($state)) {
            return ['status' => 'state_error'];
        }

        foreach ($state as $k => $times) {
            if (!is_array($times)) {
                return ['status' => 'state_error'];
            }

            $active = [];

            foreach ($times as $t) {
                if (!is_int($t) || $t < 0) {
                    return ['status' => 'state_error'];
                }

                if (($now - $t) < $window) {
                    $active[] = $t;
                }
            }

            if ($active) {
                $state[$k] = $active;
            } else {
                unset($state[$k]);
            }
        }

        $attempts = $state[$key] ?? [];

        if (count($attempts) >= 5) {
            return [
                'status' => 'blocked',
                'retry_after' => max(1, $window - ($now - min($attempts)))
            ];
        }

        $attempts[] = $now;
        $state[$key] = $attempts;

        $json = json_encode($state);

        if (
            $json === false ||
            !rewind($h) ||
            !ftruncate($h, 0) ||
            fwrite($h, $json) !== strlen($json) ||
            !fflush($h)
        ) {
            return ['status' => 'state_error'];
        }

        return ['status' => 'allowed'];
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Некорректный JSON'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$customerName = trim($data['customer_name'] ?? '');
$phone = trim($data['phone'] ?? '');
$deliveryMethod = trim($data['delivery_method'] ?? '');
$paymentMethod = trim($data['payment_method'] ?? '');
$deliveryTime = trim($data['delivery_time'] ?? '');
$comment = trim($data['comment'] ?? '');
$items = $data['items'] ?? [];

if ($customerName === '') {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Не указано имя покупателя'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if ($phone === '') {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Не указан телефон'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if (!is_array($items) || count($items) === 0) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Корзина пуста'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {

    if (!in_array($deliveryMethod, ['courier', 'pickup', 'post'], true)) {
        throw new OrderValidationException();
    }

    if (count($items) > 100) {
        throw new OrderValidationException();
    }

    $productStmt = $pdo->prepare("
        SELECT
            name,
            price,
            variants
        FROM products
        WHERE slug = :slug
          AND is_active = 1
        LIMIT 1
    ");

    $serverItems = [];
    $subtotal = 0.0;

    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new OrderValidationException();
        }

        $slug = trim((string)($item['slug'] ?? ''));
        $quantityValue = $item['quantity'] ?? null;
        $assemblyCountry = trim(
            (string)($item['assembly_country'] ?? '')
        );

        if (
            $slug === '' ||
            strlen($slug) > 255 ||
            !is_int($quantityValue) ||
            $quantityValue < 1 ||
            $quantityValue > TELVORA_MAX_ITEM_QUANTITY
        ) {
            throw new OrderValidationException();
        }

        $productStmt->execute([':slug' => $slug]);
        $product = $productStmt->fetch();

        if (!is_array($product)) {
            throw new OrderValidationException();
        }

        $productName = trim((string)($product['name'] ?? ''));
        $price = (float)($product['price'] ?? 0);

        if ($productName === '' || !is_finite($price) || $price <= 0) {
            throw new OrderValidationException();
        }

        if ($assemblyCountry !== '') {
            $variants = json_decode(
                (string)($product['variants'] ?? ''),
                true
            );
            $matchedVariant = null;

            if (is_array($variants)) {
                foreach ($variants as $variant) {
                    if (!is_array($variant)) {
                        continue;
                    }

                    $variantCountry = trim(
                        (string)($variant['country'] ?? '')
                    );
                    $variantIsActive =
                        !array_key_exists('is_active', $variant) ||
                        in_array(
                            $variant['is_active'],
                            [true, 1, '1'],
                            true
                        );

                    if (
                        $variantCountry === $assemblyCountry &&
                        $variantIsActive
                    ) {
                        $matchedVariant = $variant;
                        break;
                    }
                }
            }

            if (!is_array($matchedVariant)) {
                throw new OrderValidationException();
            }

            $price = (float)($matchedVariant['price'] ?? 0);

            if (!is_finite($price) || $price <= 0) {
                throw new OrderValidationException();
            }

            $productName .= ' — страна сборки: ' .
                trim((string)$matchedVariant['country']);
        }

        $serverItems[] = [
            'name' => $productName,
            'quantity' => $quantityValue,
            'price' => $price,
        ];

        $subtotal += $price * $quantityValue;
    }

    $subtotal = round($subtotal, 2);
    $delivery =
        $deliveryMethod === 'pickup' ||
        $subtotal >= TELVORA_FREE_DELIVERY_THRESHOLD
            ? 0.0
            : TELVORA_DELIVERY_FEE;
    $total = round($subtotal + $delivery, 2);
    $rateLimit = consumeApiOrderRateLimit();

    if ($rateLimit['status'] === 'state_error') {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'message' => 'Не удалось обработать заказ. Попробуйте позже.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($rateLimit['status'] === 'blocked') {
        header('Retry-After: ' . max(1, (int)($rateLimit['retry_after'] ?? 600)));
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => 'Слишком много запросов. Попробуйте позже.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();


    $stmt = $pdo->prepare("
        INSERT INTO orders (
    customer_name,
    phone,
    email,
    address,
    delivery_time,
    delivery_method,
    payment_method,
    comment,
    total,
    status
)
        VALUES (
    :customer_name,
    :phone,
    :email,
    :address,
    :delivery_time,
    :delivery_method,
    :payment_method,
    :comment,
    :total,
    'Новый'
)
    ");

    $stmt->execute([
        ':customer_name' => $customerName,
        ':phone' => $phone,
        ':email' => $data['email'] ?? null,
        ':address' => $data['address'] ?? null,
':delivery_time' => $deliveryTime,
        ':delivery_method' => $deliveryMethod,
        ':payment_method' => $paymentMethod,
        ':comment' => $comment,
        ':total' => $total
    ]);

    $orderId = (int)$pdo->lastInsertId();

/*
 * Формируем номер заказа:
 * TLV-YYYYMMDD-0001
 * TLV-YYYYMMDD-0002
 * и т.д.
 */
$orderDate = date('Ymd');
$orderPrefix = 'TLV-' . $orderDate . '-';

$numberStmt = $pdo->prepare("
    SELECT MAX(CAST(SUBSTRING(order_number, 14) AS UNSIGNED))
    FROM orders
    WHERE order_number LIKE :prefix
");

$numberStmt->execute([
    ':prefix' => $orderPrefix . '%'
]);

$lastNumber = (int)$numberStmt->fetchColumn();
$orderSequence = $lastNumber + 1;

$orderNumber = $orderPrefix . str_pad(
    (string)$orderSequence,
    4,
    '0',
    STR_PAD_LEFT
);

$updateOrderNumber = $pdo->prepare("
    UPDATE orders
    SET order_number = :order_number
    WHERE id = :id
");

$updateOrderNumber->execute([
    ':order_number' => $orderNumber,
    ':id' => $orderId
]);

$itemStmt = $pdo->prepare("
        INSERT INTO order_items (
            order_id,
            product_name,
            quantity,
            price
        )
        VALUES (
            :order_id,
            :product_name,
            :quantity,
            :price
        )
    ");

    foreach ($serverItems as $item) {

        $productName = $item['name'];
        $quantity = $item['quantity'];
        $price = $item['price'];

        $itemStmt->execute([
            ':order_id' => $orderId,
            ':product_name' => $productName,
            ':quantity' => $quantity,
            ':price' => $price
        ]);
    }

    $pdo->commit();

// Отправляем уведомление в Telegram
$itemsText = '';

foreach ($serverItems as $item) {
    $productName = $item['name'];
    $quantity = $item['quantity'];
    $price = $item['price'];

    $itemsText .= '• ' .
        htmlspecialchars($productName, ENT_QUOTES, 'UTF-8') .
        ' × ' . $quantity .
        ' — ' . number_format($price, 0, ',', ' ') . " ₽\n";
}

$email = trim($data['email'] ?? '');
$address = trim($data['address'] ?? '');

$telegramMessage =
    "🛒 <b>НОВЫЙ ЗАКАЗ #{$orderNumber}</b>\n\n" .
    "👤 <b>Клиент:</b> " . htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') . "\n" .
    "📞 <b>Телефон:</b> " . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') . "\n";

if ($email !== '') {
    $telegramMessage .=
        "📧 <b>Email:</b> " .
        htmlspecialchars($email, ENT_QUOTES, 'UTF-8') .
        "\n";
}

if ($address !== '') {
    $telegramMessage .=
        "📍 <b>Адрес:</b> " .
        htmlspecialchars($address, ENT_QUOTES, 'UTF-8') .
        "\n";
}

$telegramMessage .=
    "\n🚚 <b>Доставка:</b> " .
    htmlspecialchars($deliveryMethod, ENT_QUOTES, 'UTF-8') . "\n";

if ($deliveryTime !== '') {
    $telegramMessage .=
        "🕐 <b>Время доставки:</b> " .
        htmlspecialchars($deliveryTime, ENT_QUOTES, 'UTF-8') . "\n";
}

$telegramMessage .=
    "💳 <b>Оплата:</b> " .
    htmlspecialchars($paymentMethod, ENT_QUOTES, 'UTF-8') . "\n\n" .
    "📦 <b>Товары:</b>\n" .
    $itemsText .
    "\n💰 <b>Итого:</b> " .
    number_format($total, 0, ',', ' ') .
    " ₽";

if ($comment !== '') {
    $telegramMessage .=
        "\n\n💬 <b>Комментарий:</b>\n" .
        htmlspecialchars($comment, ENT_QUOTES, 'UTF-8');
}

sendTelegramMessage(
    $telegramBotToken,
    $telegramChatId,
    $telegramMessage
);

echo json_encode([
    'success' => true,
    'order_id' => $orderId,
    'order_number' => $orderNumber,
    'message' => 'Заказ успешно сохранён'
], JSON_UNESCAPED_UNICODE);

} catch (OrderValidationException $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Некорректный или недоступный товар в заказе'
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Не удалось сохранить заказ'
    ], JSON_UNESCAPED_UNICODE);
}

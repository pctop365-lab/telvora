<?php

$productionAutoload = dirname(__DIR__, 2) . '/telvora_vendor/vendor/autoload.php';
$localAutoload = __DIR__ . '/vendor/autoload.php';

if (is_file($productionAutoload)) {
    require_once $productionAutoload;
} elseif (is_file($localAutoload)) {
    require_once $localAutoload;
} else {
    throw new RuntimeException('PDF renderer dependency is unavailable.');
}

use Dompdf\Dompdf;
use Dompdf\Options;

header('Content-Type: application/pdf; charset=utf-8');

/*
|--------------------------------------------------------------------------
| НАСТРОЙКИ БАЗЫ
|--------------------------------------------------------------------------
*/

function telvoraPdfSecrets(): array
{
    $file = dirname(__DIR__, 2) . '/telvora_runtime/telvora_secrets.php';

    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException('Private configuration is unavailable.');
    }

    $secrets = require $file;

    if (!is_array($secrets)) {
        throw new RuntimeException('Private configuration is unavailable.');
    }

    return $secrets;
}

function telvoraPdfSecret(string $key): string
{
    static $secrets = null;

    if ($secrets === null) {
        $secrets = telvoraPdfSecrets();
    }

    $value = $secrets[$key] ?? null;

    if (!is_string($value) || $value === '') {
        throw new RuntimeException('Private configuration is unavailable.');
    }

    return $value;
}

try {
    $dbHost = telvoraPdfSecret('db_host');
    $dbName = telvoraPdfSecret('db_name');
    $dbUser = telvoraPdfSecret('db_user');
    $dbPass = telvoraPdfSecret('db_password');
    $pdfSigningKey = telvoraPdfSecret('telegram_bot_token');
} catch (Throwable $e) {
    http_response_code(500);
    exit('Service unavailable');
}

/*
|--------------------------------------------------------------------------
| ID ЗАКАЗА
|--------------------------------------------------------------------------
|
| Для теста можно открыть:
| https://telvora.ru/generate_invoice_pdf.php?order_id=1
|
*/

$orderId = (int)($_GET['order_id'] ?? 0);

if ($orderId <= 0) {
    http_response_code(400);
    exit('Не указан order_id');
}

$pdfTimestamp = $_SERVER['HTTP_X_TELVORA_PDF_TIMESTAMP'] ?? '';
$pdfSignature = $_SERVER['HTTP_X_TELVORA_PDF_SIGNATURE'] ?? '';

if (
    !is_string($pdfTimestamp) ||
    !ctype_digit($pdfTimestamp) ||
    abs(time() - (int)$pdfTimestamp) > 120
) {
    http_response_code(403);
    exit('Forbidden');
}

$expectedSignature = hash_hmac(
    'sha256',
    'telvora-pdf|' . $orderId . '|' . $pdfTimestamp,
    $pdfSigningKey
);

if (
    !is_string($pdfSignature) ||
    !hash_equals($expectedSignature, $pdfSignature)
) {
    http_response_code(403);
    exit('Forbidden');
}

/*
|--------------------------------------------------------------------------
| ПОДКЛЮЧЕНИЕ К БАЗЕ
|--------------------------------------------------------------------------
*/

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
    exit('Ошибка подключения к базе данных');
}

/*
|--------------------------------------------------------------------------
| ПОЛУЧАЕМ ЗАКАЗ
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        order_number,
        customer_name,
        phone,
        email,
address,
delivery_time,
delivery_method,
        payment_method,
        comment,
        total,
        status,
        created_at
    FROM orders
    WHERE id = :id
    LIMIT 1
");

$stmt->execute([
    ':id' => $orderId
]);

$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    exit('Заказ не найден');
}

/*
|--------------------------------------------------------------------------
| ПОЛУЧАЕМ ТОВАРЫ
|--------------------------------------------------------------------------
*/

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
    ':order_id' => $orderId
]);

$items = $itemStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| ДАННЫЕ TELVORA
|--------------------------------------------------------------------------
*/

$sellerName = 'TELVORA';
$sellerDetails = 'TELVORA';
$sellerPhone = '8 926 202-01-19';
$sellerEmail = 'telvora24@gmail.com';
$sellerAddress = 'г. Москва, Багратионовский проезд';




/*
|--------------------------------------------------------------------------
| ВСПОМОГАТЕЛЬНАЯ ФУНКЦИЯ
|--------------------------------------------------------------------------
*/

function h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function money($value): string
{
    return number_format(
        (float)$value,
        0,
        ',',
        ' '
    ) . ' ₽';
}

function translatePaymentMethod($value): string
{
    $map = [
        'cash' => 'Наличными',
        'card' => 'Банковской картой',
        'online' => 'Онлайн',
        'cash_on_delivery' => 'Наличными при получении',
    ];

    return $map[$value] ?? $value;
}

function translateDeliveryMethod($value): string
{
    $map = [
        'courier' => 'Курьером',
        'pickup' => 'Самовывоз',
        'delivery' => 'Доставка',
    ];

    return $map[$value] ?? $value;
}

/*
|--------------------------------------------------------------------------
| НОМЕР И ДАТА
|--------------------------------------------------------------------------
*/

$orderNumber = $order['order_number'];

if (!$orderNumber) {
    $orderNumber = 'TLV-' . date('Ymd', strtotime($order['created_at'])) . '-' .
        str_pad((string)$order['id'], 4, '0', STR_PAD_LEFT);
}

$orderDate = date(
    'd.m.Y',
    strtotime($order['created_at'])
);

/*
|--------------------------------------------------------------------------
| HTML НАКЛАДНОЙ
|--------------------------------------------------------------------------
*/

$logoPath = __DIR__ . '/pdf/telvora-logo-word.png';

if (!file_exists($logoPath)) {
    die('LOGO FILE NOT FOUND: ' . $logoPath);
}

$logoData = 'data:image/png;base64,' . base64_encode(
    file_get_contents($logoPath)
);
if (!$logoData || strlen($logoData) < 100) {
    die('LOGO DATA ERROR');
}

$html = '
<!DOCTYPE html>
<html lang="ru">
<head>

<meta charset="UTF-8">

<style>

@page {
    margin: 18mm 15mm 18mm 15mm;
}

body {
    font-family: DejaVu Sans, sans-serif;
    font-size: 10px;
    color: #111;
    margin: 0;
}

.header {
    text-align: center;
    margin-bottom: 18px;
}

.logo {
    font-size: 24px;
    font-weight: bold;
    letter-spacing: 3px;
}

.subtitle {
    font-size: 9px;
    margin-top: 4px;
    letter-spacing: 1px;
}

.title {
    font-size: 18px;
    font-weight: bold;
    margin-top: 18px;
}

.order-number {
    font-size: 11px;
    margin-top: 6px;
}

table {
    width: 100%;
    border-collapse: collapse;
}

.info-table {
    margin-top: 12px;
}

.info-table td {
    border: 1px solid #222;
    padding: 7px;
    vertical-align: top;
}

.label {
    width: 32%;
    font-weight: bold;
    background: #f2f2f2;
}

.products {
    margin-top: 16px;
}

.products th,
.products td {
    border: 1px solid #222;
    padding: 7px;
}

.products th {
    background: #e9e9e9;
    font-weight: bold;
    text-align: center;
}

.center {
    text-align: center;
}

.right {
    text-align: right;
}

.total-table {
    margin-top: 10px;
}

.total-table td {
    border: 1px solid #222;
    padding: 8px;
    font-size: 12px;
    font-weight: bold;
}

.total-label {
    text-align: right;
}

.notice {
    margin-top: 18px;
    font-size: 8.5px;
    line-height: 1.4;
}

.confirmation {
    margin-top: 10px;
    font-size: 8.5px;
    line-height: 1.4;
}

.signatures {
    margin-top: 25px;
}

.signatures td {
    width: 50%;
    vertical-align: top;
    padding-right: 20px;
}

.signature-line {
    margin-top: 30px;
    border-bottom: 1px solid #222;
    height: 20px;
}

.signature-text {
    margin-top: 4px;
    text-align: center;
    font-size: 8px;
}

.footer {
    position: fixed;
    bottom: 4mm;
    left: 0;
    right: 0;
    text-align: center;
}

.footer-logo {
    width: 44mm;
    height: auto;
}

</style>

</head>

<body>

<div class="header">

    <div class="title">
        ТОВАРНАЯ НАКЛАДНАЯ
    </div>

    <div class="order-number">
        № <b>' . h($orderNumber) . '</b>
        &nbsp;&nbsp;&nbsp;
        от <b>' . h($orderDate) . '</b>
    </div>

</div>

<table class="info-table">

<tr>
    <td class="label">Продавец / ИНН / Реквизиты</td>
    <td>' . h($sellerDetails) . '</td>
</tr>

<tr>
    <td class="label">Телефон</td>
    <td>' . h($sellerPhone) . '</td>
</tr>

<tr>
    <td class="label">Email</td>
    <td>' . h($sellerEmail) . '</td>
</tr>

<tr>
    <td class="label">Адрес</td>
    <td>' . h($sellerAddress) . '</td>
</tr>

</table>

<table class="info-table">

<tr>
    <td class="label">Покупатель</td>
    <td>' . h($order['customer_name']) . '</td>
</tr>

<tr>
    <td class="label">Телефон</td>
    <td>' . h($order['phone']) . '</td>
</tr>

<tr>
    <td class="label">Адрес доставки</td>
    <td>' . h($order['address'] ?: '—') . '</td>
</tr>

<tr>
    <td class="label">Удобное время доставки</td>
    <td>' . h($order['delivery_time'] ?? '—') . '</td>
</tr>

<tr>
    <td class="label">Комментарий</td>
    <td>' . h($order['comment'] ?: '—') . '</td>
</tr>

</table>

<table class="products">

<thead>

<tr>
    <th style="width:7%;">№</th>
    <th>Наименование товара</th>
    <th style="width:12%;">Количество</th>
    <th style="width:17%;">Цена</th>
    <th style="width:18%;">Сумма</th>
</tr>

</thead>

<tbody>
';

$rowNumber = 1;

foreach ($items as $item) {

    $quantity = (int)$item['quantity'];
    $price = (float)$item['price'];
    $sum = $quantity * $price;

    $html .= '
<tr>

    <td class="center">
        ' . $rowNumber . '
    </td>

    <td>
        ' . h($item['product_name']) . '
    </td>

    <td class="center">
        ' . $quantity . '
    </td>

    <td class="right">
        ' . money($price) . '
    </td>

    <td class="right">
        ' . money($sum) . '
    </td>

</tr>
';

    $rowNumber++;
}

$html .= '

</tbody>

</table>

<table class="total-table">

<tr>

<td style="width:75%; text-align:left;">
    ИТОГО:
</td>

<td style="width:25%; text-align:right;">
    ' . money($order['total']) . '
</td>

</tr>

</table>

<table class="info-table">

<tr>
    <td class="label">Способ оплаты</td>
    <td>' . h(translatePaymentMethod($order['payment_method'])) . '</td>
</tr>

<tr>
    <td class="label">Способ доставки</td>
    <td>' . h(translateDeliveryMethod($order['delivery_method'])) . '</td>
</tr>

<tr>
    <td class="label">Статус заказа</td>
    <td>' . h($order['status']) . '</td>
</tr>

</table>

<div class="notice">

При получении товара Вам необходимо проверить его внешний вид,
комплектность, отсутствие механических повреждений.
После приемки товара претензии по комплектности и наличию
механических повреждений не принимаются.

</div>

<div class="confirmation">

Товар проверен. Претензий к внешнему виду не имею.
С условиями работы интернет-магазина ознакомлен(а) и согласен(а).

</div>

<table class="signatures">

<tr>

<td>

<b>Продавец</b>

<div class="signature-line"></div>

<div class="signature-text">
Подпись
</div>

<br>

ФИО: ______________________________

</td>

<td>

<b>Покупатель</b>

<div class="signature-line"></div>

<div class="signature-text">
Подпись
</div>

<br>

ФИО: ______________________________

</td>

</tr>

</table>

<div class="footer">
    <img class="footer-logo" src="' . $logoData . '" alt="TELVORA">
</div>

</body>
</html>
';

/*
|--------------------------------------------------------------------------
| DOMPDF
|--------------------------------------------------------------------------
*/

$options = new Options();

$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);


$dompdf->loadHtml($html, 'UTF-8');

$dompdf->setPaper('A4', 'portrait');

$dompdf->render();

$filenameOrderNumber = preg_replace(
    '/[^\p{L}\p{N}_-]+/u',
    '_',
    trim((string)($order['order_number'] ?? ''))
);
$filenameOrderNumber = trim((string)$filenameOrderNumber, '_-');

if ($filenameOrderNumber === '') {
    $filenameOrderNumber = (string)$order['id'];
}

$filename = 'TELVORA_' . $filenameOrderNumber . '.pdf';

$dompdf->stream(
    $filename,
    [
        'Attachment' => false
    ]
);

exit;
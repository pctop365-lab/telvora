<?php
declare(strict_types=1);

// Read-only export for the private Google Sheet. Never place its token in the URL.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    exit;
}

try {
    require_once __DIR__ . '/runtime_config.php';
    $config = require telvoraSecretsFile();
    if (!is_array($config)) {
        throw new RuntimeException('Invalid configuration');
    }
    $token = $config['sales_export_token'] ?? null;
    $provided = $_SERVER['HTTP_X_TELVORA_EXPORT_TOKEN'] ?? '';
    if (!is_string($token) || strlen($token) < 32 ||
        !is_string($provided) || !hash_equals($token, $provided)) {
        http_response_code(404);
        exit;
    }

    foreach (['db_host', 'db_name', 'db_user', 'db_password'] as $field) {
        if (!is_string($config[$field] ?? null) || $config[$field] === '') {
            throw new RuntimeException('Missing database setting');
        }
    }
    $pdo = new PDO(
        'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
        $config['db_user'],
        $config['db_password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $orders = $pdo->query('
        SELECT o.id, o.order_number, o.created_at, o.completed_at, o.status,
               o.customer_name, o.phone, o.email, o.payment_method,
               o.subtotal, o.delivery_price, o.total,
               COALESCE(s.services_total, 0) AS services_total
        FROM orders o
        LEFT JOIN (
            SELECT order_id, SUM(total) AS services_total
            FROM order_services GROUP BY order_id
        ) s ON s.order_id = o.id
        ORDER BY o.id
    ')->fetchAll();
    $items = $pdo->query('
        SELECT i.id, i.order_id, o.order_number, i.product_name,
               i.quantity, i.price, i.supplier_offer_id_at_order,
               p.supplier_name, p.supplier_sku, p.purchase_price,
               p.currency_code, o.created_at, o.completed_at
        FROM order_items i
        INNER JOIN orders o ON o.id = i.order_id
        LEFT JOIN order_item_purchase_snapshots p ON p.order_item_id = i.id
        ORDER BY i.id
    ')->fetchAll();
    echo json_encode(['success' => true, 'orders' => $orders, 'items' => $items],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('sales_export: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Export unavailable']);
}

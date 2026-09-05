<?php

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'None'
]);

session_start();

require_once __DIR__ . '/product_variant_identity_service.php';
require_once __DIR__ . '/storefront_availability_service.php';

header('Content-Type: application/json; charset=utf-8');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$allowedOrigins = [
    'https://telvora.ru',
];

if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

$secretsFile = dirname(__DIR__, 2) . '/telvora_runtime/telvora_secrets.php';

if (!is_file($secretsFile) || !is_readable($secretsFile)) {
    http_response_code(500);
    exit(json_encode(['success' => false, 'message' => 'Сервис временно недоступен'], JSON_UNESCAPED_UNICODE));
}

$secrets = require $secretsFile;

$dbHost = $secrets['db_host'] ?? '';
$dbName = $secrets['db_name'] ?? '';
$dbUser = $secrets['db_user'] ?? '';
$dbPass = $secrets['db_password'] ?? '';

if (
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


/*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/

$rawInput = file_get_contents('php://input');

$data = [];

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (stripos($contentType, 'multipart/form-data') !== false) {

    $data = $_POST;

} elseif ($rawInput !== '') {

    $decoded = json_decode($rawInput, true);

    if (is_array($decoded)) {
        $data = $decoded;
    }
}

$action = $data['action'] ?? $_GET['action'] ?? '';


/*
|--------------------------------------------------------------------------
| JSON HELPERS
|--------------------------------------------------------------------------
*/

function decodeJsonArray($value): array
{
    if ($value === null || $value === '') {
        return [];
    }

    if (is_array($value)) {
        return $value;
    }

    $decoded = json_decode($value, true);

    return is_array($decoded) ? $decoded : [];
}


function prepareProduct(array $product): array
{
    $product['price'] = (float)($product['price'] ?? 0);

    $product['old_price'] =
        $product['old_price'] !== null
            ? (float)$product['old_price']
            : null;

    $product['rating'] = (float)($product['rating'] ?? 0);

    $product['reviews'] = (int)($product['reviews'] ?? 0);

    $product['is_active'] = (bool)($product['is_active'] ?? false);

    /*
     * JSON поля.
     * Если в БД NULL или пустое значение,
     * возвращаем пустой массив.
     */

    $product['specs'] = decodeJsonArray(
        $product['specs'] ?? null
    );

    $product['highlights'] = decodeJsonArray(
        $product['highlights'] ?? null
    );

    $product['variants'] = decodeJsonArray(
        $product['variants'] ?? null
    );

    return $product;
}

function attachStorefrontVariants(PDO $pdo, array $products): array
{
    $productIds = array_values(array_map(static fn(array $product): int => (int)$product['id'], $products));
    if ($productIds === []) return $products;
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $pdo->prepare("SELECT id, product_id, variant_key, assembly_country, display_name, is_active
                           FROM product_variants
                           WHERE product_id IN ($placeholders) AND is_active = 1
                           ORDER BY product_id ASC, id ASC");
    $stmt->execute($productIds);
    $byProduct = []; $variantIds = [];
    foreach ($stmt->fetchAll() as $variant) {
        $variant['id'] = (int)$variant['id'];
        $variant['product_id'] = (int)$variant['product_id'];
        $byProduct[$variant['product_id']][] = $variant;
        $variantIds[] = $variant['id'];
    }
    $offersByVariant = storefrontAvailabilityLoadOffers($pdo, $variantIds);
    foreach ($products as &$product) {
        $publicVariants = [];
        foreach ($byProduct[(int)$product['id']] ?? [] as $variant) {
            try {
                $identity = productVariantIdentityResolve($pdo, $product, $variant);
            } catch (ProductVariantIdentityException) {
                continue;
            }
            $legacy = $identity['variants'][$identity['target_index']];
            $availability = storefrontAvailabilityResolve($offersByVariant[$variant['id']] ?? [], 1);
            $publicVariants[] = [
                'product_variant_id' => $variant['id'],
                'country' => $identity['target']['country'],
                'display_name' => $variant['display_name'],
                'price' => $legacy['price'],
                'old_price' => $legacy['old_price'],
                'is_active' => $identity['target']['is_active'],
                'availability' => storefrontAvailabilityPublic($availability, $variant['id'])
            ];
        }
        $product['storefront_variants'] = $publicVariants;
    }
    unset($product);
    return $products;
}


/*
|--------------------------------------------------------------------------
| PUBLIC PRODUCT LIST
|--------------------------------------------------------------------------
|
| GET:
| https://telvora.ru/products.php?action=list
|
*/

if ($action === '' || $action === 'list') {

    try {

        $stmt = $pdo->query("
            SELECT
                id,
                slug,
                name,
                series,
                country,
                category,
                screen_size,
                resolution,
                price,
                old_price,
                image,
                badge,
                rating,
                reviews,
                description,
                specs,
                highlights,
                variants,
                is_active,
                created_at,
                updated_at
            FROM products
            WHERE is_active = 1
            ORDER BY id DESC
        ");

        $products = attachStorefrontVariants($pdo, $stmt->fetchAll());

        foreach ($products as &$product) {
            $product = prepareProduct($product);
        }

        unset($product);

        echo json_encode([
            'success' => true,
            'count' => count($products),
            'products' => $products
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Не удалось получить товары'
        ], JSON_UNESCAPED_UNICODE);
    }

    exit;
}


/*
|--------------------------------------------------------------------------
| ADMIN AUTHORIZATION
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['telvora_admin'])) {

    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Требуется авторизация'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}
/*
|--------------------------------------------------------------------------
| UPLOAD PRODUCT IMAGE
|--------------------------------------------------------------------------
*/

if (in_array($action, ['upload_image', 'add', 'update', 'delete'], true)) {
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

if ($action === 'upload_image') {

    if (
        !isset($_FILES['image']) ||
        !is_array($_FILES['image']) ||
        $_FILES['image']['error'] !== UPLOAD_ERR_OK
    ) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Файл изображения не получен'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $file = $_FILES['image'];

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!isset($allowedMimeTypes[$mimeType])) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Разрешены только JPG, PNG и WebP'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    if ($file['size'] > 8 * 1024 * 1024) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Размер изображения не должен превышать 8 МБ'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $uploadDir = __DIR__ . '/uploads/products';

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            http_response_code(500);

            echo json_encode([
                'success' => false,
                'message' => 'Не удалось создать папку для изображений'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }
    }

    $extension = $allowedMimeTypes[$mimeType];
    $filename = 'product_' . bin2hex(random_bytes(12)) . '.' . $extension;
    $targetPath = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Не удалось сохранить изображение'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $imageUrl = '/uploads/products/' . $filename;

    echo json_encode([
        'success' => true,
        'image' => $imageUrl
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


/*
|--------------------------------------------------------------------------
| ADMIN PRODUCT LIST
|--------------------------------------------------------------------------
*/

if ($action === 'admin_list') {

    try {

        $stmt = $pdo->query("
            SELECT
                id,
                slug,
                name,
                series,
                country,
                category,
                screen_size,
                resolution,
                price,
                old_price,
                image,
                badge,
                rating,
                reviews,
                description,
                specs,
                highlights,
                variants,
                is_active,
                created_at,
                updated_at
            FROM products
            ORDER BY id DESC
        ");

        $products = $stmt->fetchAll();

        foreach ($products as &$product) {
            $product = prepareProduct($product);
        }

        unset($product);

        echo json_encode([
            'success' => true,
            'count' => count($products),
            'products' => $products
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'Не удалось получить товары'
        ], JSON_UNESCAPED_UNICODE);
    }

    exit;
}


/*
|--------------------------------------------------------------------------
| ADD PRODUCT
|--------------------------------------------------------------------------
*/

if ($action === 'add') {

    $name = trim($data['name'] ?? '');

    $slug = trim($data['slug'] ?? '');

    $series = trim($data['series'] ?? '');

    $country = trim($data['country'] ?? '');

    $category = trim($data['category'] ?? '');

    $screenSize = trim($data['screen_size'] ?? '');

    $resolution = trim($data['resolution'] ?? '');

    $image = trim($data['image'] ?? '');

    $badge = trim($data['badge'] ?? '');

    $rating = (float)($data['rating'] ?? 0);

    $reviews = (int)($data['reviews'] ?? 0);

    $description = trim($data['description'] ?? '');

    $specs = is_array($data['specs'] ?? null)
        ? $data['specs']
        : [];

    $highlights = is_array($data['highlights'] ?? null)
        ? $data['highlights']
        : [];

    $variants = is_array($data['variants'] ?? null)
        ? $data['variants']
        : [];

    if (
        $name === '' ||
        $slug === '' ||
        $category === ''
    ) {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Заполните название, slug и категорию'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }


    if (!array_is_list($variants) || count($variants) < 1 || count($variants) > 200) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Добавьте от 1 до 200 стран сборки'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $weightStatement = $pdo->prepare(
            'SELECT HEX(WEIGHT_STRING(CAST(:value AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci))'
        );
        $canonicalVariants = [];
        $seenCountryWeights = [];

        foreach ($variants as $variant) {
            if (!is_array($variant) || !isset($variant['country']) || !is_string($variant['country'])) {
                throw new InvalidArgumentException('Страна сборки варианта некорректна');
            }

            $variantCountry = $variant['country'];
            if (!mb_check_encoding($variantCountry, 'UTF-8')) {
                throw new InvalidArgumentException('Страна сборки варианта некорректна');
            }
            $variantCountry = preg_replace('/\A\s+|\s+\z/u', '', $variantCountry);
            $controls = is_string($variantCountry) ? preg_match('/\p{Cc}/u', $variantCountry) : false;
            if (!is_string($variantCountry) || $variantCountry === '' || mb_strlen($variantCountry, 'UTF-8') > 100 || $controls !== 0) {
                throw new InvalidArgumentException('Страна сборки варианта некорректна');
            }

            $weightStatement->execute([':value' => $variantCountry]);
            $countryWeight = $weightStatement->fetchColumn();
            if (!is_string($countryWeight) || $countryWeight === '') {
                throw new RuntimeException('Не удалось проверить страну сборки');
            }
            if (isset($seenCountryWeights[$countryWeight])) {
                throw new InvalidArgumentException('Страны сборки не должны повторяться');
            }
            $seenCountryWeights[$countryWeight] = true;
            $canonicalVariants[] = [
                'country' => $variantCountry,
                'price' => 0,
                'old_price' => null,
                'is_active' => true
            ];
        }
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Не удалось проверить варианты товара'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $transactionPhase = 'product';

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO products (
                slug,
                name,
                series,
                country,
                category,
                screen_size,
                resolution,
                price,
                old_price,
                image,
                badge,
                rating,
                reviews,
                description,
                specs,
                highlights,
                variants,
                is_active
            )
            VALUES (
                :slug,
                :name,
                :series,
                :country,
                :category,
                :screen_size,
                :resolution,
                0,
                NULL,
                :image,
                :badge,
                :rating,
                :reviews,
                :description,
                :specs,
                :highlights,
                :variants,
                0
            )
        ");


        $stmt->execute([

            ':slug' => $slug,

            ':name' => $name,

            ':series' => $series,

            ':country' => $country !== ''
                ? $country
                : null,

            ':category' => $category,

            ':screen_size' => $screenSize,

            ':resolution' => $resolution,

            ':image' => $image,

            ':badge' => $badge !== ''
                ? $badge
                : null,

            ':rating' => $rating,

            ':reviews' => $reviews,

            ':description' => $description,

            ':specs' => json_encode(
                $specs,
                JSON_UNESCAPED_UNICODE
            ),

            ':highlights' => json_encode(
                $highlights,
                JSON_UNESCAPED_UNICODE
            ),

            ':variants' => json_encode(
                $canonicalVariants,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        ]);

        $productId = (int)$pdo->lastInsertId();
        $transactionPhase = 'variant';
        $variantStmt = $pdo->prepare("
            INSERT INTO product_variants (
                product_id, variant_key, assembly_country, market_region_id,
                certification_supply_type_id, manufacturer_part_number,
                display_name, classification_status, classification_evidence, is_active
            ) VALUES (
                :product_id, :variant_key, :assembly_country, NULL,
                NULL, NULL, :display_name, 'requires_classification', NULL, 1
            )
        ");
        $productVariantIds = [];
        foreach ($canonicalVariants as $variant) {
            $variantStmt->execute([
                ':product_id' => $productId,
                ':variant_key' => 'legacy-country-sha256-' . hash('sha256', $variant['country']),
                ':assembly_country' => $variant['country'],
                ':display_name' => $variant['country']
            ]);
            $productVariantIds[] = (int)$pdo->lastInsertId();
        }

        $pdo->commit();

        echo json_encode([

            'success' => true,

            'id' => $productId,

            'message' => 'Черновик товара добавлен',

            'product_variant_ids' => $productVariantIds

        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $isDuplicateSlug = $transactionPhase === 'product' && $e instanceof PDOException && $e->getCode() === '23000';
        http_response_code($isDuplicateSlug ? 409 : 400);

        echo json_encode([

            'success' => false,

            'message' =>
                $isDuplicateSlug
                    ? 'Товар с таким slug уже существует'
                    : 'Не удалось создать черновик товара'

        ], JSON_UNESCAPED_UNICODE);
    }

    exit;
}


/*
|--------------------------------------------------------------------------
| UPDATE PRODUCT
|--------------------------------------------------------------------------
*/

if ($action === 'update') {

    $id = (int)($data['id'] ?? 0);


    if ($id <= 0) {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Некорректный ID товара'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }


    $fields = [];

    $params = [
        ':id' => $id
    ];


    $map = [

        'slug' => 'slug',

        'name' => 'name',

        'series' => 'series',

        'country' => 'country',

        'category' => 'category',

        'screen_size' => 'screen_size',

        'resolution' => 'resolution',

        'price' => 'price',

        'old_price' => 'old_price',

        'image' => 'image',

        'badge' => 'badge',

        'rating' => 'rating',

        'reviews' => 'reviews',

        'description' => 'description',

        'is_active' => 'is_active'
    ];


    foreach ($map as $input => $column) {

        if (array_key_exists($input, $data)) {

            $fields[] =
                "$column = :$input";

            $params[":$input"] =
                $data[$input];
        }
    }


    /*
     * SPECS
     */

    if (array_key_exists('specs', $data)) {

        $fields[] = "specs = :specs";

        $specs = is_array($data['specs'])
            ? $data['specs']
            : [];

        $params[':specs'] =
            json_encode(
                $specs,
                JSON_UNESCAPED_UNICODE
            );
    }


    /*
     * HIGHLIGHTS
     */

    if (array_key_exists('highlights', $data)) {

        $fields[] =
            "highlights = :highlights";

        $highlights =
            is_array($data['highlights'])
                ? $data['highlights']
                : [];

        $params[':highlights'] =
            json_encode(
                $highlights,
                JSON_UNESCAPED_UNICODE
            );
    }


    if (count($fields) === 0) {

        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Нет данных для изменения'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }


    try {

        $stmt = $pdo->prepare("
            UPDATE products
            SET " . implode(', ', $fields) . "
            WHERE id = :id
        ");

        $stmt->execute($params);


        echo json_encode([

            'success' => true,

            'message' => 'Товар изменён'

        ], JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {

        http_response_code(400);

        echo json_encode([

            'success' => false,

            'message' =>
                'Не удалось изменить товар'

        ], JSON_UNESCAPED_UNICODE);
    }

    exit;
}


/*
|--------------------------------------------------------------------------
| DELETE PRODUCT
|--------------------------------------------------------------------------
*/

if ($action === 'delete') {

    $id = (int)($data['id'] ?? 0);


    if ($id <= 0) {

        http_response_code(400);

        echo json_encode([

            'success' => false,

            'message' =>
                'Некорректный ID товара'

        ], JSON_UNESCAPED_UNICODE);

        exit;
    }


    try {

        $stmt = $pdo->prepare("
            DELETE FROM products
            WHERE id = :id
        ");

        $stmt->execute([
            ':id' => $id
        ]);


        echo json_encode([

            'success' => true,

            'message' =>
                'Товар удалён'

        ], JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {

        http_response_code(400);

        echo json_encode([

            'success' => false,

            'message' =>
                'Не удалось удалить товар'

        ], JSON_UNESCAPED_UNICODE);
    }

    exit;
}


/*
|--------------------------------------------------------------------------
| UNKNOWN ACTION
|--------------------------------------------------------------------------
*/

echo json_encode([

    'success' => false,

    'message' =>
        'Неизвестное действие'

], JSON_UNESCAPED_UNICODE);

exit;

<?php

const PRODUCT_GALLERY_MAX_IMAGES = 10;
const PRODUCT_GALLERY_MAX_FILE_BYTES = 8388608;
const PRODUCT_GALLERY_MAX_REQUEST_BYTES = 33554432;

function productGalleryNormalize(array $paths, string $legacyImage = ''): array
{
    $result = [];
    foreach ($paths as $path) {
        if (!is_string($path)) continue;
        $path = trim($path);
        if ($path === '' || in_array($path, $result, true)) continue;
        if (count($result) >= PRODUCT_GALLERY_MAX_IMAGES) {
            throw new InvalidArgumentException('В галерее может быть не более 10 изображений');
        }
        $result[] = $path;
    }
    if ($result === [] && $legacyImage !== '') $result[] = $legacyImage;
    return $result;
}

function productGalleryIsManagedPath(string $path): bool
{
    return preg_match('#\A/uploads/products/product_[a-f0-9]{24}\.(?:jpg|png|webp)\z#D', $path) === 1;
}

function productGalleryAttach(PDO $pdo, array $products): array
{
    if ($products === []) return [];
    $ids = array_map(static fn(array $p): int => (int)$p['id'], $products);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT product_id, image_path FROM product_images WHERE product_id IN ($marks) ORDER BY product_id, position, id");
    $stmt->execute($ids);
    $byProduct = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $byProduct[(int)$row['product_id']][] = $row['image_path'];
    foreach ($products as &$product) {
        $gallery = productGalleryNormalize($byProduct[(int)$product['id']] ?? [], (string)($product['image'] ?? ''));
        $product['images'] = $gallery;
        if ($gallery !== []) $product['image'] = $gallery[0];
    }
    unset($product);
    return $products;
}

function productGalleryReplace(PDO $pdo, int $productId, array $paths): array
{
    $paths = productGalleryNormalize($paths);
    $delete = $pdo->prepare('DELETE FROM product_images WHERE product_id = ?');
    $delete->execute([$productId]);
    $insert = $pdo->prepare('INSERT INTO product_images (product_id, image_path, position, is_primary) VALUES (?, ?, ?, ?)');
    foreach ($paths as $position => $path) $insert->execute([$productId, $path, $position, $position === 0 ? 1 : 0]);
    return $paths;
}

function productGalleryValidateUpload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new InvalidArgumentException('Файл изображения не получен');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > PRODUCT_GALLERY_MAX_FILE_BYTES) throw new InvalidArgumentException('Размер каждого изображения не должен превышать 8 МБ');
    $tmp = (string)$file['tmp_name'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) throw new InvalidArgumentException('Разрешены только JPG, PNG и WebP');
    $info = @getimagesize($tmp);
    if ($info === false || ($info['mime'] ?? '') !== $mime || $info[0] < 1 || $info[1] < 1 || $info[0] > 12000 || $info[1] > 12000 || $info[0] * $info[1] > 40000000) {
        throw new InvalidArgumentException('Содержимое файла не является допустимым изображением');
    }
    $head = file_get_contents($tmp);
    if ($head === false || preg_match('/<\?(?:php|=)|#!\s*\/|<script\b/i', $head)) throw new InvalidArgumentException('Исполняемое содержимое в изображении запрещено');
    return ['extension' => $allowed[$mime], 'size' => $size];
}

function productGalleryFlattenFiles(array $input): array
{
    if (!is_array($input['name'] ?? null)) return [$input];
    $files = [];
    foreach ($input['name'] as $i => $name) {
        $files[] = ['name'=>$name, 'type'=>$input['type'][$i] ?? '', 'tmp_name'=>$input['tmp_name'][$i] ?? '', 'error'=>$input['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size'=>$input['size'][$i] ?? 0];
    }
    return $files;
}

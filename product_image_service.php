<?php

// Originals retain their URLs. A sidecar is published only after every derivative
// exists, so old products and interrupted uploads never advertise missing files.
function productImageCreateVariants(string $original, string $url): array
{
    if (!function_exists('imagewebp')) throw new RuntimeException('Для обработки изображений требуется PHP GD с WebP');
    $info = getimagesize($original);
    if (!$info || $info[0] * $info[1] > 40000000) throw new InvalidArgumentException('Недопустимый размер изображения');
    $limit = ini_get('memory_limit');
    $bytes = (int)$limit;
    if (preg_match('/([KMG])$/i', $limit, $unit)) $bytes *= 1024 ** (strpos('KMG', strtoupper($unit[1])) + 1);
    if ($bytes > 0 && memory_get_usage(true) + $info[0] * $info[1] * 10 + 2560 * 2560 * 5 + 33554432 > $bytes) {
        throw new InvalidArgumentException('Изображение слишком велико для обработки на сервере');
    }
    $source = match ($info['mime']) {
        'image/jpeg' => imagecreatefromjpeg($original),
        'image/png' => imagecreatefrompng($original),
        'image/webp' => imagecreatefromwebp($original),
        default => false,
    };
    if (!$source) throw new InvalidArgumentException('Не удалось прочитать изображение');
    $created = [];
    try {
        // Apply camera orientation before measuring/resizing; derivatives have no EXIF.
        if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
            $orientation = (int)(@exif_read_data($original)['Orientation'] ?? 1);
            if (in_array($orientation, [2, 4, 5, 7], true)) imageflip($source, in_array($orientation, [4, 5, 7], true) ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
            $angle = match ($orientation) { 3 => 180, 5, 6 => -90, 7, 8 => 90, default => 0 };
            if ($angle) {
                $rotated = imagerotate($source, $angle, 0);
                if (!$rotated) throw new RuntimeException('Не удалось повернуть изображение');
                imagedestroy($source); $source = $rotated;
            }
        }
        $width = imagesx($source); $height = imagesy($source);
        $fallback = $info['mime'] === 'image/jpeg' ? 'jpg' : 'png';
        $metadata = ['width' => $width, 'height' => $height, 'sources' => []];
        $previous = '';
        foreach ([160, 320, 480, 800, 1280, 1920, 2560] as $edge) {
            $scale = min(1, $edge / max($width, $height));
            $w = max(1, (int)round($width * $scale)); $h = max(1, (int)round($height * $scale));
            if ($previous === "$w:$h") continue;
            $previous = "$w:$h";
            $resized = imagecreatetruecolor($w, $h);
            try {
                imagealphablending($resized, false); imagesavealpha($resized, true);
                imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
                if (!imagecopyresampled($resized, $source, 0, 0, 0, 0, $w, $h, $width, $height)) throw new RuntimeException('Resize failed');
                foreach (['webp', $fallback] as $format) {
                    $suffix = ".{$w}x{$h}.{$format}";
                    $path = $original . $suffix;
                    $created[] = $path;
                    $ok = match ($format) {
                        'webp' => imagewebp($resized, $path, $edge <= 320 ? 85 : 90),
                        'jpg' => imagejpeg($resized, $path, 92),
                        'png' => imagepng($resized, $path, 6),
                    };
                    if (!$ok || !is_file($path) || filesize($path) === 0) throw new RuntimeException('Image encoding failed');
                    $metadata['sources'][] = ['src' => $url . $suffix, 'width' => $w, 'height' => $h, 'type' => $format === 'jpg' ? 'image/jpeg' : 'image/' . $format];
                }
            } finally { imagedestroy($resized); }
        }
        $temporary = $original . '.json.tmp'; $created[] = $temporary;
        if (file_put_contents($temporary, json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false || !rename($temporary, $original . '.json')) throw new RuntimeException('Image metadata write failed');
        return $metadata;
    } catch (Throwable $e) {
        foreach ($created as $path) if (is_file($path)) unlink($path);
        throw $e;
    } finally { imagedestroy($source); }
}

function productImageMetadata(string $url, ?string $root = null): ?array
{
    if (!preg_match('#\A/uploads/products/product_[a-f0-9]{24}\.(?:jpg|png|webp)\z#D', $url)) return null;
    $root ??= __DIR__;
    $path = $root . $url;
    if (!is_file($path . '.json')) return null;
    $metadata = json_decode((string)file_get_contents($path . '.json'), true);
    if (!is_array($metadata) || empty($metadata['width']) || empty($metadata['height']) || empty($metadata['sources'])) return null;
    // Never turn an incomplete file set into broken public URLs.
    foreach ($metadata['sources'] as $source) {
        if (!is_array($source) || !preg_match('#\A' . preg_quote($url, '#') . '\.[0-9]+x[0-9]+\.(?:jpg|png|webp)\z#D', $source['src'] ?? '') || !is_file($root . $source['src'])) return null;
    }
    return $metadata;
}

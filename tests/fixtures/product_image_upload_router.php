<?php
// Local integration harness only. Never deploy this file.
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { http_response_code(403); exit; }
$directory = realpath(getenv('TELVORA_IMAGE_TEST_DIRECTORY') ?: '');
if (!$directory || dirname($directory) !== realpath(sys_get_temp_dir()) || !str_starts_with(basename($directory), 'telvora-image-upload-')) { http_response_code(403); exit; }
require_once dirname(__DIR__, 2) . '/product_gallery_service.php';
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'GET') { echo '{"ready":true}'; exit; }
try {
    $stored = productGalleryStoreUpload($_FILES['image'] ?? [], $directory);
    echo json_encode(['success'=>true, 'image'=>$stored['url'], 'metadata'=>$stored['metadata']], JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $e) {
    http_response_code(400); echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}

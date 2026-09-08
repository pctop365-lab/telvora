<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/product_gallery_service.php';
function ok(string $name, bool $value): void { if (!$value) throw new RuntimeException("FAIL $name"); echo "PASS $name\n"; }
ok('legacy image compatibility', productGalleryNormalize([], '/legacy/tv.jpg') === ['/legacy/tv.jpg']);
ok('order and primary are first', productGalleryNormalize(['/b.jpg','/a.jpg']) === ['/b.jpg','/a.jpg']);
ok('duplicates are idempotent', productGalleryNormalize(['/a.jpg','/a.jpg']) === ['/a.jpg']);
ok('managed filename accepted', productGalleryIsManagedPath('/uploads/products/product_0123456789abcdef01234567.webp'));
ok('path traversal rejected', !productGalleryIsManagedPath('/uploads/products/../secrets.php'));
ok('executable extension rejected', !productGalleryIsManagedPath('/uploads/products/product_0123456789abcdef01234567.php'));
$tooMany = false; try { productGalleryNormalize(array_map(fn($i)=>"/$i.jpg", range(1,11))); } catch (InvalidArgumentException) { $tooMany=true; }
ok('image count limited', $tooMany);
echo "PASS gallery unit suite\n";

<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/product_gallery_service.php';

function imageCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS $message\n";
}

$root = dirname(__DIR__) . '/.seo-build/image-optimization-fixture';
$directory = $root . '/uploads/products';
if (!is_dir($directory)) mkdir($directory, 0755, true);
$input = $argv[1] ?? dirname(__DIR__) . '/public/images/tech-home-cinema-light.png';
$info = getimagesize($input);
$extension = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'][$info['mime']];
$url = '/uploads/products/product_' . str_repeat('a',24) . '.' . $extension;
$path = $root . $url;
copy($input, $path);
$metadata = productImageCreateVariants($path, $url);
imageCheck(productImageMetadata($url, $root) === $metadata, 'metadata reads back exactly');
$seen = []; $bytes = [];
foreach ($metadata['sources'] as $source) {
    $actual = getimagesize($root . $source['src']);
    imageCheck($actual[0] === $source['width'] && $actual[1] === $source['height'] && $actual['mime'] === $source['type'], 'valid derivative ' . basename($source['src']));
    imageCheck($actual[0] <= $info[0] && $actual[1] <= $info[1], 'never upscale');
    imageCheck(abs($actual[0] / $actual[1] - $info[0] / $info[1]) < 0.02, 'preserve proportions');
    $key = $source['type'] . ':' . $source['width'];
    imageCheck(!isset($seen[$key]), 'unique srcset width'); $seen[$key] = true;
    $bytes[$source['src']] = filesize($root . $source['src']);
}
$modern = array_values(array_filter($metadata['sources'], fn($s)=>$s['type']==='image/webp'));
$largest = $modern[count($modern)-1];
$decoded = imagecreatefromwebp($root . $largest['src']);
$original = imagecreatefromstring(file_get_contents($path));
$reference = imagescale($original, $largest['width'], $largest['height'], IMG_BICUBIC_FIXED);
imagepng($reference, $root . '/quality-reference.png');
$squared = 0; $samples = 0;
for ($y=0; $y<imagesy($decoded); $y+=2) for ($x=0; $x<imagesx($decoded); $x+=2) {
    $a=imagecolorat($decoded,$x,$y); $b=imagecolorat($reference,$x,$y);
    foreach ([0,8,16] as $shift) { $difference=(($a>>$shift)&255)-(($b>>$shift)&255); $squared+=$difference*$difference; $samples++; }
}
$psnr=$squared>0 ? 10*log10(255*255/($squared/$samples)) : 100;
imageCheck($psnr > 34, 'large WebP PSNR > 34 dB (' . round($psnr,2) . ')');
imagedestroy($decoded); imagedestroy($original); imagedestroy($reference);

// Small transparent images stay small and keep alpha in WebP AND fallback PNG.
$smallUrl = '/uploads/products/product_' . str_repeat('b',24) . '.png';
$small=imagecreatetruecolor(80,50); imagealphablending($small,false); imagesavealpha($small,true);
imagefill($small,0,0,imagecolorallocatealpha($small,0,0,0,127));
imagefilledrectangle($small,20,10,60,40,imagecolorallocatealpha($small,255,0,0,0));
imagepng($small,$root.$smallUrl); imagedestroy($small);
$smallMeta=productImageCreateVariants($root.$smallUrl,$smallUrl);
imageCheck(count($smallMeta['sources'])===2,'small image has just one size and two formats');
foreach($smallMeta['sources'] as $s){
    $im=imagecreatefromstring(file_get_contents($root.$s['src']));
    imageCheck(imagesx($im)===80 && imagesy($im)===50 && ((imagecolorat($im,0,0)>>24)&127)===127,'small dimensions and transparency');
    imagedestroy($im);
}
// WebP input is also accepted by the same server pipeline.
$webpUrl='/uploads/products/product_'.str_repeat('c',24).'.webp';
copy($root.$smallMeta['sources'][0]['src'],$root.$webpUrl);
imageCheck(productImageCreateVariants($root.$webpUrl,$webpUrl)['width']===80,'WebP input');
// All EXIF rotations/mirrors, with colored corners to detect wrong orientation.
$im=imagecreatetruecolor(120,60);
$colors=[[255,0,0],[0,255,0],[0,0,255],[255,255,0]];
foreach($colors as $i=>$rgb) imagefilledrectangle($im,($i%2)*60,(int)floor($i/2)*30,($i%2)*60+59,(int)floor($i/2)*30+29,imagecolorallocate($im,...$rgb));
ob_start(); imagejpeg($im,null,95); $jpeg=ob_get_clean(); imagedestroy($im);
$corners=[1=>0,2=>1,3=>3,4=>2,5=>0,6=>2,7=>3,8=>1];
foreach($corners as $orientation=>$corner){
    $rotUrl='/uploads/products/product_'.sprintf('%024x',100+$orientation).'.jpg';
    $exif="Exif\0\0II\x2a\0\x08\0\0\0\x01\0\x12\x01\x03\0\x01\0\0\0".pack('v',$orientation)."\0\0\0\0\0\0";
    file_put_contents($root.$rotUrl,substr($jpeg,0,2)."\xff\xe1".pack('n',strlen($exif)+2).$exif.substr($jpeg,2));
    $rot=productImageCreateVariants($root.$rotUrl,$rotUrl);
    imageCheck($rot['width']===($orientation>=5?60:120) && $rot['height']===($orientation>=5?120:60),'EXIF dimensions '.$orientation);
    $decoded=imagecreatefromwebp($root.$rot['sources'][0]['src']);$pixel=imagecolorat($decoded,5,5);imagedestroy($decoded);
    foreach([16,8,0] as $c=>$shift) imageCheck(abs((($pixel>>$shift)&255)-$colors[$corner][$c])<20,'EXIF corner '.$orientation);
}

$missing=$root.$smallMeta['sources'][0]['src']; rename($missing,$missing.'.test-backup');
try { imageCheck(productImageMetadata($smallUrl,$root)===null,'incomplete derivatives fall back to legacy URL'); }
finally { rename($missing.'.test-backup',$missing); }
imageCheck(productImageMetadata('/legacy.jpg',$root)===null,'legacy single image has no metadata requirement');
imageCheck(productImageMetadata('/uploads/products/../secrets.php',$root)===null,'metadata path traversal blocked');
imageCheck(count(productGalleryNormalize(array_map(fn($i)=>"/frame-$i.jpg",range(1,45))))===45,'45 frames accepted');
$frames=[]; $frameMetadata=[];
for($i=1;$i<=45;$i++) {
    $frame='/uploads/products/product_'.sprintf('%024x',$i).'.'.$extension;
    copy($path,$root.$frame);
    $frameMeta=$metadata;
    foreach($frameMeta['sources'] as &$s) {
        $old=$s['src']; $s['src']=str_replace($url,$frame,$old);
        copy($root.$old,$root.$s['src']);
    }
    unset($s);
    $frames[]=$frame; $frameMetadata[$frame]=$frameMeta;
}
file_put_contents($root.'/fixture.json',json_encode(['input'=>$input,'url'=>$url,'metadata'=>$metadata,'bytes'=>$bytes,'originalBytes'=>filesize($path),'psnr'=>$psnr,'images'=>$frames,'image_variants'=>$frameMetadata],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
echo "FIXTURE $root/fixture.json\n";

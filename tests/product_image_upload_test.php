<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/product_gallery_service.php';
$directory = sys_get_temp_dir() . '/telvora-image-upload-' . bin2hex(random_bytes(8));
mkdir($directory,0700);
$router=__DIR__.'/fixtures/product_image_upload_router.php';
$env=getenv(); $env['TELVORA_IMAGE_TEST_DIRECTORY']=$directory;
$process=proc_open([PHP_BINARY,'-d','extension=gd','-d','extension=exif','-d','extension=fileinfo','-d','upload_max_filesize=8M','-d','post_max_size=10M','-d','max_file_uploads=1','-S','127.0.0.1:4189',$router],[0=>['pipe','r'],1=>['file',$directory.'/server.log','a'],2=>['file',$directory.'/server.log','a']],$pipes,dirname(__DIR__),$env);
if(!is_resource($process)) throw new RuntimeException('Cannot start local image server');
fclose($pipes[0]);
try {
    $ready=false;
    for($i=0;$i<40;$i++) {
        $ready=@file_get_contents('http://127.0.0.1:4189/',false,stream_context_create(['http'=>['timeout'=>1]]));
        if($ready!==false)break;
        usleep(100000);
    }
    if(!$ready)throw new RuntimeException(file_get_contents($directory.'/server.log'));
    $image=imagecreatetruecolor(80,50); imagefilledrectangle($image,0,0,79,49,imagecolorallocate($image,120,160,200));
    ob_start();imagejpeg($image,null,95);$bytes=ob_get_clean();imagedestroy($image);
    $urls=[];
    for($i=0;$i<45;$i++) {
        $boundary='telvora'.bin2hex(random_bytes(8));
        $body="--$boundary\r\nContent-Disposition: form-data; name=\"image\"; filename=\"frame-$i.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n$bytes\r\n--$boundary--\r\n";
        $response=file_get_contents('http://127.0.0.1:4189/',false,stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: multipart/form-data; boundary=$boundary\r\n",'content'=>$body,'timeout'=>10,'ignore_errors'=>true]]));
        $data=json_decode($response,true);
        if(!is_array($data))throw new RuntimeException('Non-JSON response: '.$response);
        if(empty($data['success']) || count($data['metadata']['sources'])!==2)throw new RuntimeException('Upload failed: '.$response);
        $urls[]=$data['image'];
        foreach($data['metadata']['sources'] as $source) {
            $file=$directory.'/'.basename($source['src']);
            if(!getimagesize($file))throw new RuntimeException('Invalid derivative');
        }
    }
    if(count(array_unique($urls))!==45)throw new RuntimeException('Non-unique uploads');
    // Exercise the real save/read functions against an isolated in-memory DB.
    $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE product_images (id INTEGER PRIMARY KEY, product_id INTEGER, image_path TEXT, position INTEGER, is_primary INTEGER)');
    productGalleryReplace($pdo,1,$urls);
    $products=productGalleryAttach($pdo,[['id'=>1,'image'=>''],['id'=>2,'image'=>'/legacy.jpg']]);
    if($products[0]['images']!==$urls || $products[0]['image']!==$urls[0] || $products[1]['images']!==['/legacy.jpg'])throw new RuntimeException('Gallery persistence failed');
    echo "PASS 45 real multipart uploads, WebP/JPEG decoding, unique URLs, gallery save/read order and legacy fallback (isolated SQLite)\n";
} finally {
    proc_terminate($process);proc_close($process);
    // Only the dedicated flat temporary directory created by this test is removed.
    foreach(glob($directory.'/*')?:[] as $file) if(is_file($file))unlink($file);
    rmdir($directory);
}

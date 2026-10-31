<?php

// Stateless GD check in a fresh PHP process with the production memory limit.
// Storage::fake writes only inside the container tmpfs; no DB connection is used.
chdir('/var/www/html');
foreach(['logs','framework/views','framework/cache/data'] as $dir) @mkdir('storage/'.$dir,0777,true);
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('testing') || ini_get('memory_limit')!=='192M') throw new RuntimeException('Testing isolation and bounded PHP required');
Illuminate\Support\Facades\Storage::fake('minio-media');
$path='/tmp/club-large-exif.jpg';
$image=imagecreatetruecolor(6000,4000);
imagefilledrectangle($image,0,0,5999,3999,imagecolorallocate($image,255,90,79));
imagejpeg($image,$path,90);unset($image);
$jpeg=file_get_contents($path);
$exif="Exif\0\0"."II".pack('vV',42,8).pack('v',1).pack('vvVv',0x112,3,1,6)."\0\0".pack('V',0);
file_put_contents($path,substr($jpeg,0,2)."\xff\xe1".pack('n',strlen($exif)+2).$exif.substr($jpeg,2));unset($jpeg);
try {
    $result=app(App\Services\MediaUploadService::class)->temporary(new Illuminate\Http\UploadedFile($path,'fixture.jpg',null,null,true),1);
    $token=json_decode(Illuminate\Support\Facades\Crypt::decryptString($result['token']),true);
    $size=getimagesizefromstring(Illuminate\Support\Facades\Storage::disk('minio-media')->get($token['key']));
    if ($size[0]!==1067 || $size[1]!==1600 || $size['mime']!=='image/webp') throw new RuntimeException('24 MP JPEG normalization failed');
    $report=['passed'=>true,'pixels'=>24000000,'orientation'=>6,'width'=>1067,'height'=>1600,'memory_limit'=>'192M','storage'=>'container tmpfs only','database_writes'=>0];
    file_put_contents('/evidence/normalization-tests.json',json_encode($report,JSON_PRETTY_PRINT).PHP_EOL);
    echo 'PASS 24 MP JPEG resize and EXIF orientation within production PHP memory limit'.PHP_EOL;
} finally {unlink($path);}

<?php

// Exactly one temporary CLUB object. No users, landings or permanent objects are written.
chdir('/var/www/html');
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$media=app(App\Services\MediaUploadService::class);
$disk=Illuminate\Support\Facades\Storage::disk(config('media.disk'));
$key=null;
$result=['object_count'=>0,'namespace'=>'club/tmp/','no_live_accounts'=>true,'no_live_landings'=>true];
try {
    if (!config('filesystems.disks.minio-media.key') || !config('media.imgproxy_key') || !config('media.imgproxy_salt')) {
        throw new RuntimeException('Missing private configuration');
    }
    $client=$disk->getClient();
    $result['credential_mode']='owner_authorized_temporary_shared';
    $uuid=Illuminate\Support\Str::uuid();
    $key='club/tmp/'.$uuid.'/master.webp';
    Illuminate\Support\Str::createUuidsUsing(fn () => $uuid);
    $result['object_count']=1;
    $uploaded=$media->temporary(new Illuminate\Http\UploadedFile(public_path('marketing/club-card.png'),'private-smoke.png',null,null,true),1);
    $token=json_decode(Illuminate\Support\Facades\Crypt::decryptString($uploaded['token']),true,16,JSON_THROW_ON_ERROR);
    $key=$token['key'];
    if (!$media->isTemporaryKey($key)) throw new RuntimeException('Invalid smoke key');
    $result['object_count']=1;$result['private_object_verified']=$disk->exists($key);
    $result['object_key']=$key;
    $anonymous=Illuminate\Support\Facades\Http::timeout(20)->get(rtrim(config('filesystems.disks.minio-media.endpoint'),'/').'/'.config('media.bucket').'/'.$key);
    if (!in_array($anonymous->status(),[401,403],true)) throw new RuntimeException('Object must remain private');
    $result['anonymous_s3_access_denied']=true;
    $head=$client->headObject(['Bucket'=>config('media.bucket'),'Key'=>$key]);
    $result['stored_content_type']=$head['ContentType'];
    $result['public_images']=[];
    foreach(['thumbnail','card','social'] as $preset){
        $url=app(App\Services\MediaUrl::class)->url($key,$preset);
        $response=Illuminate\Support\Facades\Http::timeout(30)->get($url);
        $size=@getimagesizefromstring($response->body());
        if (!$response->successful() || !$size || $size[0]!==config('media.presets.'.$preset.'.width') || $size[1]!==config('media.presets.'.$preset.'.height')) {
            throw new RuntimeException('Public imgproxy preset failed');
        }
        if ($preset==='social' && $size['mime']!=='image/jpeg') throw new RuntimeException('Social JPEG required');
        $result['public_images'][$preset]=['http'=>$response->status(),'mime'=>$size['mime'],'width'=>$size[0],'height'=>$size[1],'url'=>$url];
    }
    $result['passed']=true;
} catch (Throwable $exception) {
    // Never dump SDK requests or credential-bearing exception messages.
    $result['passed']=false;
    $result['failure']='Storage or public imgproxy verification failed';
    if ($exception instanceof Aws\S3\Exception\S3Exception) {
        $result['s3_status']=$exception->getStatusCode();
        $result['s3_error_code']=$exception->getAwsErrorCode();
    }
} finally {
    Illuminate\Support\Str::createUuidsNormally();
    if ($key!==null && $media->isTemporaryKey($key)) {
        try {$result['controlled_cleanup']=$disk->delete($key) && !$disk->exists($key);}
        catch(Throwable){$result['controlled_cleanup']=false;}
    }
}
file_put_contents('/evidence/storage-live.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit(($result['passed']??false)&&($result['controlled_cleanup']??false)?0:1);

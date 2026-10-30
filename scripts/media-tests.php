<?php

// Included only after catalog-tests.php has verified schema isolation and migrated.
use App\Services\MediaUploadService;
use App\Services\MediaUrl;
use App\Services\MediaReferences;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

config(['media.imgproxy_key'=>str_repeat('a1',32),'media.imgproxy_salt'=>str_repeat('b2',32),
    'filesystems.disks.minio-media.key'=>'fixture-only-key','filesystems.disks.minio-media.secret'=>'fixture-only-secret']);
Storage::fake('minio-media');
$disk=Storage::disk('minio-media');
$media=app(MediaUploadService::class);$url=app(MediaUrl::class);
$admin=login(true);$landing=App\Models\MarketingLanding::findOrFail((int)substr($club->source_id,8));
$base='/admin/landings/'.$landing->id;$editPath=route('landings.edit',$landing,false);
$livePublished=$landing->published_configuration;$originalDraft=$landing->configuration;
$linksBefore=DB::table('affiliate_short_links')->orderBy('code')->get()->toJson();
function fixtureUpload(string $format='png',int $width=80,int $height=40): UploadedFile {
    $file='/tmp/image-'.Str::uuid().'.'.$format;
    $im=imagecreatetruecolor($width,$height);imagefill($im,0,0,imagecolorallocate($im,255,90,79));
    match($format){'jpg'=>imagejpeg($im,$file,90),'png'=>imagepng($im,$file),'webp'=>imagewebp($im,$file,90)};
    imagedestroy($im);
    return new UploadedFile($file,'untrusted-original.'.$format,null,null,true);
}
function uploadRequest(UploadedFile $file): Symfony\Component\HttpFoundation\Response {
    global $cookies;
    Auth::forgetGuards();
    $response=kernelRequest(Request::create('https://jakawi.club/media/uploads','POST',[],$cookies,['image'=>$file],['HTTP_ACCEPT'=>'application/json','REMOTE_ADDR'=>'192.0.2.40']));
    foreach($response->headers->getCookies() as $cookie)$cookies[$cookie->getName()]=$cookie->getValue();
    return $response;
}
$upload=null;
foreach(['jpg','png','webp'] as $format){
    $file=fixtureUpload($format);$response=uploadRequest($file);
    check($response->getStatusCode()===200,'multipart '.$format.' upload accepted for marketing admin');
    $upload=payload($response);$token=json_decode(Crypt::decryptString($upload['token']),true);
    check($media->isTemporaryKey($token['key']) && str_starts_with($token['key'],'club/tmp/'),'random private temporary '.$format.' key isolated to CLUB');
    check($disk->getVisibility($token['key'])==='private' && $token['user']===$admin->id && $token['expires']>=now()->addHours(24)->subMinute()->timestamp,'private object and owner-bound 24h token');
    $bytes=$disk->get($token['key']);$size=getimagesizefromstring($bytes);
    check($size['mime']==='image/webp' && $size[0]===80 && $size[1]===40,'normalized '.$format.' to verified WebP');
    check(str_starts_with($upload['url'],'https://img.jakawi.com/') && !str_contains($upload['url'],'untrusted-original'),'signed absolute thumbnail uses random key');
    unlink($file->getPathname());
}
$large=fixtureUpload('jpg',2000,1000);$resized=$media->temporary($large,$admin->id);$resizedToken=json_decode(Crypt::decryptString($resized['token']),true);
$size=getimagesizefromstring($disk->get($resizedToken['key']));
check($size[0]===1600 && $size[1]===800,'normalization caps longest edge at 1600');unlink($large->getPathname());
// EXIF APP1 orientation 6: valid JPEG with a little-endian TIFF orientation entry.
$oriented=fixtureUpload('jpg',80,40);$jpeg=file_get_contents($oriented->getPathname());
$exif="Exif\0\0"."II".pack('vV',42,8).pack('v',1).pack('vvVv',0x112,3,1,6)."\0\0".pack('V',0);
file_put_contents($oriented->getPathname(),substr($jpeg,0,2)."\xff\xe1".pack('n',strlen($exif)+2).$exif.substr($jpeg,2));
$or=$media->temporary($oriented,$admin->id);$orToken=json_decode(Crypt::decryptString($or['token']),true);$dims=getimagesizefromstring($disk->get($orToken['key']));
check($dims[0]===40 && $dims[1]===80,'JPEG EXIF orientation corrected');unlink($oriented->getPathname());
$bad='/tmp/invalid-media';file_put_contents($bad,'Not an image');
check(uploadRequest(new UploadedFile($bad,'fake.jpg','image/jpeg',null,true))->getStatusCode()===422,'MIME spoof rejected after file inspection');
$tooBig='/tmp/oversized-media';$f=fopen($tooBig,'w');ftruncate($f,10*1024*1024+1);fclose($f);
check(uploadRequest(new UploadedFile($tooBig,'large.png',null,null,true))->getStatusCode()===422,'more than 10 MiB rejected');unlink($tooBig);unlink($bad);
foreach([[16001,1],[6000,4001]] as [$w,$h]){
    // Valid PNG header dimensions reject before decode/allocation; no huge fixture is decoded.
    $p='/tmp/dimension-fixture.png';$chunk=pack('NNCCCCC',$w,$h,8,2,0,0,0);
    file_put_contents($p,"\x89PNG\r\n\x1a\n".pack('N',13).'IHDR'.$chunk.pack('N',crc32('IHDR'.$chunk)));
    try{$media->validate(new UploadedFile($p,'dimension.png',null,null,true));check(false,'dimension guard');}
    catch(Illuminate\Validation\ValidationException){check(true,'reject excessive dimension/pixel count before decoding '.$w.'x'.$h);}
    unlink($p);
}
$adminCookies=$cookies;
$cookies=[];app('session')->flush();app('session')->regenerate();check(uploadRequest(fixtureUpload())->getStatusCode()===401,'guest cannot upload');
$member=login(false);check(uploadRequest(fixtureUpload())->getStatusCode()===403,'non-admin cannot upload');
$cookies=$adminCookies;
foreach([
    ['key'=>$token['key'],'user'=>$admin->id+999,'expires'=>now()->addHour()->timestamp],
    ['key'=>$token['key'],'user'=>$admin->id,'expires'=>now()->timestamp],
    ['key'=>'tmp/'.Str::uuid().'/master.webp','user'=>$admin->id,'expires'=>now()->addHour()->timestamp],
    ['key'=>'club/tmp/../outside.webp','user'=>$admin->id,'expires'=>now()->addHour()->timestamp],
] as $invalid){
    try{$media->promote(Crypt::encryptString(json_encode($invalid)),$admin->id,$landing->id);check(false,'token guard');}
    catch(Illuminate\Validation\ValidationException){check(true,'expired foreign or non-CLUB token rejected');}
}
try{$media->promote('tampered',$admin->id,$landing->id);check(false,'cipher guard');}
catch(Illuminate\Validation\ValidationException){check(true,'tampered encrypted token rejected');}
$data=['title'=>$landing->title,'slug'=>$landing->slug,'campaign'=>$landing->campaign,'configuration'=>$originalDraft,'revision'=>$landing->revision,'commercial_image_upload'=>$upload['token'],'commercial_image_remove'=>false];
$invalidBefore=$landing->fresh()->toArray();
web('PUT',$base,[...$data,'commercial_image_upload'=>'expired-or-tampered']);
$invalidEditor=payload(web('GET',$editPath));
check(isset($invalidEditor['props']['errors']['commercial_image_upload']) && $landing->fresh()->toArray()===$invalidBefore,'invalid token on save rolls back draft and published image');
check(web('PUT',$base,$data)->isRedirect(),'save draft promotes upload in existing editor transaction');$landing->refresh();
$key=$landing->configuration['commercial']['image'];
check($media->isMediaKey($key) && str_starts_with($key,'club/landings/'.$landing->id.'/cover/') && $disk->exists($key),'permanent WebP saved under correct CLUB landing prefix');
check($landing->published_configuration===$livePublished,'media draft save leaves public snapshot byte-identical');
$publicBefore=payload(web('GET','/'))['props']['landing']['social'];
check(!str_contains(json_encode($publicBefore),$key),'draft upload absent from LIVE Open Graph');
check(web('POST',$base.'/publicar',['revision'=>$landing->revision])->isRedirect(),'publish promotes image reference explicitly');$landing->refresh();
$card=App\Support\LandingCommercial::forLanding($landing);$social=payload(web('GET','/'))['props']['landing']['social'];
check(str_contains($card['image_url'],'/rs:fill:960:540/') && str_contains($card['image_url'],$key),'catalog uses published MinIO image via imgproxy');
check(str_contains($social['image_url'],'/rs:fill:1200:630/') && str_contains($social['image_url'],'/f:jpg/') && str_contains($social['image_url'],$key),'Open Graph selects stable public JPEG 1200x630');
$path=substr($social['image_url'],strlen(config('media.imgproxy_url'))+1);[$sig,$processing]=explode('/',$path,2);
$expected=rtrim(strtr(base64_encode(hash_hmac('sha256',hex2bin(config('media.imgproxy_salt')).'/'.$processing,hex2bin(config('media.imgproxy_key')),true)),'+/','-_'),'=');
check(hash_equals($expected,$sig) && !str_contains($social['image_url'],'X-Amz-Expires'),'imgproxy HMAC correct and no expiring S3 presigned URL');
$mediaEditor=payload(web('GET',$editPath));
$mediaWelcome=payload(web('GET',$base.'/preview'));
$replacement=$media->temporary(fixtureUpload('webp'),$admin->id);
$data['configuration']=$landing->configuration;
check(web('PUT',$base,[...$data,'revision'=>$landing->revision,'commercial_image_upload'=>$replacement['token']])->isRedirect(),'replace image in draft');$landing->refresh();$replacementKey=$landing->configuration['commercial']['image'];
check($replacementKey!==$key && $disk->exists($key) && $landing->published_configuration['commercial']['image']===$key,'replace retains previously published image');
web('POST',$base.'/publicar',['revision'=>$landing->revision]);$landing->refresh();
check(app(MediaReferences::class)->contains($key) && DB::table('marketing_landing_snapshots')->where('landing_id',$landing->id)->exists(),'published historical snapshot retains old image reference');
web('PUT',$base,[...$data,'configuration'=>$landing->configuration,'revision'=>$landing->revision,'commercial_image_upload'=>'','commercial_image_remove'=>true]);$landing->refresh();
check($landing->configuration['commercial']['image']===null && $landing->published_configuration['commercial']['image']===$replacementKey,'remove affects draft only');
web('POST',$base.'/publicar',['revision'=>$landing->revision]);$landing->refresh();
check($landing->published_configuration['commercial']['image']===null && app(MediaReferences::class)->contains($replacementKey),'publish removal retains prior public image in history');
check($url->url('https://example.test/legacy.jpg')==='https://example.test/legacy.jpg','configured legacy HTTPS URLs remain compatible');
check(!$media->isMediaKey('landings/1/cover/'.Str::uuid().'.webp') && !$media->isTemporaryKey('tmp/'.Str::uuid().'/master.webp'),'COM root namespaces never accepted as CLUB keys');
// Fake disk lives solely in container tmpfs. No live bucket mutations in this suite.
$expiredTmp='club/tmp/'.Str::uuid().'/master.webp';$freshTmp='club/tmp/'.Str::uuid().'/master.webp';
$orphan='club/landings/'.$landing->id.'/cover/'.Str::uuid().'.webp';
$outside='landings/123/cover/'.Str::uuid().'.webp';
foreach([$expiredTmp,$freshTmp,$orphan,$outside] as $object)$disk->put($object,'fixture');
foreach([$expiredTmp,$orphan,$key,$replacementKey,$outside] as $object)touch($disk->path($object),now()->subHours(100)->timestamp);
check(Artisan::call('media:gc',['--dry-run'=>true])===0 && $disk->exists($expiredTmp),'GC dry run does not delete');
check(Artisan::call('media:gc')===0,'GC first pass succeeds');
check(!$disk->exists($expiredTmp) && $disk->exists($freshTmp) && $disk->exists($orphan),'24h temporary expiry and first-observation orphan grace');
check($disk->exists($key) && $disk->exists($replacementKey) && $disk->exists($outside),'GC preserves history and every COM object');
$marker='club/gc/'.hash('sha256',$orphan).'.json';
$disk->put($marker,json_encode(['key'=>$orphan,'since'=>now()->subHours(49)->timestamp]));
check(Artisan::call('media:gc')===0 && !$disk->exists($orphan) && $disk->exists($outside),'GC deletes unreferenced CLUB object after 48h and never touches COM');
check(DB::table('affiliate_short_links')->orderBy('code')->get()->toJson()===$linksBefore,'upload publish replace remove and GC preserve affiliate links');
// CSRF must be enforced for multipart endpoint outside the test bypass.
$app->instance('env','production');
check(uploadRequest(fixtureUpload())->getStatusCode()===419,'multipart upload protected by CSRF');$app->instance('env','testing');
file_put_contents('/evidence/media-browser-fixtures.json',json_encode(['editor'=>$mediaEditor,'welcome'=>$mediaWelcome,'upload'=>$upload,'card'=>$card,'social'=>$social],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
// Restore isolated landing so existing catalog pagination assertions remain independent.
$landing->configuration=$originalDraft;$landing->published_configuration=$livePublished;$landing->save();$offers->syncClub();

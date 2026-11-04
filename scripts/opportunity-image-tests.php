<?php

// Included inside landing-tests.php's isolated PostgreSQL schema and test-only MinIO disk.
$oppStart = count($passed);
config(['media.imgproxy_key'=>str_repeat('a1',32),'media.imgproxy_salt'=>str_repeat('b2',32),
    'filesystems.disks.minio-media.key'=>'fixture','filesystems.disks.minio-media.secret'=>'fixture',
    'ads.webinar_enabled'=>true,'ads.privacy_ready'=>true,'ads.meta.pixel_id'=>'123456789012345','ads.meta.access_token'=>'fixture']);
Illuminate\Support\Facades\Storage::fake('minio-media');
$oppDisk = Illuminate\Support\Facades\Storage::disk('minio-media');
$oppMedia = app(App\Services\MediaUploadService::class);
function oppFile(string $format='png'): Illuminate\Http\UploadedFile {
    $path='/tmp/opp-'.Illuminate\Support\Str::uuid().'.'.$format;
    $im=imagecreatetruecolor(200,100);
    imagealphablending($im,false);imagesavealpha($im,true);
    imagefill($im,0,0,imagecolorallocatealpha($im,0,0,0,127));
    imagefilledellipse($im,100,50,90,70,imagecolorallocatealpha($im,255,90,79,0));
    match($format){'png'=>imagepng($im,$path),'webp'=>imagewebp($im,$path,90),'jpg'=>imagejpeg($im,$path,90)};
    imagedestroy($im);
    return new Illuminate\Http\UploadedFile($path,'fixture.'.$format,null,null,true);
}
function oppUpload(Illuminate\Http\UploadedFile $file): Symfony\Component\HttpFoundation\Response {
    global $app,$browserCookies;
    Auth::forgetGuards();
    $request=Illuminate\Http\Request::create('https://jakawi.club/media/uploads','POST',['purpose'=>'opportunity'],$browserCookies,['image'=>$file],['HTTP_ACCEPT'=>'application/json','REMOTE_ADDR'=>'192.0.2.60']);
    $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$response=$kernel->handle($request);$kernel->terminate($request,$response);
    foreach($response->headers->getCookies() as $cookie)$browserCookies[$cookie->getName()]=$cookie->getValue();
    return $response;
}
$oppUpload=null;
foreach(['png','webp','jpg'] as $format){
    $file=oppFile($format);$response=oppUpload($file);unlink($file->getPathname());
    check($response->getStatusCode()===200,'Opportunity authorized '.$format.' upload');
    $oppUpload=payload($response);
    $token=json_decode(Illuminate\Support\Facades\Crypt::decryptString($oppUpload['token']),true);
    $bytes=$oppDisk->get($token['key']);$image=imagecreatefromstring($bytes);$size=getimagesizefromstring($bytes);
    check($size[0]===200 && $size[1]===100 && $size['mime']==='image/webp','Opportunity '.$format.' optimized without upscale or crop');
    if($format!=='jpg')check((imagecolorsforindex($image,imagecolorat($image,0,0))['alpha'])===127 && imagecolorsforindex($image,imagecolorat($image,100,50))['alpha']===0,'Opportunity '.$format.' alpha survives WebP encoding');
    if ($format === 'png') file_put_contents('/evidence/transparent-fixture.webp', $bytes);
    imagedestroy($image);
    check(str_contains($oppUpload['url'],'/rs:fit:1600:0:0/') && !str_contains($oppUpload['url'],'/bg:') && str_contains($oppUpload['url'],'/f:webp/'),'Opportunity alpha-safe signed delivery '.$format);
    check($oppUpload['metadata']['bytes']===strlen($bytes) && $oppUpload['metadata']['width']===200,'Opportunity optimized metadata '.$format);
}
// Resampling must retain alpha, too, and never crop the source ratio.
foreach (['png','webp'] as $format) {
    $path='/tmp/opp-large.'.$format;
    $im=imagecreatetruecolor(2000,1000);imagealphablending($im,false);imagesavealpha($im,true);
    imagefill($im,0,0,imagecolorallocatealpha($im,0,0,0,127));
    imagefilledellipse($im,1000,500,600,600,imagecolorallocatealpha($im,255,90,79,0));
    if($format==='png')imagepng($im,$path);else imagewebp($im,$path,90);
    imagedestroy($im);
    $result=$oppMedia->temporary(new Illuminate\Http\UploadedFile($path,'large.'.$format,null,null,true),$admin->id,'opportunity');unlink($path);
    $decoded=json_decode(Illuminate\Support\Facades\Crypt::decryptString($result['token']),true);
    $image=imagecreatefromstring($oppDisk->get($decoded['key']));
    check(imagesx($image)===1600 && imagesy($image)===800 && imagecolorsforindex($image,imagecolorat($image,0,0))['alpha']===127,'Opportunity resized '.$format.' preserves alpha and aspect ratio at 1600');
    imagedestroy($image);
}
$bad='/tmp/opp-bad';file_put_contents($bad,'<svg xmlns="http://www.w3.org/2000/svg"></svg>');
check(oppUpload(new Illuminate\Http\UploadedFile($bad,'spoof.png','image/png',null,true))->getStatusCode()===422,'Opportunity SVG and spoofed MIME rejected');
unlink($bad);
$big='/tmp/opp-big';$stream=fopen($big,'w');ftruncate($stream,5*1024*1024+1);fclose($stream);
check(oppUpload(new Illuminate\Http\UploadedFile($big,'big.png',null,null,true))->getStatusCode()===422,'Opportunity greater than 5 MiB rejected');unlink($big);
$oppCookies=$browserCookies;freshBrowser();
check(oppUpload(oppFile())->getStatusCode()===401,'Opportunity guest upload blocked');
$browserCookies=$oppCookies;
$admin->refresh();$admin->is_marketing_admin=false;$admin->save();
check(oppUpload(oppFile())->getStatusCode()===403,'Opportunity member upload blocked');
$admin->is_marketing_admin=true;$admin->save();
$home->refresh();$oppOriginal=$home->toArray();
$oppConfig=App\Support\LandingContent::webinarDefaults();
$home->update(['configuration'=>$oppConfig,'published_configuration'=>$oppConfig,'template'=>'WEBINAR','status'=>'published']);
$oppBase='/admin/landings/'.$home->id;
$oppData=fn(array $extra=[])=>[...['title'=>$home->title,'slug'=>$home->slug,'campaign'=>$home->campaign,'revision'=>$home->fresh()->revision,'configuration'=>$home->fresh()->configuration],...$extra];
$beforeInvalid=$home->fresh()->toArray();
check(http('PUT',$oppBase,$oppData(['opportunity_image_upload'=>'tampered']))->getStatusCode()===422 && $home->fresh()->toArray()===$beforeInvalid,'Opportunity tampered token returns controlled error and preserves draft');
$decoded=json_decode(Illuminate\Support\Facades\Crypt::decryptString($oppUpload['token']),true);
foreach ([['user'=>$admin->id+999],['expires'=>now()->timestamp],['purpose'=>'card']] as $patch) {
    $invalid=Illuminate\Support\Facades\Crypt::encryptString(json_encode([...$decoded,...$patch]));
    check(http('PUT',$oppBase,$oppData(['opportunity_image_upload'=>$invalid]))->getStatusCode()===422 && $home->fresh()->toArray()===$beforeInvalid,'Opportunity foreign expired or wrong-purpose token rejected without mutation');
}
check(http('PUT',$oppBase,$oppData(['opportunity_image_upload'=>$oppUpload['token'],'opportunity_image_alt'=>'Conexiones JAKAWI']))->isRedirect(),'Opportunity save promotes owner-bound temporary upload');
$home->refresh();$oppKey=$home->configuration['webinar']['opportunity']['image']['asset_id'];
check($oppMedia->isMediaKey($oppKey) && $oppDisk->exists($oppKey),'Opportunity asset_id uses existing unique CLUB media key');
check(!isset($home->published_configuration['webinar']['opportunity']['image']),'Opportunity draft image never published implicitly');
$preview=payload(http('GET',$oppBase.'/preview',[],true));
check(str_contains($preview['props']['landing']['opportunity_image_url'],$oppKey),'Opportunity saved draft preview resolves image');
$editor=payload(http('GET',$oppBase.'/editar',[],true));
check($editor['props']['opportunity_image_metadata']['width']===200 && str_contains($editor['props']['opportunity_image_url'],$oppKey),'Opportunity editor reload restores image and metadata');
$edited=$home->configuration;$edited['webinar']['opportunity']['description']='Un texto editado';
http('PUT',$oppBase,$oppData(['configuration'=>$edited]));$home->refresh();
check($home->configuration['webinar']['opportunity']['image']['asset_id']===$oppKey,'Opportunity editing other text preserves asset');
$export=payload(http('GET',$oppBase.'/export'));
check($export['schema']===App\Support\LandingContent::SCHEMA && $export['content']['webinar']['opportunity']['image']['asset_id']===$oppKey && !str_contains(json_encode($export),'img.jakawi.com'),'Opportunity V3 export contains asset_id and no delivery URL');
$import=App\Support\LandingContent::import(json_encode($export),App\Support\LandingContent::webinarDefaults(),true);
check($import['webinar']['opportunity']['image']===$home->configuration['webinar']['opportunity']['image'],'Opportunity V3 import/export roundtrip');
$patch=['schema'=>App\Support\LandingContent::SCHEMA,'content'=>['webinar'=>['opportunity'=>['title'=>'Otro título']]]];
check(App\Support\LandingContent::import(json_encode($patch),$home->configuration)['webinar']['opportunity']['image']['asset_id']===$oppKey,'Opportunity partial JSON preserves omitted image');
$patch['content']['webinar']['opportunity']['image']=null;
check(App\Support\LandingContent::import(json_encode($patch),$home->configuration)['webinar']['opportunity']['image']===null,'Opportunity JSON null detaches image');
check(!isset(App\Support\LandingContent::validate(App\Support\LandingContent::webinarDefaults())['webinar']['opportunity']['image']),'Opportunity historical V3 without image supported');
foreach(['https://example.test/x.webp','club/landings/999/cover/'.Illuminate\Support\Str::uuid().'.webp','../outside.webp',$token['key']] as $invalid){
    $next=$home->configuration;$next['webinar']['opportunity']['image']['asset_id']=$invalid;
    check(http('PUT',$oppBase,$oppData(['configuration'=>$next]))->getStatusCode()===422,'Opportunity invalid or unavailable asset_id rejected');
}
$unowned='club/landings/999/cover/'.Illuminate\Support\Str::uuid().'.webp';$oppDisk->put($unowned,$bytes);
$next=$home->configuration;$next['webinar']['opportunity']['image']['asset_id']=$unowned;
check(http('PUT',$oppBase,$oppData(['configuration'=>$next]))->getStatusCode()===422,'Opportunity existing but unreferenced asset rejected');
http('POST',$oppBase.'/publicar',['revision'=>$home->fresh()->revision]);
$routes=['/','/principal','/ads/meta/webinar'];$oppPublished=[];
foreach($routes as $route){$response=http('GET',$route,[],true);check($response->getStatusCode()===200,'Opportunity channel available '.$route);$oppPublished[$route]=payload($response);}
check($oppPublished['/']['props']['landing']['content']===$oppPublished['/ads/meta/webinar']['props']['landing']['content'] && $oppPublished['/']['props']['landing']['opportunity_image_url']===$oppPublished['/ads/meta/webinar']['props']['landing']['opportunity_image_url'],'Opportunity organic and Meta share published content and exact signed image');
file_put_contents('/evidence/opportunity-fixtures.json',json_encode(['editor'=>$editor,'preview'=>$preview,'published'=>$oppPublished],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
http('POST',$oppBase.'/duplicar');
$shared=App\Models\MarketingLanding::where('slug','like','principal-copia-%')->firstOrFail();
check($shared->configuration['webinar']['opportunity']['image']['asset_id']===$oppKey,'Opportunity duplicated draft shares existing asset');
$file=oppFile('webp');$replacement=$oppMedia->temporary($file,$admin->id,'opportunity');unlink($file->getPathname());
check(http('PUT',$oppBase,$oppData(['opportunity_image_upload'=>$replacement['token'],'opportunity_image_alt'=>'Nueva imagen']))->isRedirect(),'Opportunity replace in draft');
$nextKey=$home->fresh()->configuration['webinar']['opportunity']['image']['asset_id'];
check($nextKey!==$oppKey && $oppDisk->exists($oppKey) && payload(http('GET','/ads/meta/webinar',[],true))['props']['landing']['content']['webinar']['opportunity']['image']['asset_id']===$oppKey,'Opportunity replacement preserves public and shared previous asset');
http('POST',$oppBase.'/publicar',['revision'=>$home->fresh()->revision]);
foreach($routes as $route)check(payload(http('GET',$route,[],true))['props']['landing']['content']['webinar']['opportunity']['image']['asset_id']===$nextKey,'Opportunity one publication updates channel '.$route);
check(http('PUT',$oppBase,$oppData(['opportunity_image_remove'=>true]))->isRedirect(),'Opportunity logical removal saved');
check($home->fresh()->configuration['webinar']['opportunity']['image']===null && $home->fresh()->published_configuration['webinar']['opportunity']['image']['asset_id']===$nextKey,'Opportunity remove remains draft until publish');
http('POST',$oppBase.'/publicar',['revision'=>$home->fresh()->revision]);
foreach($routes as $route){$props=payload(http('GET',$route,[],true))['props'];check($props['landing']['content']['webinar']['opportunity']['image']===null && $props['landing']['opportunity_image_url']===null,'Opportunity one removal publication updates '.$route);}
check(Artisan::call('media:gc')===0 && $oppDisk->exists($oppKey) && $oppDisk->exists($nextKey),'Opportunity shared assets and snapshots survive existing GC');
check($shared->fresh()->configuration['webinar']['opportunity']['image']['asset_id']===$oppKey,'Opportunity removal never edits another landing');
// Removing a broken historical reference must not require a successful MinIO read.
$broken=$home->fresh()->configuration;
$broken['webinar']['opportunity']['image']=['asset_id'=>'club/landings/999/cover/'.Illuminate\Support\Str::uuid().'.webp','alt'=>'','position'=>'right','fit'=>'contain'];
$home->update(['configuration'=>$broken]);
check(http('PUT',$oppBase,$oppData(['opportunity_image_remove'=>true]))->isRedirect() && $home->fresh()->configuration['webinar']['opportunity']['image']===null,'Opportunity detach works even when previous asset is unavailable');
file_put_contents('/evidence/opportunity-tests.json',json_encode(['passed'=>array_slice($passed,$oppStart),'storage'=>'Storage::fake minio-media in container tmpfs','database'=>'temporary schema','live_mutations'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
$home->forceFill($oppOriginal);$home->save();

<?php
use App\Support\LandingContent;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;

Notification::fake();
Http::preventStrayRequests();
config(['jakawi.registration_open'=>false,'mail.default'=>'log']);
$uuid=$admin->uuid;$id=$admin->id;
check($admin->fresh()->theme_preference==='system','additive migration defaults existing account to system');
$columns=Schema::getColumns('users');
check(collect($columns)->firstWhere('name','id')['type_name']==='int8','users.id remains bigint');
foreach(['light','dark','system'] as $value){
 $r=http('PATCH','/settings/appearance',['theme_preference'=>$value],false,['HTTP_X_APPEARANCE_IDENTITY'=>$uuid]);
 check($r->getStatusCode()===200 && $admin->fresh()->theme_preference===$value,'authenticated preference persists '.$value);
 $page=payload(http('GET','/',[],true));
 check($page['props']['theme_preference']===$value && $page['props']['appearance_identity']===$uuid,'account authoritative on public landing '.$value);
}
check(http('PATCH','/settings/appearance',['theme_preference'=>'sepia'],false,['HTTP_X_APPEARANCE_IDENTITY'=>$uuid])->getStatusCode()===422,'reject noncanonical preference');
check(http('PATCH','/settings/appearance',['theme_preference'=>'dark'],false,['HTTP_X_APPEARANCE_IDENTITY'=>'other-account'])->getStatusCode()===409,'reject stale account request');
check($admin->fresh()->id===$id && $admin->fresh()->uuid===$uuid,'identity and permanent UUID preserved');
http('PATCH','/settings/appearance',['theme_preference'=>'dark'],false,['HTTP_X_APPEARANCE_IDENTITY'=>$uuid]);
$f=[];
$capture=function(string $name,string $url)use(&$f):void {
 $page=payload(http('GET',$url,[],true));
 check(isset($page['component']),'HTTP fixture '.$name);
 $html=http('GET',$url)->getContent();
 check(str_contains($html,'window.appearanceStore') && strpos($html,'window.appearanceStore')<strpos($html,'/build/'),'prepaint bootstrap before CSS '.$name);
 $f[$name]=['page'=>$page,'html'=>$html,'url'=>$url];
};
$capture('account','/mi-cuenta');
$capture('admin','/admin/landings');
$capture('editor','/admin/landings/'.$home->id.'/editar');
$capture('preview','/admin/landings/'.$home->id.'/preview');
$capture('confirmation','/admin/landings/'.$home->id.'/preview/confirmado');
// Affiliate access remains unchanged: generate display data in the isolated schema only.
$affiliate=payload(http('GET','/afiliados',[],true));
if(isset($affiliate['component'])) $capture('affiliates','/afiliados');
check(http('POST','/logout')->isRedirect(),'logout');
check(http('PATCH','/settings/appearance',['theme_preference'=>'light'])->getStatusCode()===401,'guest cannot persist to account');
freshBrowser();
check(http('POST','/login',['email'=>$admin->email,'password'=>'PasswordEditor123'])->isRedirect(),'new browser login');
check(payload(http('GET','/',[],true))['props']['theme_preference']==='dark','new browser recovers account preference');
$other=new User(['name'=>'Other account','email'=>'other@example.test','password'=>'OtherPassword123']);$other->email_verified_at=now();$other->activated_at=now();$other->save();
freshBrowser();http('POST','/login',['email'=>$other->email,'password'=>'OtherPassword123']);
check(payload(http('GET','/',[],true))['props']['theme_preference']==='system','other account never inherits first preference');
check(http('PATCH','/settings/appearance',['theme_preference'=>'light'],false,['HTTP_X_APPEARANCE_IDENTITY'=>$uuid])->getStatusCode()===409,'cross-account stale update rejected');
check($admin->fresh()->theme_preference==='dark' && $other->fresh()->theme_preference==='system','cross-account updates leave both preferences intact');
http('POST','/logout');freshBrowser();
$capture('login','/login');$capture('recovery','/recuperar-password');$capture('activation','/activar/'.str_repeat('a',64));
$capture('reset','/restablecer-password/'.str_repeat('a',64));
$capture('membership','/activar');
foreach(['LEAD_VSL','WEBINAR','WAITLIST'] as $template){
 $content=$template==='WEBINAR'?LandingContent::webinarDefaults():LandingContent::defaults();
 $content['presentation']['template']=$template;
 $home->update(['configuration'=>$content,'published_configuration'=>$content,'status'=>'published']);
 $capture($template,'/');
}
check(App\Models\User::count()===2,'appearance checks create only two isolated accounts');
check(config('jakawi.registration_open')===false && config('mail.default')==='log','appearance preserves launch and mail flags');
file_put_contents('/evidence/fixtures.json',json_encode($f,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

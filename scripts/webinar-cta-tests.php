<?php
use App\Support\LandingContent as CtaContent;
use App\Models\MarketingLanding as CtaLanding;
config(['ads.webinar_enabled'=>true,'ads.privacy_ready'=>true,'ads.meta.pixel_id'=>'123456789','ads.meta.token'=>'fixture','jakawi.registration_open'=>true,'jakawi.transactional_mail_ready'=>true,'mail.default'=>'smtp','mail.mailers.smtp.host'=>'smtp.example.test','mail.from.address'=>'test@jakawi.club']);
Illuminate\Support\Facades\Notification::fake();
Illuminate\Support\Facades\Http::preventStrayRequests();
$content=CtaContent::webinarDefaults();
check($content['registration']['navigation_label']==='QUIERO RESERVAR MI LUGAR GRATIS' && $content['registration']['submit_label']==='COMPLETAR MI REGISTRO','separate seed labels');
$legacy=$content;unset($legacy['registration']['navigation_label']);$legacy['registration']['submit_label']='MI CTA ANTERIOR';
check(CtaContent::withPresentation($legacy)['registration']['navigation_label']===null && CtaContent::withPresentation($legacy)['registration']['submit_label']==='MI CTA ANTERIOR','legacy explicit shared CTA preserved');
foreach ([null,'','IR AL FORMULARIO'] as $value){
 $variant=$content;$variant['registration']['navigation_label']=$value;
 check(CtaContent::validate($variant)['registration']['navigation_label']===($value?:null),'navigation optional '.json_encode($value));
}
check(CtaContent::import(json_encode(CtaContent::envelope($content)),CtaContent::defaults(),true)['registration']==$content['registration'],'V3 roundtrip separated labels');
$home->update(['configuration'=>$legacy,'published_configuration'=>$legacy,'status'=>'published']);
$base='/admin/landings/'.$home->id;
$before=$home->fresh()->published_configuration;
$data=['title'=>$home->title,'slug'=>$home->slug,'configuration'=>$content,'revision'=>$home->revision];
check(http('PUT',$base,$data)->isRedirect(),'save separate CTA draft');
check($home->fresh()->published_configuration===$before,'save does not publish labels');
$preview=payload(http('GET',$base.'/preview',[],true));$editor=payload(http('GET',$base.'/editar',[],true));
check($preview['props']['landing']['content']['registration']==$content['registration'],'preview uses separated fields');
check(http('POST',$base.'/publicar',['revision'=>$home->fresh()->revision])->isRedirect(),'explicit publication of CTA labels');
$pages=[];
foreach(['/','/principal','/ads/meta/webinar'] as $route){$pages[$route]=payload(http('GET',$route,[],true));check($pages[$route]['props']['landing']['content']['registration']==$content['registration'],'channel labels same '.$route);}
$variant=$content;$variant['registration']['navigation_label']='NAVEGAR';$variant['registration']['submit_label']='ENVIAR';
check(CtaContent::import(json_encode(['schema'=>CtaContent::SCHEMA,'content'=>['registration'=>['navigation_label'=>'NAVEGAR','submit_label'=>'ENVIAR']]]),$content)['registration']==$variant['registration'],'two labels independently configurable');
$export=payload(http('GET',$base.'/export'));check($export['content']['registration']['navigation_label']===$content['registration']['navigation_label'],'HTTP export preserves navigation label');
check(App\Models\AdEvent::count()===0 && App\Models\User::count()===1,'GET CTA destinations create neither accounts nor conversions');
file_put_contents('/evidence/cta-fixtures.json',json_encode(['pages'=>$pages,'preview'=>$preview,'editor'=>$editor,'legacy'=>$legacy],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

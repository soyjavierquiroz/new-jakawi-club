<?php
// Runs within landing-tests.php's disposable schema and authenticated admin session.
use App\Support\LandingContent as MetricContent;
use App\Models\MarketingLanding as MetricLanding;

$metricLegacy = MetricContent::webinarDefaults();
check(MetricContent::import(json_encode(MetricContent::envelope($metricLegacy)), $metricLegacy, true) == $metricLegacy, 'metrics old V3 unchanged without metric or segments');
$metricContent = $metricLegacy;
$metricContent['hero']['title_segments'] = [['text'=>'Tu ciudad ', 'style'=>'normal'], ['text'=>'tiene', 'style'=>'coral']];
$metricContent['webinar']['opportunity']['title'] = 'Los negocios necesitan clientes. Las personas quieren experiencias. JAKAWI los conecta.';
$metricContent['webinar']['opportunity']['title_segments'] = [['text'=>'Los negocios necesitan clientes. Las personas quieren experiencias. ', 'style'=>'normal'], ['text'=>'JAKAWI los conecta.', 'style'=>'coral']];
$metricContent['webinar']['early']['title_segments'] = [['text'=>$metricContent['webinar']['early']['title'], 'style'=>'amarillo-acento']];
foreach ($metricContent['benefits']['items'] as $i => &$card) {
    $card['title'] = ['Recomienda sin pagar', 'Conviértete en Pionero', 'Incorpora negocios'][$i];
    $card['metric'] = ['value'=>['15','30','50'][$i], 'unit'=>'%'];
    $card['condition'] = 'Ejemplo editorial de prueba. No es una comisión vigente.';
    $card['offer_status'] = 'Propuesta futura · sin publicación';
}
unset($card);
$noMetric = $metricContent; $noMetric['benefits']['items'][0]['metric'] = null;
check(MetricContent::validate($noMetric)['benefits']['items'][0]['metric'] === null, 'metrics null removes metric without replacing legacy title');
$metricRoundtrip = MetricContent::import(json_encode(MetricContent::envelope($metricContent)), $metricLegacy, true);
check($metricRoundtrip == $metricContent, 'metrics V3 roundtrip metric condition status and title segments');
foreach ([['2.75','Bs'],['1250','puntos'],['100000000000000000000000','unidades-largas']] as [$value,$unit]) {
    $variant=$metricContent; $variant['benefits']['items'][0]['metric']=['value'=>$value,'unit'=>$unit];
    check(MetricContent::validate($variant)['benefits']['items'][0]['metric'] == ['value'=>$value,'unit'=>$unit], 'metrics alternative value and unit '.$unit);
}
foreach (MetricContent::TITLE_STYLES as $style) {
    $variant=$metricContent; $variant['hero']['title_segments'][1]['style']=$style;
    check(MetricContent::validate($variant)['hero']['title_segments'][1]['style']==$style, 'metrics allowed title style '.$style);
}
$metricPlain = MetricContent::import(json_encode(['schema'=>MetricContent::SCHEMA,'content'=>['hero'=>['title'=>'String antiguo nuevo']]]),$metricContent);
check($metricPlain['hero']['title']=='String antiguo nuevo' && !isset($metricPlain['hero']['title_segments']), 'metrics legacy title patch clears obsolete emphasis without replacing text');
$invalidMetrics = [
 ['benefits.items.0.metric.value', '<script>'], ['benefits.items.0.metric.value', 30],
 ['benefits.items.0.metric.unit', ''], ['benefits.items.0.metric.unit', str_repeat('u',17)],
 ['benefits.items.0.metric.css', 'red'], ['benefits.items.0.condition', []], ['benefits.items.0.condition', null], ['benefits.items.0.condition','<b>Vigente</b>'],
 ['hero.title_segments.0.style', 'red'], ['hero.title_segments.0.style', 'font-bold'],
 ['hero.title_segments.0.text', '<script>'], ['hero.title_segments.0.text', 'Texto diferente'],
 ['hero.title_segments.0.className', 'evil'], ['hero.title_segments.0.style', ['color'=>'red']],
 ['hero.title_segments', []], ['hero.title_segments', array_fill(0,33,['text'=>'a','style'=>'normal'])],
];
foreach ($invalidMetrics as [$path,$bad]) {
    $variant=$metricContent; data_set($variant,$path,$bad);
    try { MetricContent::validate($variant); check(false,'metrics reject '.$path); }
    catch (\Illuminate\Validation\ValidationException $e) { check(count($e->errors())>0,'metrics reject '.$path); }
}
$metricData=['title'=>'Métricas aisladas','slug'=>'editorial-metrics-test','configuration'=>$metricContent];
check(http('POST','/admin/landings',$metricData)->isRedirect(),'metrics editor saves draft in isolated schema');
$metricLanding=MetricLanding::where('slug',$metricData['slug'])->firstOrFail(); $metricBase='/admin/landings/'.$metricLanding->id;
check($metricLanding->published_configuration==null && http('GET','/'.$metricLanding->slug)->getStatusCode()==404,'metrics draft does not publish');
$metricPreview=payload(http('GET',$metricBase.'/preview',[],true));
check($metricPreview['props']['landing']['content']['benefits']['items']==$metricContent['benefits']['items'],'metrics preview preserves semantic cards');
check($metricPreview['props']['landing']['content']['hero']['title_segments']==$metricContent['hero']['title_segments'],'metrics preview preserves headline emphasis');
$metricEditor=payload(http('GET',$metricBase.'/editar',[],true));
$metricExport=payload(http('GET',$metricBase.'/export'));
check(MetricContent::import(json_encode($metricExport),$metricContent)==$metricContent,'metrics HTTP export import matches');
check(http('POST',$metricBase.'/publicar',['revision'=>1])->isRedirect(),'metrics explicit publication isolated');
$metricPublished=payload(http('GET','/'.$metricLanding->slug,[],true));
check($metricPublished['props']['landing']['content']['benefits']==$metricPreview['props']['landing']['content']['benefits'],'metrics published cards equal preview');
$snapshot=$metricLanding->fresh()->published_configuration;
$variant=$metricContent; $variant['benefits']['items'][0]['metric']['value']='99';
check(http('PUT',$metricBase,[...$metricData,'configuration'=>$variant,'revision'=>2])->isRedirect() && $metricLanding->fresh()->published_configuration==$snapshot,'metrics save draft preserves published snapshot');
check(payload(http('GET','/'.$metricLanding->slug,[],true))['props']['landing']['content']['benefits']['items'][0]['metric']['value']=='15','metrics public value unchanged until publication');
check(http('POST',$metricBase.'/publicar',['revision'=>3])->isRedirect() && payload(http('GET','/'.$metricLanding->slug,[],true))['props']['landing']['content']['benefits']['items'][0]['metric']['value']=='99','metrics explicit republication updates public value');
// All acquisition channels resolve the same published snapshot of the home landing.
$home->configuration=$metricContent; $home->save();
$homeBase='/admin/landings/'.$home->id;
check(http('POST',$homeBase.'/publicar',['revision'=>$home->revision])->isRedirect(),'metrics publish home isolated');
$channels=[];
foreach (['/','/principal','/ads/meta/webinar'] as $path) $channels[$path]=payload(http('GET',$path,[],true))['props']['landing']['content'];
check($channels['/']==$channels['/principal'] && $channels['/']==$channels['/ads/meta/webinar'],'metrics all active channels same titles styles cards from snapshot');
$homeSnapshot=$home->fresh()->published_configuration;
check(http('PUT',$homeBase,['title'=>$home->title,'slug'=>$home->slug,'configuration'=>$variant,'revision'=>$home->fresh()->revision])->isRedirect(),'metrics home draft saved');
foreach (array_keys($channels) as $path) check(payload(http('GET',$path,[],true))['props']['landing']['content']==$channels[$path],'metrics draft leaves channel unchanged '.$path);
check(http('POST',$homeBase.'/publicar',['revision'=>$home->fresh()->revision])->isRedirect(),'metrics publish home update isolated');
$updated=[];
foreach (array_keys($channels) as $path) $updated[$path]=payload(http('GET',$path,[],true))['props']['landing']['content'];
check($updated['/']==$updated['/principal'] && $updated['/']==$updated['/ads/meta/webinar'] && $updated['/']['benefits']['items'][0]['metric']['value']=='99','metrics publication updates all active channels together');
file_put_contents('/evidence/editorial-metrics-fixtures.json' ,json_encode(['editor'=>$metricEditor,'preview'=>$metricPreview,'public'=>$metricPublished,'legacy'=>$metricLegacy],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

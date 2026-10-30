<?php

// Included inside the existing disposable PostgreSQL schema, never in public.
use App\Support\LandingContent as CopyContent;
use App\Models\MarketingLanding as CopyLanding;

$copyOriginal = CopyLanding::orderBy('id')->get()->toArray();
$copyFields = CopyContent::registrationDefaults();
foreach (['LEAD_VSL', 'WEBINAR', 'WAITLIST'] as $copyTemplate) {
    $legacy = $copyTemplate === 'WEBINAR' ? CopyContent::webinarDefaults() : CopyContent::defaults();
    $legacy['presentation']['template'] = $copyTemplate;
    foreach (array_keys($copyFields) as $key) unset($legacy['registration'][$key]);
    foreach ([CopyContent::LEGACY_SCHEMA, CopyContent::V2_SCHEMA, CopyContent::SCHEMA] as $copySchema) {
        $doc = CopyContent::envelope($legacy);
        $doc['schema'] = $copySchema;
        if ($copySchema !== CopyContent::SCHEMA) $doc['content'] = array_intersect_key($doc['content'], array_flip(CopyContent::EDITORIAL));
        foreach (array_keys($copyFields) as $key) unset($doc['content']['registration'][$key]);
        $normalized = CopyContent::import(json_encode($doc), $legacy, $copySchema === CopyContent::SCHEMA);
        check(array_intersect_key($normalized['registration'], $copyFields) === $copyFields, 'copy defaults históricos '.$copyTemplate.' '.$copySchema);
    }
}
$copy = $v3Config;
$copy['registration'] = [...$copy['registration'], 'form_title' => 'Tu invitación al webinar', 'form_subtitle' => 'Completa tus datos para reservar.',
    'name_placeholder' => 'Nombre para tu invitación', 'phone_placeholder' => 'WhatsApp de contacto', 'email_placeholder' => 'Email para activar tu cuenta',
    'submit_label' => 'RESERVAR MI INVITACIÓN', 'submit_microcopy' => 'Sin costo en esta etapa.', 'existing_account_label' => '¿Tienes acceso?', 'existing_account_link_label' => 'Entrar a mi cuenta'];
$copyData = ['title'=>'Copy aislado','slug'=>'copy-aislado','configuration'=>$copy];
check(http('POST','/admin/landings',$copyData)->isRedirect(), 'copy guardar desde controles administrativos existentes');
$copyLanding = CopyLanding::where('slug','copy-aislado')->firstOrFail();
$copyBase = '/admin/landings/'.$copyLanding->id;
$copyPreview = payload(http('GET',$copyBase.'/preview',[],true));
$copyEditor = payload(http('GET',$copyBase.'/editar',[],true));
check($copyPreview['props']['landing']['content']['registration'] == $copy['registration'] && !$copyPreview['props']['launch']['registration_open'], 'copy preview real conserva nueve campos y bloquea registro');
$copyExport = payload(http('GET',$copyBase.'/export'));
check(CopyContent::import(json_encode($copyExport),CopyContent::defaults(),true)['registration'] == $copy['registration'], 'copy export/import creación roundtrip completo');
$copyPatch = ['schema'=>CopyContent::SCHEMA,'content'=>['registration'=>['submit_label'=>'RESERVAR MI PLAZA']]];
$copyPatched = payload(http('POST',$copyBase.'/import',['editorial_json'=>json_encode($copyPatch)]))['configuration'];
check($copyPatched['registration'] == [...$copy['registration'],'submit_label'=>'RESERVAR MI PLAZA'] && $copyLanding->fresh()->configuration == $copy, 'copy parche conserva omitidos sin persistir');
$copyPatch['content']['registration']['submit_label'] = null;
$copyReset = CopyContent::import(json_encode($copyPatch),$copy);
check($copyReset['registration']['submit_label'] === null && $copyReset['hero'] === $copy['hero'], 'copy null restablece fallback hero sin materializar CTA de otra campaña');
check(http('POST',$copyBase.'/publicar',['revision'=>1])->isRedirect(), 'copy publicación explícita en esquema aislado');
$copyPublic = payload(http('GET','/copy-aislado',[],true));
check($copyPublic['props']['landing']['content']['registration'] == $copyPreview['props']['landing']['content']['registration'], 'copy público y preview equivalentes');
$copySnapshot = $copyLanding->fresh()->published_configuration;
check(http('PUT',$copyBase,[...$copyData,'configuration'=>$copyReset,'revision'=>2])->isRedirect() && $copyLanding->fresh()->published_configuration === $copySnapshot, 'copy guardar borrador no altera snapshot publicado');
foreach (array_keys($copyFields) as $key) {
    foreach (['<b>Texto</b>', 'https://example.test', 'Visita www.example.com', 'javascript:alert(1)', 'example.com', 12, false, ['label'=>'Texto'], str_repeat('a',1201)] as $bad) {
        $badContent = $copy;
        $badContent['registration'][$key] = $bad;
        $response = http('PUT',$copyBase,[...$copyData,'configuration'=>$badContent,'revision'=>3]);
        $import = http('POST',$copyBase.'/import',['editorial_json'=>json_encode(['schema'=>CopyContent::SCHEMA,'content'=>['registration'=>[$key=>$bad]]])]);
        check($response->getStatusCode()===422 && isset(payload($response)['errors']['content.registration.'.$key])
            && $import->getStatusCode()===422 && isset(payload($import)['errors']['content.registration.'.$key]), 'copy rechaza texto inseguro en guardado/import '.$key.' '.json_encode($bad === str_repeat('a',1201) ? 'demasiado largo' : $bad));
    }
}
foreach (['endpoint','action','privacy','fields','name','validation','existing_account_url'] as $key) {
    $response = http('POST',$copyBase.'/import',['editorial_json'=>json_encode(['schema'=>CopyContent::SCHEMA,'content'=>['registration'=>[$key=>'/admin']]])]);
    check($response->getStatusCode()===422 && isset(payload($response)['errors']['content.registration.'.$key]), 'copy no admite cambiar operación '.$key);
}
foreach (['form_subtitle','submit_microcopy'] as $key) {
    foreach (['',null] as $blank) {
        $blankCopy = $copy;
        $blankCopy['registration'][$key] = $blank;
        check(CopyContent::validate($blankCopy)['registration'][$key] === '', 'copy permite omitir auxiliar '.$key);
        check(http('PUT',$copyBase,[...$copyData,'configuration'=>$blankCopy,'revision'=>$copyLanding->fresh()->revision])->isRedirect(), 'copy auxiliar vacío pasa middleware HTTP '.$key);
    }
}
foreach ($copyOriginal as $row) check(CopyLanding::findOrFail($row['id'])->toArray() == $row, 'copy no modifica otra landing '.$row['id']);
file_put_contents('/evidence/registration-copy-fixtures.json',json_encode(['preview'=>$copyPreview,'public'=>$copyPublic,'editor'=>$copyEditor,'content'=>$copy,'export'=>$copyExport,'fallback'=>$copyReset],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

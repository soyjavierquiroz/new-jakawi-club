<?php

// Included by landing-tests.php, inside its disposable schema and authenticated admin session.
use App\Support\LandingContent as V3Content;
use App\Models\MarketingLanding as V3Landing;

$v3Document = json_decode(file_get_contents('/reference/landings/JAKAWI-WEBINAR-V3-EXAMPLE.json'), true, 24, JSON_THROW_ON_ERROR);
$v3Snapshots = V3Landing::pluck('published_configuration', 'id')->all();
$v3Count = V3Landing::count();
$v3Foreign = V3Content::webinarDefaults();
$v3Foreign['presentation']['webinar']['starts_at'] = '2030-11-10T19:30:00-04:00';
$v3Foreign['video']['youtube_url'] = 'https://www.youtube.com/watch?v=M7lc1UVf-VE';
$v3Foreign['confirmation_video']['youtube_url'] = 'https://www.youtube.com/watch?v=aqz-KE-bpKQ';
$v3Foreign['whatsapp_group'] = ['mode' => 'custom', 'url' => 'https://chat.whatsapp.com/ForeignCampaignFixture123'];
$v3Foreign['confirmation_whatsapp_group'] = ['mode' => 'inherit', 'url' => null];
$v3Foreign['next_step'] = ['action' => 'whatsapp', 'redirect_seconds' => 60];
config(['jakawi.video_url' => $v3Foreign['video']['youtube_url'], 'jakawi.confirmation_video_url' => $v3Foreign['confirmation_video']['youtube_url']]);
$v3Result = http('POST', '/admin/landings/import', ['editorial_json' => json_encode($v3Document), 'configuration' => $v3Foreign]);
check($v3Result->getStatusCode() === 200, 'v3 ejemplo oficial completo importable en creación sin seed');
$v3Config = payload($v3Result)['configuration'];
check(V3Content::envelope($v3Config) == $v3Document, 'v3 documento nuevo conserva exactamente su contenido editorial');
check($v3Config['video']['youtube_url'] === null && $v3Config['confirmation_video']['youtube_url'] === null
    && $v3Config['presentation']['webinar']['starts_at'] === null && $v3Config['whatsapp_group']['mode'] === 'none'
    && $v3Config['confirmation_whatsapp_group']['mode'] === 'none' && $v3Config['next_step']['action'] === 'recover', 'v3 nuevo no hereda medios fecha grupos ni opciones de otra campaña');
check(V3Landing::count() === $v3Count, 'v3 importar creación no crea ni guarda landing');
$v3Data = ['title' => 'JSON v3 aislado', 'slug' => 'json-v3-aislado', 'configuration' => $v3Config];
check(http('POST', '/admin/landings', $v3Data)->isRedirect(), 'v3 guardar independiente crea borrador aislado');
$v3Landing = V3Landing::where('slug', $v3Data['slug'])->firstOrFail();
$v3Base = '/admin/landings/'.$v3Landing->id;
check($v3Landing->published_configuration === null && http('GET', '/'.$v3Landing->slug)->getStatusCode() === 404, 'v3 nuevo permanece sin publicar');
$v3Preview = payload(http('GET', $v3Base.'/preview', [], true));
$v3Confirmation = payload(http('GET', $v3Base.'/preview/confirmado', [], true));
check(isset($v3Preview['props']['landing']['content']['presentation']['webinar']) && ! $v3Preview['props']['launch']['registration_open'], 'v3 preview captación composición activada y registro deshabilitado');
check($v3Confirmation['props']['confirmation']['preview'] && $v3Confirmation['props']['confirmation']['video']['youtube_url'] === null
    && $v3Confirmation['props']['confirmation']['whatsapp_group_url'] === null, 'v3 preview confirmación sin video ni grupo aunque existan fallbacks');
$v3Export = payload(http('GET', $v3Base.'/export'));
$v3Roundtrip = http('POST', $v3Base.'/import', ['editorial_json' => json_encode($v3Export)]);
check($v3Roundtrip->getStatusCode() === 200 && payload($v3Roundtrip)['configuration'] == $v3Config, 'v3 exportar e importar equivalentes incluidos medios y grupos');

foreach (['LEAD_VSL', 'WAITLIST'] as $v3Template) {
    $v3Other = V3Content::defaults();
    $v3Other['presentation']['template'] = $v3Template;
    $v3OtherDoc = V3Content::envelope($v3Other);
    $v3OtherImport = http('POST', '/admin/landings/import', ['editorial_json' => json_encode($v3OtherDoc), 'configuration' => $v3Foreign]);
    check($v3OtherImport->getStatusCode() === 200 && payload($v3OtherImport)['configuration'] == $v3Other
        && ! isset(payload($v3OtherImport)['configuration']['presentation']['webinar']), 'v3 nuevo '.$v3Template.' no arrastra composición anterior');
}

$v3Patch = ['schema' => V3Content::SCHEMA, 'content' => [
    'hero' => ['title' => 'Titular corregido desde JSON'],
    'presentation' => ['show_video' => true, 'show_confirmation_video' => true, 'webinar' => ['starts_at' => '2030-11-10T19:30:00-04:00', 'sections' => array_reverse($v3Config['presentation']['webinar']['sections'])]],
    'benefits' => ['items' => [['title' => 'Único beneficio', 'description' => 'La lista se reemplaza completa.']]],
    'video' => ['youtube_url' => 'https://youtu.be/M7lc1UVf-VE', 'source_aspect_ratio' => '16:9', 'focal_x' => 0.2],
    'confirmation_video' => ['youtube_url' => 'https://youtu.be/aqz-KE-bpKQ', 'source_aspect_ratio' => '3:4', 'focal_y' => 0.8],
    'confirmation_whatsapp_group' => ['mode' => 'custom', 'url' => 'https://chat.whatsapp.com/ConfirmationFixture12345'],
]];
$v3Patch['content']['presentation']['webinar']['sections'][5]['visible'] = false; // opportunity in reverse order
$v3Unsaved = $v3Config;
$v3Unsaved['seo']['title'] = 'SEO todavía sin guardar';
$v3PatchedResponse = http('POST', $v3Base.'/import', ['editorial_json' => json_encode($v3Patch), 'configuration' => $v3Unsaved]);
check($v3PatchedResponse->getStatusCode() === 200, 'v3 parche existente con fecha layout videos independientes y grupo');
$v3Patched = payload($v3PatchedResponse)['configuration'];
check($v3Patched['seo'] === $v3Unsaved['seo'] && $v3Patched['hero']['description'] === $v3Config['hero']['description']
    && $v3Patched['webinar'] === $v3Config['webinar'] && count($v3Patched['benefits']['items']) === 1, 'v3 fusión conserva omitidos sin guardar y reemplaza listas completas');
check($v3Patched['video']['youtube_url'] === 'https://www.youtube.com/watch?v=M7lc1UVf-VE'
    && $v3Patched['confirmation_video']['youtube_url'] === 'https://www.youtube.com/watch?v=aqz-KE-bpKQ'
    && $v3Patched['video']['focal_y'] === 0.5 && $v3Patched['confirmation_video']['focal_x'] === 0.5, 'v3 normaliza videos y conserva encuadres independientes');
check($v3Landing->fresh()->configuration == $v3Config && $v3Landing->fresh()->revision === 1, 'v3 parche no persiste ni modifica revisión');
check(http('PUT', $v3Base, [...$v3Data, 'configuration' => $v3Patched, 'revision' => 1])->isRedirect(), 'v3 guardar parche es independiente');
$v3SavedPreview = payload(http('GET', $v3Base.'/preview', [], true));
$v3SavedConfirmation = payload(http('GET', $v3Base.'/preview/confirmado', [], true));
check($v3SavedPreview['props']['landing']['content']['presentation']['webinar'] == $v3Patched['presentation']['webinar']
    && $v3SavedPreview['props']['launch']['whatsapp_url'] === null && ! isset($v3SavedPreview['props']['landing']['content']['confirmation_whatsapp_group']), 'v3 preview respeta orden visibilidad y fecha sin exponer WhatsApp');
check($v3SavedConfirmation['props']['confirmation']['video']['youtube_url'] === $v3Patched['confirmation_video']['youtube_url']
    && $v3SavedConfirmation['props']['confirmation']['whatsapp_group_url'] === $v3Patched['confirmation_whatsapp_group']['url'], 'v3 preview confirmación utiliza su video y grupo propios');
check(http('POST', $v3Base.'/publicar', ['revision' => 2])->isRedirect(), 'v3 publicación explícita solo en esquema de pruebas');
$v3Published = $v3Landing->fresh()->published_configuration;
check(http('PUT', $v3Base, [...$v3Data, 'configuration' => $v3Config, 'revision' => 3])->isRedirect()
    && $v3Landing->fresh()->published_configuration === $v3Published, 'v3 editar borrador no altera snapshot publicado');
check(payload(http('GET', '/'.$v3Landing->slug, [], true))['props']['landing']['content']['hero']['title'] === $v3Patched['hero']['title'], 'v3 público mantiene snapshot hasta republicación');

foreach ([V3Content::LEGACY_SCHEMA, V3Content::V2_SCHEMA] as $v3Legacy) {
    $v3OldPatch = ['schema' => $v3Legacy, 'content' => ['hero' => ['title' => 'Texto compatible']]];
    $v3OldResult = V3Content::import(json_encode($v3OldPatch), $v3Patched);
    check($v3OldResult['hero']['title'] === 'Texto compatible' && $v3OldResult['presentation'] === $v3Patched['presentation']
        && $v3OldResult['video'] === $v3Patched['video'] && $v3OldResult['confirmation_whatsapp_group'] === $v3Patched['confirmation_whatsapp_group'], 'v3 compatibilidad sin perder configuración '.$v3Legacy);
}
$v3JustDate = V3Content::import(json_encode(['schema' => V3Content::SCHEMA, 'content' => ['presentation' => ['webinar' => ['starts_at' => null]]]]), $v3Patched);
check($v3JustDate['presentation']['webinar']['starts_at'] === null && $v3JustDate['presentation']['webinar']['sections'] === $v3Patched['presentation']['webinar']['sections'], 'v3 parche fecha null conserva título orden y visibilidad');
$v3JustMode = V3Content::import(json_encode(['schema' => V3Content::SCHEMA, 'content' => ['confirmation_whatsapp_group' => ['mode' => 'custom']]]), $v3Patched);
check($v3JustMode['confirmation_whatsapp_group'] === $v3Patched['confirmation_whatsapp_group'], 'v3 parche de modo conserva URL válida omitida');

$v3Invalids = [
    [['presentation' => ['webinar' => ['starts_at' => '']]], 'content.presentation.webinar.starts_at'],
    [['presentation' => ['webinar' => ['starts_at' => '2030-02-30T19:30:00-04:00']]], 'content.presentation.webinar.starts_at'],
    [['presentation' => ['webinar' => ['starts_at' => '2030-11-10T19:30:00+00:00']]], 'content.presentation.webinar.starts_at'],
    [['presentation' => ['show_video' => 'false']], 'content.presentation.show_video'],
    [['video' => ['focal_x' => '0.5']], 'content.video.focal_x'],
    [['video' => ['focal_x' => 1.1]], 'content.video.focal_x'],
    [['video' => ['aspect_ratio' => '16:9']], 'content.video.aspect_ratio'],
    [['confirmation_video' => ['source_type' => 'vimeo']], 'content.confirmation_video.source_type'],
    [['confirmation_video' => ['youtube_url' => 'javascript:alert(1)']], 'content.confirmation_video.youtube_url'],
    [['video' => ['poster' => '/marketing/missing-poster.png']], 'content.video.poster'],
    [['confirmation_whatsapp_group' => ['mode' => 'custom', 'url' => null]], 'content.confirmation_whatsapp_group.url'],
    [['confirmation_whatsapp_group' => ['url' => 'https://evil.example/group']], 'content.confirmation_whatsapp_group.url'],
    [['confirmation_whatsapp_group' => ['endpoint' => '/admin']], 'content.confirmation_whatsapp_group.endpoint'],
    [['hero' => ['secret' => 'not-a-secret']], 'content.hero.secret'],
    [['hero' => ['title' => '<script>alert(1)</script>']], 'content.hero.title'],
    [['hero' => ['title' => null]], 'content.hero.title'],
    [['after_registration' => ['message' => 'Correo enviado']], 'content.after_registration.message'],
    [['next_step' => ['action' => 'none']], 'document.content.next_step'],
    [['benefits' => ['items' => []]], 'content.benefits.items'],
    [['webinar' => ['steps' => ['items' => [['title' => 'Uno', 'description' => 'Solo uno.']]]]], 'content.webinar.steps.items'],
];
$v3Errors = [];
foreach ($v3Invalids as [$v3BadContent, $v3ErrorPath]) {
    $v3Bad = http('POST', $v3Base.'/import', ['editorial_json' => json_encode(['schema' => V3Content::SCHEMA, 'content' => $v3BadContent])]);
    check($v3Bad->getStatusCode() === 422 && isset(payload($v3Bad)['errors'][$v3ErrorPath]), 'v3 error explícito en '.$v3ErrorPath);
    $v3Errors[$v3ErrorPath] = payload($v3Bad);
}
foreach (['missing', 'duplicate', 'hidden', 'unknown'] as $v3Case) {
    $v3Sections = $v3Config['presentation']['webinar']['sections'];
    if ($v3Case === 'missing') array_pop($v3Sections);
    if ($v3Case === 'duplicate') $v3Sections[0] = $v3Sections[1];
    if ($v3Case === 'hidden') $v3Sections[6]['visible'] = false;
    if ($v3Case === 'unknown') $v3Sections[0]['id'] = 'custom';
    $v3Bad = http('POST', $v3Base.'/import', ['editorial_json' => json_encode(['schema' => V3Content::SCHEMA, 'content' => ['presentation' => ['webinar' => ['sections' => $v3Sections]]]])]);
    check($v3Bad->getStatusCode() === 422, 'v3 rechaza lista de secciones '.$v3Case);
}
foreach (['video', 'confirmation_video', 'whatsapp_group', 'confirmation_whatsapp_group', 'presentation', 'seo', 'webinar'] as $v3Missing) {
    $v3Incomplete = $v3Document;
    unset($v3Incomplete['content'][$v3Missing]);
    $v3Bad = http('POST', '/admin/landings/import', ['editorial_json' => json_encode($v3Incomplete)]);
    check($v3Bad->getStatusCode() === 422 && (bool) array_filter(array_keys(payload($v3Bad)['errors']), fn (string $path): bool => str_starts_with($path, 'content.'.$v3Missing)), 'v3 nueva exige configuración explícita '.$v3Missing);
}
$v3Mismatch = $v3Document;
$v3Mismatch['template'] = 'WAITLIST';
$v3Bad = http('POST', $v3Base.'/import', ['editorial_json' => json_encode($v3Mismatch)]);
check($v3Bad->getStatusCode() === 422 && isset(payload($v3Bad)['errors']['content.presentation.template']), 'v3 rechaza templates contradictorios');
$v3Huge = ['schema' => V3Content::SCHEMA, 'content' => ['hero' => ['title' => str_repeat('á', 32769)]]];
$v3Bad = http('POST', $v3Base.'/import', ['editorial_json' => json_encode($v3Huge, JSON_UNESCAPED_UNICODE)]);
check($v3Bad->getStatusCode() === 422 && str_contains(payload($v3Bad)['errors']['editorial_json'][0], 'bytes'), 'v3 límite 64 KiB medido en bytes UTF-8 también en servidor');
check(str_contains($v3Errors['content.video.focal_x']['errors']['content.video.focal_x'][0], '0')
    && str_contains($v3Errors['content.after_registration.message']['errors']['content.after_registration.message'][0], 'servidor'), 'v3 errores en español explican rango y estado de confirmación');
check($v3Landing->fresh()->revision === 4 && $v3Landing->fresh()->configuration == $v3Config
    && $v3Landing->fresh()->published_configuration === $v3Published, 'v3 errores conservan borrador revisión y snapshot');
foreach ($v3Snapshots as $v3Id => $v3Snapshot) {
    check(V3Landing::findOrFail($v3Id)->published_configuration === $v3Snapshot, 'v3 no modifica snapshot de otra landing '.$v3Id);
}
file_put_contents('/evidence/json-v3-fixtures.json', json_encode([
    'document' => $v3Document, 'imported' => payload($v3Result), 'patch' => $v3Patch, 'patched' => payload($v3PatchedResponse),
    'editor' => payload(http('GET', $v3Base.'/editar', [], true)), 'preview' => $v3Preview, 'confirmation' => $v3Confirmation,
    'dated' => $v3SavedPreview, 'dated_confirmation' => $v3SavedConfirmation, 'errors' => $v3Errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
require '/tests/benefit-icons-tests.php';
require '/tests/registration-copy-tests.php';
config(['jakawi.video_url' => null, 'jakawi.confirmation_video_url' => null]);

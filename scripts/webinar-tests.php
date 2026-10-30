<?php

// Included by landing-tests.php after the HTTP kernel, admin and isolated schema are ready.
$webinar = App\Support\LandingContent::webinarDefaults();
check(App\Support\LandingContent::validate($webinar) == $webinar, 'WEBINAR seed válido sin fecha ni escasez');
check(! isset(App\Support\LandingContent::defaults()['presentation']['webinar']), 'defaults históricos no habilitan composición nueva');
$oldSnapshot = $landing->fresh()->published_configuration;
$webinarData = ['title' => 'Webinar aislado', 'slug' => 'webinar-aislado', 'configuration' => $webinar, 'campaign' => 'webinar_fixture'];
check(http('POST', '/admin/landings', $webinarData)->isRedirect(), 'crear WEBINAR como borrador independiente');
$webinarLanding = App\Models\MarketingLanding::where('slug', 'webinar-aislado')->firstOrFail();
$webinarBase = '/admin/landings/'.$webinarLanding->id;
check($webinarLanding->published_configuration === null && http('GET', '/webinar-aislado')->getStatusCode() === 404, 'WEBINAR nuevo no publica');
$webinarPreview = payload(http('GET', $webinarBase.'/preview', [], true));
check($webinarPreview['props']['landing']['content']['presentation']['webinar']['starts_at'] === null, 'preview WEBINAR sin fecha no hereda fecha global');
check($webinarPreview['props']['launch']['whatsapp_url'] === null && ! isset($webinarPreview['props']['landing']['content']['whatsapp_group']), 'WEBINAR captación no expone WhatsApp');
check(http('POST', $webinarBase.'/publicar', ['revision' => $webinarLanding->fresh()->revision])->isRedirect(), 'publicar WEBINAR promueve borrador correcto en esquema aislado');
$webinarPublic = payload(http('GET', '/webinar-aislado', [], true));
$dated = $webinar;
$dated['presentation']['webinar']['starts_at'] = '2030-11-10T19:30:00-04:00'; // Fixture only, never a real event.
$dated['presentation']['webinar']['sections'] = array_reverse($dated['presentation']['webinar']['sections']);
foreach ($dated['presentation']['webinar']['sections'] as &$section) {
    if ($section['id'] === 'opportunity') $section['visible'] = false;
}
unset($section);
check(http('PUT', $webinarBase, [...$webinarData, 'configuration' => $dated, 'revision' => $webinarLanding->fresh()->revision])->isRedirect(), 'guardar WEBINAR con fecha Bolivia, orden y visibilidad');
check($webinarLanding->fresh()->published_configuration == $webinar, 'guardar fecha y orden no cambia snapshot público');
$datedPreview = payload(http('GET', $webinarBase.'/preview', [], true));
check($datedPreview['props']['landing']['content']['presentation']['webinar'] == $dated['presentation']['webinar'], 'preview conserva fecha con offset y orden exactos');
check(http('POST', $webinarBase.'/publicar', ['revision' => $webinarLanding->fresh()->revision])->isRedirect(), 'publicar snapshot WEBINAR actualizado');
check(payload(http('GET', '/webinar-aislado', [], true))['props']['landing']['content']['presentation']['webinar'] == $dated['presentation']['webinar'], 'público usa snapshot WEBINAR promovido');
foreach (['2030-02-30T19:30:00-04:00', '2030-11-10T19:30:00', '2030-11-10T19:30:00+00:00', 'invalid'] as $invalidDate) {
    $invalid = $dated;
    $invalid['presentation']['webinar']['starts_at'] = $invalidDate;
    check(http('PUT', $webinarBase, [...$webinarData, 'configuration' => $invalid, 'revision' => $webinarLanding->fresh()->revision])->getStatusCode() === 422, 'rechazar fecha WEBINAR inválida '.$invalidDate);
}
foreach (['missing', 'duplicate', 'hidden-form', 'unknown'] as $case) {
    $invalid = $dated;
    $sections = &$invalid['presentation']['webinar']['sections'];
    if ($case === 'missing') array_pop($sections);
    if ($case === 'duplicate') $sections[0] = $sections[1];
    if ($case === 'unknown') $sections[0]['id'] = 'arbitrary';
    if ($case === 'hidden-form') foreach ($sections as &$item) { if ($item['id'] === 'registration') $item['visible'] = false; }
    unset($sections, $item);
    check(http('PUT', $webinarBase, [...$webinarData, 'configuration' => $invalid, 'revision' => $webinarLanding->fresh()->revision])->getStatusCode() === 422, 'rechazar layout WEBINAR '.$case);
}
$document = App\Support\LandingContent::envelope($dated);
check(isset($document['content']['webinar'], $document['content']['presentation']['webinar']) && $document['schema'] === App\Support\LandingContent::SCHEMA, 'exportar WEBINAR v3 con fecha y composición');
check(App\Support\LandingContent::import(json_encode($document), $dated) == $dated, 'roundtrip JSON v2 WEBINAR conserva fecha, medios y layout');
$textPatch = App\Support\LandingContent::import(json_encode(['schema' => App\Support\LandingContent::SCHEMA, 'content' => ['webinar' => ['opportunity' => ['title' => 'Oportunidad editada']]]]), $dated);
check($textPatch['webinar']['opportunity']['title'] === 'Oportunidad editada' && $textPatch['webinar']['early'] === $dated['webinar']['early'], 'WEBINAR JSON parcial conserva campos y listas omitidos');
$legacyImport = App\Support\LandingContent::import(json_encode(['schema' => 'jakawi-club-landing-v1', 'content' => ['hero' => ['title' => 'Título heredado']]]), $dated);
check($legacyImport['hero']['title'] === 'Título heredado' && $legacyImport['presentation']['webinar'] == $dated['presentation']['webinar'], 'importar v1 conserva configuración WEBINAR');
check($landing->fresh()->published_configuration === $oldSnapshot, 'WEBINAR no modifica snapshot de otra landing');
file_put_contents('/evidence/webinar-fixtures.json', json_encode(['empty' => $webinarPublic, 'dated' => $datedPreview, 'editor' => payload(http('GET', $webinarBase.'/editar', [], true))], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

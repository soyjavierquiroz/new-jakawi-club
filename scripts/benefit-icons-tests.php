<?php

use App\Support\LandingContent as V3Content;
use App\Models\MarketingLanding as V3Landing;

// Runs only inside landing-tests.php's disposable PostgreSQL schema and admin session.
$iconContent = $v3Config;
$selectedIcons = ['play', 'users', 'gift'];
foreach ($iconContent['benefits']['items'] as $i => &$item) $item['icon'] = $selectedIcons[$i];
unset($item);
foreach (V3Content::BENEFIT_ICONS as $iconName) {
    $iconPatch = ['schema' => V3Content::SCHEMA, 'content' => ['benefits' => ['items' => [
        [...$iconContent['benefits']['items'][0], 'icon' => $iconName],
    ]]]];
    $iconResult = http('POST', $v3Base.'/import', ['editorial_json' => json_encode($iconPatch)]);
    check($iconResult->getStatusCode() === 200 && payload($iconResult)['configuration']['benefits']['items'][0]['icon'] === $iconName,
        'icono permitido importable: '.$iconName);
}
$iconData = ['title' => 'Iconos aislados', 'slug' => 'iconos-aislados', 'configuration' => $iconContent];
check(http('POST', '/admin/landings', $iconData)->isRedirect(), 'iconos guardar tres selecciones en borrador aislado');
$iconLanding = V3Landing::where('slug', $iconData['slug'])->firstOrFail();
$iconBase = '/admin/landings/'.$iconLanding->id;
foreach (['/editar', '/preview'] as $iconRoute) {
    $iconPage = payload(http('GET', $iconBase.$iconRoute, [], true));
    $iconConfig = $iconPage['props']['landing'][$iconRoute === '/editar' ? 'configuration' : 'content'];
    check(array_column($iconConfig['benefits']['items'], 'icon') === $selectedIcons, 'iconos editor/preview conserva tres selecciones: '.$iconRoute);
}
$iconExport = payload(http('GET', $iconBase.'/export'));
check($iconExport['content']['benefits']['items'] == $iconContent['benefits']['items']
    && V3Content::import(json_encode($iconExport), V3Content::defaults())['benefits'] == $iconContent['benefits'], 'iconos export/import roundtrip exacto');
check(http('POST', $iconBase.'/publicar', ['revision' => 1])->isRedirect()
    && array_column(payload(http('GET', '/iconos-aislados', [], true))['props']['landing']['content']['benefits']['items'], 'icon') === $selectedIcons,
    'iconos publicar muestra tres selecciones solo en esquema aislado');
$iconSnapshot = $iconLanding->fresh()->published_configuration;
$iconLegacy = $iconContent;
foreach ($iconLegacy['benefits']['items'] as &$item) unset($item['icon']);
unset($item);
check(http('PUT', $iconBase, [...$iconData, 'configuration' => $iconLegacy, 'revision' => 2])->isRedirect()
    && $iconLanding->fresh()->published_configuration === $iconSnapshot, 'iconos quitar selección guarda fallback sin cambiar snapshot');
check(! array_key_exists('icon', payload(http('GET', $iconBase.'/preview', [], true))['props']['landing']['content']['benefits']['items'][0]), 'iconos preview sin propiedad mantiene fallback');
foreach ([V3Content::LEGACY_SCHEMA, V3Content::V2_SCHEMA, V3Content::SCHEMA] as $iconSchema) {
    $iconOld = V3Content::import(json_encode(['schema' => $iconSchema, 'content' => ['benefits' => $iconLegacy['benefits']]]), $iconContent);
    check($iconOld['benefits'] === $iconLegacy['benefits'], 'iconos omisión compatible y reemplazo de lista: '.$iconSchema);
}
foreach (['LEAD_VSL', 'WAITLIST'] as $iconTemplate) {
    $iconOther = $iconContent;
    $iconOther['presentation']['template'] = $iconTemplate;
    check(V3Content::import(json_encode(V3Content::envelope($iconOther)), V3Content::defaults(), true)['benefits'] === $iconOther['benefits'], 'iconos contrato compatible con '.$iconTemplate);
}
foreach (['', null, false, 12, [], ['name' => 'play'], 'constructor', '__proto__', 'arbitrary', 'Play', '<svg/>', '() => alert(1)'] as $badIcon) {
    $badConfig = $iconContent;
    $badConfig['benefits']['items'][0]['icon'] = $badIcon;
    $badImport = http('POST', $iconBase.'/import', ['editorial_json' => json_encode(V3Content::envelope($badConfig))]);
    $badSave = http('PUT', $iconBase, [...$iconData, 'configuration' => $badConfig, 'revision' => 3]);
    check($badImport->getStatusCode() === 422 && isset(payload($badImport)['errors']['content.benefits.items.0.icon'])
        && $badSave->getStatusCode() === 422 && isset(payload($badSave)['errors']['content.benefits.items.0.icon']), 'iconos importación y guardado rechazan valor inseguro: '.json_encode($badIcon));
}
check($iconLanding->fresh()->configuration == $iconLegacy && $iconLanding->fresh()->published_configuration === $iconSnapshot,
    'iconos valores rechazados no cambian borrador ni snapshot');
$iconMissing = http('POST', $iconBase.'/import', ['editorial_json' => json_encode(['schema' => V3Content::SCHEMA, 'content' => ['benefits' => ['items' => [['icon' => 'play']]]]])]);
check($iconMissing->getStatusCode() === 422 && isset(payload($iconMissing)['errors']['content.benefits.items.0.title'])
    && isset(payload($iconMissing)['errors']['content.benefits.items.0.description']), 'icono no sustituye textos obligatorios');

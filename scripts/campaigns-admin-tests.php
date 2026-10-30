<?php

// Included by landing-tests.php while authenticated in its disposable PostgreSQL schema.
use App\Models\MarketingLanding;
use App\Support\LandingContent;
use App\Support\RegistrationNextStep;

$fallback = 'https://chat.whatsapp.com/DefaultGroupFixture1234';
$own = 'https://chat.whatsapp.com/LandingGroupFixture1234';
$different = 'https://chat.whatsapp.com/ConfirmGroupFixture1234';
config(['jakawi.whatsapp_group_url' => $fallback]);
$campaignFixtures = ['landings' => [], 'confirmations' => [], 'previews' => []];
$otherPublic = MarketingLanding::pluck('published_configuration', 'id')->all();
foreach (['default' => $fallback, 'custom' => $own, 'none' => null] as $mode => $expected) {
    $campaignContent = LandingContent::defaults();
    $campaignContent['confirmation_whatsapp_group'] = ['mode' => 'inherit', 'url' => null];
    $campaignContent['whatsapp_group'] = ['mode' => $mode, 'url' => $mode === 'custom' ? $own : null];
    $campaignContent['video']['youtube_url'] = 'https://www.youtube.com/watch?v=aqz-KE-bpKQ';
    $campaignContent['confirmation_video']['youtube_url'] = 'https://www.youtube.com/watch?v=M7lc1UVf-VE';
    $campaignContent['next_step'] = ['action' => 'whatsapp', 'redirect_seconds' => 0];
    $campaignData = ['title' => 'Campaña '.$mode, 'slug' => 'campaign-'.$mode, 'campaign' => 'campaign_'.$mode, 'configuration' => $campaignContent];
    check(http('POST', '/admin/landings', $campaignData)->isRedirect(), 'campaña independiente creada: '.$mode);
    $campaign = MarketingLanding::where('slug', $campaignData['slug'])->firstOrFail();
    $campaignBase = '/admin/landings/'.$campaign->id;
    check(http('POST', $campaignBase.'/publicar', ['revision' => $campaign->revision])->isRedirect(), 'grupo publicado: '.$mode);
    $publicPage = payload(http('GET', '/'.$campaign->slug, [], true));
    check($publicPage['props']['launch']['whatsapp_url'] === null && ! isset($publicPage['props']['landing']['content']['whatsapp_group']), 'captación sin grupo público: '.$mode);
    $campaignFixtures['landings'][$mode] = $publicPage;
    foreach (['inherit' => $expected, 'custom' => $different, 'none' => null] as $confirmationMode => $confirmationExpected) {
        $campaignContent['confirmation_whatsapp_group'] = ['mode' => $confirmationMode, 'url' => $confirmationMode === 'custom' ? $different : null];
        check(http('PUT', $campaignBase, [...$campaignData, 'configuration' => $campaignContent, 'revision' => $campaign->fresh()->revision])->isRedirect(), 'guardar confirmación '.$mode.'/'.$confirmationMode);
        $previewPage = payload(http('GET', $campaignBase.'/preview/confirmado', [], true));
        check($previewPage['props']['confirmation']['whatsapp_group_url'] === $confirmationExpected
            && ($previewPage['props']['confirmation']['next']['url'] ?? null) === $confirmationExpected, 'preview grupo y destino efectivos '.$mode.'/'.$confirmationMode);
        check($campaign->fresh()->published_configuration['confirmation_whatsapp_group']['mode'] === 'inherit', 'borrador no cambia grupo publicado '.$mode.'/'.$confirmationMode);
        $campaignFixtures['confirmations'][$mode.'-'.$confirmationMode] = $previewPage;
    }
    $campaignContent['whatsapp_group'] = ['mode' => 'custom', 'url' => $different];
    http('PUT', $campaignBase, [...$campaignData, 'configuration' => $campaignContent, 'revision' => $campaign->fresh()->revision]);
    $draftPage = payload(http('GET', $campaignBase.'/preview', [], true));
    check($draftPage['props']['launch']['whatsapp_url'] === null
        && payload(http('GET', '/'.$campaign->slug, [], true))['props']['launch']['whatsapp_url'] === null, 'preview y público reservan WhatsApp para confirmación '.$mode);
    $campaignFixtures['previews'][$mode] = $draftPage;
    check(http('POST', $campaignBase.'/publicar', ['revision' => $campaign->fresh()->revision])->isRedirect()
        && payload(http('GET', '/'.$campaign->slug, [], true))['props']['launch']['whatsapp_url'] === null && $campaign->fresh()->published_configuration['whatsapp_group']['url'] === $different, 'publicar aplica grupo guardado '.$mode);
    $campaignFixtures['editor'] = payload(http('GET', $campaignBase.'/editar', [], true));
    foreach (['jakawi-club-landing-v1', LandingContent::SCHEMA] as $schemaVersion) {
        $importedCampaign = LandingContent::import(json_encode(['schema' => $schemaVersion, 'content' => ['hero' => ['title' => 'Texto editorial']]]), $campaignContent);
        check($importedCampaign['whatsapp_group'] === $campaignContent['whatsapp_group'] && $importedCampaign['confirmation_whatsapp_group'] === $campaignContent['confirmation_whatsapp_group'], 'JSON conserva operación '.$schemaVersion.'/'.$mode);
        foreach (['whatsapp_group', 'confirmation_whatsapp_group'] as $section) {
            check(http('POST', $campaignBase.'/import', ['editorial_json' => json_encode(['schema' => $schemaVersion, 'content' => [$section => ['mode' => 'custom', 'url' => $own]]])])->getStatusCode() === 422, 'JSON rechaza grupos '.$schemaVersion.'/'.$section);
        }
    }
    $exportedCampaign = json_encode(payload(http('GET', $campaignBase.'/export')));
    check(! str_contains($exportedCampaign, 'chat.whatsapp.com') && ! str_contains($exportedCampaign, 'whatsapp_group'), 'export editorial excluye grupos y URL '.$mode);
}
foreach ($otherPublic as $id => $snapshot) {
    check(MarketingLanding::findOrFail($id)->published_configuration === $snapshot, 'campañas no alteran otros snapshots '.$id);
}
$unsafe = ['http://chat.whatsapp.com/ApprovedGroupFixture123', 'https://evil.example/group', 'https://chat.whatsapp.com.evil.test/ApprovedGroupFixture123',
    'https://chat.whatsapp.com@evil.test/ApprovedGroupFixture123', 'https://user@chat.whatsapp.com/ApprovedGroupFixture123', 'https://chat.whatsapp.com:443/ApprovedGroupFixture123',
    'https://chat.whatsapp.com/ApprovedGroupFixture123?redirect=https://evil.test', 'https://chat.whatsapp.com/ApprovedGroupFixture123#redirect',
    'https://chat.whatsapp.com/%41pprovedGroupFixture123', 'https://chat.whatsapp.com/ApprovedGroupFixture123/extra', 'https://chat.whatsapp.com/short',
    'javascript:alert(1)', null];
foreach (['whatsapp_group', 'confirmation_whatsapp_group'] as $section) {
    foreach ($unsafe as $badUrl) {
        $badContent = $campaignContent;
        $badContent[$section] = ['mode' => 'custom', 'url' => $badUrl];
        check(http('PUT', $campaignBase, [...$campaignData, 'configuration' => $badContent, 'revision' => $campaign->fresh()->revision])->getStatusCode() === 422, 'guardar rechaza URL insegura '.$section);
        $savedDraft = $campaign->fresh()->configuration;
        $savedSnapshot = $campaign->fresh()->published_configuration;
        $campaign->update(['configuration' => $badContent]);
        check(http('POST', $campaignBase.'/publicar', ['revision' => $campaign->fresh()->revision])->getStatusCode() === 422
            && $campaign->fresh()->published_configuration === $savedSnapshot, 'publicar revalida URL; conserva snapshot '.$section);
        $campaign->update(['configuration' => $savedDraft]);
    }
}
$legacyCampaign = $campaignContent;
unset($legacyCampaign['whatsapp_group'], $legacyCampaign['confirmation_whatsapp_group'], $legacyCampaign['presentation']['show_confirmation_video']);
$legacyNormalized = LandingContent::validate($legacyCampaign);
check($legacyNormalized['whatsapp_group']['mode'] === 'default' && $legacyNormalized['confirmation_whatsapp_group']['mode'] === 'inherit'
    && $legacyNormalized['presentation']['show_confirmation_video'], 'compatibilidad de snapshots previos sin migraciones');
file_put_contents('/evidence/campaign-fixtures.json', json_encode($campaignFixtures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

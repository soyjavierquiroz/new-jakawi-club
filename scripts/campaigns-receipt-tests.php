<?php

// Included by postconversion-tests.php; all registrations use an existing isolated fixture account.
use App\Support\LandingContent;
use Illuminate\Support\Facades\DB;

$receiptFixtures = [];
$campaignConversions = (int) DB::table('marketing_event_totals')->where('event', 'registration_completed')->sum('total');
foreach (['principal' => 'default', 'webinar' => 'custom', 'fundadores' => 'none'] as $campaignSlug => $landingMode) {
    $campaignOrigin = App\Models\MarketingLanding::where('slug', $campaignSlug)->firstOrFail();
    foreach (['inherit', 'custom', 'none'] as $confirmationMode) {
        config(['jakawi.whatsapp_group_url' => 'https://chat.whatsapp.com/DefaultGroupFixture1234', 'jakawi.confirmation_video_url' => 'https://youtu.be/aqz-KE-bpKQ']);
        $receiptContent = LandingContent::defaults();
        $receiptContent['whatsapp_group'] = ['mode' => $landingMode, 'url' => $landingMode === 'custom' ? 'https://chat.whatsapp.com/LandingGroupFixture1234' : null];
        $receiptContent['confirmation_whatsapp_group'] = ['mode' => $confirmationMode, 'url' => $confirmationMode === 'custom' ? 'https://chat.whatsapp.com/ConfirmGroupFixture1234' : null];
        $receiptContent['confirmation_video']['youtube_url'] = $landingMode === 'custom' ? 'https://www.youtube.com/watch?v=M7lc1UVf-VE' : null;
        $receiptContent['confirmation_video']['focal_x'] = 0.25;
        $receiptContent['presentation']['show_confirmation_video'] = $landingMode !== 'none';
        $receiptContent['next_step'] = ['action' => 'whatsapp', 'redirect_seconds' => 10];
        $campaignOrigin->update(['configuration' => $receiptContent, 'published_configuration' => $receiptContent, 'published_campaign' => 'receipt_'.$landingMode]);
        freshBrowser(); app('cache')->flush();
        check(http('POST', '/registro', registration('clock@example.test', ['landing_id' => $campaignOrigin->id]))->isRedirect('https://jakawi.club/'.$campaignSlug.'/gracias'), 'recibo aceptado '.$landingMode.'/'.$confirmationMode);
        $receiptPage = payload(http('GET', '/'.$campaignSlug.'/confirmado', [], true));
        $expectedGroup = match ($confirmationMode) {
            'custom' => 'https://chat.whatsapp.com/ConfirmGroupFixture1234',
            'inherit' => match ($landingMode) { 'default' => config('jakawi.whatsapp_group_url'), 'custom' => $receiptContent['whatsapp_group']['url'], default => null },
            default => null,
        };
        $expectedVideo = match ($landingMode) { 'default' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', 'custom' => 'https://www.youtube.com/watch?v=M7lc1UVf-VE', default => null };
        check($receiptPage['props']['confirmation']['whatsapp_group_url'] === $expectedGroup
            && ($receiptPage['props']['confirmation']['next']['url'] ?? null) === $expectedGroup, 'grupo y contador del recibo '.$landingMode.'/'.$confirmationMode);
        check($receiptPage['props']['confirmation']['video']['youtube_url'] === $expectedVideo
            && $receiptPage['props']['confirmation']['video']['aspect_ratio'] === '3:4'
            && $receiptPage['props']['confirmation']['video']['focal_x'] === 0.25, 'video específico, fallback u oculto del recibo '.$landingMode.'/'.$confirmationMode);
        $changedContent = $receiptContent;
        $changedContent['confirmation_whatsapp_group'] = ['mode' => 'custom', 'url' => 'https://chat.whatsapp.com/LaterGroupFixture123456'];
        $changedContent['confirmation_video']['youtube_url'] = 'https://www.youtube.com/watch?v=aqz-KE-bpKQ';
        $changedContent['presentation']['show_confirmation_video'] = true;
        $campaignOrigin->update(['configuration' => $changedContent, 'published_configuration' => $changedContent, 'published_campaign' => 'changed']);
        config(['jakawi.whatsapp_group_url' => $changedContent['confirmation_whatsapp_group']['url'], 'jakawi.confirmation_video_url' => 'https://youtu.be/M7lc1UVf-VE']);
        $afterChange = payload(http('GET', '/'.$campaignSlug.'/confirmado', [], true));
        check($afterChange['props']['confirmation'] === $receiptPage['props']['confirmation']
            && app('session')->get('registration_receipt')['campaign'] === 'receipt_'.$landingMode, 'snapshot inmutable incluso null y fallback '.$landingMode.'/'.$confirmationMode);
        $receiptFixtures[$landingMode.'-'.$confirmationMode] = $receiptPage;
    }
}
check((int) DB::table('marketing_event_totals')->where('event', 'registration_completed')->sum('total') === $campaignConversions, 'matriz de recibos y refrescos no duplica conversiones');
// An absent video remains absent even when a fallback is configured later.
config(['jakawi.confirmation_video_url' => null]);
$absentContent = LandingContent::defaults();
$campaignOrigin->update(['published_configuration' => $absentContent]);
freshBrowser(); app('cache')->flush();
http('POST', '/registro', registration('clock@example.test', ['landing_id' => $campaignOrigin->id]));
config(['jakawi.confirmation_video_url' => 'https://youtu.be/aqz-KE-bpKQ']);
check(payload(http('GET', '/'.$campaignSlug.'/confirmado', [], true))['props']['confirmation']['video']['youtube_url'] === null, 'sin fuente no inventa video posterior en recibo');
file_put_contents('/evidence/campaign-receipt-fixtures.json', json_encode($receiptFixtures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

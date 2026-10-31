<?php
namespace App\Services;
use App\Models\{AdEvent, AdVisit, MembershipPurchase};
use Illuminate\Http\Request;
use Illuminate\Support\Str;
final class Advertising {
    public function ready(string $provider): bool {
        return in_array($provider, ['meta', 'tiktok'], true) && config('ads.privacy_ready') && config("ads.$provider.enabled")
            && (bool) config("ads.$provider.pixel_id") && (bool) config("ads.$provider.token");
    }
    // Tracking must never change the outcome of a business operation. Do not log personal data or API exceptions.
    public function safely(callable $action): mixed { try { return \Illuminate\Support\Facades\DB::transaction($action); } catch (\Throwable) { return null; } }
    public function capture(Request $request): void {
        $provider = $request->route('provider');
        if (!in_array($provider, ['meta', 'tiktok'], true)) return;
        if ($request->session()->get('ads_provider') !== $provider) $request->session()->put('ads_entered_at', now()->toIso8601String());
        $request->session()->put('ads_provider', $provider);
        if ($request->cookie('jakawi_ads_consent') !== 'yes') return;
        $visit = AdVisit::find($request->session()->get('ad_visit_id'));
        $existing = $visit && !$visit->revoked_at && $visit->expires_at->isFuture() && $visit->provider === $provider;
        $context = $existing ? $visit->context : [];
        if (!$existing) $context =
        ['url' => rtrim(config('app.url'), '/').'/ads/'.$provider.'/'.$request->route('page'),
            'entered_at' => $request->session()->get('ads_entered_at', now()->toIso8601String()), 'consent_version' => 'ads-v1'];
        foreach (['utm_source','utm_medium','utm_campaign','utm_content','utm_term','utm_id','campaign_id','adset_id','ad_id', $provider === 'meta' ? 'fbclid' : 'ttclid'] as $key) {
            $value = $request->query($key);
            if (!isset($context[$key]) && is_string($value) && preg_match('/^[\pL\pN _.:+\/-]{1,200}$/uD', $value)) $context[$key] = $value;
        }
        foreach ($provider === 'meta' ? ['_fbp' => 'fbp', '_fbc' => 'fbc'] : ['_ttp' => 'ttp'] as $cookie => $key) {
            $value = $request->cookie($cookie);
            if (is_string($value) && preg_match('/^[a-zA-Z0-9._-]{1,250}$/D', $value)) $context[$key] = $value;
        }
        if ($provider === 'meta' && isset($context['fbclid']) && !isset($context['fbc'])) $context['fbc'] = 'fb.1.'.now()->getTimestampMs().'.'.$context['fbclid'];
        if ($existing) { $visit->update(['context' => $context]); return; }
        $scope = $request->session()->get('ad_consent_scope');
        if (!is_string($scope)) { $scope = (string) Str::uuid(); $request->session()->put('ad_consent_scope', $scope); }
        $visit = AdVisit::create(['consent_scope' => $scope, 'id' => (string) Str::uuid(), 'provider' => $provider, 'context' => $context,
            'consented_at' => now(), 'expires_at' => now()->addDays(config('ads.retention_days'))]);
        $request->session()->put('ad_visit_id', $visit->id);
    }
    public function visit(Request $request): ?AdVisit {
        if ($request->cookie('jakawi_ads_consent') !== 'yes') return null;
        return AdVisit::whereKey($request->session()->get('ad_visit_id'))->whereNull('revoked_at')->where('expires_at', '>', now())->first();
    }
    public function record(MembershipPurchase $purchase, string $type, ?string $time = null): ?AdEvent {
        $visit = AdVisit::whereKey($purchase->ad_visit_id)->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        if (!$visit) return null;
        $name = $type === 'Lead' && $visit->provider === 'tiktok' ? 'CompleteRegistration' : $type;
        $intent = $purchase->intent;
        $payload = ['email' => hash('sha256', strtolower(trim($intent['email']))),
            'phone' => hash('sha256', preg_replace('/[^0-9]/', '', $intent['phone'])),
            'tiktok_phone' => hash('sha256', '+'.preg_replace('/[^0-9]/', '', $intent['phone']))];
        $event = AdEvent::firstOrCreate(['provider' => $visit->provider, 'club_reference' => $purchase->id, 'event_name' => $name],
            ['id' => (string) Str::uuid(), 'visit_id' => $visit->id, 'occurred_at' => $time ?? now(), 'payload' => $payload, 'expires_at' => $visit->expires_at]);
        if ($event->wasRecentlyCreated) $this->enqueue($event);
        return $event;
    }
    public function enqueue(AdEvent $event): void {
        if (!$this->ready($event->provider)) return;
        \App\Jobs\DeliverAdEvent::dispatch($event->id)->onQueue('ads')->beforeCommit();
        $event->update(['status' => 'pending']);
    }
    public function browser(Request $request): array {
        $provider = $request->route('provider');
        $visit = $this->visit($request);
        $allowed = $visit && $provider === $visit->provider && $this->ready($provider);
        $events = [];
        if ($allowed && ($purchase = app(PublicMembershipCheckout::class)->current($request)) && $purchase->ad_visit_id === $visit->id) {
            $events = AdEvent::where('club_reference', $purchase->id)->where('provider', $provider)->where('event_name', '!=', 'Purchase')
                ->get()->map(fn (AdEvent $e): array => ['id' => $e->id, 'name' => $e->event_name])->all();
        }
        return ['provider' => $provider, 'allowed' => (bool) $allowed, 'pixel_id' => $allowed ? config("ads.$provider.pixel_id") : null,
            'preference' => $request->cookie('jakawi_ads_consent'), 'retention_days' => config('ads.retention_days'), 'events' => $events];
    }
}

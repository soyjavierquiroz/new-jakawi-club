<?php
namespace App\Jobs;
use App\Models\{AdEvent, AdVisit, MembershipPurchase};
use App\Services\{Advertising, ClubPurchaseClient};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{DB, Http};
final class DeliverAdEvent implements ShouldQueue {
    use Queueable;
    public int $tries = 6;
    public int $timeout = 11;
    public function __construct(public string $eventId) {}
    public function backoff(): array { return [60, 300, 900, 3600, 10800]; }
    public function handle(): void {
        $retry = DB::transaction(function (): bool {
            $event = AdEvent::lockForUpdate()->find($this->eventId);
            if (!$event || in_array($event->status, ['accepted', 'rejected'], true)) return false;
            $visit = AdVisit::find($event->visit_id);
            if (!$visit || $visit->revoked_at || $visit->expires_at->isPast() || $event->expires_at->isPast() || $event->occurred_at->lt(now()->subDays(7))) {
                $event->update(['status' => 'rejected', 'response_code' => 'consent_or_expiry']); return false;
            }
            if (!app(Advertising::class)->ready($event->provider)) {
                $event->update(['status' => 'received', 'response_code' => 'integration_disabled']); return false;
            }
            $event->increment('attempts');
            try {
                $properties = [];
                if ($event->event_name === 'Purchase') {
                    $purchase = MembershipPurchase::findOrFail($event->club_reference);
                    // Only the signed membership.activated handler creates Purchase. Obtain the actual approved amount from COM.
                    $state = app(ClubPurchaseClient::class)->request('GET', '/'.$purchase->id, null, 3, 1)->json();
                    if (($state['contract'] ?? '') !== 'jakawi-club-purchases-v1' || ($state['club_reference'] ?? '') !== $purchase->id
                        || ($state['payment_reference'] ?? '') !== $purchase->payment_reference || ($state['status'] ?? '') !== 'approved'
                        || ($state['currency'] ?? '') !== 'BOB' || !is_string($state['amount'] ?? null)
                        || !preg_match('/^[0-9]{1,8}\\.[0-9]{2}$/D', $state['amount']) || (float) $state['amount'] <= 0) {
                        $event->update(['status' => 'pending', 'response_code' => 'approval_not_confirmed']); return true;
                    }
                    $properties = ['value' => (float) $state['amount'], 'currency' => 'BOB', 'order_id' => $purchase->id];
                }
                $event->update(['status' => 'sent']);
                $response = $this->send($event, $visit, $properties);
                $data = $response->json();
                $accepted = $response->successful() && ($event->provider === 'meta'
                    ? (int) ($data['events_received'] ?? 0) === 1 : isset($data['code']) && (int) $data['code'] === 0);
                $temporary = $response->status() === 429 || $response->serverError()
                    || ($event->provider === 'meta' && ($data['error']['is_transient'] ?? false))
                    || ($event->provider === 'tiktok' && in_array((int) ($data['code'] ?? 0), [40100, 40101, 40102, 50000], true));
                $code = $event->provider === 'meta' ? ($data['error']['code'] ?? null) : ($data['code'] ?? null);
                $event->update(['status' => $accepted ? 'accepted' : ($temporary ? 'pending' : 'rejected'),
                    'response_code' => (string) $response->status().(is_numeric($code) ? ':'.$code : ''), 'delivered_at' => $accepted ? now() : null]);
                return !$accepted && $temporary;
            } catch (\Throwable) {
                $event->update(['status' => 'pending', 'response_code' => 'transport_error']); return true;
            }
        });
        if ($retry) throw new \RuntimeException('Advertising delivery temporarily unavailable.');
    }
    private function send(AdEvent $event, AdVisit $visit, array $properties): \Illuminate\Http\Client\Response {
        $context = $visit->context; $hashes = $event->payload;
        $base = ['event_id' => $event->id, 'event_time' => $event->occurred_at->timestamp];
        if ($event->provider === 'meta') {
            $user = ['em' => [$hashes['email']], 'ph' => [$hashes['phone']]];
            foreach (['fbp', 'fbc'] as $key) if (!empty($context[$key])) $user[$key] = $context[$key];
            return Http::connectTimeout(2)->timeout(5)->withOptions(['allow_redirects' => false])->withToken(config('ads.meta.token'))->post(
                'https://graph.facebook.com/'.config('ads.meta.version').'/'.rawurlencode(config('ads.meta.pixel_id')).'/events',
                ['data' => [[...$base, 'event_name' => $event->event_name, 'action_source' => 'website',
                    'event_source_url' => $context['url'], 'user_data' => $user, 'custom_data' => (object) $properties]]]);
        }
        $user = ['email' => $hashes['email'], 'phone' => $hashes['tiktok_phone']];
        if (!empty($context['ttclid'])) $user['ttclid'] = $context['ttclid'];
        if (!empty($context['ttp'])) $user['ttp'] = $context['ttp'];
        return Http::connectTimeout(2)->timeout(5)->withOptions(['allow_redirects' => false])->withHeaders(['Access-Token' => config('ads.tiktok.token')])->post(
            'https://business-api.tiktok.com/open_api/v1.3/event/track/', ['event_source' => 'web', 'event_source_id' => config('ads.tiktok.pixel_id'),
                'data' => [[...$base, 'event' => $event->event_name, 'user' => $user, 'page' => ['url' => $context['url']], 'properties' => (object) $properties]]]);
    }
    public function failed(?\Throwable $exception): void {
        AdEvent::whereKey($this->eventId)->whereNotIn('status', ['accepted', 'rejected'])->update(['status' => 'rejected', 'response_code' => 'retry_exhausted']);
    }
}

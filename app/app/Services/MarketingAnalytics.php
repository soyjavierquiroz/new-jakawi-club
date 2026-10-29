<?php

namespace App\Services;

use App\Models\MarketingLanding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MarketingAnalytics
{
    public function record(string $event, string $placement, ?MarketingLanding $landing = null): void
    {
        // No IP, account, referral, URL, device or form data in this table.
        DB::transaction(function () use ($event, $placement, $landing): void {
        DB::statement(
            'INSERT INTO marketing_event_totals (day, event, placement, total) VALUES (?, ?, ?, 1)
             ON CONFLICT (day, event, placement) DO UPDATE SET total = marketing_event_totals.total + 1',
            [now('America/La_Paz')->toDateString(), $event, $placement],
        );
        if ($landing !== null) {
            DB::statement(
                'INSERT INTO marketing_landing_event_totals (landing_id, day, event, placement, campaign, total) VALUES (?, ?, ?, ?, ?, 1)
                 ON CONFLICT (landing_id, day, event, placement, campaign) DO UPDATE SET total = marketing_landing_event_totals.total + 1',
                [$landing->id, now('America/La_Paz')->toDateString(), $event, $placement, $landing->published_campaign ?? ''],
            );
        }
        });
    }

    public function recordVisitEvent(Request $request, string $event, string $placement, ?MarketingLanding $landing = null): void
    {
        // Atomic session/day deduplication also handles concurrent effects and video resumes.
        // Only a short-lived, keyed digest is stored in the existing local cache.
        $key = 'marketing:'.hash_hmac('sha256', $request->session()->getId().'|'.now('America/La_Paz')->toDateString().'|'.$event.'|'.$placement.'|'.$landing?->id, config('app.key'));
        if (Cache::add($key, true, now()->addDay())) {
            try {
                $this->record($event, $placement, $landing);
            } catch (\Throwable $exception) {
                Cache::forget($key);
                throw $exception;
            }
        }
    }
}

<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MarketingAnalytics
{
    public function record(string $event, string $placement): void
    {
        // No IP, account, referral, URL, device or form data in this table.
        DB::statement(
            'INSERT INTO marketing_event_totals (day, event, placement, total) VALUES (?, ?, ?, 1)
             ON CONFLICT (day, event, placement) DO UPDATE SET total = marketing_event_totals.total + 1',
            [now('America/La_Paz')->toDateString(), $event, $placement],
        );
    }

    public function recordVisitEvent(Request $request, string $event, string $placement): void
    {
        // Atomic session/day deduplication also handles concurrent effects and video resumes.
        // Only a short-lived, keyed digest is stored in the existing local cache.
        $key = 'marketing:'.hash_hmac('sha256', $request->session()->getId().'|'.now('America/La_Paz')->toDateString().'|'.$event.'|'.$placement, config('app.key'));
        if (Cache::add($key, true, now()->addDay())) {
            try {
                $this->record($event, $placement);
            } catch (\Throwable $exception) {
                Cache::forget($key);
                throw $exception;
            }
        }
    }
}

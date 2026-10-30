<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class AffiliateLinkClicks
{
    public function record(Request $request, string $code): void
    {
        if ($request->getMethod() !== 'GET' || $request->headers->has('X-Inertia-Prefetch')) {
            return;
        }
        foreach (['Purpose', 'Sec-Purpose', 'X-Purpose', 'X-Moz'] as $header) {
            if (preg_match('/prefetch|prerender|preview/i', $request->header($header, ''))) {
                return;
            }
        }
        // Known link previews/crawlers are not human offer openings. These
        // headers are inspected in memory only, never persisted or logged.
        if (preg_match('~facebookexternalhit|WhatsApp/|Twitterbot|LinkedInBot|Slackbot|Discordbot|TelegramBot|Googlebot|bingbot~i', $request->userAgent() ?? '')) {
            return;
        }
        try {
            DB::statement('INSERT INTO affiliate_link_daily_clicks (link_code, day, clicks) VALUES (?, ?, 1)
                ON CONFLICT (link_code, day) DO UPDATE SET clicks = affiliate_link_daily_clicks.clicks + 1',
                [$code, now('America/La_Paz')->toDateString()]);
        } catch (QueryException) {
            // A telemetry failure must not break attribution or the redirect.
            // Do not log the request, code, visitor or database exception.
            Log::warning('Contador agregado de enlaces temporalmente no disponible.');
        }
    }

    public function byLink(User $user): Builder
    {
        return DB::table('affiliate_link_daily_clicks as clicks')
            ->join('affiliate_short_links as owned', 'owned.code', '=', 'clicks.link_code')
            ->where('owned.affiliate_id', $user->id)->groupBy('clicks.link_code')
            ->select('clicks.link_code')->selectRaw('sum(clicks.clicks)::bigint AS clicks_total')
            ->selectRaw('coalesce(sum(clicks.clicks) FILTER (WHERE clicks.day >= ? AND clicks.day <= ?), 0)::bigint AS clicks_7_days', [
                now('America/La_Paz')->subDays(6)->toDateString(), now('America/La_Paz')->toDateString(),
            ]);
    }

    public function summary(User $user): array
    {
        $totals = DB::query()->fromSub($this->byLink($user), 'totals')
            ->selectRaw('coalesce(sum(clicks_total),0)::bigint AS total, coalesce(sum(clicks_7_days),0)::bigint AS recent')->first();
        return ['total' => (int) $totals->total, 'last_7_days' => (int) $totals->recent];
    }
}

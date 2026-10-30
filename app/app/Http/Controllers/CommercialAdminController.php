<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CommercialAdminController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $filters = $request->validate([
            'partner' => ['sometimes', 'nullable', 'string', 'max:190'],
            'campaign' => ['sometimes', 'nullable', 'string', 'max:190'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ]);
        $events = DB::table('commercial_events')
            ->when($filters['partner'] ?? null, fn ($q, $value) => $q->where('partner_id', $value))
            ->when($filters['campaign'] ?? null, fn ($q, $value) => $q->where('campaign', $value))
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate(25, ['event_id', 'occurred_at', 'landing', 'campaign', 'partner_id', 'partner_name', 'event_type', 'com_referral_id', 'club_referral_code', 'referrer_club_uuid', 'conversion_status', 'com_reference_type', 'com_reference_id'])
            ->withQueryString();
        $events->through(function (object $event): object {
            $event->occurred_at = Carbon::parse($event->occurred_at)->utc()->toIso8601String();
            $event->referral_verification = $event->referrer_club_uuid ? 'club_code_verified' : ($event->com_referral_id ? 'com_reference_only' : null);
            unset($event->referrer_club_uuid);
            return $event;
        });
        return Inertia::render('admin/CommercialConversions', [
            'events' => $events,
            'filters' => ['partner' => $filters['partner'] ?? '', 'campaign' => $filters['campaign'] ?? ''],
            'partners' => DB::table('commercial_events')->whereNotNull('partner_id')->select('partner_id')->distinct()->orderBy('partner_id')->pluck('partner_id'),
            'campaigns' => DB::table('commercial_events')->whereNotNull('campaign')->select('campaign')->distinct()->orderBy('campaign')->pluck('campaign'),
        ]);
    }
}

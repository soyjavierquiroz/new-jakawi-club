<?php

namespace App\Http\Controllers;

use App\Services\AffiliateConversions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AffiliateController extends Controller
{
    public function __invoke(Request $request, AffiliateConversions $conversions): Response
    {
        $user = $request->user();
        // Existing account creation and legacy backfill issue permanent codes.
        // Never replace an identity or invent an unregistered fallback code.
        abort_unless(is_string($user->referral_code) && preg_match('/^[A-Z0-9]{12}$/D', $user->referral_code), 409);
        $operations = $conversions->confirmed($user)
            ->orderByDesc('occurred_at')->orderBy('com_reference_type')->orderBy('com_reference_id')
            ->paginate(25, ['occurred_at', 'partner_name', 'partner_id', 'event_type', 'conversion_status', 'com_reference_type', 'com_reference_id'])
            ->through(function (object $operation): array {
                $operation->occurred_at = Carbon::parse($operation->occurred_at)->utc()->toIso8601String();

                return (array) $operation;
            });

        return Inertia::render('Affiliates', [
            'campaign' => [
                'name' => 'Dossis 43 — Café y antojo',
                'personal_url' => 'https://jakawi.com/l/dossis-43-cafe-y-antojo?'.http_build_query(['club_ref' => $user->referral_code]),
            ],
            'conversions' => $operations,
            'commission_entries' => \Illuminate\Support\Facades\DB::table('commission_ledger')
                ->where('affiliate_uuid', $user->uuid)->where('affiliate_code', $user->referral_code)
                ->orderByDesc('id')->paginate(25, ['id','com_reference_type','com_reference_id','status','amount','currency','review_required','eligible','rule_id','created_at','approved_at'], 'commission_page'),
            'economic_cutoff' => config('commissions.economic_cutoff'),
            'economics' => app(\App\Services\Commissions\CommissionEngine::class)->summary($user),
        ]);
    }
}

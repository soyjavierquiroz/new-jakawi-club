<?php

namespace App\Http\Controllers;

use App\Services\AffiliateConversions;
use App\Services\CommercialCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AffiliateController extends Controller
{
    public function __invoke(Request $request, AffiliateConversions $conversions, \App\Services\AffiliateShortLinks $links, CommercialCatalog $catalog): Response
    {
        $user = $request->user();
        // Existing account creation and legacy backfill issue permanent codes.
        // Never replace an identity or invent an unregistered fallback code.
        abort_unless(is_string($user->referral_code) && preg_match('/^[A-Z0-9]{12}$/D', $user->referral_code), 409);
        $filters = $request->validate([
            'tab' => ['sometimes', Rule::in(['explore', 'links', 'history'])],
            'q' => ['nullable', 'string', 'max:120'], 'city' => ['nullable', 'string', 'max:190'],
            'type' => ['nullable', Rule::in(['benefit', 'experience', 'acquisition'])],
            'availability' => ['nullable', Rule::in(['active', 'upcoming', 'paused'])],
        ]);
        $tab = $filters['tab'] ?? 'explore';
        $personal = DB::table('affiliate_short_links')->where('affiliate_id', $user->id);
        $offers = $catalog->query()->leftJoinSub($personal, 'personal', 'personal.offer_key', '=', 'catalog.key');
        if ($tab === 'explore') {
            $offers->where('catalog_present', true)->whereNotIn('effective_state', ['finished', 'retired']);
            if ($q = $filters['q'] ?? null) {
                $offers->where(fn ($query) => $query->where('name', 'ilike', '%'.$q.'%')->orWhere('partner_search', 'ilike', '%'.$q.'%'));
            }
            if ($city = $filters['city'] ?? null) $offers->whereJsonContains('cities', $city);
            if ($type = $filters['type'] ?? null) $offers->where('type', $type);
            if ($state = $filters['availability'] ?? null) $offers->where('effective_state', $state);
        } else {
            $offers->whereNotNull('personal.code');
            if ($tab === 'history') $offers->whereIn('effective_state', ['finished', 'retired']);
        }
        $offerPage = $offers->orderBy('name')->orderBy('catalog.key')
            ->paginate(12, ['catalog.*', 'personal.code'], 'offer_page')->withQueryString()
            ->through(fn (object $offer): array => [...$catalog->present($offer), 'personal_url' => $offer->code ? $links->url($offer->code) : null]);
        $operations = $conversions->confirmed($user)
            ->orderByDesc('occurred_at')->orderBy('com_reference_type')->orderBy('com_reference_id')
            ->paginate(25, ['occurred_at', 'partner_name', 'partner_id', 'event_type', 'conversion_status', 'com_reference_type', 'com_reference_id'])->withQueryString()
            ->through(function (object $operation): array {
                $operation->occurred_at = Carbon::parse($operation->occurred_at)->utc()->toIso8601String();

                return (array) $operation;
            });

        return Inertia::render('Affiliates', [
            'tab' => $tab, 'filters' => $filters, 'offers' => $offerPage,
            'cities' => DB::table('commercial_offers')->where('catalog_present', true)
                ->selectRaw('DISTINCT jsonb_array_elements_text(cities) AS city')->orderBy('city')->pluck('city'),
            'quick_links' => DB::table('affiliate_short_links as links')->join('commercial_offers as offers', 'offers.key', '=', 'links.offer_key')
                ->where('links.affiliate_id', $user->id)->orderByDesc('links.created_at')->limit(5)->get(['offers.name', 'links.code'])
                ->map(fn (object $link): array => ['name' => $link->name, 'url' => $links->url($link->code)]),
            'conversions' => $operations,
            'commission_entries' => \Illuminate\Support\Facades\DB::table('commission_ledger')
                ->where('affiliate_uuid', $user->uuid)->where('affiliate_code', $user->referral_code)
                ->orderByDesc('id')->paginate(25, ['id','com_reference_type','com_reference_id','status','amount','currency','review_required','eligible','rule_id','created_at','approved_at'], 'commission_page')->withQueryString(),
            'economic_cutoff' => config('commissions.economic_cutoff'),
            'economics' => app(\App\Services\Commissions\CommissionEngine::class)->summary($user),
        ]);
    }

    public function generate(Request $request, string $offer, CommercialCatalog $catalog, \App\Services\AffiliateShortLinks $links): \Illuminate\Http\RedirectResponse
    {
        abort_unless(is_string($request->user()->referral_code) && preg_match('/^[A-Z0-9]{12}$/D', $request->user()->referral_code), 409);
        DB::transaction(function () use ($request, $offer, $catalog, $links): void {
            abort_unless(DB::table('commercial_offers')->where('key', $offer)->lockForUpdate()->first(), 404);
            $item = $catalog->query()->where('key', $offer)->first();
            abort_unless($item->effective_state === 'active', 409, 'Esta oportunidad no está disponible para promoción.');
            abort_unless($item->source === 'com' ? $links->validDestination($item->destination_url) : $catalog->clubLanding($item) !== null, 409);
            $links->forAffiliate($request->user(), $offer);
        });
        return redirect('/afiliados?tab=links')->with('status', 'Tu enlace permanente está listo.');
    }
}

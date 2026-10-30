<?php

namespace App\Http\Controllers;

use App\Services\AffiliateShortLinks;
use App\Services\CommercialCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CommercialOfferAdminController extends Controller
{
    public function index(Request $request, AffiliateShortLinks $links, CommercialCatalog $catalog): Response
    {
        $data = $request->validate(['offer' => ['sometimes', 'string', Rule::exists('commercial_offers', 'key')], 'q' => ['nullable', 'string', 'max:120']]);
        $selected = $data['offer'] ?? 'dossis-43';
        $associated = DB::table('affiliate_short_links')->where('offer_key', $selected)
            ->orderBy('created_at')->orderBy('code')->paginate(25, ['code', 'referral_code', 'created_at'], 'links_page')->withQueryString()
            ->through(fn (object $link): array => ['url' => $links->url($link->code), 'referral_code' => $link->referral_code, 'created_at' => $link->created_at]);
        $offers = $catalog->query();
        if ($q = $data['q'] ?? null) $offers->where(fn ($query) => $query->where('name', 'ilike', '%'.$q.'%')->orWhere('partner_search', 'ilike', '%'.$q.'%'));
        return Inertia::render('admin/CommercialOffers', [
            'offers' => $offers->orderBy('name')->orderBy('key')->paginate(20)->withQueryString()->through($catalog->present(...)),
            'selected' => $selected, 'links' => $associated, 'q' => $data['q'] ?? '',
            'last_sync' => DB::table('catalog_sync_runs')->orderByDesc('id')->first(),
            'last_success' => DB::table('catalog_sync_runs')->where('status', 'success')->orderByDesc('id')->first(),
        ]);
    }

    public function update(Request $request, string $offer): RedirectResponse
    {
        abort_unless(DB::table('commercial_offers')->where('key', $offer)->exists(), 404);
        $data = $request->validate(['local_paused' => ['required', 'boolean']]);
        // Local authority can only restrict promotion. COM owns destination and states.
        DB::table('commercial_offers')->where('key', $offer)->update([...$data, 'updated_at' => now()]);
        return back()->with('status', 'Promoción local actualizada. El estado de origen y los enlaces se conservan.');
    }
}

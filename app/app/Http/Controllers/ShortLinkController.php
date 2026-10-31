<?php

namespace App\Http\Controllers;

use App\Services\AffiliateLinkClicks;
use App\Services\AffiliateShortLinks;
use App\Services\CommercialCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ShortLinkController extends Controller
{
    public function __invoke(Request $request, string $shortCode, AffiliateShortLinks $links, CommercialCatalog $catalog, AffiliateLinkClicks $clicks): Response
    {
        $link = DB::table('affiliate_short_links')->where('code', $shortCode)->first();
        $offer = $link ? $catalog->query()->where('key', $link->offer_key)->first() : null;
        $headers = ['Cache-Control' => 'no-store, private, max-age=0', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow'];
        if (! $offer) {
            return response()->view('short-link-unavailable', ['state' => 'missing', 'name' => null], 404, $headers);
        }
        $state = $offer->effective_state;
        if ($state === 'active') {
            if ($offer->source === 'club' && $catalog->clubLanding($offer)) {
                // Reuse the existing session-based CLUB invitation route, never club_ref.
                $clicks->record($request, $link->code);
                return redirect()->away(rtrim(config('app.url'), '/').'/r/'.$link->referral_code, 302, $headers);
            }
            if ($offer->source === 'com' && $links->validDestination($offer->destination_url)) {
                $destination = $links->destination($offer->destination_url, $link->referral_code);
                $clicks->record($request, $link->code);
                return redirect()->away($destination, 302, $headers);
            }
            $state = 'paused';
        }
        return response()->view('short-link-unavailable', [
            'state' => $state, 'name' => $offer->name, 'description' => $offer->short_description,
            'availability' => $offer->availability_status,
            'landingSocial' => \App\Support\LandingSeo::unavailable((object) [...(array) $offer, 'effective_state' => $state]),
        ], 200, $headers);
    }
}

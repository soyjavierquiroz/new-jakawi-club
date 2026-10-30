<?php

namespace App\Http\Controllers;

use App\Services\AffiliateShortLinks;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ShortLinkController extends Controller
{
    public function __invoke(string $shortCode, AffiliateShortLinks $links): Response
    {
        // Stateless route: no session, cookie, attribution capture or visitor writes.
        $offer = DB::table('affiliate_short_links as links')
            ->join('commercial_offers as offers', 'offers.key', '=', 'links.offer_key')
            ->where('links.code', $shortCode)->first(['offers.name', 'offers.status', 'offers.destination_url', 'links.referral_code']);
        $headers = ['Cache-Control' => 'no-store, private, max-age=0', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow'];
        if (! $offer) {
            return response()->view('short-link-unavailable', ['state' => 'missing', 'name' => null], 404, $headers);
        }
        if ($offer->status !== 'active') {
            return response()->view('short-link-unavailable', ['state' => $offer->status, 'name' => $offer->name], 200, $headers);
        }
        if (! $links->validDestination($offer->destination_url)) {
            return response()->view('short-link-unavailable', ['state' => 'paused', 'name' => $offer->name], 200, $headers);
        }

        return redirect()->away($links->destination($offer->destination_url, $offer->referral_code), 302, $headers);
    }
}

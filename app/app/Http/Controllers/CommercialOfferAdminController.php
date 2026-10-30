<?php

namespace App\Http\Controllers;

use App\Services\AffiliateShortLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CommercialOfferAdminController extends Controller
{
    public function index(Request $request, AffiliateShortLinks $links): Response
    {
        $data = $request->validate(['offer' => ['sometimes', 'string', Rule::exists('commercial_offers', 'key')]]);
        $selected = $data['offer'] ?? 'dossis-43';
        $associated = DB::table('affiliate_short_links')->where('offer_key', $selected)
            ->orderBy('created_at')->orderBy('code')->paginate(25, ['code', 'referral_code', 'created_at'])->withQueryString()
            ->through(fn (object $link): array => ['url' => $links->url($link->code), 'referral_code' => $link->referral_code, 'created_at' => $link->created_at]);

        return Inertia::render('admin/CommercialOffers', [
            'offers' => DB::table('commercial_offers')->orderBy('key')->get(),
            'selected' => $selected, 'links' => $associated,
        ]);
    }

    public function update(Request $request, string $offer, AffiliateShortLinks $links): RedirectResponse
    {
        abort_unless(DB::table('commercial_offers')->where('key', $offer)->exists(), 404);
        $data = $request->validate([
            'destination_url' => ['bail', 'required', 'string', 'max:2048', function (string $attribute, mixed $value, \Closure $fail) use ($links): void {
                if (! $links->validDestination($value)) {
                    $fail('Usa una URL HTTPS de jakawi.com o www.jakawi.com, sin credenciales ni puerto.');
                }
            }],
            'status' => ['required', Rule::in(['active', 'paused', 'finished'])],
        ]);
        DB::table('commercial_offers')->where('key', $offer)->update([...$data, 'updated_at' => now()]);

        return back()->with('status', 'Oferta actualizada. Sus enlaces permanentes se conservan.');
    }
}

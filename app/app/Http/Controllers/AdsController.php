<?php
namespace App\Http\Controllers;
use App\Services\{Advertising, ReferralAttribution, PublicMembershipCheckout};
use Illuminate\Http\Request;
final class AdsController extends Controller {
    public function show(Request $request, string $provider, string $page): \Symfony\Component\HttpFoundation\Response|\Inertia\Response {
        app(Advertising::class)->safely(fn () => app(Advertising::class)->capture($request));
        if ($page === 'membresia') return app(WelcomeController::class)->show($request, app(ReferralAttribution::class), 'membresia');
        return app(PublicMembershipController::class)->show($request, app(PublicMembershipCheckout::class), app(ReferralAttribution::class));
    }
}

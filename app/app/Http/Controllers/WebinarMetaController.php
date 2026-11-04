<?php
namespace App\Http\Controllers;

use App\Models\MarketingLanding;
use App\Services\{Advertising, ReferralAttribution};
use Illuminate\Http\Request;

final class WebinarMetaController extends Controller
{
    public function show(Request $request): \Inertia\Response
    {
        $landing = $this->landing();
        app(Advertising::class)->safely(fn () => app(Advertising::class)->capture($request));
        return app(WelcomeController::class)->show($request, app(ReferralAttribution::class), $landing->slug);
    }
    public function thanks(Request $request): \Inertia\Response|\Illuminate\Http\RedirectResponse
    {
        return app(RegistrationController::class)->confirmed($request, $this->landing()->slug);
    }
    private function landing(): MarketingLanding
    {
        $landing = MarketingLanding::where('slug', config('jakawi.home_landing_slug'))->firstOrFail();
        abort_unless($landing->isPublic() && ($landing->published_configuration['presentation']['template'] ?? null) === 'WEBINAR', 404);
        return $landing;
    }
}

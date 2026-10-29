<?php

namespace App\Http\Controllers;

use App\Services\ReferralAttribution;
use App\Models\MarketingLanding;
use App\Support\LaunchSettings;
use App\Support\LandingContent;
use App\Support\LandingSlug;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WelcomeController extends Controller
{
    public function __invoke(Request $request, ReferralAttribution $referrals): Response
    {
        $landing = MarketingLanding::where('slug', config('jakawi.home_landing_slug'))->firstOrFail();
        abort_unless($landing->isPublic(), 404);
        return $this->render($request, $referrals, $landing);
    }

    public function show(Request $request, ReferralAttribution $referrals, string $slug): Response
    {
        abort_unless(LandingSlug::allowed($slug), 404);
        $landing = MarketingLanding::where('slug', $slug)->firstOrFail();
        abort_unless($landing->isPublic(), 404);
        return $this->render($request, $referrals, $landing);
    }

    public function legacy(Request $request, string $slug): RedirectResponse
    {
        abort_unless(LandingSlug::allowed($slug), 404);
        $landing = MarketingLanding::where('slug', $slug)->firstOrFail();
        abort_unless($landing->isPublic(), 404);
        $query = $request->getQueryString();
        return redirect('/'.$slug.($query ? '?'.$query : ''), 301);
    }

    public function preview(Request $request, ReferralAttribution $referrals, MarketingLanding $landing): Response
    {
        return $this->render($request, $referrals, $landing, true);
    }

    private function render(Request $request, ReferralAttribution $referrals, MarketingLanding $landing, bool $preview = false): Response
    {
        if (!$preview && !$request->session()->has('registration_utms.'.$landing->id)) {
            $utms = [];
            foreach (['utm_source','utm_medium','utm_campaign','utm_content','utm_term'] as $key) {
                $value = $request->query($key);
                if (is_string($value) && trim($value) !== '') {
                    $utms[$key] = mb_substr(preg_replace('/[\x00-\x1f<>]/u', '', trim($value)) ?? '', 0, 200);
                }
            }
            if ($utms) $request->session()->put('registration_utms.'.$landing->id, $utms);
        }
        $content = LandingContent::withPresentation($preview ? $landing->configuration : $landing->published_configuration);
        $launch = LaunchSettings::publicData();
        $launch['video_url'] = $content['video']['youtube_url'];
        $launch['video_poster'] = $content['video']['poster'];
        // Group destinations belong to the receipt-protected confirmation only.
        $launch['whatsapp_url'] = null;
        unset($content['whatsapp_group'], $content['confirmation_whatsapp_group']);
        if ($preview) {
            $launch['registration_open'] = false;
        }
        return Inertia::render('Welcome', [
            'launch' => $launch,
            'landing' => ['id' => $landing->id, 'slug' => $landing->slug, 'content' => $content, 'preview' => $preview, 'revision' => $landing->revision],
            'referral_code' => $preview ? null : $referrals->current($request)?->referral_code,
            'referral_preference' => $request->cookie('jakawi_referral_consent'),
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegistrationRequest;
use App\Models\User;
use App\Models\MarketingLanding;
use App\Services\AccountMail;
use App\Services\MarketingAnalytics;
use App\Services\ReferralAttribution;
use App\Support\LandingContent;
use App\Support\LandingSlug;
use App\Support\RegistrationNextStep;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationController extends Controller
{
    public function store(RegistrationRequest $request, ReferralAttribution $referrals, AccountMail $mail, MarketingAnalytics $analytics): RedirectResponse
    {
        $data = $request->validated();
        $referrer = $referrals->current($request, $data['referral_code'] ?? null);
        $created = false;
        $receipt = null;
        try {
            DB::transaction(function () use ($data, $referrer, $analytics, &$created, &$receipt): void {
                // Capture the same published landing/campaign used for the conversion.
                $landing = isset($data['landing_id']) ? MarketingLanding::query()->sharedLock()->find($data['landing_id']) : null;
                if (isset($data['landing_id'])) {
                    abort_unless($landing?->isPublic() && LandingSlug::allowed($landing->slug), 422);
                    $content = LandingContent::withPresentation($landing->published_configuration);
                    $receipt = ['id' => $landing->id, 'slug' => $landing->slug, 'campaign' => $landing->published_campaign,
                        'content' => $content['after_registration'], 'next_step' => $content['next_step'],
                        'confirmation_video' => $content['confirmation_video']];
                }
                if (User::where('email', $data['email'])->exists()) {
                    return;
                }
                $user = new User([
                    'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'],
                ]);
                if ($referrer && $referrer->email !== $data['email']) {
                    $user->referred_by_id = $referrer->id;
                }
                $user->privacy_accepted_at = now();
                $user->privacy_version = config('jakawi.privacy_version');
                $user->save();
                // Confirmed server-side, in the same transaction as the new account.
                $analytics->record('registration_completed', 'form', $landing?->isPublic() ? $landing : null);
                $created = true;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // The database also handles two concurrent requests for the same email.
            if (! User::where('email', $data['email'])->exists()) {
                throw $exception;
            }
        }
        if ($created) {
            $mail->send($data['email']);
        }
        $request->session()->regenerate();
        $request->session()->put('welcome_expires_at', now()->addMinutes(15)->timestamp);
        $request->session()->put('registration_receipt', $receipt);
        $request->session()->put('registration_email_digest', hash_hmac('sha256', $data['email'], config('app.key')));
        $referrals->forget($request);

        // Identical receipt for new and existing accounts. No email, name or code leaks.
        return $receipt ? redirect()->route('registration.confirmed', ['slug' => $receipt['slug']]) : redirect()->route('welcome');
    }

    public function welcome(Request $request): Response|RedirectResponse
    {
        if (! $this->hasReceipt($request)) {
            return redirect()->route('home');
        }
        $receipt = $request->session()->get('registration_receipt');
        if ($receipt) {
            return redirect()->route('registration.confirmed', ['slug' => $receipt['slug']]);
        }

        return $this->renderReceipt($request);
    }

    public function confirmed(Request $request, string $slug): Response|RedirectResponse
    {
        abort_unless(LandingSlug::allowed($slug), 404);
        $landing = MarketingLanding::where('slug', $slug)->firstOrFail();
        abort_unless($landing->isPublic(), 404);
        $receipt = $request->session()->get('registration_receipt');
        if (! $this->hasReceipt($request) || ! $receipt || $receipt['id'] !== $landing->id || $receipt['slug'] !== $slug) {
            return redirect()->route('landing.show', ['slug' => $slug]);
        }

        return $this->renderReceipt($request, $receipt);
    }

    public function preview(Request $request, MarketingLanding $landing): Response
    {
        $content = LandingContent::withPresentation($landing->configuration);

        return $this->renderReceipt($request, ['slug' => $landing->slug, 'content' => $content['after_registration'], 'next_step' => $content['next_step'], 'confirmation_video' => $content['confirmation_video']], true);
    }

    private function hasReceipt(Request $request): bool
    {
        if ($request->session()->get('welcome_expires_at', 0) > now()->timestamp) {
            return true;
        }
        $request->session()->forget(['welcome_expires_at', 'registration_receipt', 'registration_email_digest']);

        return false;
    }

    private function renderReceipt(Request $request, ?array $receipt = null, bool $preview = false): Response
    {
        $user = $request->user();
        $digest = $request->session()->get('registration_email_digest');
        $activated = ! $preview && $user?->hasVerifiedEmail() && $user->activated_at
            && (! $digest || hash_equals($digest, hash_hmac('sha256', $user->email, config('app.key'))));
        $settings = $receipt['next_step'] ?? ['action' => 'recover', 'redirect_seconds' => 0];

        return Inertia::render('Registered', [
            'referral_url' => $activated ? $user->referralUrl() : null,
            'confirmation' => [
                'content' => $receipt['content'] ?? LandingContent::confirmationDefaults(),
                'activation' => $activated ? 'confirmed' : 'pending', 'preview' => $preview,
                'landing_slug' => $receipt['slug'] ?? null,
                'video' => LandingContent::confirmationVideo($receipt['confirmation_video'] ?? []),
                'whatsapp_group_url' => RegistrationNextStep::whatsappGroup(),
                'next' => RegistrationNextStep::resolve($settings, (bool) $activated),
                'unavailable' => ($settings['action'] ?? '') === 'whatsapp' && RegistrationNextStep::whatsappGroup() === null,
            ],
        ]);
    }
}

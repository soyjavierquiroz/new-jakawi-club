<?php
namespace App\Http\Controllers;
use App\Models\MembershipPurchase;
use App\Services\ClubPurchaseClient;
use App\Services\MemberActivation;
use App\Services\PublicMembershipCheckout;
use App\Services\ReferralAttribution;
use App\Support\Normalize;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
final class PublicMembershipController extends Controller {
    public function show(Request $request, PublicMembershipCheckout $checkout, ReferralAttribution $referrals): \Symfony\Component\HttpFoundation\Response {
        \App\Support\MembershipJourney::forget($request);
        $code = $request->query('ref', $request->query('club_ref'));
        if (is_string($code)) $referrals->capture($request, $code);
        $purchase = $checkout->current($request);
        $unavailable = false;
        $profileIncomplete = false;
        $active = $request->user() && app(MemberActivation::class)->confirmed($request->user());
        if ($request->user() && !$purchase && !$active && !$request->isMethod('HEAD')) {
            try {
                // Reuse the authenticated profile and the existing serialized creation path.
                $purchase = $checkout->create($request, $this->buyerData($request));
            } catch (ValidationException) {
                $profileIncomplete = true;
            }
        }
        if ($purchase && !$request->isMethod('HEAD')) {
            try {
                $purchase = $checkout->sync($purchase);
                $user = $request->user();
                // Only the original authenticated CLUB identity can request this link.
                if ($user && $user->hasVerifiedEmail() && $purchase->user_id === $user->id && $purchase->intent['club_user_uuid'] === $user->uuid
                    && $purchase->identity_status !== 'linked') {
                    $response = app(ClubPurchaseClient::class)->request('POST', '/'.$purchase->id.'/identity', [
                        'club_user_uuid' => $user->uuid, 'claim_secret' => $purchase->intent['claim_secret'],
                    ]);
                    $purchase->user_id = $user->id; $purchase->save();
                    $purchase = $checkout->accept($purchase, $response->json());
                }
            } catch (\RuntimeException) { $unavailable = true; }
        }
        $buyer = $request->user() ? $request->user()->only(['name', 'email', 'phone']) : ($purchase ? array_intersect_key($purchase->intent, array_flip(['name', 'email', 'phone'])) : null);
        return Inertia::render('MembershipCheckout', [
            'buyer' => $buyer, 'signed_in' => (bool) $request->user(),
            'active' => ($request->user() && app(MemberActivation::class)->confirmed($request->user())) || (bool) $purchase?->activated_at,
            'purchase' => $purchase ? ['status' => $purchase->status, 'qr_ready' => $purchase->qr_ready,
                'receipt_received' => $purchase->receipt_received || (bool) $purchase->receipt_sha256,
                'receipt_pending' => $purchase->receipt_pending && !$purchase->activated_at, 'reported' => (bool) $purchase->reported_at || $purchase->payment_confirmed,
                'payment_confirmed' => $purchase->payment_confirmed, 'beneficiary_name' => $purchase->beneficiary_name] : null,
            'recipient' => config('purchases.recipient'), 'unavailable' => $unavailable, 'profile_incomplete' => $profileIncomplete,
        ])->toResponse($request)->withHeaders(['Cache-Control' => 'private, no-store']);
    }
    public function store(Request $request, PublicMembershipCheckout $checkout): RedirectResponse {
        if ($request->user() && app(MemberActivation::class)->confirmed($request->user())) return redirect($this->destination($request));
        if (!$checkout->current($request)) {
            $purchase = $checkout->create($request, $this->buyerData($request));
        } else $purchase = $checkout->current($request);
        try { $checkout->sync($purchase); }
        catch (\RuntimeException) { return redirect($this->destination($request))->with('status', 'No pudimos conectar con el servicio de pagos. Tu compra se conserva. Vuelve a intentarlo.'); }
        return redirect($this->destination($request));
    }
    private function destination(Request $request): string {
        $provider = $request->session()->get('ads_provider');
        return in_array($provider, ['meta', 'tiktok'], true) ? '/ads/'.$provider.'/activar' : '/activar';
    }
    private function buyerData(Request $request): array {
        $source = $request->user() ? $request->user()->only(['name', 'email', 'phone']) : $request->only(['name', 'email', 'phone']);
        return validator([
            'name' => Normalize::name($source['name'] ?? ''), 'email' => Normalize::email($source['email'] ?? ''), 'phone' => Normalize::phone($source['phone'] ?? ''),
        ], ['name' => 'required|string|max:120', 'email' => 'required|email:rfc|max:254', 'phone' => ['required', 'regex:/^\+[1-9][0-9]{7,14}$/D']], [
            'name.required' => 'Escribe tu nombre completo.', 'email.*' => 'Escribe un correo válido.', 'phone.*' => 'Incluye el prefijo de tu país, por ejemplo +59170000000.',
        ])->validate();
    }
    public function switchAccount(Request $request): RedirectResponse {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        // Only a fixed local destination survives; purchase capabilities and buyer data do not.
        $request->session()->put('url.intended', '/activar');
        Inertia::clearHistory();
        return redirect()->route('membership.activate');
    }
    public function report(Request $request, PublicMembershipCheckout $checkout): RedirectResponse {
        $purchase = $checkout->current($request); abort_unless($purchase, 404);
        $request->validate(['payment_confirmed' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value !== true) $fail('Confirma que ya realizaste la transferencia de Bs 100 para registrar tu aviso.');
        }]]);
        try {
            $checkout->report($purchase);
        } catch (\RuntimeException $failure) {
            $message = $failure instanceof \App\Services\ClubPurchaseFailure && $failure->status === 422
                ? 'No pudimos registrar tu aviso. Actualiza el estado y confirma de nuevo tu transferencia.'
                : 'No pudimos confirmar tu aviso. Tu compra se conserva. Actualiza el estado antes de volver a intentarlo.';
            return redirect($this->destination($request))->withErrors(['payment_confirmed' => $message]);
        }
        return redirect($this->destination($request));
    }

    public function receipt(Request $request, PublicMembershipCheckout $checkout): RedirectResponse {
        $purchase = $checkout->current($request); abort_unless($purchase, 404);
        $request->validate(['receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240']]);
        if ($purchase->activated_at || $purchase->status === 'under_review') return redirect($this->destination($request));
        $file = $request->file('receipt');
        abort_unless(in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'application/pdf'], true), 422);
        try {
            $purchase = $checkout->refresh($purchase);
            if (!in_array($purchase->status, ['awaiting_payment', 'PAYMENT_REPORTED', 'rejected'], true)) return redirect($this->destination($request));
            $bytes = file_get_contents($file->getRealPath());
            $response = app(ClubPurchaseClient::class)->request('POST', '/'.$purchase->id.'/receipt', [
                'content_base64' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes),
            ]);
            // The upload temporary file is never promoted to public CLUB storage.
            $checkout->accept($purchase, $response->json());
        } catch (\RuntimeException) {
            return redirect($this->destination($request))->withErrors(['receipt' => 'No pudimos confirmar el envío. Actualiza el estado y vuelve a intentarlo con el mismo comprobante.']);
        }
        return redirect($this->destination($request));
    }
    public function qr(Request $request, PublicMembershipCheckout $checkout): \Symfony\Component\HttpFoundation\Response {
        $purchase = $checkout->current($request); abort_unless($purchase && $purchase->qr_ready && in_array($purchase->status, ['awaiting_payment', 'rejected'], true), 404);
        try {
            $qr = Cache::remember('membership-original-qr', 60, function (): array {
                $response = app(ClubPurchaseClient::class)->request('GET', '/qr');
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($response->body());
                if (!in_array($mime, ['image/png', 'image/jpeg'], true) || strlen($response->body()) > 10485760) throw new \RuntimeException('Invalid QR');
                return ['bytes' => $response->body(), 'mime' => $mime];
            });
        } catch (\RuntimeException) { abort(503, 'QR no disponible. Vuelve a intentarlo.'); }
        return response($qr['bytes'], 200, ['Content-Type' => $qr['mime'], 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="qr-membresia.'.($qr['mime'] === 'image/png' ? 'png' : 'jpg').'"']);
    }
}

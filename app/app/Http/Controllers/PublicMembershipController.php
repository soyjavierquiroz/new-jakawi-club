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
use Inertia\Inertia;
final class PublicMembershipController extends Controller {
    public function show(Request $request, PublicMembershipCheckout $checkout, ReferralAttribution $referrals): \Symfony\Component\HttpFoundation\Response {
        \App\Support\MembershipJourney::forget($request);
        $code = $request->query('ref', $request->query('club_ref'));
        if (is_string($code)) $referrals->capture($request, $code);
        $purchase = $checkout->current($request);
        $unavailable = false;
        if ($purchase && !$request->isMethod('HEAD')) {
            try {
                $purchase = $checkout->sync($purchase);
                $user = $request->user();
                // A verified authenticated account may claim its own declared email.
                // Matching a guest-supplied email alone never establishes ownership.
                if ($user && $user->hasVerifiedEmail() && $purchase->intent['email'] === $user->email
                    && (!$purchase->user_id || $purchase->user_id === $user->id) && $purchase->identity_status !== 'linked') {
                    $response = app(ClubPurchaseClient::class)->request('POST', '/'.$purchase->id.'/identity', [
                        'club_user_uuid' => $user->uuid, 'claim_secret' => $purchase->intent['claim_secret'],
                    ]);
                    $purchase->user_id = $user->id; $purchase->save();
                    $purchase = $checkout->apply($purchase, $response->json());
                }
            } catch (\RuntimeException) { $unavailable = true; }
        }
        $buyer = $request->user() ? $request->user()->only(['name', 'email', 'phone']) : ($purchase ? array_intersect_key($purchase->intent, array_flip(['name', 'email', 'phone'])) : null);
        return Inertia::render('MembershipCheckout', [
            'buyer' => $buyer, 'signed_in' => (bool) $request->user(),
            'active' => ($request->user() && app(MemberActivation::class)->confirmed($request->user())) || (bool) $purchase?->activated_at,
            'purchase' => $purchase ? ['status' => $purchase->status, 'qr_ready' => $purchase->qr_ready,
                'receipt_received' => (bool) $purchase->receipt_sha256] : null,
            'recipient' => config('purchases.recipient'), 'unavailable' => $unavailable,
        ])->toResponse($request)->withHeaders(['Cache-Control' => 'private, no-store']);
    }
    public function store(Request $request, PublicMembershipCheckout $checkout): RedirectResponse {
        if ($request->user() && app(MemberActivation::class)->confirmed($request->user())) return redirect('/activar');
        if (!$checkout->current($request)) {
            $source = $request->user() ? $request->user()->only(['name', 'email', 'phone']) : $request->only(['name', 'email', 'phone']);
            $data = validator([
                'name' => Normalize::name($source['name'] ?? ''), 'email' => Normalize::email($source['email'] ?? ''), 'phone' => Normalize::phone($source['phone'] ?? ''),
            ], ['name' => 'required|string|max:120', 'email' => 'required|email:rfc|max:254', 'phone' => ['required', 'regex:/^\+[1-9][0-9]{7,14}$/D']], [
                'name.required' => 'Escribe tu nombre completo.', 'email.*' => 'Escribe un correo válido.', 'phone.*' => 'Incluye el prefijo de tu país, por ejemplo +59170000000.',
            ])->validate();
            $purchase = $checkout->create($request, $data);
        } else $purchase = $checkout->current($request);
        try { $checkout->sync($purchase); }
        catch (\RuntimeException) { return redirect('/activar')->with('status', 'No pudimos conectar con el servicio de pagos. Tu compra se conserva. Vuelve a intentarlo.'); }
        return redirect('/activar');
    }
    public function receipt(Request $request, PublicMembershipCheckout $checkout): RedirectResponse {
        $purchase = $checkout->current($request); abort_unless($purchase, 404);
        $request->validate(['receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240']]);
        if ($purchase->activated_at || $purchase->status === 'under_review') return redirect('/activar');
        $file = $request->file('receipt');
        abort_unless(in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'application/pdf'], true), 422);
        try {
            $purchase = $checkout->sync($purchase);
            if (!in_array($purchase->status, ['awaiting_payment', 'rejected'], true)) return redirect('/activar');
            $bytes = file_get_contents($file->getRealPath());
            $response = app(ClubPurchaseClient::class)->request('POST', '/'.$purchase->id.'/receipt', [
                'content_base64' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes),
            ]);
            // The upload temporary file is never promoted to public CLUB storage.
            $checkout->apply($purchase, $response->json());
        } catch (\RuntimeException) {
            return redirect('/activar')->withErrors(['receipt' => 'No pudimos confirmar el envío. Actualiza el estado y vuelve a intentarlo con el mismo comprobante.']);
        }
        return redirect('/activar');
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

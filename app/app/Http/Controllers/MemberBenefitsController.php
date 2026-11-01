<?php

namespace App\Http\Controllers;

use App\Services\ClubPurchaseClient;
use App\Services\ClubPurchaseFailure;
use App\Services\Identity\IdentityFailure;
use App\Services\Identity\IdentityLink;
use App\Services\PublicMembershipCheckout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberBenefitsController extends Controller
{
    public function store(Request $request, PublicMembershipCheckout $checkout, IdentityLink $identity): JsonResponse
    {
        $headers = ['Cache-Control' => 'private, no-store', 'Pragma' => 'no-cache'];
        $reply = fn (array $body, int $status = 200) => response()->json($body, $status, $headers);
        $user = $request->user();
        abort_unless($user?->activated_at && $user->hasVerifiedEmail(), 403);
        // References, UUIDs and claims supplied by the browser are never used.
        $purchase = $checkout->forBenefits($request);
        if (!$purchase) return $reply(['message' => 'No encontramos una compra autorizada en tu cuenta. Contacta con JAKAWI para recuperar tu acceso.'], 409);
        $intent = $purchase->intent;
        $original = $purchase->user_id === $user->id && ($intent['club_user_uuid'] ?? null) === $user->uuid;
        $linkedGuest = !$purchase->user_id && $purchase->identity_status === 'linked' && $user->global_subject_id
            && $purchase->global_subject_id === $user->global_subject_id;
        $sessionGuest = !$purchase->user_id && empty($intent['club_user_uuid']) && $purchase->identity_status !== 'linked'
            && $request->session()->get('membership_purchase') === $purchase->id;
        if (!$original && !$linkedGuest && !$sessionGuest) return $reply(['message' => 'Esta compra requiere verificar a su titular. Contacta con JAKAWI para recuperar tu acceso.'], 409);
        $claim = $intent['claim_secret'] ?? null;
        if (!is_string($claim) || $claim === '') return $reply(['message' => 'Falta la prueba segura original de esta compra. Contacta con JAKAWI para recuperar tu acceso; no necesitas volver a pagar.'], 409);
        try {
            // GET only: this path must never create or replay a purchase.
            $purchase = $checkout->refresh($purchase);
            if (!$purchase->activated_at) return $reply(['message' => 'Tu pago todavía está en verificación. Podrás entrar cuando se confirme tu membresía.'], 409);
            if ($sessionGuest) $request->validate(['consent' => 'required|accepted'], ['consent.*' => 'Autoriza vincular esta compra con tu cuenta para continuar.']);
            $state = $identity->state($user);
            if (!$state['connected']) {
                $request->validate(['consent' => 'required|accepted'], ['consent.*' => 'Autoriza la conexión de tu acceso antes de continuar.']);
                $state = $identity->activate($user);
                if (!empty($state['authorization_url'])) return $reply(['authorization_url' => $state['authorization_url']]);
                if (!empty($state['needs_email_confirmation']) && !empty($state['can_activate'])) return $reply(['confirm_email' => true]);
                if (!$state['connected']) return $reply(['message' => match ($state['status']) {
                    'CONFLICT', 'BLOCKED', 'REVOKED' => 'No pudimos autorizar esta identidad. Entra con tu propia cuenta JAKAWI o contacta con soporte para revisar el vínculo.',
                    default => 'Tu acceso está pendiente de autorización. Vuelve a intentarlo en unos momentos.',
                }], 409);
            } else {
                // Revalidate the authoritative mapping (SSO identities are checked by COM below).
                if (!$user->sso_provisioned_at && !$identity->refresh($user)['connected']) {
                    return $reply(['message' => 'Tu conexión necesita autorización de nuevo. Vuelve a activar tu acceso.'], 409);
                }
            }
            $user->refresh();
            if ($purchase->global_subject_id && $purchase->global_subject_id !== $user->global_subject_id) {
                return $reply(['message' => 'La compra está vinculada a otra identidad. Contacta con soporte para revisar el acceso sin cambiar de titular.'], 409);
            }
            $data = app(ClubPurchaseClient::class)->request('POST', '/'.$purchase->id.'/access-ticket', [
                'club_user_uuid' => $user->uuid, 'claim_secret' => $claim,
            ], attempts: 1)->json();
            if (!is_array($data) || ($data['contract'] ?? null) !== 'jakawi-club-member-access-v1'
                || ($data['entry_url'] ?? null) !== 'https://jakawi.com/club/access' || ($data['destination'] ?? null) !== '/beneficios'
                || !is_string($data['ticket'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $data['ticket'])) throw new ClubPurchaseFailure();
            // Only this uncacheable JSON response carries the ephemeral ticket; never Inertia props/session.
            return $reply(['ticket' => $data['ticket'], 'entry_url' => $data['entry_url']]);
        } catch (IdentityFailure) {
            return $reply(['message' => 'No pudimos autorizar tu acceso. Tu membresía se conserva; vuelve a intentarlo.'], 503);
        } catch (ClubPurchaseFailure $failure) {
            return $reply(['message' => match ($failure->reason) {
                'IDENTITY_LINK_REQUIRED' => 'Tu acceso requiere completar la autorización de tu identidad. Vuelve a activar tu acceso.',
                'IDENTITY_CONFLICT' => 'La compra está vinculada a otra identidad. Entra con tu propia cuenta JAKAWI o contacta con soporte para revisar el vínculo.',
                default => 'No pudimos abrir tus beneficios. Tu membresía se conserva. Vuelve aquí y reintenta el acceso si el enlace vence.',
            }], 409);
        } catch (\RuntimeException) {
            return $reply(['message' => 'No pudimos comprobar tu acceso. Tu membresía se conserva; vuelve a intentarlo.'], 503);
        }
    }
}

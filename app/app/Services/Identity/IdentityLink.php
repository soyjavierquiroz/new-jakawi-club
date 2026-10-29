<?php

namespace App\Services\Identity;

use App\Models\IdentityHandoff;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class IdentityLink
{
    private function account(User $user): void
    {
        if (! config('identity.enabled') || ! $user->activated_at || ! $user->hasVerifiedEmail()) {
            throw new IdentityFailure('LOCAL_VERIFICATION', false);
        }
    }

    private function latest(User $user): ?IdentityHandoff
    {
        return IdentityHandoff::where('user_id', $user->id)->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    public function state(User $user): array
    {
        if (! config('identity.enabled')) return ['status' => 'DISABLED', 'connected' => false];
        $link = DB::table('club_identity_links')->where('user_id', $user->id)->first();
        $handoff = $this->latest($user);
        $status = $link?->status === 'LINKED' ? 'LINKED' : ($link?->status ?? $handoff?->status ?? 'READY');
        if ($status === 'AUTH_REQUIRED' && $handoff?->remote_expires_at?->isPast()) $status = 'EXPIRED';
        $needsEmail = ! app(ActivationEvidence::class)->find($user);
        return [
            'status' => $status, 'connected' => $status === 'LINKED',
            'needs_email_confirmation' => $needsEmail,
            'authorization_url' => $status === 'AUTH_REQUIRED' && $handoff ? ComIdentityClient::ISSUER.'/identity/club/link/'.$handoff->id : null,
            'retry_at' => $handoff?->next_attempt_at?->toIso8601String(),
            'can_activate' => ! in_array($status, ['LINKED', 'CONFLICT', 'REVOKED', 'BLOCKED', 'AUTH_REQUIRED'], true),
            'message' => match ($status) {
                'LINKED' => 'Cuenta JAKAWI conectada',
                'AUTH_REQUIRED' => 'Inicia sesión en JAKAWI.COM y autoriza la conexión con tu cuenta.',
                'CONFLICT', 'BLOCKED' => 'La conexión requiere revisión. No se han cambiado tus permisos.',
                'REVOKED' => 'La conexión fue revocada. Contacta con JAKAWI para revisarla.',
                'RETRY', 'QUEUED', 'SENDING' => 'Tu solicitud está guardada. Puedes reintentar la conexión.',
                'EXPIRED' => 'La solicitud venció. Puedes iniciar una nueva activación.',
                'VERIFICATION_REQUIRED' => 'JAKAWI.COM requiere verificar de nuevo la evidencia de correo.',
                default => $needsEmail ? 'Primero confirmaremos tu correo para este acceso.' : 'Activa tu acceso a JAKAWI.COM cuando lo decidas.',
            },
        ];
    }

    /** Reconcile only by authenticated UUID lookups; never provision on a GET. */
    public function refresh(User $user): array
    {
        $this->account($user);
        $client = app(ComIdentityClient::class);
        $link = $client->link($user->uuid);
        if ($link !== null) {
            $this->storeLink($user, $link);
            return $this->state($user->fresh());
        }
        $handoff = $this->latest($user);
        if ($handoff) {
            $result = $client->handoff($handoff->id);
            if ($result !== null) {
                $this->accept($user, $handoff, $result);
                if (in_array($result['status'], ['PROVISIONED', 'LINKED', 'REVOKED'], true)) return $this->state($user->fresh());
            }
            elseif ($handoff->proof_expires_at->isPast() && ! in_array($handoff->status, ['CONFLICT', 'REVOKED', 'BLOCKED'], true)) {
                $handoff->status = 'EXPIRED';
                $handoff->save();
            }
        }
        // A missing mapping must never leave a stale local link labelled connected.
        DB::table('club_identity_links')->where('user_id', $user->id)->where('status', 'LINKED')
            ->update(['status' => 'CONFLICT', 'checked_at' => now()]);
        return $this->state($user->fresh());
    }

    /** Explicit private POST starts/retries one durable operation with immutable bytes. */
    public function activate(User $user): array
    {
        $this->account($user);
        $state = $this->refresh($user);
        if (! $state['can_activate']) return $state;
        if ($state['needs_email_confirmation']) return $state;

        $handoff = DB::transaction(function () use ($user): IdentityHandoff {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->account($locked);
            $last = $this->latest($locked);
            if ($last && ! in_array($last->status, ['EXPIRED', 'VERIFICATION_REQUIRED'], true)) return $last;
            $op = new IdentityHandoff;
            $op->id = strtolower((string) Str::uuid7(now()));
            $op->user_id = $locked->id;
            $op->request_body = json_encode([
                'contract' => ComIdentityClient::CONTRACT, 'source_app' => 'club',
                'handoff_id' => $op->id, 'club_user_id' => $locked->uuid,
                'name' => $locked->name, 'email' => $locked->email, 'whatsapp' => $locked->phone,
                'email_activation_proof' => app(ActivationEvidence::class)->proof($locked, $op->id),
                'consent' => ['purpose' => 'identity_link', 'version' => '1', 'accepted_at' => now()->utc()->toIso8601String()],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $op->status = 'QUEUED';
            $op->proof_expires_at = now()->addSeconds(240);
            $op->save();
            return $op;
        });
        $claimed = DB::transaction(function () use ($handoff): bool {
            $op = IdentityHandoff::whereKey($handoff->id)->lockForUpdate()->firstOrFail();
            if (! in_array($op->status, ['QUEUED', 'RETRY', 'SENDING'], true) || $op->next_attempt_at?->isFuture()) return false;
            $op->status = 'SENDING';
            $op->attempts++;
            $op->next_attempt_at = now()->addSeconds(20);
            $op->save();
            return true;
        });
        if ($claimed) {
            try {
                $this->accept($user, $handoff->fresh(), app(ComIdentityClient::class)->create($handoff->request_body));
            } catch (IdentityFailure $failure) {
                $handoff->refresh();
                $handoff->status = $failure->retryable ? 'RETRY' : 'BLOCKED';
                $handoff->next_attempt_at = now()->addSeconds($failure->retryAfter);
                $handoff->last_error = $failure->reason;
                $handoff->save();
                throw $failure;
            }
        }
        return $this->state($user->fresh());
    }

    private function accept(User $user, IdentityHandoff $op, array $data): void
    {
        $status = $data['status'] ?? null;
        // COM's minimal conflict variant deliberately omits identity/link fields.
        if ($status === 'CONFLICT' && ($data['error'] ?? null) === 'IDEMPOTENCY_CONFLICT'
            && ($data['contract'] ?? null) === ComIdentityClient::CONTRACT && ($data['handoff_id'] ?? null) === $op->id
            && ! array_key_exists('link', $data)) {
            $op->status = 'CONFLICT';
            $op->last_error = 'IDEMPOTENCY_CONFLICT';
            $op->next_attempt_at = null;
            $op->save();
            return;
        }
        if (($data['contract'] ?? null) !== ComIdentityClient::CONTRACT || ($data['issuer'] ?? null) !== ComIdentityClient::ISSUER
            || ($data['club_user_id'] ?? null) !== $user->uuid || ($data['handoff_id'] ?? null) !== $op->id
            || ! in_array($status, ['PROVISIONED', 'AUTH_REQUIRED', 'VERIFICATION_REQUIRED', 'CONFLICT', 'LINKED', 'REVOKED'], true)) {
            throw new IdentityFailure('UNTRUSTED_RESULT', false);
        }
        if (in_array($status, ['PROVISIONED', 'LINKED', 'REVOKED'], true)) {
            if (! is_array($data['link'] ?? null) || ($data['link']['status'] ?? null) !== ($status === 'REVOKED' ? 'REVOKED' : 'LINKED')) {
                throw new IdentityFailure('UNTRUSTED_LINK', false);
            }
            if ($status === 'PROVISIONED' && ($data['link']['method'] ?? null) !== 'CLUB_EMAIL_ACTIVATION') {
                throw new IdentityFailure('UNTRUSTED_METHOD', false);
            }
            $this->storeLink($user, $data['link']);
        } elseif (array_key_exists('link', $data)) {
            throw new IdentityFailure('PREMATURE_LINK', false);
        }
        $expiry = null;
        if ($status === 'AUTH_REQUIRED') {
            if (($data['authorization_url'] ?? null) !== ComIdentityClient::ISSUER.'/identity/club/link/'.$op->id) {
                throw new IdentityFailure('UNTRUSTED_REDIRECT', false);
            }
            $expiry = $this->date($data['expires_at'] ?? null);
            if ($expiry->greaterThan(now()->addSeconds(930))) throw new IdentityFailure('UNTRUSTED_EXPIRY', false);
            if ($expiry->isPast()) $status = 'EXPIRED';
        }
        $op->status = $status;
        $op->remote_expires_at = $expiry;
        $op->next_attempt_at = null;
        $op->last_error = null;
        $op->save();
    }

    private function date(mixed $value): Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ].*(?:Z|[+-]\d{2}(?::?\d{2})?)$/D', $value)) {
            throw new IdentityFailure('UNTRUSTED_DATE', false);
        }
        try { return Carbon::parse($value)->utc(); }
        catch (\Throwable) { throw new IdentityFailure('UNTRUSTED_DATE', false); }
    }

    private function storeLink(User $user, array $link): void
    {
        $subject = $link['global_subject_id'] ?? null;
        $status = $link['status'] ?? null;
        if (($link['issuer'] ?? null) !== ComIdentityClient::ISSUER || ($link['source_app'] ?? null) !== 'club'
            || ($link['club_user_id'] ?? null) !== $user->uuid || ! is_string($subject) || ! Str::isUuid($subject) || strtolower($subject) !== $subject
            || ! in_array($status, ['LINKED', 'REVOKED'], true)
            || ! in_array($link['method'] ?? null, ['CLUB_EMAIL_ACTIVATION', 'COM_AUTHENTICATED_CONSENT'], true)) {
            throw new IdentityFailure('UNTRUSTED_LINK', false);
        }
        $authorized = $this->date($link['authorized_at'] ?? null);
        $revoked = $status === 'REVOKED' ? $this->date($link['revoked_at'] ?? null) : null;
        if ($authorized->greaterThan(now()->addSeconds(30)) || ($status === 'LINKED' && ($link['revoked_at'] ?? null) !== null)
            || ($revoked && ($revoked->lessThan($authorized) || $revoked->greaterThan(now()->addSeconds(30))))) {
            throw new IdentityFailure('UNTRUSTED_AUTHORIZATION', false);
        }
        try {
            DB::transaction(function () use ($user, $subject, $status, $link, $authorized, $revoked): void {
                $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if ($locked->global_subject_id !== null && $locked->global_subject_id !== $subject) {
                    throw new IdentityFailure('SUBJECT_CONFLICT', false);
                }
                DB::table('users')->where('id', $locked->id)->update(['global_subject_id' => $subject]);
                DB::table('club_identity_links')->updateOrInsert(['user_id' => $locked->id], [
                    'global_subject_id' => $subject, 'status' => $status, 'method' => $link['method'],
                    'authorized_at' => $authorized, 'revoked_at' => $revoked, 'checked_at' => now(),
                ]);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            throw new IdentityFailure('SUBJECT_CONFLICT', false);
        }
    }
}

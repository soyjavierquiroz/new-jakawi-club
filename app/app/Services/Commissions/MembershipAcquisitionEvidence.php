<?php

namespace App\Services\Commissions;

use App\Models\User;
use App\Services\ClubPurchaseClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MembershipAcquisitionEvidence
{
    public const CONTRACT = 'jakawi-acquisition-evidence-v1';

    public function resolve(object $person, object $event, Carbon $at): ?array
    {
        $payload = json_decode($event->raw_body, true, 32, JSON_THROW_ON_ERROR);
        $payment = $payload['payment_reference'] ?? null;
        $subject = null;
        $reason = null;
        if (!is_string($payment) || $payment === '' || strlen($payment) > 100) $reason = 'Falta referencia de pago original COM.';
        if ($person->beneficiary_type === 'INDIVIDUAL') {
            $user = User::where('uuid', $person->beneficiary_id)->first();
            $subject = $user?->global_subject_id;
            $revoked = $user && DB::table('club_identity_links')->where('user_id', $user->id)->where('status', 'REVOKED')->exists();
            if (!$subject || !Str::isUuid($subject) || $revoked) $reason = 'Afiliado original sin vínculo COM autorizado; pendiente de identidad.';
        }
        if ($reason) {
            $this->store($person, $event, $payment, $subject, null, 'pending', $reason);
            return null;
        }
        // Same inputs are queried at most once per five minutes. Identity-linked
        // changes the input; explicit admin retry uses this same bounded mechanism.
        $cached = DB::table('acquisition_evidence')->where('event_id', $event->id)
            ->where('affiliate_subject', $subject)->where('identity_context', $this->identityContext($person))->where('created_at', '>', now()->subMinutes(5))->orderByDesc('id')->first();
        if ($cached) return $cached->result ? json_decode($cached->result, true, 32, JSON_THROW_ON_ERROR) : null;
        $raw = null;
        try {
            $response = app(ClubPurchaseClient::class)->acquisitionEvidence($payment, $subject);
            $raw = $response->body();
        } catch (\RuntimeException) {
            $this->store($person, $event, $payment, $subject, null, 'pending', 'COM no disponible; reintento acotado, sin comisión.');
            return null;
        }
        try {
            $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            $this->validate($body, $person, $event, $at, $payment, $subject);
        } catch (\Throwable) {
            $this->store($person, $event, $payment, $subject, $raw, 'invalid', 'Evidencia COM inválida o incoherente con compra, identidad o activación.');
            return null;
        }
        $first = $body['first_paid_acquisition'];
        $state = $body['affiliate']['state'] ?? 'ANY';
        $result = ['first_acquisition' => $first, 'membership_status' => $state,
            'reference' => $body['evidence_reference'], 'acquisition_at' => $body['evaluated_at'], 'body' => $body];
        $status = $first === false ? 'excluded' : ($first === true && $state !== 'UNKNOWN' ? 'verified' : 'pending');
        $reason = $first === false ? 'Compra posterior, renovación o reactivación; sin comisión.' : ($status === 'verified'
            ? 'Evidencia COM certificada; requiere regla vigente y aprobación manual.'
            : 'Pendiente de evidencia COM: '.$this->reason($body['reason'] ?? 'UNKNOWN').' / '.$this->reason($body['affiliate']['reason'] ?? ''));
        $this->store($person, $event, $payment, $subject, $raw, $status, $reason, $result);
        return $result;
    }

    private function reason(string $reason): string
    {
        return match ($reason) {
            'HISTORY_BEFORE_OBSERVATION_BOUNDARY' => 'Historia anterior a la cobertura 10F; primera compra sin certificar',
            'BUYER_IDENTITY_UNRESOLVED' => 'Comprador invitado o identidad pendiente de vínculo autorizado',
            'BUYER_LINK_UNAUTHORIZED' => 'Vínculo del comprador sin autorización vigente',
            'HISTORICAL_STATE_NOT_OBSERVED' => 'Estado histórico del afiliado sin cobertura suficiente',
            'IDENTITY_UNKNOWN' => 'Identidad COM del afiliado sin resolver',
            default => $reason,
        };
    }

    private function date(mixed $value): Carbon
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)$/D', $value)) throw new \UnexpectedValueException();
        return Carbon::parse($value);
    }

    private function validate(array $body, object $person, object $event, Carbon $at, string $payment, ?string $subject): void
    {
        if (($body['contract'] ?? null) !== self::CONTRACT || ($body['purchase_reference'] ?? null) !== $payment
            || !in_array($body['first_paid_acquisition'] ?? null, [true, false, 'unknown'], true)
            || !is_array($body['evidence_references'] ?? null) || !array_is_list($body['evidence_references'])
            || !preg_match('/^com:acquisition-evidence:sha256:[a-f0-9]{64}$/D', $body['evidence_reference'] ?? '')) throw new \UnexpectedValueException();
        foreach ($body['evidence_references'] as $reference) {
            if (!is_string($reference) || !preg_match('/^com:membership-evidence:[0-9]+$/D', $reference)) throw new \UnexpectedValueException();
        }
        $unsigned = $body;
        unset($unsigned['evidence_reference']);
        if (!hash_equals('com:acquisition-evidence:sha256:'.hash('sha256', json_encode($unsigned, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), $body['evidence_reference'])) throw new \UnexpectedValueException();
        if (!in_array($body['purchase_source'] ?? null, ['membership_bank_payments', 'membership_purchases', null], true)) throw new \UnexpectedValueException();
        $knownSubjects = DB::table('acquisition_aliases')->where('person_id', $person->id)->where('kind', 'subject')->pluck('reference')->all();
        if ($event->global_subject_id) $knownSubjects[] = $event->global_subject_id;
        if (($body['global_subject_id'] ?? null) !== null && (!Str::isUuid($body['global_subject_id']) || ($knownSubjects && !in_array($body['global_subject_id'], $knownSubjects, true)))) throw new \UnexpectedValueException();
        if (($body['membership_reference'] ?? null) !== null && $body['membership_reference'] !== $event->com_reference_id) throw new \UnexpectedValueException();
        if (($body['evaluated_at'] ?? null) !== null && abs((float)$this->date($body['evaluated_at'])->format('U.u') - (float)$at->format('U.u')) >= 1) throw new \UnexpectedValueException();
        if (($body['first_activated_at'] ?? null) !== null) $this->date($body['first_activated_at']);
        if ($subject !== null) {
            $affiliate = $body['affiliate'] ?? null;
            if (!is_array($affiliate) || ($affiliate['global_subject_id'] ?? null) !== $subject || !in_array($affiliate['state'] ?? null, ['FREE','PAID','UNKNOWN'], true)) throw new \UnexpectedValueException();
            if ($affiliate['state'] !== 'UNKNOWN' && $this->date($affiliate['evaluated_at'] ?? null)->ne($this->date($body['evaluated_at'] ?? null))) throw new \UnexpectedValueException();
        } elseif (($body['affiliate'] ?? null) !== null) throw new \UnexpectedValueException();
        if ($body['first_paid_acquisition'] === true && (empty($body['purchase_source']) || empty($body['global_subject_id']) || empty($body['identity_reference'])
            || empty($body['evidence_references']) || ($body['membership_reference'] ?? null) !== $event->com_reference_id
            || $this->date($body['first_activated_at'] ?? null)->ne($this->date($body['evaluated_at'] ?? null)))) throw new \UnexpectedValueException();
    }

    private function identityContext(object $person): string
    {
        return hash('sha256', DB::table('acquisition_aliases')->where('person_id', $person->id)
            ->whereIn('kind', ['user','subject'])->orderBy('kind')->orderBy('reference')->get(['kind','reference'])->toJson());
    }

    private function store(object $person, object $event, ?string $payment, ?string $subject, ?string $raw, string $status, string $reason, ?array $result = null): void
    {
        DB::table('acquisition_evidence')->insert(['person_id' => $person->id, 'event_id' => $event->id,
            'purchase_reference' => $payment, 'affiliate_subject' => $subject, 'identity_context' => $this->identityContext($person), 'response_body' => $raw,
            'evidence_reference' => $result['reference'] ?? null, 'status' => $status, 'reason' => $reason,
            'result' => $result ? json_encode($result, JSON_THROW_ON_ERROR) : null, 'created_at' => now()]);
    }
}

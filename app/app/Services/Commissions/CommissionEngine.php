<?php

namespace App\Services\Commissions;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class CommissionEngine
{
    public function operation(object $event): Builder
    {
        return DB::table('commercial_events')->where('source', $event->source)
            ->where('com_reference_type', $event->com_reference_type)->where('com_reference_id', $event->com_reference_id);
    }

    private function lock(object $event): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [json_encode([$event->source, $event->com_reference_type, $event->com_reference_id], JSON_THROW_ON_ERROR)]);
    }

    public function audit(?object $before, object $after, string $action, ?User $actor, string $reason, bool $rule = false): void
    {
        DB::table('commission_audit')->insert([
            $rule ? 'rule_id' : 'ledger_id' => $after->id, 'actor_uuid' => $actor?->uuid,
            'action' => $action, 'reason' => $reason, 'before' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
            'after' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    // The signed payload preserves all six fractional digits. The receiver's timestamp
    // binding may truncate them, so economic decisions use the authenticated original.
    public const EVENT_TIME_SQL = "((commercial_events.raw_body::jsonb)->>'occurred_at')::timestamptz";

    public function occurredAt(object $event): Carbon
    {
        return Carbon::parse(json_decode($event->raw_body, true, 32, JSON_THROW_ON_ERROR)['occurred_at']);
    }

    public function firstConfirmation(object $event): ?object
    {
        return $this->operation($event)->whereIn('conversion_status', ['confirmed','activated'])
            ->orderByRaw(self::EVENT_TIME_SQL.' ASC')->orderBy('id')->first();
    }

    private function eligible(object $event): bool
    {
        return $event->source === 'jakawi.com' && $event->conversion_status === 'confirmed'
            && $this->occurredAt($event)->gt(Carbon::parse(config('commissions.economic_cutoff')))
            && $event->referrer_club_uuid !== null && $event->club_referral_code !== null
            && User::where('uuid', $event->referrer_club_uuid)->where('referral_code', $event->club_referral_code)->exists();
    }

    // Called inside the receiver transaction, so receipt and economic record commit together.
    public function record(object $event, ?User $actor = null): object
    {
        if ($event->event_type === 'membership.activated') {
            $activation = DB::table('acquisition_activations')->where('event_id',$event->id)->firstOrFail();
            return $this->recordMembership(DB::table('acquisition_people')->find($activation->person_id),$activation) ?? throw ValidationException::withMessages(['review'=>'Sin regla aplicable.']);
        }
        return DB::transaction(function () use ($event, $actor): object {
            $this->lock($event);
            $latest = $this->operation($event)->orderByRaw(self::EVENT_TIME_SQL.' DESC')->orderByDesc('id')->firstOrFail();
            $entry = DB::table('commission_ledger')->where('source', $event->source)
                ->where('com_reference_type', $event->com_reference_type)->where('com_reference_id', $event->com_reference_id)->lockForUpdate()->first();
            if (! $entry) {
                $id = DB::table('commission_ledger')->insertGetId([
                    'source' => $event->source, 'com_reference_type' => $event->com_reference_type, 'com_reference_id' => $event->com_reference_id,
                    'original_event_id' => $event->id, 'latest_event_id' => $latest->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $entry = DB::table('commission_ledger')->find($id);
                $this->audit(null, $entry, 'registered', $actor, 'Operación COM registrada; sin obligación económica.');
            }
            $changes = ['latest_event_id' => $latest->id];
            $confirmation = $this->firstConfirmation($event);
            // A later state update cannot turn an operation confirmed before the cut
            // into a new economic fact. Late delivery uses exactly the same boundary.
            $valid = $confirmation !== null
                && $this->occurredAt($confirmation)->gt(Carbon::parse(config('commissions.economic_cutoff')))
                && $this->eligible($latest);
            if ($entry->affiliate_uuid === null && $valid && $entry->status !== 'discarded') {
                $changes += ['affiliate_uuid' => $latest->referrer_club_uuid, 'affiliate_code' => $latest->club_referral_code, 'status' => 'candidate'];
            }
            $owner = $entry->affiliate_uuid ?? ($changes['affiliate_uuid'] ?? null);
            $code = $entry->affiliate_code ?? ($changes['affiliate_code'] ?? null);
            $valid = $valid && $owner === $latest->referrer_club_uuid && $code === $latest->club_referral_code;
            if ($entry->commission_event_id !== null) {
                $basis = DB::table('commercial_events')->find($entry->commission_event_id);
                $valid = $valid && $basis->partner_id === $latest->partner_id;
            }
            $changes['eligible'] = $valid;
            // Once a discrepancy is detected it needs an explicit administrative review.
            $changes['review_required'] = (bool) $entry->review_required || ($owner !== null && ! $valid);
            if ($valid && $entry->rule_id === null && $entry->status !== 'discarded') {
                // Earliest eligible remunerable event; rule version is frozen once applied.
                $valuation = $this->operation($event)->where('conversion_status', 'confirmed')->where('partner_id', $latest->partner_id)
                    ->where('referrer_club_uuid', $owner)->where('club_referral_code', $code)
                    ->whereRaw(self::EVENT_TIME_SQL.' > ?::timestamptz', [config('commissions.economic_cutoff')])
                    ->whereExists(function (Builder $q): void {
                        $q->selectRaw('1')->from('commission_rules as r')->where('r.status', 'active')->where('r.beneficiary_type','INDIVIDUAL')
                            ->whereColumn('r.partner_id', 'commercial_events.partner_id')->whereColumn('r.event_type', 'commercial_events.event_type')
                            ->whereRaw('r.effective_at <= '.self::EVENT_TIME_SQL);
                    })->orderByRaw(self::EVENT_TIME_SQL.' ASC')->orderBy('id')->first();
                if ($valuation) {
                    $rule = DB::table('commission_rules')->where('status', 'active')->where('beneficiary_type','INDIVIDUAL')->where('partner_id', $valuation->partner_id)
                        ->where('event_type', $valuation->event_type)->where('effective_at', '<=', $this->occurredAt($valuation)->utc()->format('Y-m-d H:i:s.uP'))
                        ->orderByDesc('effective_at')->orderByDesc('id')->sharedLock()->first();
                    if ($rule) $changes += ['rule_id' => $rule->id, 'commission_event_id' => $valuation->id,
                        'amount' => $rule->fixed_amount, 'currency' => $rule->currency, 'rule_snapshot' => json_encode($rule, JSON_THROW_ON_ERROR)];
                }
            }
            return $this->save($entry, $changes, 'evaluated', $actor, 'Evaluación por fecha económica COM y corte autorizado; aprobación administrativa manual.');
        }, 3);
    }

    public function recordMembership(object $person, object $activation): ?object
    {
        return DB::transaction(function () use ($person, $activation): ?object {
            DB::select("select pg_advisory_xact_lock(hashtextextended('club_acquisition', 0))");
            return $this->evaluateMembership($person, $activation);
        }, 3);
    }

    private function evaluateMembership(object $person, object $activation): ?object
    {
        if (!$person->beneficiary_id) return null;
        $event = DB::table('commercial_events')->find($activation->event_id);
        if (!$this->occurredAt($event)->gt(Carbon::parse(config('commissions.economic_cutoff')))) return null;
        $existing = DB::table('commission_ledger')->where('acquisition_person_id',$person->id)->first();
        // An identity merge may reveal a previously valued guest acquisition.
        $other = DB::table('commission_ledger')->where('com_reference_type','membership')->whereIn('com_reference_id', DB::table('acquisition_activations')->where('person_id',$person->id)->select('membership_reference'))->first();
        if ($existing || $other) {
            $entry = $existing ?? $other;
            $enabled = $person->beneficiary_type !== 'PARTNER' || DB::table('affiliate_partners')->where('source','jakawi.com')->where('partner_id',$person->beneficiary_id)->where('status','enabled')->exists();
            if (!$enabled || $entry->beneficiary_type !== $person->beneficiary_type || $entry->beneficiary_id !== $person->beneficiary_id) return $this->save($entry,['eligible'=>false,'review_required'=>true],'identity_review',null,'Revisar beneficiario original o estado Partner antes de aprobar.');
            return $this->automaticallyApprove($entry, $person, $activation);
        }
        if ($person->beneficiary_type === 'PARTNER' && !DB::table('affiliate_partners')->where('source','jakawi.com')->where('partner_id',$person->beneficiary_id)->where('status','enabled')->exists()) return null;
        $at = $this->occurredAt($event);
        $acquisitionRule = DB::table('commission_rules')->where('nature','ONE_TIME_ACQUISITION')
            ->where('event_type','membership.activated')->exists();
        $evidence = null;
        if ($acquisitionRule) {
            // Never value a renewal or an unverified first purchase. A signed activation
            // proves approval, but does not prove that this is the buyer's first ever.
            if ($event->source !== 'jakawi.com' || $event->event_type !== 'membership.activated' || $event->conversion_status !== 'activated') return null;
            $first = DB::table('acquisition_activations')->where('person_id',$person->id)->orderBy('activated_at')->orderBy('id')->first();
            if (!$first || $first->id !== $activation->id) return null;
            if ($person->beneficiary_type === 'INDIVIDUAL') {
                $beneficiary = User::where('uuid',$person->beneficiary_id)->first();
                if (!$beneficiary || DB::table('acquisition_aliases')->where('person_id',$person->id)->where(function (Builder $q) use ($beneficiary): void {
                    $q->where(fn (Builder $u) => $u->where('kind','user')->where('reference',$beneficiary->uuid));
                    if ($beneficiary->global_subject_id) $q->orWhere(fn (Builder $u) => $u->where('kind','subject')->where('reference',$beneficiary->global_subject_id));
                })->exists()) return null;
            }
            $evidence = app(MembershipAcquisitionEvidence::class)->resolve($person,$event,$at);
            if (!$evidence || $evidence['first_acquisition'] !== true || empty($evidence['reference'])) return null;
            $at = Carbon::parse($evidence['acquisition_at'] ?? $at);
            if (!$at->gt(Carbon::parse(config('commissions.economic_cutoff')))) return null;
            if ($person->beneficiary_type === 'INDIVIDUAL' && !in_array($evidence['membership_status'],['FREE','PAID'],true)) return null;
        }
        $rule = DB::table('commission_rules')->where('status','active')->where('event_type','membership.activated')
            ->where('nature',$acquisitionRule ? 'ONE_TIME_ACQUISITION' : 'LEGACY')
            ->when($acquisitionRule, fn (Builder $q) => $q->where('membership_status',$person->beneficiary_type === 'PARTNER' ? 'ANY' : $evidence['membership_status']))
            ->where('beneficiary_type',$person->beneficiary_type)->whereIn('partner_id',[$person->beneficiary_id,'*'])
            ->where('effective_at','<=',$at->utc()->format('Y-m-d H:i:s.uP'))
            ->orderByRaw("CASE WHEN partner_id = '*' THEN 1 ELSE 0 END")->orderByDesc('effective_at')->orderByDesc('id')->sharedLock()->first();
        if (!$rule) return null;
        $id = DB::table('commission_ledger')->insertGetId([
            'source'=>'jakawi.com','com_reference_type'=>'membership','com_reference_id'=>$activation->membership_reference,
            'original_event_id'=>$event->id,'latest_event_id'=>$event->id,'commission_event_id'=>$event->id,
            'beneficiary_type'=>$person->beneficiary_type,'beneficiary_id'=>$person->beneficiary_id,'acquisition_person_id'=>$person->id,
            'affiliate_uuid'=>$person->beneficiary_type === 'INDIVIDUAL' ? $person->beneficiary_id : null,
            'affiliate_code'=>$person->referral_code,'rule_id'=>$rule->id,'rule_snapshot'=>json_encode($evidence ? [...(array)$rule,
                'acquisition_at'=>$at->format('Y-m-d\TH:i:s.uP'),
                'payment_reference'=>json_decode($event->raw_body,true,32,JSON_THROW_ON_ERROR)['payment_reference'],
                'beneficiary_type'=>$person->beneficiary_type,'beneficiary_id'=>$person->beneficiary_id,
                'membership_evidence'=>$evidence] : $rule,JSON_THROW_ON_ERROR),
            'amount'=>$rule->fixed_amount,'currency'=>$rule->currency,'status'=>'candidate','eligible'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $entry=DB::table('commission_ledger')->find($id);
        $this->audit(null,$entry,'registered',null,'Membresía activada; beneficiario original y regla diferenciada. Se verificará aprobación automática sin desembolso.');
        return $this->automaticallyApprove($entry, $person, $activation);
    }

    // Shared by receipt processing, bounded retries and explicit existing-entry approval.
    // Never modifies the immutable economic snapshot or initiates a payment.
    private function automaticallyApprove(object $entry, object $person, object $activation): object
    {
        $entry = DB::table('commission_ledger')->where('id', $entry->id)->lockForUpdate()->firstOrFail();
        $snapshot = json_decode($entry->rule_snapshot ?? '{}', true, 32, JSON_THROW_ON_ERROR);
        if (($snapshot['nature'] ?? null) !== 'ONE_TIME_ACQUISITION' || $entry->status === 'discarded') return $entry;
        $event = DB::table('commercial_events')->find($entry->original_event_id);
        $evidence = app(MembershipAcquisitionEvidence::class)->resolve($person, $event, $this->occurredAt($event));
        $first = DB::table('acquisition_activations')->where('person_id', $person->id)->orderBy('activated_at')->orderBy('id')->first();
        $rivals = DB::table('commission_ledger')->where('id', '<>', $entry->id)->where(function (Builder $q) use ($person): void {
            $q->where('acquisition_person_id', $person->id)->orWhereIn('com_reference_id', DB::table('acquisition_activations')->where('person_id', $person->id)->select('membership_reference'));
        })->exists();
        $conflicts = DB::table('commercial_event_conflicts')->where(function (Builder $q) use ($event, $person): void {
            $q->whereIn('commercial_event_id', $this->operation($event)->select('id'))->orWhereIn('commercial_event_id', DB::table('acquisition_facts')->where(function (Builder $f) use ($person): void {
                foreach (['payment_reference' => 'payment', 'global_subject_id' => 'subject', 'club_user_uuid' => 'user'] as $column => $kind) {
                    $f->orWhereIn($column, DB::table('acquisition_aliases')->where('person_id', $person->id)->where('kind', $kind)->selectRaw($kind === 'payment' ? 'reference' : 'reference::uuid'));
                }
            })->select('event_id'));
        })->exists();
        $latest = $this->operation($event)->where('event_type', '<>', 'membership.identity_linked')->orderByRaw(self::EVENT_TIME_SQL.' DESC')->orderByDesc('id')->first();
        $beneficiary = $person->beneficiary_type === 'INDIVIDUAL' ? User::where('uuid', $person->beneficiary_id)->first() : null;
        $validBeneficiary = $beneficiary
            ? $beneficiary->activated_at !== null && $beneficiary->hasVerifiedEmail()
                && $beneficiary->referral_code === $entry->affiliate_code && $person->referral_code === $entry->affiliate_code
                && !DB::table('acquisition_aliases')->where('person_id', $person->id)->where(function (Builder $q) use ($beneficiary): void {
                    $q->where(fn (Builder $a) => $a->where('kind', 'user')->where('reference', $beneficiary->uuid));
                    if ($beneficiary->global_subject_id) $q->orWhere(fn (Builder $a) => $a->where('kind', 'subject')->where('reference', $beneficiary->global_subject_id));
                })->exists()
            : $person->beneficiary_type === 'PARTNER' && DB::table('affiliate_partners')->where('source', 'jakawi.com')->where('partner_id', $person->beneficiary_id)->where('status', 'enabled')->exists();
        $rule = DB::table('commission_rules')->where('id', $entry->rule_id)->sharedLock()->first();
        $valid = $entry->eligible && !$entry->review_required && !$rivals && !$conflicts
            && $validBeneficiary && $entry->beneficiary_type === $person->beneficiary_type && $entry->beneficiary_id === $person->beneficiary_id
            && $first && $first->id === $activation->id && $first->event_id === $event->id
            && $this->occurredAt($event)->gt(Carbon::parse(config('commissions.economic_cutoff')))
            && $event->source === 'jakawi.com' && $event->event_type === 'membership.activated' && $latest->conversion_status === 'activated'
            && (json_decode($event->raw_body, true)['identity_status'] ?? null) !== 'conflict'
            && $evidence && $evidence['first_acquisition'] === true && !empty($evidence['body']['global_subject_id'])
            && !empty($evidence['reference']) && ($snapshot['membership_evidence']['first_acquisition'] ?? null) === true
            && $evidence['membership_status'] === ($snapshot['membership_evidence']['membership_status'] ?? null)
            && $evidence['body']['global_subject_id'] === ($snapshot['membership_evidence']['body']['global_subject_id'] ?? null)
            && ($person->beneficiary_type === 'PARTNER' || in_array($evidence['membership_status'], ['FREE', 'PAID'], true))
            && $rule && $rule->nature === 'ONE_TIME_ACQUISITION' && $rule->rule_version === ($snapshot['rule_version'] ?? null)
            && $rule->status === 'active' && Carbon::parse($rule->effective_at)->lte(Carbon::parse($snapshot['acquisition_at']))
            && (string)$entry->amount === (string)$snapshot['fixed_amount'] && $entry->currency === $snapshot['currency'];
        if (!$valid) return $this->save($entry, ['review_required' => true], 'automatic_review', null,
            'Requiere revisión: evidencia, identidad, beneficiario, tarifa, duplicado o alerta sin confirmar. Importe histórico conservado.');
        if ($entry->status !== 'candidate') return $entry;
        return $this->save($entry, ['status' => 'approved', 'approval_mode' => 'automatic', 'approved_by' => null, 'approved_at' => now()],
            'automatically_approved', null, 'Primera adquisición COM verificada; atribución, identidad, beneficiario y tarifa original válidos, sin conflictos ni alertas. Pendiente de liquidación; sin desembolso.');
    }

    public function flagOperationReview(object $event): void
    {
        $fact = DB::table('acquisition_facts')->where('event_id', $event->id)->first();
        $people = DB::table('acquisition_aliases')->whereIn('reference', array_filter([$fact?->payment_reference, $fact?->club_user_uuid, $fact?->global_subject_id]))->select('person_id');
        $entries = DB::table('commission_ledger')->where(function (Builder $q) use ($event, $people): void {
            $q->where(fn (Builder $operation) => $operation->where('source', $event->source)->where('com_reference_type', $event->com_reference_type)->where('com_reference_id', $event->com_reference_id))
                ->orWhereIn('acquisition_person_id', $people)
                ->orWhere(fn (Builder $memberships) => $memberships->where('com_reference_type', 'membership')->whereIn('com_reference_id', DB::table('acquisition_activations')->whereIn('person_id', $people)->select('membership_reference')));
        })->orderBy('id')->lockForUpdate()->get();
        foreach ($entries as $entry) $this->save($entry, ['review_required' => true], 'event_conflict', null, 'Conflicto de evento firmado COM; aprobación bloqueada y valoración conservada.');
    }

    public function approveExistingAcquisition(int $id): object
    {
        return DB::transaction(function () use ($id): object {
            DB::select("select pg_advisory_xact_lock(hashtextextended('club_acquisition', 0))");
            $entry = DB::table('commission_ledger')->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($entry->acquisition_person_id, 422);
            $activation = DB::table('acquisition_activations')->where('event_id', $entry->original_event_id)->firstOrFail();
            return $this->automaticallyApprove($entry, DB::table('acquisition_people')->find($activation->person_id), $activation);
        }, 3);
    }

    private function save(object $entry, array $changes, string $action, ?User $actor, string $reason, bool $forceAudit = false): object
    {
        $changes = array_filter($changes, fn ($v, $k) => $entry->$k !== $v && (string) $entry->$k !== (string) $v, ARRAY_FILTER_USE_BOTH);
        if ($changes === [] && ! $forceAudit) return $entry;
        DB::table('commission_ledger')->where('id', $entry->id)->update([...$changes, 'version' => $entry->version + 1, 'updated_at' => now()]);
        $after = DB::table('commission_ledger')->find($entry->id);
        $this->audit($entry, $after, $action, $actor, $reason);
        return $after;
    }

    public function review(int $id, array $data, User $actor): object
    {
        return DB::transaction(function () use ($id, $data, $actor): object {
            $old = DB::table('commission_ledger')->find($id);
            abort_unless($old, 404);
            $event = DB::table('commercial_events')->find($old->original_event_id);
            if ($old->acquisition_person_id) DB::select("select pg_advisory_xact_lock(hashtextextended('club_acquisition', 0))");
            $this->lock($event);
            $entry = DB::table('commission_ledger')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($entry->version !== (int) $data['version']) throw ValidationException::withMessages(['review' => 'El registro cambió. Recarga antes de decidir.']);
            $entry = $this->record($event, $actor);
            $action = $data['action'];
            if ($entry->acquisition_person_id && (json_decode($entry->rule_snapshot ?? '{}', true)['nature'] ?? null) === 'ONE_TIME_ACQUISITION' && $action !== 'discard') return $entry;
            if ($action === 'approve' && $entry->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['review' => 'La valoración cambió. Evalúa y recarga antes de aprobar.']);
            }
            if ($entry->status === 'discarded') throw ValidationException::withMessages(['review' => 'La comisión descartada es definitiva.']);
            $changes = [];
            if ($action === 'evaluate') {
                if ($entry->eligible) $changes['review_required'] = false;
            } elseif ($action === 'approve') {
                $rule = $entry->rule_id ? DB::table('commission_rules')->where('id', $entry->rule_id)->sharedLock()->first() : null;
                if ($entry->status !== 'candidate' || ! $entry->eligible || $entry->review_required || ! $rule || $rule->status !== 'active' || Carbon::parse($rule->effective_at)->isFuture()
                    || $entry->amount === null || $entry->currency === null || $entry->commission_event_id === null) {
                    throw ValidationException::withMessages(['review' => 'Exige operación posterior al corte, referente verificado, regla activa e importe exacto; revisa las discrepancias.']);
                }
                $changes = ['status' => 'approved', 'approved_by' => $actor->uuid, 'approved_at' => now()];
            } elseif ($action === 'discard') {
                $changes = ['status' => 'discarded'];
            }
            return $this->save($entry, $changes, $action, $actor, $data['reason'], true);
        }, 3);
    }

    public function summary(User $user): array
    {
        $q = DB::table('commission_ledger')->where('affiliate_uuid', $user->uuid)->where('affiliate_code', $user->referral_code);
        return ['pending' => (clone $q)->where('status', 'candidate')->where('eligible', true)->where('review_required', false)->count(),
            'approved' => (clone $q)->where('status', 'approved')->where('review_required', false)->count(),
            'review' => (clone $q)->where('review_required', true)->where('status', '<>', 'discarded')->count(),
            'currencies' => (clone $q)->whereNotNull('currency')->groupBy('currency')->orderBy('currency')
                ->selectRaw("currency, coalesce(sum(amount) filter (where status = 'candidate' and eligible and not review_required),0)::text as pending, coalesce(sum(amount) filter (where status = 'approved' and not review_required),0)::text as approved, coalesce(sum(amount) filter (where status in ('candidate','approved') and review_required),0)::text as review")
                ->get()->all()];
    }
}

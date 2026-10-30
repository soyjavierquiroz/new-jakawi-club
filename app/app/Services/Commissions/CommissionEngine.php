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

    private function eligible(object $event): bool
    {
        return $event->source === 'jakawi.com' && $event->conversion_status === 'confirmed'
            && $event->referrer_club_uuid !== null && $event->club_referral_code !== null
            && User::where('uuid', $event->referrer_club_uuid)->where('referral_code', $event->club_referral_code)->exists();
    }

    // Called inside the receiver transaction, so receipt and economic record commit together.
    public function record(object $event, ?User $actor = null): object
    {
        return DB::transaction(function () use ($event, $actor): object {
            $this->lock($event);
            $latest = $this->operation($event)->orderByDesc('occurred_at')->orderByDesc('received_at')->orderByDesc('id')->firstOrFail();
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
            $valid = $this->eligible($latest);
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
                    ->whereExists(function (Builder $q): void {
                        $q->selectRaw('1')->from('commission_rules as r')->where('r.status', 'active')
                            ->whereColumn('r.partner_id', 'commercial_events.partner_id')->whereColumn('r.event_type', 'commercial_events.event_type')
                            ->whereColumn('r.effective_at', '<=', 'commercial_events.occurred_at');
                    })->orderBy('occurred_at')->orderBy('received_at')->orderBy('id')->first();
                if ($valuation) {
                    $rule = DB::table('commission_rules')->where('status', 'active')->where('partner_id', $valuation->partner_id)
                        ->where('event_type', $valuation->event_type)->where('effective_at', '<=', $valuation->occurred_at)
                        ->orderByDesc('effective_at')->orderByDesc('id')->sharedLock()->first();
                    if ($rule) $changes += ['rule_id' => $rule->id, 'commission_event_id' => $valuation->id,
                        'amount' => $rule->fixed_amount, 'currency' => $rule->currency, 'rule_snapshot' => json_encode($rule, JSON_THROW_ON_ERROR)];
                }
            }
            return $this->save($entry, $changes, 'evaluated', $actor, 'Evaluación de elegibilidad y regla; aprobación y conciliación pendientes.');
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
            $this->lock($event);
            $entry = DB::table('commission_ledger')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($entry->version !== (int) $data['version']) throw ValidationException::withMessages(['review' => 'El registro cambió. Recarga antes de decidir.']);
            $entry = $this->record($event, $actor);
            $action = $data['action'];
            if ($action === 'approve' && $entry->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['review' => 'La valoración cambió. Evalúa y recarga antes de aprobar.']);
            }
            if ($entry->status === 'discarded') throw ValidationException::withMessages(['review' => 'La comisión descartada es definitiva.']);
            $changes = [];
            if ($action === 'evaluate') {
                if ($entry->eligible) $changes['review_required'] = false;
            } elseif ($action === 'reconcile') {
                if ($entry->status === 'approved') throw ValidationException::withMessages(['review' => 'Descarta primero una aprobación si la conciliación cambió.']);
                $changes = ['reconciliation' => $data['reconciliation'], 'external_reference' => $data['external_reference'],
                    'reconciliation_note' => $data['reason'], 'reconciled_by' => $actor->uuid, 'reconciled_at' => now()];
                if ($data['reconciliation'] === 'remunerated_by_com') $changes['status'] = 'discarded';
            } elseif ($action === 'approve') {
                $rule = $entry->rule_id ? DB::table('commission_rules')->where('id', $entry->rule_id)->sharedLock()->first() : null;
                if ($entry->status !== 'candidate' || ! $entry->eligible || $entry->review_required || ! $rule || $rule->status !== 'active' || Carbon::parse($rule->effective_at)->isFuture()
                    || $entry->reconciliation !== 'club_exclusive' || ! $entry->external_reference) {
                    throw ValidationException::withMessages(['review' => 'Exige candidatura vigente, regla activa y exclusividad CLUB acreditada; revisa las discrepancias.']);
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
        return ['pending' => (clone $q)->where('status', 'candidate')->count(),
            'approved' => (clone $q)->where('status', 'approved')->count(),
            'review' => (clone $q)->where('review_required', true)->where('status', '<>', 'discarded')->count(),
            'currencies' => (clone $q)->whereNotNull('currency')->groupBy('currency')->orderBy('currency')
                ->selectRaw("currency, coalesce(sum(amount) filter (where status = 'candidate'),0)::text as pending, coalesce(sum(amount) filter (where status = 'approved' and not review_required),0)::text as approved, coalesce(sum(amount) filter (where status = 'approved' and review_required),0)::text as review")
                ->get()->all()];
    }
}

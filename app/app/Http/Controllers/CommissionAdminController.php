<?php

namespace App\Http\Controllers;

use App\Services\Commissions\CommissionEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CommissionAdminController extends Controller
{
    public function rules(): Response
    {
        $rules = DB::table('commission_rules')->orderByDesc('id')->paginate(25);
        $audit = DB::table('commission_audit')->whereIn('rule_id', $rules->getCollection()->pluck('id'))->orderBy('id')
            ->get(['rule_id','action','actor_uuid','reason','created_at','before','after'])->groupBy('rule_id');
        $rules->through(function (object $rule) use ($audit): object {
            $rule->history = $audit->get($rule->id, collect())->all();
            return $rule;
        });
        return Inertia::render('admin/CommissionRules', ['rules' => $rules, 'economic_cutoff' => config('commissions.economic_cutoff'), 'acquisition_contract_ready' => true]);
    }

    public function storeRule(Request $request, CommissionEngine $engine): RedirectResponse
    {
        $data = $request->validate([
            'beneficiary_type' => ['sometimes', Rule::in(['INDIVIDUAL','PARTNER'])],
            'membership_status' => ['sometimes', Rule::in(['ANY','FREE','PAID'])],
            'nature' => ['sometimes', Rule::in(['LEGACY','ONE_TIME_ACQUISITION'])],
            'partner_id' => ['required', 'string', 'max:190', 'regex:/^\S(?:[^\x00-\x1F\x7F]*\S)?$/uD'],
            'event_type' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9._-]*$/D'],
            'fixed_amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,4})?$/D', 'not_regex:/^0(?:\.0+)?$/D'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/D'],
            'effective_at' => ['required', 'string', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'],
        ]);
        $data['beneficiary_type'] ??= 'INDIVIDUAL';
        $data['membership_status'] ??= 'ANY';
        $data['nature'] ??= 'LEGACY';
        if ($data['nature'] === 'ONE_TIME_ACQUISITION' && $data['event_type'] !== 'membership.activated') {
            throw ValidationException::withMessages(['event_type' => 'Adquisición única exige membership.activated.']);
        }
        if ($data['event_type'] === 'membership.activated' && ($data['nature'] !== 'ONE_TIME_ACQUISITION'
            || $data['currency'] !== 'BOB' || ($data['beneficiary_type'] === 'INDIVIDUAL' ? !in_array($data['membership_status'], ['FREE','PAID'], true) : $data['membership_status'] !== 'ANY')
            || Carbon::parse($data['effective_at'])->isPast())) {
            throw ValidationException::withMessages(['event_type' => 'Membresía exige adquisición única, BOB, categoría FREE/PAID individual (ANY Partner) y vigencia futura.']);
        }
        DB::transaction(function () use ($request, $engine, $data): void {
            $parts = explode('.', $data['fixed_amount']);
            $id = DB::table('commission_rules')->insertGetId([
                ...$data, 'rule_version' => $data['nature'] === 'ONE_TIME_ACQUISITION' ? 'ACQ-'.(string)\Illuminate\Support\Str::uuid() : null, 'fixed_amount' => $parts[0].'.'.str_pad($parts[1] ?? '', 4, '0'),
                'effective_at' => Carbon::parse($data['effective_at'])->utc()->format('Y-m-d H:i:s.uP'),
                'status' => 'draft', 'created_by' => $request->user()->uuid, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $engine->audit(null, DB::table('commission_rules')->find($id), 'rule_created', $request->user(), 'Nueva versión en borrador; sin activación automática.', true);
        });
        return back()->with('status', 'Regla guardada como borrador. Actívala expresamente cuando esté aprobada.');
    }

    public function ruleStatus(Request $request, int $rule, CommissionEngine $engine): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])], 'reason' => ['required', 'string', 'min:5', 'max:2000']]);
        DB::transaction(function () use ($request, $rule, $data, $engine): void {
            DB::select("select pg_advisory_xact_lock(hashtextextended('commission_rule_status', 0))");
            $before = DB::table('commission_rules')->where('id', $rule)->lockForUpdate()->first();
            abort_unless($before, 404);
            if ($before->status === $data['status']) return;
            if ($data['status'] === 'active' && $before->nature === 'ONE_TIME_ACQUISITION' && Carbon::parse($before->effective_at)->isPast()) {
                throw ValidationException::withMessages(['rule' => 'La adquisición exige una nueva versión con vigencia futura; no se permite activación retroactiva.']);
            }
            if ($data['status'] === 'active' && DB::table('commission_rules')->where('id', '<>', $rule)->where('status', 'active')
                ->where('membership_status',$before->membership_status)->where('nature',$before->nature)->where('beneficiary_type',$before->beneficiary_type)->where('partner_id', $before->partner_id)->where('event_type', $before->event_type)->where('effective_at', $before->effective_at)->exists()) {
                throw ValidationException::withMessages(['rule' => 'Ya existe una versión activa para este partner, evento y fecha.']);
            }
            DB::table('commission_rules')->where('id', $rule)->update(['status' => $data['status'], 'updated_at' => now()]);
            $engine->audit($before, DB::table('commission_rules')->find($rule), 'rule_'.$data['status'], $request->user(), $data['reason'], true);
        }, 3);
        return back()->with('status', 'Estado de la regla actualizado. Las valoraciones anteriores se conservan.');
    }

    public function ledger(Request $request, CommissionEngine $engine): Response
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(['registered','candidate','approved','discarded','evidence','review','pending_payment'])]]);
        $entries = DB::table('commission_ledger as l')->join('commercial_events as e', 'e.id', '=', 'l.original_event_id')
            ->when($filters['status'] ?? null, function ($q, $v): void {
                if (in_array($v, ['approved', 'pending_payment'], true)) $q->where('l.status', 'approved')->where('l.review_required', false);
                elseif ($v === 'review') $q->where('l.review_required', true)->where('l.status', '<>', 'discarded');
                elseif ($v === 'evidence') $q->whereIn('l.status', ['registered', 'candidate'])->where('l.review_required', false);
                else $q->where('l.status', $v);
            })
            ->orderByDesc('l.id')->paginate(25, ['l.*','e.event_id','e.partner_id','e.partner_name','e.event_type'])->withQueryString();
        $ids = $entries->getCollection()->pluck('id');
        $audit = DB::table('commission_audit')->whereIn('ledger_id', $ids)->orderBy('id')
            ->get(['ledger_id','action','actor_uuid','reason','created_at','before','after'])->groupBy('ledger_id');
        $entries->through(function (object $entry) use ($audit, $engine): object {
            $entry->beneficiary_name = $entry->beneficiary_type === 'PARTNER'
                ? DB::table('affiliate_partners')->where('partner_id', $entry->beneficiary_id)->value('name')
                : DB::table('users')->where('uuid', $entry->affiliate_uuid)->value('name');
            $confirmation = $engine->firstConfirmation($entry);
            $entry->economic_occurred_at = $confirmation ? $engine->occurredAt($confirmation)->format('Y-m-d\TH:i:s.uP') : null;
            $entry->after_cutoff = $confirmation !== null && $engine->occurredAt($confirmation)->gt(Carbon::parse(config('commissions.economic_cutoff')));
            $entry->rule_active = $entry->rule_id !== null && DB::table('commission_rules')->where('id', $entry->rule_id)->where('status', 'active')->exists();
            $entry->history = $audit->get($entry->id, collect())->all();
            return $entry;
        });
        $evidence = DB::table('acquisition_evidence as a')->whereIn('a.id', DB::table('acquisition_evidence')->selectRaw('max(id)')->groupBy('event_id'))
            ->whereIn('a.status', ['pending', 'invalid', 'verified'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('commission_ledger as l')->whereColumn('l.original_event_id', 'a.event_id'))
            ->when(in_array($filters['status'] ?? '', ['approved', 'pending_payment', 'discarded'], true), fn ($q) => $q->whereRaw('false'))
            ->when(($filters['status'] ?? '') === 'review', fn ($q) => $q->whereRaw("(a.status = 'invalid' OR a.result->>'first_acquisition' = 'unknown' OR a.result->>'membership_status' = 'UNKNOWN')"))
            ->when(($filters['status'] ?? '') === 'evidence', fn ($q) => $q->whereRaw("a.status = 'pending' AND coalesce(a.result->>'first_acquisition', '') <> 'unknown' AND coalesce(a.result->>'membership_status', '') <> 'UNKNOWN'"))
            ->orderByDesc('a.id')->limit(50)->get(['a.id','a.event_id','a.purchase_reference','a.status','a.reason','a.created_at'])->map(function (object $item): object {
                $result = json_decode(DB::table('acquisition_evidence')->where('id', $item->id)->value('result') ?? '{}', true);
                $item->requires_review = $item->status === 'invalid' || ($result['first_acquisition'] ?? null) === 'unknown' || ($result['membership_status'] ?? null) === 'UNKNOWN';
                if ($item->status === 'verified' && !DB::table('commission_ledger')->where('original_event_id', $item->event_id)->exists()) {
                    $item->reason = 'Evidencia COM válida; sin tarifa aplicable por fecha original o elegibilidad CLUB. No se emitió comisión.';
                }
                return $item;
            });
        return Inertia::render('admin/CommissionLedger', ['evidence' => $evidence, 'entries' => $entries, 'filters' => $filters, 'economic_cutoff' => config('commissions.economic_cutoff')]);
    }

    public function retryEvidence(int $evidence, CommissionEngine $engine): RedirectResponse
    {
        $item = DB::table('acquisition_evidence')->find($evidence);
        abort_unless($item, 404);
        $activation = DB::table('acquisition_activations')->where('event_id', $item->event_id)->firstOrFail();
        $person = DB::table('acquisition_people')->find($activation->person_id);
        $engine->recordMembership($person, $activation);
        return back()->with('status', 'Evaluación solicitada; las consultas repetidas se limitan a una cada cinco minutos por identidad. Sin pagos automáticos.');
    }

    public function review(Request $request, int $entry, CommissionEngine $engine): RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required','integer','min:1'],
            'action' => ['required', Rule::in(['evaluate','approve','discard'])],
            'reason' => ['required','string','min:5','max:2000'],
        ]);
        $engine->review($entry, $data, $request->user());
        return back()->with('status', 'Decisión registrada en el historial económico. No se ha realizado ningún pago.');
    }
}

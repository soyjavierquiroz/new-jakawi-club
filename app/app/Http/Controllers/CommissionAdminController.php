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
        return Inertia::render('admin/CommissionRules', ['rules' => $rules]);
    }

    public function storeRule(Request $request, CommissionEngine $engine): RedirectResponse
    {
        $data = $request->validate([
            'partner_id' => ['required', 'string', 'max:190', 'regex:/^\S(?:[^\x00-\x1F\x7F]*\S)?$/uD'],
            'event_type' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9._-]*$/D'],
            'fixed_amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,4})?$/D', 'not_regex:/^0(?:\.0+)?$/D'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/D'],
            'effective_at' => ['required', 'string', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'],
        ]);
        DB::transaction(function () use ($request, $engine, $data): void {
            $parts = explode('.', $data['fixed_amount']);
            $id = DB::table('commission_rules')->insertGetId([
                ...$data, 'fixed_amount' => $parts[0].'.'.str_pad($parts[1] ?? '', 4, '0'),
                'effective_at' => Carbon::parse($data['effective_at'])->utc(),
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
            if ($data['status'] === 'active' && DB::table('commission_rules')->where('id', '<>', $rule)->where('status', 'active')
                ->where('partner_id', $before->partner_id)->where('event_type', $before->event_type)->where('effective_at', $before->effective_at)->exists()) {
                throw ValidationException::withMessages(['rule' => 'Ya existe una versión activa para este partner, evento y fecha.']);
            }
            DB::table('commission_rules')->where('id', $rule)->update(['status' => $data['status'], 'updated_at' => now()]);
            $engine->audit($before, DB::table('commission_rules')->find($rule), 'rule_'.$data['status'], $request->user(), $data['reason'], true);
        }, 3);
        return back()->with('status', 'Estado de la regla actualizado. Las valoraciones anteriores se conservan.');
    }

    public function ledger(Request $request): Response
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(['registered','candidate','approved','discarded'])],
            'reconciliation' => ['nullable', Rule::in(['pending','club_exclusive','remunerated_by_com'])]]);
        $entries = DB::table('commission_ledger as l')->join('commercial_events as e', 'e.id', '=', 'l.original_event_id')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('l.status', $v))
            ->when($filters['reconciliation'] ?? null, fn ($q, $v) => $q->where('l.reconciliation', $v))
            ->orderByDesc('l.id')->paginate(25, ['l.*','e.event_id','e.partner_id','e.partner_name','e.event_type'])->withQueryString();
        $ids = $entries->getCollection()->pluck('id');
        $audit = DB::table('commission_audit')->whereIn('ledger_id', $ids)->orderBy('id')
            ->get(['ledger_id','action','actor_uuid','reason','created_at','before','after'])->groupBy('ledger_id');
        $entries->through(function (object $entry) use ($audit): object {
            $entry->history = $audit->get($entry->id, collect())->all();
            return $entry;
        });
        return Inertia::render('admin/CommissionLedger', ['entries' => $entries, 'filters' => $filters]);
    }

    public function review(Request $request, int $entry, CommissionEngine $engine): RedirectResponse
    {
        $data = $request->validate([
            'version' => ['required','integer','min:1'],
            'action' => ['required', Rule::in(['evaluate','reconcile','approve','discard'])],
            'reason' => ['required','string','min:5','max:2000'],
            'reconciliation' => ['required_if:action,reconcile', Rule::in(['club_exclusive','remunerated_by_com'])],
            'external_reference' => ['required_if:action,reconcile','nullable','string','max:190','regex:/^\S(?:[^\x00-\x1F\x7F]*\S)?$/uD'],
        ]);
        $engine->review($entry, $data, $request->user());
        return back()->with('status', 'Decisión registrada en el historial económico. No se ha realizado ningún pago.');
    }
}

<?php

namespace App\Services\Commissions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AcquisitionRates
{
    public const VERSION = 'PRELAUNCH-10E-v1';

    public function publish(User $actor): array
    {
        return DB::transaction(function () use ($actor): array {
            DB::select("select pg_advisory_xact_lock(hashtextextended('commission_rule_status', 0))");
            $existing = DB::table('commission_rules')->where('rule_version', self::VERSION)->get();
            if ($existing->isNotEmpty()) {
                if ($existing->count() !== 3) throw new \RuntimeException('Incomplete acquisition rate publication');
                return $existing->all();
            }
            $effective = now()->utc()->format('Y-m-d H:i:s.uP');
            foreach ([['INDIVIDUAL','FREE','15.0000'],['INDIVIDUAL','PAID','25.0000'],['PARTNER','ANY','30.0000']] as [$type,$state,$amount]) {
                $id = DB::table('commission_rules')->insertGetId([
                    'partner_id'=>'*','beneficiary_type'=>$type,'membership_status'=>$state,
                    'nature'=>'ONE_TIME_ACQUISITION','rule_version'=>self::VERSION,
                    'event_type'=>'membership.activated','fixed_amount'=>$amount,'currency'=>'BOB',
                    'effective_at'=>$effective,'status'=>'active','created_by'=>$actor->uuid,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
                app(CommissionEngine::class)->audit(null,DB::table('commission_rules')->find($id),'rule_active',$actor,
                    'Decisión comercial del propietario Fase 10E: tarifa fija BOB de prelanzamiento, primera adquisición; sin retroactividad ni pagos automáticos. Emisión requiere evidencia COM.',true);
            }
            return DB::table('commission_rules')->where('rule_version',self::VERSION)->get()->all();
        }, 3);
    }
}

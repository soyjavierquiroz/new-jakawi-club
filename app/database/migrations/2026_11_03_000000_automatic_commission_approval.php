<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('commission_ledger', fn (Blueprint $t) => $t->string('approval_mode', 16)->default('manual'));
        DB::statement("ALTER TABLE commission_ledger DROP CONSTRAINT commission_manual_approval,
            ADD CONSTRAINT commission_approval_mode CHECK (approval_mode IN ('manual','automatic')),
            ADD CONSTRAINT commission_approval CHECK (status <> 'approved' OR (
                rule_id IS NOT NULL AND commission_event_id IS NOT NULL
                AND ((affiliate_uuid IS NOT NULL AND affiliate_code IS NOT NULL) OR (beneficiary_type = 'PARTNER' AND beneficiary_id IS NOT NULL))
                AND amount > 0 AND currency IS NOT NULL AND approved_at IS NOT NULL
                AND ((approval_mode = 'manual' AND approved_by IS NOT NULL)
                    OR (approval_mode = 'automatic' AND approved_by IS NULL AND acquisition_person_id IS NOT NULL))
            ))");
    }
    public function down(): void { throw new RuntimeException('Preserve approval audit; roll back compatible code only.'); }
};

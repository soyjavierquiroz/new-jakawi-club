<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('commission_rules', function (Blueprint $t): void {
            $t->string('membership_status', 16)->default('ANY');
            $t->string('nature', 32)->default('LEGACY');
            $t->string('rule_version', 64)->nullable();
        });
        DB::statement('DROP INDEX commission_rule_active_version');
        DB::statement("CREATE UNIQUE INDEX commission_rule_active_version ON commission_rules(partner_id,event_type,effective_at,beneficiary_type,membership_status,nature) WHERE status = 'active'");
        DB::statement("ALTER TABLE commission_rules ADD CHECK (membership_status IN ('ANY','FREE','PAID')), ADD CHECK (nature IN ('LEGACY','ONE_TIME_ACQUISITION')), ADD CHECK (nature <> 'ONE_TIME_ACQUISITION' OR (event_type = 'membership.activated' AND currency = 'BOB' AND ((beneficiary_type = 'PARTNER' AND membership_status = 'ANY') OR (beneficiary_type = 'INDIVIDUAL' AND membership_status IN ('FREE','PAID')))))");
        // The existing immutable JSON snapshot holds evidence alongside the exact rule.
        // No existing ledger, rule or audit row is rewritten.
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve economic history; roll back application code only.');
    }
};

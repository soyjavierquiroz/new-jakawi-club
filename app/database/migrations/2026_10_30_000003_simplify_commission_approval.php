<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Schema cleanup only: no imports, repricing, reconciliation or data backfill.
        // Dropping these columns also removes the obsolete checks and filter index.
        DB::statement('ALTER TABLE commission_ledger
            DROP COLUMN reconciliation,
            DROP COLUMN external_reference,
            DROP COLUMN reconciliation_note,
            DROP COLUMN reconciled_by,
            DROP COLUMN reconciled_at,
            ADD CONSTRAINT commission_manual_approval CHECK (
                status <> \'approved\' OR (
                    rule_id IS NOT NULL AND commission_event_id IS NOT NULL
                    AND affiliate_uuid IS NOT NULL AND affiliate_code IS NOT NULL
                    AND amount IS NOT NULL AND amount > 0 AND currency IS NOT NULL
                    AND approved_by IS NOT NULL AND approved_at IS NOT NULL
                )
            )');
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve the economic ledger and approval history. Deploy a compatible application release.');
    }
};

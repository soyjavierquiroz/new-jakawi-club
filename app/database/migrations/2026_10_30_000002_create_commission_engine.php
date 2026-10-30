<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('partner_id', 190);
            $table->string('event_type', 100);
            $table->decimal('fixed_amount', 20, 4);
            $table->string('currency', 3);
            $table->timestampTz('effective_at', 6);
            $table->string('status', 16)->default('draft');
            $table->uuid('created_by');
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
        });
        DB::statement("ALTER TABLE commission_rules ADD CHECK (fixed_amount > 0 AND fixed_amount < 1000000000000), ADD CHECK (currency ~ '^[A-Z]{3}$'), ADD CHECK (status IN ('draft','active','inactive'))");
        DB::statement("CREATE UNIQUE INDEX commission_rule_active_version ON commission_rules (partner_id,event_type,effective_at) WHERE status = 'active'");
        Schema::create('commission_ledger', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 32);
            $table->string('com_reference_type', 64);
            $table->string('com_reference_id', 190);
            $table->foreignId('original_event_id')->constrained('commercial_events')->restrictOnDelete();
            $table->foreignId('latest_event_id')->constrained('commercial_events')->restrictOnDelete();
            $table->foreignId('commission_event_id')->nullable()->constrained('commercial_events')->restrictOnDelete();
            $table->uuid('affiliate_uuid')->nullable()->index();
            $table->string('affiliate_code', 12)->nullable();
            $table->foreignId('rule_id')->nullable()->constrained('commission_rules')->restrictOnDelete();
            $table->jsonb('rule_snapshot')->nullable();
            $table->decimal('amount', 20, 4)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('status', 16)->default('registered');
            $table->boolean('eligible')->default(false);
            $table->boolean('review_required')->default(false);
            $table->string('reconciliation', 24)->default('pending');
            $table->string('external_reference', 190)->nullable();
            $table->text('reconciliation_note')->nullable();
            $table->uuid('reconciled_by')->nullable();
            $table->timestampTz('reconciled_at', 6)->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at', 6)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
            $table->unique(['source','com_reference_type','com_reference_id'], 'commission_operation_unique');
            $table->unique('original_event_id');
            $table->unique('commission_event_id');
            $table->index(['status','reconciliation']);
        });
        DB::statement("ALTER TABLE commission_ledger ADD CHECK (status IN ('registered','candidate','approved','discarded')), ADD CHECK (reconciliation IN ('pending','club_exclusive','remunerated_by_com')), ADD CHECK (num_nonnulls(rule_id,commission_event_id,amount,currency,rule_snapshot) = 0 OR (num_nonnulls(rule_id,commission_event_id,amount,currency,rule_snapshot) = 5 AND amount > 0)), ADD CHECK ((affiliate_uuid IS NULL) = (affiliate_code IS NULL)), ADD CHECK (status <> 'approved' OR (rule_id IS NOT NULL AND reconciliation = 'club_exclusive' AND external_reference IS NOT NULL AND approved_by IS NOT NULL AND approved_at IS NOT NULL)), ADD CHECK (reconciliation = 'pending' OR (external_reference IS NOT NULL AND reconciliation_note IS NOT NULL AND reconciled_by IS NOT NULL AND reconciled_at IS NOT NULL))");
        Schema::create('commission_audit', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ledger_id')->nullable()->constrained('commission_ledger')->restrictOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('commission_rules')->restrictOnDelete();
            $table->string('action', 40);
            $table->uuid('actor_uuid')->nullable();
            $table->text('reason');
            $table->jsonb('before')->nullable();
            $table->jsonb('after');
            $table->timestampTz('created_at', 6);
        });
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION protect_commission_history() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Commission history cannot be deleted or rewritten';
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER commission_audit_append_only BEFORE UPDATE OR DELETE ON commission_audit FOR EACH ROW EXECUTE FUNCTION protect_commission_history();
            CREATE TRIGGER commission_ledger_no_delete BEFORE DELETE ON commission_ledger FOR EACH ROW EXECUTE FUNCTION protect_commission_history();
            CREATE TRIGGER commission_rules_no_delete BEFORE DELETE ON commission_rules FOR EACH ROW EXECUTE FUNCTION protect_commission_history();
            CREATE FUNCTION protect_commission_snapshots() RETURNS trigger AS $$
            BEGIN
                IF TG_TABLE_NAME = 'commission_rules' THEN
                    IF (to_jsonb(NEW) - 'status' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'updated_at') THEN
                        RAISE EXCEPTION 'Create a new commission rule version instead';
                    END IF;
                ELSE
                    IF ROW(NEW.source,NEW.com_reference_type,NEW.com_reference_id,NEW.original_event_id) IS DISTINCT FROM ROW(OLD.source,OLD.com_reference_type,OLD.com_reference_id,OLD.original_event_id)
                    OR (OLD.affiliate_uuid IS NOT NULL AND ROW(NEW.affiliate_uuid,NEW.affiliate_code) IS DISTINCT FROM ROW(OLD.affiliate_uuid,OLD.affiliate_code))
                    OR (OLD.rule_id IS NOT NULL AND ROW(NEW.rule_id,NEW.commission_event_id,NEW.amount,NEW.currency,NEW.rule_snapshot) IS DISTINCT FROM ROW(OLD.rule_id,OLD.commission_event_id,OLD.amount,OLD.currency,OLD.rule_snapshot)) THEN
                        RAISE EXCEPTION 'Commission identity and valuation snapshots are permanent';
                    END IF;
                    IF OLD.status = 'discarded' AND NEW.status <> 'discarded' THEN
                        RAISE EXCEPTION 'Discarded commissions cannot be reopened';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER commission_rules_immutable BEFORE UPDATE ON commission_rules FOR EACH ROW EXECUTE FUNCTION protect_commission_snapshots();
            CREATE TRIGGER commission_ledger_immutable BEFORE UPDATE ON commission_ledger FOR EACH ROW EXECUTE FUNCTION protect_commission_snapshots();
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve the economic ledger and audit history; roll back application code only.');
    }
};

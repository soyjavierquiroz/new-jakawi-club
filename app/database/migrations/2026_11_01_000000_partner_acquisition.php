<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void {
  Schema::create('affiliate_partners', function(Blueprint $t): void {
   $t->id(); $t->string('source',32)->default('jakawi.com'); $t->string('partner_id',190); $t->string('name',190); $t->string('status',16)->default('disabled'); $t->string('com_url',2048); $t->string('code',32)->unique(); $t->timestampsTz(); $t->unique(['source','partner_id']);
  });
  Schema::create('affiliate_partner_access', function(Blueprint $t): void {
   $t->id(); $t->foreignId('partner')->constrained('affiliate_partners')->restrictOnDelete(); $t->foreignId('user_id')->constrained()->restrictOnDelete(); $t->uuid('authorized_by'); $t->timestampTz('authorized_at'); $t->unique(['partner','user_id']);
  });
  Schema::create('acquisition_people', function(Blueprint $t): void {
   $t->uuid('id')->primary(); $t->string('beneficiary_type',16)->nullable(); $t->string('beneficiary_id',190)->nullable(); $t->string('referral_code',12)->nullable(); $t->timestampTz('fixed_at')->nullable(); $t->timestampTz('created_at');
  });
  Schema::create('acquisition_aliases', function(Blueprint $t): void {
   $t->id(); $t->string('kind',16); $t->string('reference',190); $t->uuid('person_id'); $t->foreign('person_id')->references('id')->on('acquisition_people')->restrictOnDelete(); $t->unique(['kind','reference']);
  });
  Schema::create('acquisition_facts', function(Blueprint $t): void {
   $t->id(); $t->foreignId('event_id')->unique()->constrained('commercial_events')->restrictOnDelete(); $t->string('payment_reference',190)->nullable()->index(); $t->uuid('club_reference')->nullable()->index(); $t->uuid('club_user_uuid')->nullable()->index(); $t->uuid('global_subject_id')->nullable()->index(); $t->jsonb('acquisition')->nullable();
  });
  Schema::create('acquisition_activations', function(Blueprint $t): void {
   $t->id(); $t->string('membership_reference',190)->unique(); $t->uuid('person_id')->index(); $t->foreign('person_id')->references('id')->on('acquisition_people')->restrictOnDelete(); $t->foreignId('event_id')->constrained('commercial_events')->restrictOnDelete(); $t->timestampTz('activated_at');
  });
  Schema::table('commission_rules', fn(Blueprint $t) => $t->string('beneficiary_type',16)->default('INDIVIDUAL'));
  Schema::table('commission_ledger', function(Blueprint $t): void {
   $t->string('beneficiary_type',16)->default('INDIVIDUAL'); $t->string('beneficiary_id',190)->nullable(); $t->uuid('acquisition_person_id')->nullable()->unique();
  });
  DB::statement('DROP INDEX commission_rule_active_version');
  DB::statement("CREATE UNIQUE INDEX commission_rule_active_version ON commission_rules(partner_id,event_type,effective_at,beneficiary_type) WHERE status = 'active'");
  DB::statement('ALTER TABLE commission_ledger DROP CONSTRAINT commission_manual_approval');
  DB::statement("ALTER TABLE commission_ledger ADD CONSTRAINT commission_manual_approval CHECK(status <> 'approved' OR (rule_id IS NOT NULL AND commission_event_id IS NOT NULL AND ((affiliate_uuid IS NOT NULL AND affiliate_code IS NOT NULL) OR (beneficiary_type = 'PARTNER' AND beneficiary_id IS NOT NULL)) AND amount > 0 AND currency IS NOT NULL AND approved_by IS NOT NULL AND approved_at IS NOT NULL))");
  DB::statement("ALTER TABLE affiliate_partners ADD CHECK(status IN ('enabled','disabled')), ADD CHECK(source = 'jakawi.com')");
  DB::statement("ALTER TABLE commission_rules ADD CHECK(beneficiary_type IN ('INDIVIDUAL','PARTNER'))");
  DB::unprepared(<<<'SQL'
CREATE FUNCTION protect_acquisition_beneficiary() RETURNS trigger AS $$ BEGIN
 IF OLD.beneficiary_id IS NOT NULL AND ROW(NEW.beneficiary_type,NEW.beneficiary_id,NEW.referral_code,NEW.fixed_at) IS DISTINCT FROM ROW(OLD.beneficiary_type,OLD.beneficiary_id,OLD.referral_code,OLD.fixed_at) THEN RAISE EXCEPTION 'Original beneficiary is permanent'; END IF;
 RETURN NEW; END; $$ LANGUAGE plpgsql;
CREATE FUNCTION protect_membership_ledger_beneficiary() RETURNS trigger AS $$ BEGIN
 IF ROW(NEW.beneficiary_type,NEW.beneficiary_id,NEW.acquisition_person_id) IS DISTINCT FROM ROW(OLD.beneficiary_type,OLD.beneficiary_id,OLD.acquisition_person_id) THEN RAISE EXCEPTION 'Economic beneficiary snapshot is permanent'; END IF;
 RETURN NEW; END; $$ LANGUAGE plpgsql;
CREATE TRIGGER ledger_acquisition_original BEFORE UPDATE ON commission_ledger FOR EACH ROW EXECUTE FUNCTION protect_membership_ledger_beneficiary();
CREATE TRIGGER acquisition_original BEFORE UPDATE ON acquisition_people FOR EACH ROW EXECUTE FUNCTION protect_acquisition_beneficiary();

SQL);
 }
 public function down(): void { throw new RuntimeException('Preserve attribution and economic history; roll back code only.'); }
};

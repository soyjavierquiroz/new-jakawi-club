<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('ad_visits', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->string('provider', 10); $t->text('context');
            $t->timestampTz('consented_at'); $t->timestampTz('revoked_at')->nullable(); $t->timestampTz('expires_at')->index();
        });
        Schema::table('membership_purchases', function (Blueprint $t): void { $t->uuid('ad_visit_id')->nullable()->index(); });
        Schema::create('ad_events', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->uuid('visit_id')->index(); $t->uuid('club_reference')->index();
            $t->string('provider', 10); $t->string('event_name', 40); $t->timestampTz('occurred_at');
            $t->text('payload'); $t->string('status', 20)->default('received')->index();
            $t->unsignedInteger('attempts')->default(0); $t->string('response_code', 40)->nullable();
            $t->timestampTz('delivered_at')->nullable(); $t->timestampTz('expires_at')->index();
            $t->unique(['provider', 'club_reference', 'event_name']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('ad_events'); Schema::table('membership_purchases', fn (Blueprint $t) => $t->dropColumn('ad_visit_id')); Schema::dropIfExists('ad_visits');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_events', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 32);
            $table->string('event_id', 128);
            $table->unsignedSmallInteger('schema_version');
            $table->string('event_type', 100);
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('received_at', 6);
            $table->string('com_reference_type', 64);
            $table->string('com_reference_id', 190);
            $table->string('partner_id', 190)->nullable()->index();
            $table->string('partner_name', 190)->nullable();
            $table->text('landing')->nullable();
            $table->string('campaign', 190)->nullable()->index();
            $table->string('com_referral_id', 190)->nullable();
            $table->string('club_referral_code', 12)->nullable();
            // Historical verification snapshot; never creates or updates a user.
            $table->uuid('referrer_club_uuid')->nullable();
            $table->uuid('global_subject_id')->nullable();
            $table->string('conversion_status', 64);
            $table->string('key_id', 64);
            $table->bigInteger('signed_at');
            $table->char('payload_sha256', 64);
            $table->char('raw_body_sha256', 64);
            $table->text('raw_body');
            $table->unique(['source', 'event_id']);
            $table->index(['occurred_at', 'id']);
        });
        Schema::create('commercial_event_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commercial_event_id')->constrained('commercial_events')->restrictOnDelete();
            $table->char('payload_sha256', 64);
            $table->char('raw_body_sha256', 64);
            $table->text('raw_body');
            $table->string('key_id', 64);
            $table->bigInteger('signed_at');
            $table->timestampTz('detected_at', 6);
            $table->unique(['commercial_event_id', 'payload_sha256'], 'commercial_conflict_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('commercial_events')->exists()) {
            throw new RuntimeException('Commercial provenance must be preserved; disable the route instead.');
        }
        Schema::dropIfExists('commercial_event_conflicts');
        Schema::dropIfExists('commercial_events');
    }
};

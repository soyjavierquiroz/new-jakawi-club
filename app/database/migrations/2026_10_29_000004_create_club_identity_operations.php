<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_identity_activations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->char('email_digest', 64);
            $table->timestampTz('activated_at');
            $table->unique(['user_id', 'email_digest']);
        });
        // Separate native broker: identity confirmation never replaces recovery tokens.
        Schema::create('club_identity_activation_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('club_identity_handoffs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('request_body');
            $table->string('status', 32)->default('QUEUED');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('proof_expires_at');
            $table->timestampTz('remote_expires_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->string('last_error', 48)->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'created_at']);
        });
        Schema::create('club_identity_links', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->restrictOnDelete();
            $table->uuid('global_subject_id')->unique();
            $table->string('status', 16);
            $table->string('method', 40);
            $table->timestampTz('authorized_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('checked_at');
        });
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION protect_club_global_subject() RETURNS trigger AS $$
            BEGIN
                IF OLD.global_subject_id IS NOT NULL AND NEW.global_subject_id IS DISTINCT FROM OLD.global_subject_id THEN
                    RAISE EXCEPTION 'COM global subject is permanent';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER users_global_subject_permanent BEFORE UPDATE ON users
            FOR EACH ROW EXECUTE FUNCTION protect_club_global_subject();
            SQL);
    }

    public function down(): void
    {
        // Retain issued mappings and consent evidence across code rollbacks.
        throw new RuntimeException('Identity evidence must be retained. Roll back code without dropping identity tables.');
    }
};

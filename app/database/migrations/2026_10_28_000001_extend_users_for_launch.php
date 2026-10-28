<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Abort atomically instead of merging or deleting legacy accounts.
        if (DB::table('users')->selectRaw('lower(trim(email))')->groupByRaw('lower(trim(email))')
            ->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('Hay emails históricos que requieren normalización manual. No se alteraron cuentas.');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->unique();
            $table->string('referral_code', 16)->nullable()->unique();
            $table->string('phone', 16)->nullable();
            $table->string('city', 100)->nullable();
            $table->foreignId('referred_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('privacy_accepted_at')->nullable();
            $table->string('privacy_version', 20)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->string('password')->nullable()->change();
        });

        DB::table('users')->orderBy('id')->chunkById(100, function ($users): void {
            foreach ($users as $user) {
                do {
                    $code = strtoupper(Str::random(12));
                } while (DB::table('users')->where('referral_code', $code)->exists());
                DB::table('users')->where('id', $user->id)->update([
                    'uuid' => (string) Str::uuid(),
                    'referral_code' => $code,
                    'email' => mb_strtolower(trim($user->email)),
                    'activated_at' => $user->email_verified_at && $user->password ? now() : null,
                ]);
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable(false)->change();
            $table->string('referral_code', 16)->nullable(false)->change();
        });

        DB::statement('CREATE UNIQUE INDEX users_email_normalized_unique ON users (lower(trim(email)))');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_no_self_referral CHECK (referred_by_id IS NULL OR referred_by_id <> id)');
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION protect_jakawi_user_identity() RETURNS trigger AS $$
            BEGIN
                IF NEW.uuid IS DISTINCT FROM OLD.uuid OR NEW.referral_code IS DISTINCT FROM OLD.referral_code
                   OR NEW.referred_by_id IS DISTINCT FROM OLD.referred_by_id THEN
                    RAISE EXCEPTION 'Permanent JAKAWI identity and attribution cannot be changed';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER users_permanent_identity BEFORE UPDATE ON users
            FOR EACH ROW EXECUTE FUNCTION protect_jakawi_user_identity();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS users_permanent_identity ON users; DROP FUNCTION IF EXISTS protect_jakawi_user_identity();');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_no_self_referral');
        DB::statement('DROP INDEX IF EXISTS users_email_normalized_unique');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['referred_by_id']);
            $table->dropColumn(['uuid', 'referral_code', 'phone', 'city', 'referred_by_id', 'privacy_accepted_at', 'privacy_version', 'activated_at']);
        });
        // Keep nullable passwords: rollback must never invent credentials for pending accounts.
    }
};

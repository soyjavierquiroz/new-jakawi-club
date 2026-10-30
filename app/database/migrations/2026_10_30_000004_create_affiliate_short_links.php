<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_offers', function (Blueprint $table): void {
            $table->string('key', 80)->primary();
            $table->string('name', 190);
            $table->text('original_url');
            $table->text('destination_url');
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE commercial_offers ADD CONSTRAINT commercial_offers_status CHECK (status IN ('active', 'paused', 'finished'))");
        Schema::create('affiliate_short_links', function (Blueprint $table): void {
            $table->string('code', 8)->primary();
            $table->foreignId('affiliate_id')->constrained('users')->restrictOnDelete();
            $table->string('offer_key', 80);
            $table->foreign('offer_key')->references('key')->on('commercial_offers')->restrictOnDelete();
            $table->string('referral_code', 12);
            $table->timestampsTz();
            $table->unique(['affiliate_id', 'offer_key']);
        });
        DB::statement("ALTER TABLE affiliate_short_links ADD CONSTRAINT short_link_code_format CHECK (code ~ '^[A-Za-z0-9]{8}$')");
        DB::statement("ALTER TABLE affiliate_short_links ADD CONSTRAINT short_link_referral_format CHECK (referral_code ~ '^[A-Z0-9]{12}$')");
        // One real pilot, no catalog imports or affiliate identities in migrations.
        DB::table('commercial_offers')->insert([
            'key' => 'dossis-43', 'name' => 'Dossis 43 — Café y antojo',
            'original_url' => 'https://jakawi.com/l/dossis-43-cafe-y-antojo',
            'destination_url' => 'https://jakawi.com/l/dossis-43-cafe-y-antojo',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_short_links');
        Schema::dropIfExists('commercial_offers');
    }
};

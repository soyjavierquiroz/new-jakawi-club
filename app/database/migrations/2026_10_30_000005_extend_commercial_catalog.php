<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_offers', function (Blueprint $table): void {
            $table->string('source', 16)->default('com')->index();
            $table->string('source_id', 80)->nullable();
            $table->unique(['source', 'source_id']);
            $table->string('type', 24)->default('benefit')->index();
            $table->text('short_description')->nullable();
            $table->text('image_url')->nullable();
            $table->jsonb('partners')->default('[]');
            $table->jsonb('cities')->default('[]');
            $table->text('partner_search')->default('');
            $table->string('category', 120)->nullable();
            $table->string('publication_status', 32)->default('published');
            $table->string('landing_status', 32)->default('PUBLISHED');
            $table->string('availability_status', 32)->default('unavailable');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable()->index();
            $table->timestampTz('source_updated_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->boolean('catalog_present')->default(false)->index();
            $table->boolean('local_paused')->default(false);
        });
        // COM confirmed this stable identity; keep the existing PK and every FK.
        DB::table('commercial_offers')->where('key', 'dossis-43')->update(['source_id' => 'benefit:1']);
        DB::table('commercial_offers')->where('status', '!=', 'active')->update(['local_paused' => true]);
        Schema::create('catalog_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 16);
            $table->unsignedInteger('offers')->default(0);
            $table->unsignedInteger('pages')->default(0);
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at');
        });
    }

    public function down(): void
    {
        // Operational rollback uses the previous code; historical catalog is retained.
        throw new RuntimeException('Restore the previous application without dropping catalog history.');
    }
};

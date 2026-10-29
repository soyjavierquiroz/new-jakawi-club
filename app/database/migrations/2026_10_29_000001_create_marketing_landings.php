<?php

use App\Support\LandingContent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_marketing_admin')->default(false);
        });
        Schema::create('marketing_landings', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 120);
            $table->string('slug', 100)->unique();
            $table->string('template', 20)->default('LEAD_VSL');
            $table->jsonb('configuration');
            $table->jsonb('published_configuration')->nullable();
            $table->string('campaign', 80)->nullable();
            $table->string('published_campaign', 80)->nullable();
            $table->string('status', 16)->default('draft')->index();
            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('marketing_landing_event_totals', function (Blueprint $table): void {
            $table->foreignId('landing_id')->constrained('marketing_landings');
            $table->date('day');
            $table->string('event', 32);
            $table->string('placement', 16);
            $table->string('campaign', 80)->default('');
            $table->unsignedBigInteger('total')->default(0);
            $table->primary(['landing_id', 'day', 'event', 'placement', 'campaign'], 'landing_event_totals_pk');
        });
        $content = json_encode(LandingContent::defaults(), JSON_THROW_ON_ERROR);
        DB::table('marketing_landings')->insert([
            'title' => 'Landing principal JAKAWI', 'slug' => config('jakawi.home_landing_slug', 'principal'),
            'configuration' => $content, 'published_configuration' => $content,
            'status' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_landing_event_totals');
        Schema::dropIfExists('marketing_landings');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_marketing_admin'));
    }
};

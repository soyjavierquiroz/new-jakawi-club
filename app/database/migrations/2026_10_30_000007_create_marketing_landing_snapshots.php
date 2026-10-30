<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_landing_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('landing_id')->constrained('marketing_landings')->restrictOnDelete();
            $table->string('snapshot_hash', 64);
            $table->jsonb('configuration');
            $table->timestampTz('created_at');
            $table->unique(['landing_id', 'snapshot_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_landing_snapshots');
    }
};

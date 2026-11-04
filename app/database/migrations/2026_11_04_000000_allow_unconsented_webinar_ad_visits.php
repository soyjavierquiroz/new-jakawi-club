<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Route activation must not fabricate a consent timestamp.
        Schema::table('ad_visits', function (Blueprint $table): void {
            $table->timestampTz('consented_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Preserve null timestamps: rollback must never invent consent evidence.
    }
};

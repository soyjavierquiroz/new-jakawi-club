<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_link_daily_clicks', function (Blueprint $table): void {
            $table->string('link_code', 8);
            $table->foreign('link_code')->references('code')->on('affiliate_short_links')->restrictOnDelete();
            $table->date('day');
            $table->unsignedBigInteger('clicks');
            $table->primary(['link_code', 'day']);
        });
        DB::statement('ALTER TABLE affiliate_link_daily_clicks ADD CONSTRAINT affiliate_clicks_positive CHECK (clicks > 0)');
        // No historical backfill: measurement begins with real eligible GETs.
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve measured clicks; rollback application code without deleting aggregates.');
    }
};

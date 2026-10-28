<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_event_totals', function (Blueprint $table): void {
            $table->date('day');
            $table->string('event', 32);
            $table->string('placement', 16);
            $table->unsignedBigInteger('total')->default(0);
            $table->primary(['day', 'event', 'placement']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_event_totals');
    }
};

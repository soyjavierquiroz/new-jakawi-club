<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('membership_purchases', function (Blueprint $table): void {
            $table->text('beneficiary_name')->nullable();
            $table->timestampTz('reported_at')->nullable();
            $table->boolean('payment_confirmed')->default(false);
            $table->timestampTz('payment_confirmed_at')->nullable();
            $table->boolean('receipt_pending')->default(false);
            $table->boolean('receipt_received')->default(false);
        });
    }

    public function down(): void { /* Retain COM declarations and receipt evidence. */ }
};

<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('membership_purchases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('intent');
            $table->char('buyer_email_digest', 64)->index();
            $table->string('status', 32)->default('awaiting_payment');
            $table->uuid('payment_reference')->nullable()->unique();
            $table->string('membership_reference', 190)->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->string('receipt_sha256', 64)->nullable();
            $table->string('identity_status', 32)->default('pending');
            $table->uuid('global_subject_id')->nullable();
            $table->boolean('qr_ready')->default(false);
            $table->timestampTz('checkout_recorded_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'status']);
        });
    }
    public function down(): void { /* Purchase evidence is retained intentionally. */ }
};

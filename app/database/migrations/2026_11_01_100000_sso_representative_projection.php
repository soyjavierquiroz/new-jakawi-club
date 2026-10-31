<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('users', function (Blueprint $table): void {
   // A subject projection need not claim the email of an unrelated historical CLUB account.
   $table->string('email')->nullable()->change();
   $table->timestamp('sso_provisioned_at')->nullable();
  });
 }
 public function down(): void {
  // Preserve provisioned identities and nullable emails on operational rollback.
 }
};

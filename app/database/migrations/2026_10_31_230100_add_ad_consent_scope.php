<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('ad_visits', function (Blueprint $t): void { $t->uuid('consent_scope')->nullable()->index(); });
    }
    public function down(): void {
        Schema::table('ad_visits', function (Blueprint $t): void { $t->dropColumn('consent_scope'); });
    }
};

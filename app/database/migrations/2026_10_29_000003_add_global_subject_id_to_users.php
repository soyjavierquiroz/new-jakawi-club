<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // COM alone issues this identifier. Existing CLUB leads stay unlinked.
            $table->uuid('global_subject_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['global_subject_id']);
            $table->dropColumn('global_subject_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('acquisition_evidence', function (Blueprint $t): void {
            $t->id();
            $t->uuid('person_id')->index();
            $t->foreignId('event_id')->constrained('commercial_events');
            $t->string('purchase_reference', 100)->nullable();
            $t->uuid('affiliate_subject')->nullable();
            $t->char('identity_context', 64);
            $t->text('response_body')->nullable();
            $t->string('evidence_reference')->nullable();
            $t->string('status', 16);
            $t->text('reason');
            $t->jsonb('result')->nullable();
            $t->timestampTz('created_at', 6);
            $t->index(['event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Conservar evidencia económica privada; rollback de código únicamente.');
    }
};

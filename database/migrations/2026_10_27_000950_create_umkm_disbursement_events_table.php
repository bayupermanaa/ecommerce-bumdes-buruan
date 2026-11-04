<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('umkm_disbursement_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('umkm_disbursement_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('idempotency_key')->unique('ude_idempotency_key_unique');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['umkm_disbursement_id', 'created_at'], 'ude_disbursement_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('umkm_disbursement_events');
    }
};

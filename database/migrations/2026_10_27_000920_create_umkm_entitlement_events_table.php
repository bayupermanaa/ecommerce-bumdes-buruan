<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('umkm_entitlement_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('umkm_entitlement_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type');
            $table->decimal('amount', 15, 2);
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('idempotency_key')->unique('uee_idempotency_key_unique');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['umkm_entitlement_id', 'created_at'], 'uee_entitlement_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('umkm_entitlement_events');
    }
};

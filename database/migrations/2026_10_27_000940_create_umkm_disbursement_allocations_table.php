<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('umkm_disbursement_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('umkm_disbursement_id')->constrained()->restrictOnDelete();
            $table->foreignId('umkm_entitlement_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            // Maintained by the future transactional service for active and paid allocations.
            $table->string('entitlement_reservation_lock')->nullable()->unique('uda_entitlement_reservation_lock_unique');
            $table->timestamps();

            $table->unique(['umkm_disbursement_id', 'umkm_entitlement_id'], 'uda_disbursement_entitlement_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('umkm_disbursement_allocations');
    }
};

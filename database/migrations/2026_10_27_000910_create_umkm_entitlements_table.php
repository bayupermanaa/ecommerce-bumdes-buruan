<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('umkm_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('umkm_store_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_order_id')->unique('ue_store_order_unique')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            // NULL is retained for held sources whose commission snapshot was never calculated.
            $table->decimal('source_umkm_amount', 15, 2)->nullable();
            $table->decimal('source_commission_amount', 15, 2)->nullable();
            $table->decimal('source_commission_percentage', 5, 2)->nullable();
            $table->string('commission_status_snapshot');
            $table->string('status')->default('accrued');
            $table->timestamp('accrued_at');
            $table->timestamp('eligible_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->text('held_reason')->nullable();
            $table->text('reconciliation_notes')->nullable();
            $table->timestamps();

            $table->index(['umkm_store_id', 'status'], 'ue_store_status_idx');
            $table->index(['order_id', 'payment_id'], 'ue_order_payment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('umkm_entitlements');
    }
};

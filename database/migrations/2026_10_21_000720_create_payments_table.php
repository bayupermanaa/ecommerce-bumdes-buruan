<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep payment attempts at order level and preserve their submitted evidence.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            // Set only when an attempt is confirmed, enforcing one successful payment per order.
            $table->foreignId('confirmed_order_id')->nullable()->unique('payments_confirmed_order_unique')->constrained('orders')->restrictOnDelete();
            $table->foreignId('bumdes_bank_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('payment_number')->unique();
            $table->uuid('idempotency_key')->nullable()->unique('payments_idempotency_key_unique');
            $table->string('payment_method');
            $table->decimal('amount', 15, 2);
            $table->string('status')->default('pending_confirmation');
            $table->string('payer_name')->nullable();
            $table->string('transfer_reference')->nullable();
            // This path will point to a private disk when proof upload is implemented later.
            $table->string('transfer_proof_path')->nullable();
            $table->string('cash_received_by_name')->nullable();
            $table->timestamp('cash_received_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status'], 'payments_order_status_idx');
            $table->index(['bumdes_bank_account_id', 'status'], 'payments_bank_status_idx');
        });
    }

    /**
     * Reverse only the payment-attempt table.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

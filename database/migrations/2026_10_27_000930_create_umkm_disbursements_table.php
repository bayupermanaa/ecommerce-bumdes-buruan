<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('umkm_disbursements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('umkm_store_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipient_bank_account_id')->constrained('umkm_recipient_bank_accounts')->restrictOnDelete();
            $table->string('recipient_bank_name');
            $table->string('recipient_account_holder_name');
            $table->string('recipient_account_number');
            $table->decimal('requested_amount', 15, 2);
            $table->string('status')->default('requested');
            // Set to "store:{id}" while a request is active, then cleared on terminal states.
            $table->string('active_request_lock')->nullable()->unique('ud_active_request_lock_unique');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('transfer_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('transfer_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('review_notes')->nullable();
            $table->string('transfer_reference')->nullable();
            $table->string('transfer_proof_path')->nullable();
            $table->timestamp('transfer_verified_at')->nullable();
            $table->uuid('idempotency_key')->unique('ud_idempotency_key_unique');
            $table->timestamps();

            $table->index(['umkm_store_id', 'status'], 'ud_store_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('umkm_disbursements');
    }
};

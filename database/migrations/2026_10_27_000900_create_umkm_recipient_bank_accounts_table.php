<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('umkm_recipient_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('umkm_store_id')->constrained()->restrictOnDelete();
            $table->string('bank_name');
            $table->string('account_holder_name');
            $table->string('account_number');
            $table->string('status')->default('active');
            // Set to "store:{id}" while active; NULL permits historical inactive records.
            $table->string('active_store_lock')->nullable()->unique('urba_active_store_lock_unique');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['umkm_store_id', 'status'], 'urba_store_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('umkm_recipient_bank_accounts');
    }
};

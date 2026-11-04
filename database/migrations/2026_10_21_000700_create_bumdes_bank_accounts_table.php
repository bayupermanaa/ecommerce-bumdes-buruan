<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store official BUMDes accounts independently from individual payment attempts.
     */
    public function up(): void
    {
        Schema::create('bumdes_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name');
            $table->string('account_holder_name');
            $table->string('account_number');
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('status', 'bba_status_idx');
        });
    }

    /**
     * Reverse only the table introduced by this migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('bumdes_bank_accounts');
    }
};

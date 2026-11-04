<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store an append-only audit history for each store-specific fulfillment flow.
     */
    public function up(): void
    {
        Schema::create('store_order_status_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status');
            $table->string('to_status');
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['store_order_id', 'created_at'], 'store_order_status_log_idx');
        });
    }

    /**
     * Reverse only the additive fulfillment audit table.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_order_status_logs');
    }
};

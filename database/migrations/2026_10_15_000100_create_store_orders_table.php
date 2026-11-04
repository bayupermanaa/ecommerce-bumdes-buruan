<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('store_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('umkm_store_id')->constrained()->restrictOnDelete();
            $table->string('store_order_number')->unique();
            $table->string('store_name_snapshot');
            $table->text('store_address_snapshot')->nullable();
            $table->string('store_phone_snapshot', 50)->nullable();
            $table->decimal('subtotal', 15, 2);
            $table->decimal('shipping_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2);
            $table->string('fulfillment_status')->default('pending_payment');
            $table->timestamps();

            $table->index(['order_id', 'umkm_store_id']);
            $table->index(['umkm_store_id', 'fulfillment_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_orders');
    }
};

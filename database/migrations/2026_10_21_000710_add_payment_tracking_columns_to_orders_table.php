<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add nullable payment metadata so historical orders remain valid.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method')->nullable()->after('payment_status');
            $table->timestamp('payment_confirmed_at')->nullable()->after('payment_method');
            $table->foreignId('payment_confirmed_by')
                ->nullable()
                ->after('payment_confirmed_at')
                ->constrained('users')
                ->restrictOnDelete();

            $table->index(['payment_status', 'payment_method'], 'orders_payment_method_idx');
        });
    }

    /**
     * Reverse only the additive payment tracking columns.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_payment_method_idx');
            $table->dropForeign(['payment_confirmed_by']);
            $table->dropColumn(['payment_method', 'payment_confirmed_at', 'payment_confirmed_by']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store cancellation audit data without changing existing order snapshots.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('order_status');
            $table->text('cancellation_reason')->nullable()->after('cancelled_at');
            $table->index('cancelled_at');
        });
    }

    /**
     * Reverse only the additive cancellation metadata.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['cancelled_at']);
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};

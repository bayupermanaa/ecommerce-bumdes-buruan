<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_orders', function (Blueprint $table): void {
            $table->foreignId('commission_agreement_id')
                ->nullable()
                ->after('umkm_store_id')
                ->constrained('commission_agreements')
                ->restrictOnDelete();
            $table->decimal('commission_percentage', 5, 2)->nullable()->after('total');
            $table->decimal('commission_amount', 15, 2)->nullable()->after('commission_percentage');
            $table->decimal('umkm_amount', 15, 2)->nullable()->after('commission_amount');
            $table->string('commission_status')->nullable()->after('umkm_amount');
            $table->index(['commission_status', 'commission_agreement_id'], 'store_orders_commission_snapshot_idx');
        });
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table): void {
            $table->dropIndex('store_orders_commission_snapshot_idx');
            $table->dropForeign(['commission_agreement_id']);
            $table->dropColumn([
                'commission_agreement_id',
                'commission_percentage',
                'commission_amount',
                'umkm_amount',
                'commission_status',
            ]);
        });
    }
};

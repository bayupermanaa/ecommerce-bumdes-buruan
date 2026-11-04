<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep commission agreements independent from order and payment processing.
     */
    public function up(): void
    {
        // MySQL may retain the table and preceding indexes when a later index creation fails.
        if (Schema::hasTable('commission_agreements')) {
            if (! Schema::hasIndex('commission_agreements', 'ca_store_period_idx')) {
                Schema::table('commission_agreements', function (Blueprint $table) {
                    $table->index(['umkm_store_id', 'effective_start_date', 'effective_end_date'], 'ca_store_period_idx');
                });
            }

            return;
        }

        Schema::create('commission_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('umkm_store_id')->constrained()->restrictOnDelete();
            $table->decimal('commission_percentage', 5, 2);
            $table->date('effective_start_date');
            $table->date('effective_end_date')->nullable();
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['umkm_store_id', 'status'], 'ca_store_status_idx');
            $table->index(['umkm_store_id', 'effective_start_date', 'effective_end_date'], 'ca_store_period_idx');
        });
    }

    /**
     * Remove only the additive commission-agreement table.
     */
    public function down(): void
    {
        Schema::dropIfExists('commission_agreements');
    }
};

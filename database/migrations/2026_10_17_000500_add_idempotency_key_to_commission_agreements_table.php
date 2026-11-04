<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_agreements', function (Blueprint $table): void {
            // Nullable keeps existing agreements valid; new forms always provide a UUID.
            $table->uuid('idempotency_key')
                ->nullable()
                ->unique('commission_agreements_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('commission_agreements', function (Blueprint $table): void {
            $table->dropUnique('commission_agreements_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};

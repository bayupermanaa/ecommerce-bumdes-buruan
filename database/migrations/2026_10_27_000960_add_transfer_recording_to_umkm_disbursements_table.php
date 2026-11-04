<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::table('umkm_disbursements', function (Blueprint $table): void { $table->decimal('transfer_amount',15,2)->nullable()->after('transfer_reference'); $table->timestamp('transfer_recorded_at')->nullable()->after('transfer_proof_path'); }); } public function down(): void { Schema::table('umkm_disbursements', function (Blueprint $table): void { $table->dropColumn(['transfer_amount','transfer_recorded_at']); }); } };

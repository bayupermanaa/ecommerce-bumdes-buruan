<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('orders', function (Blueprint $table) { $table->foreignId('chat_offer_id')->nullable()->unique()->after('checkout_token')->constrained('chat_offers')->nullOnDelete(); }); Schema::table('order_items', function (Blueprint $table) { $table->json('custom_specifications')->nullable()->after('variant_sku_snapshot'); }); }
    public function down(): void { Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('custom_specifications')); Schema::table('orders', function (Blueprint $table) { $table->dropForeign(['chat_offer_id']); $table->dropUnique(['chat_offer_id']); $table->dropColumn('chat_offer_id'); }); }
};

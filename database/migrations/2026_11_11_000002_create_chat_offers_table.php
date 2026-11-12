<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('chat_offers', function (Blueprint $table) {
        $table->id(); $table->foreignId('chat_conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
        $table->foreignId('seller_id')->constrained('users')->restrictOnDelete(); $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
        $table->foreignId('product_id')->constrained('products')->restrictOnDelete(); $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
        $table->string('product_name_snapshot'); $table->string('product_image_snapshot')->nullable(); $table->string('variant_label_snapshot')->nullable(); $table->string('variant_sku_snapshot')->nullable();
        $table->json('custom_specifications')->nullable(); $table->unsignedInteger('quantity')->default(1); $table->decimal('unit_price', 15, 2); $table->decimal('total_price', 15, 2);
        $table->date('needed_on')->nullable(); $table->timestamp('expires_at'); $table->string('status', 30)->default('pending');
        $table->foreignId('replaced_by_id')->nullable()->constrained('chat_offers')->nullOnDelete(); $table->timestamp('responded_at')->nullable(); $table->timestamps();
        $table->index(['chat_conversation_id', 'status']); $table->index(['customer_id', 'status']);
    }); }
    public function down(): void { Schema::dropIfExists('chat_offers'); }
};

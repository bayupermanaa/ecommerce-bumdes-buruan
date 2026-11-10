<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('label');
            $table->string('sku')->unique();
            $table->decimal('price', 15, 2);
            $table->unsignedInteger('stock')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'label']);
            $table->index(['product_id', 'is_active', 'price']);
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')
                ->constrained('product_variants')->restrictOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')
                ->constrained('product_variants')->restrictOnDelete();
            $table->string('variant_label_snapshot')->nullable()->after('sku_snapshot');
            $table->string('variant_sku_snapshot')->nullable()->after('variant_label_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_variant_id');
            $table->dropColumn(['variant_label_snapshot', 'variant_sku_snapshot']);
        });

        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_variant_id');
        });

        Schema::dropIfExists('product_variants');
    }
};

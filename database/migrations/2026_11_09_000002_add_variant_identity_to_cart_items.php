<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL can commit each ALTER separately. This guard allows a retry after
        // the column was added but a later index operation failed on a disposable DB.
        if (! Schema::hasColumn('cart_items', 'variant_identity')) {
            Schema::table('cart_items', function (Blueprint $table): void {
                // NULL is not unique in MySQL. Zero identifies a plain product.
                $table->unsignedBigInteger('variant_identity')->default(0);
            });
        }

        // Create the replacement first: InnoDB needs an index starting with cart_id
        // to enforce the existing cart foreign key when the old unique is dropped.
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->unique(['cart_id', 'product_id', 'variant_identity'], 'cart_items_line_identity_unique');
        });
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropUnique('cart_items_cart_id_product_id_unique');
        });
    }

    public function down(): void
    {
        // Recreate the legacy index before removing the one supporting cart_id's FK.
        // Existing carts with multiple variants must be emptied before rollback.
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->unique(['cart_id', 'product_id']);
        });
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropUnique('cart_items_line_identity_unique');
            $table->dropColumn('variant_identity');
        });
    }
};

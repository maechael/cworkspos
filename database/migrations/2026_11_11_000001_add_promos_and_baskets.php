<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buy X Take Y promos on a product, and baskets: several items sold together at one price.
 * Sale lines remember the promo and the basket they were sold in, so receipts stay right after either changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('promo_buy')->nullable()->after('reorder_level');
            $table->unsignedSmallInteger('promo_take')->nullable()->after('promo_buy');
            $table->date('promo_until')->nullable()->after('promo_take');
        });

        Schema::create('baskets', function (Blueprint $table) {
            $table->id();
            $table->string('barcode', 64)->unique();
            $table->string('name');
            $table->decimal('price', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('basket_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('basket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->timestamps();
            $table->unique(['basket_id', 'product_id']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('free_qty', 12, 3)->default(0)->after('line_total');
            $table->string('promo', 40)->nullable()->after('free_qty');
            $table->foreignId('basket_id')->nullable()->after('promo')->constrained()->nullOnDelete();
            $table->string('basket_name')->nullable()->after('basket_id');
            $table->decimal('basket_qty', 12, 3)->nullable()->after('basket_name');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('basket_id');
            $table->dropColumn(['free_qty', 'promo', 'basket_name', 'basket_qty']);
        });
        Schema::dropIfExists('basket_items');
        Schema::dropIfExists('baskets');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['promo_buy', 'promo_take', 'promo_until']);
        });
    }
};

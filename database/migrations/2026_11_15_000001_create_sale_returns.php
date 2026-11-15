<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Returns: a sale's lines and total are edited down to what the shopper kept, so every report stays right,
 * and each return is kept as its own record (what came back, the refund, the reason, who did it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // total + refunded = what was paid (amount_paid - change)
            $table->decimal('refunded', 12, 2)->default(0)->after('change');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            // quantity is what the shopper kept; quantity + returned_qty is what was sold
            $table->decimal('returned_qty', 12, 3)->default(0)->after('quantity');
        });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_no')->unique();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->string('reason');
            $table->string('note')->nullable();
            $table->decimal('refund', 12, 2);
            $table->string('refund_method');
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->string('product_name');
            $table->string('basket_name')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->decimal('amount', 12, 2); // the line's price for what came back, before the sale's discount
            $table->boolean('restocked');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropColumn('returned_qty'));
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('refunded'));
    }
};

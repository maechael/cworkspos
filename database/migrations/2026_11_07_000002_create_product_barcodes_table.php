<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * More barcodes for one product, such as its box or a new packaging. Stock stays counted in the
     * product's own unit, and each code says how many of those one scan stands for.
     */
    public function up(): void
    {
        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('barcode', 64)->unique();
            $table->decimal('pieces', 12, 3)->default(1);
            $table->string('label', 60)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcodes');
    }
};

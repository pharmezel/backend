<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_details', function (Blueprint $table) {

            // Primary key
            $table->id('order_details_id');

            // Reference to orders table (which order this belongs to)
            $table->foreignId('order_id')->constrained('orders', 'order_id')->onDelete('cascade');

            // Reference to products table (what product is ordered)
            $table->foreignId('product_id') ->constrained('products', 'product_id')->onDelete('cascade');

            // Quantity of product ordered
            $table->integer('quantity');

            // Subtotal = quantity * unit_price
            $table->decimal('subtotal', 10, 2);

            // created_at & updated_at
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_details');
    }
};
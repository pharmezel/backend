<?php

/**
 * Master product catalog (superadmin-managed).
 *
 * @table products
 * @pk product_id
 * @see \App\Models\Product
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {

            // Primary key
            $table->id('product_id');

            // Product details
            $table->string('product_name');
            $table->text('description')->nullable();
            $table->string('image')->nullable();

            // Classification
            $table->string('category_name');

            // Pricing
            $table->decimal('unit_price', 10, 2);

            // Commission rate
            $table->decimal('commission_rate', 5, 2)->default(0);
            
            // Expiry tracking
            $table->date('expiry_date')->nullable();

            // Stock management
            $table->integer('stock_quantity')->default(0);

            // Date added
            $table->timestamp('date_added')->useCurrent();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
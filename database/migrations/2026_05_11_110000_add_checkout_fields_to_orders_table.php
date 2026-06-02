<?php

/**
 * Checkout: shipping_address, points_used, cod_amount on orders.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('shipping_address')->nullable();
            $table->unsignedInteger('points_used')->default(0);
            $table->decimal('cod_amount', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_address', 'points_used', 'cod_amount']);
        });
    }
};

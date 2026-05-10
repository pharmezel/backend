<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {

            // Primary key
            $table->id('order_id');

            // Reference to users table (who placed the order)
            $table->foreignId('buyer_id')->constrained('users', 'user_id')->onDelete('cascade');

            // Date when order was placed
            $table->date('order_date');

            // Total amount of the whole order
            $table->decimal('total_amount', 10, 2);

            // Payment method (e.g., cash, gcash, card)
            $table->string('payment_action');

            // Order status (pending, completed, cancelled, etc.)
            $table->string('order_status');

            // created_at & updated_at
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
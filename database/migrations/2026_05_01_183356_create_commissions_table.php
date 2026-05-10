<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissions', function (Blueprint $table) {

            // Primary key
            $table->id('commission_id');

            // Link to referral (who referred the buyer)
            $table->foreignId('referral_id')->constrained('referral_links', 'id')->onDelete('cascade');

            // Link to order
            $table->foreignId('order_id')->constrained('orders', 'order_id')->onDelete('cascade');

            // Commission earned from this order
            $table->decimal('commission_earned', 10, 2);

            // When commission was earned
            $table->date('date_earned');

            // Status 
            $table->string('status');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
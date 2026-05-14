<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products', 'product_id')->cascadeOnDelete();
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['admin_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_inventory');
    }
};

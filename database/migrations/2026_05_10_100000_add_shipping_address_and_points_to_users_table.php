<?php

/**
 * Adds default shipping address and legacy points column on users.
 *
 * Withdrawal eligibility uses computed balance (CommissionTotals), not users.points alone.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('shipping_address')->nullable();
            $table->integer('points')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['shipping_address', 'points']);
        });
    }
};

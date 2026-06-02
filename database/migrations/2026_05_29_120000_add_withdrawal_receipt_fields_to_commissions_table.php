<?php

/**
 * Supports withdrawal_receipt commissions: nullable order/referral FKs,
 * source, recipient_user_id, unique withdrawal_id.
 *
 * @see \App\Support\WithdrawalSettlement
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropForeign(['referral_id']);
            $table->dropForeign(['order_id']);
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->unsignedBigInteger('referral_id')->nullable()->change();
            $table->unsignedBigInteger('order_id')->nullable()->change();
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->foreign('referral_id')->references('id')->on('referral_links')->nullOnDelete();
            $table->foreign('order_id')->references('order_id')->on('orders')->nullOnDelete();

            $table->string('source')->default('order_referral')->after('commission_id');
            $table->foreignId('recipient_user_id')->nullable()->after('source')
                ->constrained('users', 'user_id')->nullOnDelete();
            $table->foreignId('withdrawal_id')->nullable()->after('recipient_user_id')
                ->constrained('withdrawals')->nullOnDelete();

            $table->unique('withdrawal_id');
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropUnique(['withdrawal_id']);
            $table->dropForeign(['recipient_user_id']);
            $table->dropForeign(['withdrawal_id']);
            $table->dropColumn(['source', 'recipient_user_id', 'withdrawal_id']);
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->dropForeign(['referral_id']);
            $table->dropForeign(['order_id']);
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->unsignedBigInteger('referral_id')->nullable(false)->change();
            $table->unsignedBigInteger('order_id')->nullable(false)->change();
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->foreign('referral_id')->references('id')->on('referral_links')->cascadeOnDelete();
            $table->foreign('order_id')->references('order_id')->on('orders')->cascadeOnDelete();
        });
    }
};

<?php

/**
 * Direct referral relationships (one referrer per referred buyer).
 *
 * @table referral_links
 * @see \App\Models\ReferralLink
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('referral_links', function (Blueprint $table) {
            $table->id();

            // who owns the referral code
            $table->foreignId('referrer_id')->constrained('users', 'user_id')->onDelete('cascade');

            // who used the referral code
            $table->foreignId('referred_id')->constrained('users', 'user_id')->onDelete('cascade');

            // status of referral
            $table->string('status')->default('active');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referral_links');
    }
};

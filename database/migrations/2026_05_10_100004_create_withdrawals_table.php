<?php

/**
 * Commission withdrawal requests (pending → approved → completed).
 *
 * @table withdrawals
 * Points balance deducts only when status = completed.
 * @see \App\Models\Withdrawal
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->integer('points_requested');
            $table->string('status')->default('pending');
            $table->foreignId('processed_by')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};

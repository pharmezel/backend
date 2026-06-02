<?php

/**
 * Buyer → admin (reseller) upgrade workflow; referrer approves.
 *
 * @table admin_upgrade_requests
 * @see \App\Models\AdminUpgradeRequest
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_upgrade_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->foreignId('approver_id')->constrained('users', 'user_id')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->text('requester_note')->nullable();
            $table->text('approver_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_upgrade_requests');
    }
};

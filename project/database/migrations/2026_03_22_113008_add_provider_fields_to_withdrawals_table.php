<?php

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
        Schema::table('withdrawals', function (Blueprint $table) {
            // Provider tracking fields (status column already exists)
            // status: 0=pending(manual), 1=accepted, 2=processing(provider dispatched), 3=rejected
            $table->string('provider_tx_id')->nullable()->after('user_data')->index();
            $table->string('provider_status')->nullable()->after('provider_tx_id');

            // Store which provider handled this withdrawal
            $table->unsignedBigInteger('provider_id')->nullable()->after('provider_status');
            $table->foreign('provider_id')->references('id')->on('payment_providers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropForeign(['provider_id']);
            $table->dropColumn(['provider_tx_id', 'provider_status', 'provider_id']);
        });
    }
};

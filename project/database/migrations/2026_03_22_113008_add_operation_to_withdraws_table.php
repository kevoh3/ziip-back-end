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
        Schema::table('withdraws', function (Blueprint $table) {
            // ProviderRouter operation to call when this method is used.
            // e.g. 'send_mpesa', 'send_airtel', 'send_bank', 'send_pesalink'
            // NULL = manual (admin processes it, old behaviour unchanged)
            // ProviderRouter operation to call when this method is used.
            // e.g. 'send_mpesa', 'send_airtel', 'send_bank', 'send_pesalink'
            // NULL = manual (admin processes it, old behaviour unchanged)
            $table->string('operation')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('withdraws', function (Blueprint $table) {
            $table->dropColumn(['operation']);
        });
    }
};

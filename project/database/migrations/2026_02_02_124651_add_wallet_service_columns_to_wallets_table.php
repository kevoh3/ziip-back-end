<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->string('wallet_name')->nullable()->after('balance');
            $table->text('description')->nullable()->after('wallet_name');
            $table->string('wallet_internal_account_number')->nullable()->after('description');
            $table->string('wallet_external_provider')->nullable()->after('wallet_internal_account_number');
            $table->string('wallet_external_provider_number')->nullable()->after('wallet_external_provider');
            $table->string('provider_wallet_type')->nullable()->after('wallet_external_provider_number');
            $table->string('provider_reference_id')->nullable()->after('provider_wallet_type');
            $table->json('provider_metadata')->nullable()->after('provider_reference_id');
            $table->enum('wallet_status', ['active', 'inactive', 'suspended', 'pending'])->default('active')->after('provider_metadata');
            $table->boolean('is_primary')->default(false)->after('wallet_status');
            $table->index('wallet_internal_account_number');
            $table->index('wallet_external_provider_number');
            $table->index(['user_id', 'user_type', 'currency_id']);
            $table->index(['user_id', 'user_type', 'is_primary']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn(['wallet_name', 'description', 'wallet_internal_account_number', 'wallet_external_provider', 'wallet_external_provider_number', 'provider_wallet_type', 'provider_reference_id', 'provider_metadata', 'wallet_status', 'is_primary']);
            $table->dropIndex(['wallet_internal_account_number']);
            $table->dropIndex(['wallet_external_provider_number']);
            $table->dropIndex(['user_id', 'user_type', 'currency_id']);
            $table->dropIndex(['user_id', 'user_type', 'is_primary']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddWalletServiceColumnsToWalletsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('wallets', function (Blueprint $table) {
            // Internal wallet identification
            $table->string('wallet_name')->nullable()->after('balance');
            $table->text('description')->nullable()->after('wallet_name');
            $table->string('wallet_internal_account_number')->nullable()->after('description');

            // External provider information
            $table->string('wallet_external_provider')->nullable()->after('wallet_internal_account_number');
            $table->string('wallet_external_provider_number')->nullable()->after('wallet_external_provider');

            // Additional useful columns
            $table->string('provider_wallet_type')->nullable()->after('wallet_external_provider_number');
            $table->string('provider_reference_id')->nullable()->after('provider_wallet_type');
            $table->json('provider_metadata')->nullable()->after('provider_reference_id');
            $table->enum('wallet_status', ['active', 'inactive', 'suspended', 'pending'])->default('active')->after('provider_metadata');
            $table->boolean('is_primary')->default(false)->after('wallet_status');

            // Indexes for performance
            $table->index('wallet_internal_account_number');
            $table->index('wallet_external_provider_number');
            $table->index(['user_id', 'user_type', 'currency_id']);
            $table->index(['user_id', 'user_type', 'is_primary']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn([
                'wallet_name',
                'description',
                'wallet_internal_account_number',
                'wallet_external_provider',
                'wallet_external_provider_number',
                'provider_wallet_type',
                'provider_reference_id',
                'provider_metadata',
                'wallet_status',
                'is_primary'
            ]);

            // Drop indexes
            $table->dropIndex(['wallet_internal_account_number']);
            $table->dropIndex(['wallet_external_provider_number']);
            $table->dropIndex(['user_id', 'user_type', 'currency_id']);
            $table->dropIndex(['user_id', 'user_type', 'is_primary']);
        });
    }
}

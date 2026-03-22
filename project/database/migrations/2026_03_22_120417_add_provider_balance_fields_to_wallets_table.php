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
        Schema::table('wallets', function (Blueprint $table) {
            // ----------------------------------------------------------------
            // Provider-side balance tracking (for reconciliation)
            // ----------------------------------------------------------------

            // The actual balance held at the provider (e.g. ChoiceBank balance).
            // Our system's `balance` column is our accounting truth.
            // `provider_balance` is what the provider reports — used to detect drift.
            $table->decimal('provider_balance', 20, 10)->nullable()->after('balance');

            // When we last fetched the balance from the provider API
            $table->timestamp('provider_balance_synced_at')->nullable()->after('provider_balance');

            // ----------------------------------------------------------------
            // Provider account identity
            // ----------------------------------------------------------------

            // Full name registered on the provider account (e.g. "John Doe" at ChoiceBank)
            $table->string('provider_account_name')->nullable()->after('provider_wallet_type');

            // Shortcode or Paybill number at the provider — used so others can pay IN.
            // e.g. a merchant's Till number at ChoiceBank
            $table->string('provider_shortcode')->nullable()->after('provider_account_name');

            // The provider's own status string for this account.
            // Separate from our wallet_status — provider may suspend/freeze independently.
            // e.g. 'ACTIVE', 'DORMANT', 'FROZEN', 'SUSPENDED', 'PENDING_VERIFICATION'
            $table->string('provider_account_status')->nullable()->after('provider_shortcode');

            // FK to payment_providers table — which provider backs this wallet.
            // NULL = purely internal wallet (no external provider yet).
            $table->unsignedBigInteger('payment_provider_id')->nullable()->after('provider_account_status');
            $table->foreign('payment_provider_id')
                  ->references('id')
                  ->on('payment_providers')
                  ->nullOnDelete();

            $table->index('payment_provider_id');
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropForeign(['payment_provider_id']);
            $table->dropColumn([
                'provider_balance',
                'provider_balance_synced_at',
                'provider_account_name',
                'provider_shortcode',
                'provider_account_status',
                'payment_provider_id',
            ]);
        });
    }
};

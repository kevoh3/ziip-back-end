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
        Schema::create('provider_transactions', function (Blueprint $table) {
            $table->id();

            // Link to our internal transaction record (nullable — some ops like airtime
            // may not create a Transaction row until confirmed)
            $table->foreignId('transaction_id')
                  ->nullable()
                  ->constrained('transactions')
                  ->nullOnDelete();

            $table->foreignId('provider_id')->constrained('payment_providers');

            // The provider's own transaction/application/job ID
            // e.g. ChoiceBank txId, Daraja CheckoutRequestID
            $table->string('provider_tx_id')->nullable()->index();

            // The operation that was performed
            $table->string('operation');

            // Provider-reported status string, e.g. 'PROCESSING', 'SUCCESS', 'FAILED'
            $table->string('provider_status')->nullable();

            // Full request + response from the provider (for auditing and debugging)
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();

            // Error detail when provider returns a failure
            $table->text('error_message')->nullable();

            // When the provider confirmed the transaction as final
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['provider_id', 'provider_tx_id']);
            $table->index(['transaction_id', 'provider_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_transactions');
    }
};

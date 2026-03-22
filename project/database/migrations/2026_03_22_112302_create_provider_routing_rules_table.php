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
        Schema::create('provider_routing_rules', function (Blueprint $table) {
            $table->id();

            // The operation being routed e.g. 'send_mpesa', 'send_airtel', 'airtime',
            // 'bill_payment', 'fx_exchange', 'send_pesalink', 'send_bank', 'internal_transfer'
            $table->string('operation');

            // Primary provider for this operation
            $table->foreignId('provider_id')->constrained('payment_providers')->cascadeOnDelete();

            // Optional fallback if primary fails
            $table->foreignId('fallback_provider_id')
                  ->nullable()
                  ->constrained('payment_providers')
                  ->nullOnDelete();

            // Scope to a specific currency (null = any currency)
            $table->string('currency_code', 10)->nullable();

            // Scope to a specific country (null = any country)
            $table->string('country_code', 5)->nullable();

            $table->boolean('is_active')->default(true);

            // Lower = higher priority when multiple rules match
            $table->unsignedTinyInteger('priority')->default(10);

            $table->timestamps();

            // Unique rule per operation+currency+country combination
            $table->unique(['operation', 'currency_code', 'country_code'], 'routing_operation_unique');
            $table->index(['operation', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_routing_rules');
    }
};

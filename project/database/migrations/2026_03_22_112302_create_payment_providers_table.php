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
        Schema::create('payment_providers', function (Blueprint $table) {
            $table->id();

            // Human-readable name shown in admin panel only
            $table->string('name');

            // Unique machine key used in code e.g. 'choicebank', 'daraja', 'pesapal', 'jambopay'
            $table->string('slug')->unique();

            // Fully-qualified driver class, e.g. App\PaymentProviders\ChoiceBankProvider
            $table->string('driver_class');

            // Provider credentials/settings — stored as JSON, encrypted via cast
            $table->json('config')->nullable();

            // Array of capability strings the provider supports:
            // send_mpesa, receive_mpesa, send_airtel, receive_airtel,
            // send_pesalink, send_bank, internal_transfer,
            // airtime, bill_payment, fx_exchange, bulk_transfer
            $table->json('capabilities')->nullable();

            // ISO 3166-1 alpha-2 country codes e.g. ["KE","UG","TZ"]
            $table->json('supported_countries')->nullable();

            // ISO 4217 currency codes e.g. ["KES","USD"]
            $table->json('supported_currencies')->nullable();

            $table->boolean('is_active')->default(true);

            // Lower number = higher priority when multiple providers can handle same operation
            $table->unsignedTinyInteger('priority')->default(10);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_providers');
    }
};

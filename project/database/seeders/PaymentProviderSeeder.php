<?php

namespace Database\Seeders;

use App\Models\PaymentProvider;
use App\Models\ProviderRoutingRule;
use Illuminate\Database\Seeder;

/**
 * Seeds ChoiceBank as the default provider for all operations.
 *
 * To switch any operation to a different provider later:
 *   1. Add the new provider to payment_providers
 *   2. Update the matching row in provider_routing_rules (or set a new one with higher priority)
 * The frontend code never changes — only the routing table.
 */
class PaymentProviderSeeder extends Seeder
{
    public function run(): void
    {
        $choicebank = PaymentProvider::updateOrCreate(
            ['slug' => 'choicebank'],
            [
                'name'                 => 'ChoiceBank BaaS',
                'driver_class'         => \App\PaymentProviders\ChoiceBankProvider::class,
                'config'               => null, // uses .env CHOICE_* values
                'capabilities'         => [
                    'send_mpesa',
                    'receive_mpesa',
                    'send_airtel',
                    'receive_airtel',
                    'send_pesalink',
                    'send_bank',
                    'internal_transfer',
                    'airtime',
                    'bill_payment',
                    'fx_exchange',
                    'bulk_transfer',
                    'pay_merchant',
                ],
                'supported_countries'  => ['KE', 'UG', 'TZ', 'RW'],
                'supported_currencies' => ['KES', 'USD', 'GBP', 'EUR', 'UGX', 'TZS', 'RWF'],
                'is_active'            => true,
                'priority'             => 1,
            ]
        );

        // Wire every operation to ChoiceBank as the default (null = any currency/country)
        $operations = [
            'send_mpesa',
            'send_airtel',
            'send_pesalink',
            'send_bank',
            'internal_transfer',
            'airtime',
            'bill_payment',
            'fx_exchange',
            'bulk_transfer',
            'pay_merchant',
        ];

        foreach ($operations as $operation) {
            ProviderRoutingRule::updateOrCreate(
                [
                    'operation'     => $operation,
                    'currency_code' => null,
                    'country_code'  => null,
                ],
                [
                    'provider_id'          => $choicebank->id,
                    'fallback_provider_id' => null,
                    'is_active'            => true,
                    'priority'             => 10,
                ]
            );
        }

        $this->command->info('ChoiceBank registered as default provider for all operations.');
    }
}

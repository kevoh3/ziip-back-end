<?php

namespace Database\Seeders;

use App\Models\Charge;
use Illuminate\Database\Seeder;

/**
 * Seeds charge configuration for new provider-routed features.
 * Admins can adjust these values in the admin panel.
 */
class NewFeatureChargesSeeder extends Seeder
{
    public function run(): void
    {
        $charges = [
            [
                'name' => 'Airtime Purchase',
                'slug' => 'airtime',
                'data' => json_encode([
                    'percent_charge' => 0,
                    'fixed_charge'   => 0,
                    'minimum'        => 5,
                    'maximum'        => 10000,
                    'daily_limit'    => 0,
                    'monthly_limit'  => 0,
                ]),
            ],
            [
                'name' => 'Bill Payment',
                'slug' => 'bill-payment',
                'data' => json_encode([
                    'percent_charge' => 0,
                    'fixed_charge'   => 0,
                    'minimum'        => 1,
                    'maximum'        => 100000,
                    'daily_limit'    => 0,
                    'monthly_limit'  => 0,
                ]),
            ],
            [
                'name' => 'Send to Mobile',
                'slug' => 'send-to-mobile',
                'data' => json_encode([
                    'percent_charge' => 0,
                    'fixed_charge'   => 0,
                    'minimum'        => 10,
                    'maximum'        => 150000,
                    'daily_limit'    => 0,
                    'monthly_limit'  => 0,
                ]),
            ],
            [
                'name' => 'Send to Bank',
                'slug' => 'send-to-bank',
                'data' => json_encode([
                    'percent_charge' => 0,
                    'fixed_charge'   => 0,
                    'minimum'        => 100,
                    'maximum'        => 999999,
                    'daily_limit'    => 0,
                    'monthly_limit'  => 0,
                ]),
            ],
        ];

        foreach ($charges as $charge) {
            Charge::updateOrCreate(
                ['slug' => $charge['slug']],
                ['name' => $charge['name'], 'data' => $charge['data']]
            );
        }

        $this->command->info('New feature charges seeded: airtime, bill-payment, send-to-mobile, send-to-bank');
    }
}

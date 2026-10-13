<?php

namespace Database\Seeders;

use App\Models\PaymentFeeRule;
use Illuminate\Database\Seeder;

/**
 * Starter service fee rules, one per currency. Run on demand:
 *   php artisan db:seed --class=PaymentFeeRuleSeeder
 *
 * The 3% placeholder should cover the dearest gateway plus the platform margin; once the
 * gateway reports its cost on each payment, margin = fee_amount - gateway_fee_amount.
 *
 * Nothing here is charged unless PLATFORM_CHARGES=true.
 */
class PaymentFeeRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // name, currency, min, max, percentage, fixed, cap, active
            ['Service fee NGN', 'NGN', 0.01, null, 3.0000, 0.00, null, true],
            ['Service fee USD', 'USD', 0.01, null, 3.0000, 0.00, null, true],
            ['Service fee GBP', 'GBP', 0.01, null, 3.0000, 0.00, null, true],
            ['Service fee EUR', 'EUR', 0.01, null, 3.0000, 0.00, null, true],
        ];

        foreach ($rows as [$name, $currency, $min, $max, $percentage, $fixed, $cap, $active]) {
            PaymentFeeRule::query()->firstOrCreate(
                ['name' => $name],
                [
                    'currency' => $currency,
                    'min_amount' => $min,
                    'max_amount' => $max,
                    'percentage' => $percentage,
                    'fixed_amount' => $fixed,
                    'cap_amount' => $cap,
                    'is_active' => $active,
                ],
            );
        }
    }
}

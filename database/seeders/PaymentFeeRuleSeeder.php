<?php

namespace Database\Seeders;

use App\Models\PaymentFeeRule;
use Illuminate\Database\Seeder;

/**
 * Starter service fee rules, one per currency. Run on demand:
 *   php artisan db:seed --class=PaymentFeeRuleSeeder
 *
 * The fee is a straight 3% of the donation with no fixed component (a fixed amount at or above
 * the cap would make every fee equal the cap). It should cover the dearest gateway plus the
 * platform margin; once the gateway reports its cost, margin = fee_amount - gateway_fee_amount.
 *
 * Every rule is capped at 2,000 NGN. For USD, GBP and EUR the cap is kept in NGN and converted
 * into the rule currency at quote time with the latest exchange rate (exchange:fetch-rates).
 *
 * Nothing here is charged unless PLATFORM_CHARGES=true.
 */
class PaymentFeeRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // name, currency, min, max, percentage, fixed, cap, cap currency, active
            ['Service fee NGN', 'NGN', 0.01, null, 3.0000, 0.00, 2000.00, 'NGN', true],
            ['Service fee USD', 'USD', 0.01, null, 3.0000, 0.00, 2000.00, 'NGN', true],
            ['Service fee GBP', 'GBP', 0.01, null, 3.0000, 0.00, 2000.00, 'NGN', true],
            ['Service fee EUR', 'EUR', 0.01, null, 3.0000, 0.00, 2000.00, 'NGN', true],
        ];

        foreach ($rows as [$name, $currency, $min, $max, $percentage, $fixed, $cap, $capCurrency, $active]) {
            PaymentFeeRule::query()->firstOrCreate(
                ['name' => $name],
                [
                    'currency' => $currency,
                    'min_amount' => $min,
                    'max_amount' => $max,
                    'percentage' => $percentage,
                    'fixed_amount' => $fixed,
                    'cap_amount' => $cap,
                    'cap_currency' => $capCurrency,
                    'is_active' => $active,
                ],
            );
        }
    }
}

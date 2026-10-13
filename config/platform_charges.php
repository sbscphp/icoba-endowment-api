<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform charges (service fee on checkout)
    |--------------------------------------------------------------------------
    |
    | PLATFORM_CHARGES=false   => every quote is zero: donors are charged exactly the
    |                             donation amount, fee columns stay at 0 / amount.
    | PLATFORM_CHARGES=true    => the active payment_fee_rule for the currency and amount
    |                             band is applied at intent time (e.g. 3% of the donation).
    |
    | The donation amount (transactions.amount) is never changed by any of this. The fee lives
    | in fee_amount, the charged figure is total_amount = amount + fee_amount, and once the
    | gateway reports what it kept (gateway_fee_amount) the margin is fee_amount minus that.
    */
    'enabled' => filter_var(env('PLATFORM_CHARGES', false), FILTER_VALIDATE_BOOL),

    /*
    | Offline bank transfers have no gateway charge and the donor pays from their own
    | bank app, so they carry no fee unless explicitly enabled.
    */
    'bank_transfer_applies_fee' => filter_var(env('PLATFORM_CHARGES_ON_BANK_TRANSFER', false), FILTER_VALIDATE_BOOL),

    /*
    | Tolerance (in the transaction currency) when comparing what the gateway says it
    | charged against total_amount. Larger differences are flagged in metadata as
    | gateway_amount_mismatch for admin review; the payment is still finalized.
    */
    'gateway_amount_tolerance' => (float) env('PLATFORM_CHARGES_GATEWAY_TOLERANCE', 0.01),
];

<?php

return [

    'bank_name' => env('ENDOWMENT_BANK_NAME', 'First City Monument Bank'),

    'account_name' => env(
        'ENDOWMENT_BANK_ACCOUNT_NAME',
        'Igbobi College Old Boys Association (ICOBA) Endowment Fund'
    ),

    'accounts' => [
        [
            'account_key' => 'fcmb_ngn',
            'currency' => 'NGN',
            'currency_symbol' => '₦',
            'account_number' => env('ENDOWMENT_BANK_NGN_ACCOUNT', '2007877660'),
        ],
        [
            'account_key' => 'fcmb_usd',
            'currency' => 'USD',
            'currency_symbol' => '$',
            'account_number' => env('ENDOWMENT_BANK_USD_ACCOUNT', '2007893628'),
        ],
        [
            'account_key' => 'fcmb_gbp',
            'currency' => 'GBP',
            'currency_symbol' => '£',
            'account_number' => env('ENDOWMENT_BANK_GBP_ACCOUNT', '2007893642'),
        ],
        [
            'account_key' => 'fcmb_eur',
            'currency' => 'EUR',
            'currency_symbol' => '€',
            'account_number' => env('ENDOWMENT_BANK_EUR_ACCOUNT', '2007893680'),
        ],
    ],

];

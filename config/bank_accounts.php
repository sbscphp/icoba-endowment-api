<?php

return [

    'bank_name' => env('ENDOWMENT_BANK_NAME', 'First City Monument Bank'),

    'account_name' => env(
        'ENDOWMENT_BANK_ACCOUNT_NAME',
        'Igbobi College Old Boys Association (ICOBA) Endowment Fund'
    ),

    'accounts' => [
        [
            'currency' => 'NGN',
            'currency_symbol' => '₦',
            'account_number' => env('ENDOWMENT_BANK_NGN_ACCOUNT', '2007877660'),
        ],
        [
            'currency' => 'USD',
            'currency_symbol' => '$',
            'account_number' => env('ENDOWMENT_BANK_USD_ACCOUNT', '2007893628'),
        ],
        [
            'currency' => 'GBP',
            'currency_symbol' => '£',
            'account_number' => env('ENDOWMENT_BANK_GBP_ACCOUNT', '2007893642'),
        ],
        [
            'currency' => 'EUR',
            'currency_symbol' => '€',
            'account_number' => env('ENDOWMENT_BANK_EUR_ACCOUNT', '2007893680'),
        ],
    ],

];

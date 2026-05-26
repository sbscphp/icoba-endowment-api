<?php

return [
    /*
    |--------------------------------------------------------------------------
    | FCMB webhook configuration
    |--------------------------------------------------------------------------
    |
    | FCMB is expected to push transaction notifications to ICOBA endowment
    | accounts once the partnership integration is finalized. This config holds
    | placeholder values; the final signature scheme and header names will be
    | confirmed with FCMB before the stub is promoted to production.
    */

    'enabled' => env('FCMB_WEBHOOK_ENABLED', false),

    'shared_secret' => env('FCMB_WEBHOOK_SECRET'),

    'signature_header' => env('FCMB_WEBHOOK_SIGNATURE_HEADER', 'X-FCMB-Signature'),

    'allow_test_payloads' => env('FCMB_WEBHOOK_ALLOW_TEST', false),

    'payload_map' => [
        'transactions_path' => env('FCMB_WEBHOOK_TRANSACTIONS_PATH', 'transactions'),
        'fields' => [
            'transaction_date' => env('FCMB_WEBHOOK_FIELD_DATE', 'transaction_date'),
            'amount' => env('FCMB_WEBHOOK_FIELD_AMOUNT', 'amount'),
            'narration' => env('FCMB_WEBHOOK_FIELD_NARRATION', 'narration'),
            'statement_reference' => env('FCMB_WEBHOOK_FIELD_REFERENCE', 'reference'),
            'account_number' => env('FCMB_WEBHOOK_FIELD_ACCOUNT', 'account_number'),
        ],
    ],
];

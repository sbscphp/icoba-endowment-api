<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CSV column map for FCMB statement uploads
    |--------------------------------------------------------------------------
    |
    | Map the column header (case-insensitive) used in the FCMB statement export
    | to our normalized row shape. Override via env when FCMB changes their
    | export format.
    */
    'column_map' => [
        'transaction_date' => env('FCMB_CSV_DATE_COLUMN', 'Transaction Date'),
        'amount' => env('FCMB_CSV_AMOUNT_COLUMN', 'Credit'),
        'debit' => env('FCMB_CSV_DEBIT_COLUMN', 'Debit'),
        'narration' => env('FCMB_CSV_NARRATION_COLUMN', 'Narration'),
        'statement_reference' => env('FCMB_CSV_REFERENCE_COLUMN', 'Reference'),
        'account_number' => env('FCMB_CSV_ACCOUNT_COLUMN', 'Account Number'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Amount tolerance
    |--------------------------------------------------------------------------
    |
    | Per-currency tolerance applied when auto-matching a bank credit to a
    | pending bank-transfer intent. NGN matches exactly by default; FX
    | transfers can drift slightly due to receiving bank fees / rounding.
    */
    'amount_tolerance' => [
        'NGN' => env('FCMB_AMOUNT_TOLERANCE_NGN', 0.0),
        'USD' => env('FCMB_AMOUNT_TOLERANCE_USD', 1.0),
        'GBP' => env('FCMB_AMOUNT_TOLERANCE_GBP', 1.0),
        'EUR' => env('FCMB_AMOUNT_TOLERANCE_EUR', 1.0),
    ],
];

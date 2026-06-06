<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'success_url' => env('STRIPE_SUCCESS_URL'),
        'failed_url' => env('STRIPE_FAILED_URL', env('STRIPE_CANCEL_URL')),
        'cancel_url' => env('STRIPE_CANCEL_URL'),
    ],

    'paystack' => [
        'secret' => env('PAYSTACK_SECRET_KEY'),
        'public' => env('PAYSTACK_PUBLIC_KEY'),
        'callback_url' => env('PAYSTACK_CALLBACK_URL'),
        'failed_url' => env('PAYSTACK_FAILED_URL'),
    ],

    'sms' => [
        'driver' => env('SMS_PROVIDER', 'log'),
        'infobip' => [
            'base_url' => env('INFOBIP_BASE_URL'),
            'api_key' => env('INFOBIP_API_KEY'),
            'sender' => env('INFOBIP_SENDER', env('APP_NAME', 'Laravel')),
        ],
        'termii' => [
            'base_url' => env('TERMII_BASE_URL'),
            'api_key' => env('TERMII_API_KEY'),
            'sender' => env('TERMII_SENDER', env('APP_NAME', 'Laravel')),
        ],
        'twilio' => [
            'account_sid' => env('TWILIO_ACCOUNT_SID'),
            'auth_token' => env('TWILIO_AUTH_TOKEN'),
            'from' => env('TWILIO_FROM'),
            'timeout' => env('TWILIO_TIMEOUT', 120),
        ],
    ],

];

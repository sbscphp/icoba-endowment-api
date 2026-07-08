<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | Credentials for third-party services used by the application.
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

    'sms' => [
        /*
        | SMS_MODE controls provider dispatch when security.otp_sms_dispatch_enabled is true:
        | log  — message written to logs only
        | live — sent via Termii (NG) or SMS_FOREIGN_PROVIDER (foreign)
        | Fixed SMS OTP while dispatch is off: security.otp_sms_stub_code (OTP_SMS_STUB_CODE).
        */
        'foreign_provider' => env('SMS_FOREIGN_PROVIDER', 'twilio'),
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

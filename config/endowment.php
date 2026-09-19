<?php

return [

    'project_goal_naira' => env('ENDOWMENT_PROJECT_GOAL_NGN', 10_000_000_000),

    'public_stats_cache_seconds' => env('ENDOWMENT_PUBLIC_STATS_CACHE_SECONDS', 300),

    'foundation_name' => env('ENDOWMENT_FOUNDATION_NAME', 'ICOBA Endowment Foundation'),

    'tax_id' => env('ENDOWMENT_TAX_ID', '12-3456789'),

    'contact_email' => env('ENDOWMENT_CONTACT_EMAIL', 'contact@icobaendowment.org'),

    'contact_submission_notify_email' => env(
        'ENDOWMENT_CONTACT_SUBMISSION_NOTIFY_EMAIL',
        env('ENDOWMENT_CONTACT_EMAIL', 'contact@icobaendowment.org'),
    ),

    'website' => env('ENDOWMENT_WEBSITE', 'www.icobaendowment.org'),

    'executive_director_name' => env('ENDOWMENT_EXECUTIVE_DIRECTOR', 'Dr. Sarah Johnson'),

    'executive_director_title' => env('ENDOWMENT_EXECUTIVE_DIRECTOR_TITLE', 'Executive Director'),

    'tax_deductibility_statement' => env(
        'ENDOWMENT_TAX_DEDUCTIBILITY_STATEMENT',
        'ICOBA Endowment Foundation is a registered 501(c)(3) nonprofit organization. Your contribution is tax-deductible to the full extent allowed by law. No goods or services were provided in exchange for this donation. Please retain this receipt for your tax records.'
    ),

    'receipt_thank_you' => env(
        'ENDOWMENT_RECEIPT_THANK_YOU',
        'Thank you for your generous contribution to the ICOBA Endowment Foundation. Your support helps us continue our mission of legacy and transformation projects for Igbobi College.'
    ),

    /*
    | Test vs live tagging for pledges and transactions (is_test column, see App\Support\PaymentMode).
    |
    | PAYMENTS_TEST_MODE unset => auto: test outside production, or when the transaction's gateway is
    |                             configured with test credentials (Stripe/Paystack sk_test_, FCMB test host).
    | PAYMENTS_TEST_MODE=true  => everything created is tagged test (e.g. a UAT round on the live server).
    | PAYMENTS_TEST_MODE=false => everything created is tagged live.
    */
    'payments' => [
        'test_mode' => env('PAYMENTS_TEST_MODE') === null || env('PAYMENTS_TEST_MODE') === ''
            ? null
            : filter_var(env('PAYMENTS_TEST_MODE'), FILTER_VALIDATE_BOOL),
        'fcmb_test_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('FCMB_CLNX_TEST_HOSTS', 'dev.clnx.io'))
        ))),
    ],

    'exchange_rate' => [
        // free = open.er-api.com (no key). paid = v6.exchangerate-api.com (requires EXCHANGE_RATE_API_KEY).
        'tier' => env('EXCHANGE_RATE_API_TIER', 'free'),
        'api_key' => env('EXCHANGE_RATE_API_KEY'),
        // Paid tier only: url = key in path (default). bearer = Authorization header, key omitted from URL.
        'paid_auth' => env('EXCHANGE_RATE_PAID_AUTH', 'url'),
        'fetch_interval_hours' => (int) env('EXCHANGE_RATE_FETCH_INTERVAL_HOURS', 4),
        'stale_alert_days' => (int) env('EXCHANGE_RATE_STALE_ALERT_DAYS', 2),
        'cache_key' => 'exchange_rate:last_fetch',
        'alert_to' => env('EXCHANGE_RATE_ALERT_TO', 'adamilola@sbsc.com'),
        'alert_cc' => env('EXCHANGE_RATE_ALERT_CC', 'juwonloiroayo@gmail.com'),
    ],

];

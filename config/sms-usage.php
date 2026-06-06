<?php

$parseEmails = static function (?string $value): array {
    if ($value === null || trim($value) === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $value))));
};

$parseThresholds = static function (?string $value): array {
    if ($value === null || trim($value) === '') {
        return [50.0, 75.0, 90.0];
    }

    $thresholds = array_map('floatval', array_filter(array_map('trim', explode(',', $value))));

    return $thresholds !== [] ? $thresholds : [50.0, 75.0, 90.0];
};

return [
    'termii' => [
        'monthly_budget' => (float) env('TERMII_MONTHLY_BUDGET_NGN', 0),
        'currency' => 'NGN',
        'warn_at' => $parseThresholds(env('TERMII_WARN_AT')),
        'notify' => [
            'addresses' => $parseEmails(env('TERMII_ALERT_EMAILS')),
        ],
    ],

    'twilio' => [
        'monthly_budget' => (float) env('TWILIO_MONTHLY_BUDGET', 0),
        'currency' => env('TWILIO_BALANCE_CURRENCY', 'USD'),
        'warn_at' => $parseThresholds(env('TWILIO_WARN_AT')),
        'notify' => [
            'addresses' => $parseEmails(env('TWILIO_ALERT_EMAILS')),
        ],
    ],

    'infobip' => [
        'monthly_budget' => (float) env('INFOBIP_MONTHLY_BUDGET', 0),
        'currency' => env('INFOBIP_BALANCE_CURRENCY', 'USD'),
        'warn_at' => $parseThresholds(env('INFOBIP_WARN_AT')),
        'notify' => [
            'addresses' => $parseEmails(env('INFOBIP_ALERT_EMAILS')),
        ],
    ],
];

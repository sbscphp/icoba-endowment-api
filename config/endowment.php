<?php

return [

    'project_goal_naira' => env('ENDOWMENT_PROJECT_GOAL_NGN', 10_000_000_000),

    'public_stats_cache_seconds' => env('ENDOWMENT_PUBLIC_STATS_CACHE_SECONDS', 300),

    'foundation_name' => env('ENDOWMENT_FOUNDATION_NAME', 'ICOBA Endowment Foundation'),

    'tax_id' => env('ENDOWMENT_TAX_ID', '12-3456789'),

    'contact_email' => env('ENDOWMENT_CONTACT_EMAIL', 'contact@icobaendowment.org'),

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

];

<?php

$otpMinutes = max(1, (int) env('OTP_MINUTES', 5));

return [
    /*
    |--------------------------------------------------------------------------
    | OTP (login & password-reset challenge)
    |--------------------------------------------------------------------------
    |
    | OTP codes expire after this many minutes. HTTP rate limits for OTP send
    | and verify endpoints use the same window so throttling stays aligned with
    | how long a challenge stays valid.
    |
    */
    'otp_minutes' => $otpMinutes,

    /*
    | Seconds between new OTP dispatches for the same account and purpose (login vs reset).
    | Matches the OTP validity window (OTP_MINUTES × 60): no replacement SMS/email until the current
    | code would expire. While cooling down, reuse responses still return the active challenge_token;
    | retry_after_* reflects time until a fresh dispatch is allowed.
    */
    'otp_send_cooldown_seconds' => $otpMinutes * 60,

    /*
    | Max age (from token issue time) for reusing an expired challenge_token on resend
    | endpoints. After this window the client must restart the flow (login, forgot-password, etc.).
    */
    'otp_resend_token_max_minutes' => max(1, (int) env('OTP_RESEND_TOKEN_MAX_MINUTES', 30)),

    /*
    | Max OTP send requests (initial + resend) per IP|challenge per otp_minutes window.
    */
    'otp_send_max_per_window' => max(1, (int) env('OTP_SEND_MAX_PER_WINDOW', 3)),

    /*
    | Max OTP verification HTTP requests per IP|challenge per otp_minutes window.
    | Should comfortably exceed in-flow wrong attempts (see OtpService MAX_ATTEMPTS).
    */
    'otp_verify_max_per_window' => max(1, (int) env('OTP_VERIFY_MAX_PER_WINDOW', 20)),

    /*
    | When false (default), SMS-channel OTPs use otp_sms_stub_code and no provider is called.
    | Set OTP_SMS_DISPATCH_ENABLED=true when ready to bill SMS providers (infobip/termii/twilio via SMS_PROVIDER).
    */
    'otp_sms_dispatch_enabled' => filter_var(env('OTP_SMS_DISPATCH_ENABLED', false), FILTER_VALIDATE_BOOL),

    /*
    | Fixed OTP used for SMS channel while otp_sms_dispatch_enabled is false (local/staging only).
    */
    'otp_sms_stub_code' => (string) env('OTP_SMS_STUB_CODE', '123456'),

    /*
    |--------------------------------------------------------------------------
    | Global Auth Error Opacity Override
    |--------------------------------------------------------------------------
    |
    | null  => use per-flow toggles below
    | true  => force opaque auth errors for login + forgot-password flows
    | false => force explicit auth errors for login + forgot-password flows
    |
    */
    'auth_opaque_errors' => env('AUTH_OPAQUE_ERRORS') === null
        ? null
        : filter_var(env('AUTH_OPAQUE_ERRORS'), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Login Error Opacity
    |--------------------------------------------------------------------------
    |
    | Used when AUTH_OPAQUE_ERRORS is null.
    | true  => return one generic login failure for credential/OTP failures.
    | false => allow explicit service errors during local support/debugging.
    |
    */
    'login_opaque_errors' => filter_var(env('LOGIN_OPAQUE_ERRORS', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Forgot Password Error Opacity
    |--------------------------------------------------------------------------
    |
    | Used when AUTH_OPAQUE_ERRORS is null.
    | true  => hide whether an account/challenge exists in forgot-password flow.
    | false => allow explicit forgot-password service errors for debugging.
    |
    */
    'forgot_password_opaque_errors' => filter_var(env('FORGOT_PASSWORD_OPAQUE_ERRORS', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Login Account Lockout
    |--------------------------------------------------------------------------
    |
    | Disabled by default so rollout can happen safely. When enabled, only real
    | account credential failures mutate lockout counters; missing accounts
    | still take the dummy hash path for enumeration resistance.
    |
    */
    'login_lock_enabled' => filter_var(env('LOGIN_LOCK_ENABLED', false), FILTER_VALIDATE_BOOL),
    'login_lock_attempts' => (int) env('LOGIN_LOCK_ATTEMPTS', 3),
    'login_lock_minutes' => (int) env('LOGIN_LOCK_MINUTES', 1440),

    /*
    |--------------------------------------------------------------------------
    | Login Payload Disclosure
    |--------------------------------------------------------------------------
    |
    | Include admin roles, permissions, and permissions_by_module on login responses.
    | Set LOGIN_REVEAL_PERMISSIONS=false to omit them.
    |
    */
    'login_reveal_permissions' => filter_var(env('LOGIN_REVEAL_PERMISSIONS', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Activity Tracking
    |--------------------------------------------------------------------------
    |
    | Throttle writes to avoid updating the database on every API request while
    | still providing useful "recently active" telemetry.
    |
    */
    'last_active_update_interval_seconds' => (int) env('LAST_ACTIVE_UPDATE_INTERVAL_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | API transport encryption (RequestResponseEncryptionMiddleware)
    |--------------------------------------------------------------------------
    |
    | Per-consumer behaviour is stored on api_users.encryption_mode. The values
    | below control middleware registration and defaults for new consumers.
    |
    | Modes: both | request_only | response_only
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Encryption override users (dev / support)
    |--------------------------------------------------------------------------
    |
    | When OVERRIDE_USERS is true, configured emails receive plaintext payloads only
    | on authenticated requests (valid Bearer token). Login and other auth routes
    | remain fully encrypted.
    |
    */
    'override_users' => [
        'enabled' => filter_var(env('OVERRIDE_USERS', false), FILTER_VALIDATE_BOOL),
        'emails' => [
            'admin-override@yopmail.com',
            'customer-override@yopmail.com',
        ],
    ],

    'api_encryption' => [
        'middleware_enabled' => filter_var(env('API_ENCRYPTION_MIDDLEWARE_ENABLED', false), FILTER_VALIDATE_BOOL),
        'default_mode' => env('API_ENCRYPTION_DEFAULT_MODE', 'both'),
        /*
        | When true (never honored in production), encrypted responses may include a
        | `preview` key with the plaintext JSON decoded from the body before encryption
        | (see JsonResponser::encryptedResponsePreviewEnabled()).
        */
        'response_preview' => filter_var(
            env(
                'API_ENCRYPTION_RESPONSE_PREVIEW',
                in_array(env('APP_ENV', 'production'), ['local', 'dev', 'development', 'staging'], true) ? '1' : '0'
            ),
            FILTER_VALIDATE_BOOL
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Developer: register API consumers (email with keys)
    |--------------------------------------------------------------------------
    |
    | Routes (all require X-Dev-Api-User-Secret; crypto helpers also require X-ClientKey):
    | POST /api/v1/dev/api-users
    | POST /api/v1/dev/crypto/encrypt   body: { "plaintext": "..." }
    | POST /api/v1/dev/crypto/decrypt   body: { "response": "<base64 from response envelope>" }
    |
    */
    'api_user_dev_registration' => [
        'enabled' => filter_var(env('API_USER_DEV_REGISTRATION_ENABLED', false), FILTER_VALIDATE_BOOL),
        'secret' => env('API_USER_DEV_REGISTRATION_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | ApiUserSeeder
    |--------------------------------------------------------------------------
    */
    'api_user_seeder' => [
        'email' => env('API_USER_SEEDER_EMAIL', 'api-seed@example.com'),
    ],
];

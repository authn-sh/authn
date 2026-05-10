<?php

declare(strict_types=1);

/**
 * SMS pipeline configuration (PLAN §15.2). Mirror of `authn-mail.php`.
 *
 * The driver applied per request is resolved by `App\Sms\DriverManager` —
 * `Environment.user_settings.sms.driver` may override the global default.
 */
return [
    'default_driver' => env('AUTHN_SMS_DRIVER', 'null'),

    'default_from_number' => env('AUTHN_DEFAULT_FROM_NUMBER'),

    /**
     * Per-driver settings. Each driver pulls only its own block.
     */
    'drivers' => [
        'twilio' => [
            'account_sid' => env('AUTHN_SMS_TWILIO_ACCOUNT_SID'),
            'auth_token' => env('AUTHN_SMS_TWILIO_AUTH_TOKEN'),
            'endpoint' => env(
                'AUTHN_SMS_TWILIO_ENDPOINT',
                'https://api.twilio.com/2010-04-01/Accounts/{AccountSid}/Messages.json',
            ),
        ],
        'vonage' => [
            'api_key' => env('AUTHN_SMS_VONAGE_API_KEY'),
            'api_secret' => env('AUTHN_SMS_VONAGE_API_SECRET'),
            'endpoint' => env('AUTHN_SMS_VONAGE_ENDPOINT', 'https://rest.nexmo.com/sms/json'),
        ],
        'null' => [
            // Logs the rendered body to the queue worker; useful in dev / CI.
        ],
    ],

    'retry_attempts' => 3,

    'debounce_seconds' => 60,

    /**
     * The `+1 (555) 555-0100`–`0199` reserved E.164 range short-circuits
     * to a fixed test code (PLAN §9.11). The fixed code lets pest assert
     * the whole sign-up / sign-in flow without standing up a real SMS
     * gateway.
     */
    'test_code' => env('AUTHN_SMS_TEST_CODE', '424242'),
];

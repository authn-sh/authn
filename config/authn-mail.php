<?php

declare(strict_types=1);

/**
 * Email pipeline configuration (PLAN §11).
 *
 * The driver applied per request is resolved by App\Mail\DriverManager —
 * Environment.user_settings.mail.driver may override the global default.
 */
return [
    'default_driver' => env('AUTHN_MAIL_DRIVER', 'smtp'),

    'default_from' => [
        'email' => env('AUTHN_DEFAULT_FROM_EMAIL', 'noreply@authn.local'),
        'name' => env('AUTHN_DEFAULT_FROM_NAME', 'Authn'),
    ],

    /**
     * Per-driver settings. Each driver pulls only its own block.
     */
    'drivers' => [
        'resend' => [
            'api_key' => env('RESEND_API_KEY'),
            'endpoint' => env('RESEND_ENDPOINT', 'https://api.resend.com/emails'),
        ],
        'postmark' => [
            'api_key' => env('POSTMARK_API_KEY'),
            'endpoint' => env('POSTMARK_ENDPOINT', 'https://api.postmarkapp.com/email'),
            'message_stream' => env('POSTMARK_MESSAGE_STREAM', 'outbound'),
        ],
        'ses' => [
            // SesV2 SendEmail signed via SigV4 (we sign the request manually
            // so we don't pull the AWS PHP SDK for one endpoint).
            'region' => env('AWS_REGION', 'us-east-1'),
            'access_key_id' => env('AWS_ACCESS_KEY_ID'),
            'secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
        ],
        'smtp' => [
            // Falls through to Laravel's `mail.mailers.smtp` config block.
        ],
    ],

    /**
     * Path to the mjml Node CLI binary (npm install -g mjml). The renderer
     * shells out once per template-save to compile MJML → HTML; the
     * compiled body is cached on `email_templates.body_html`.
     */
    'mjml_binary' => env('AUTHN_MJML_BINARY', 'mjml'),

    /** Default retry count for queued mail jobs. */
    'retry_attempts' => 3,

    /**
     * Per-(email_id, verification_id) debounce window in seconds. A second
     * send within the window is a no-op.
     */
    'debounce_seconds' => 60,
];

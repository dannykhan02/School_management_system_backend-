<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notification Channel Settings
    |--------------------------------------------------------------------------
    |
    | Controls which notification channels are active.
    | Toggle these in .env without touching code.
    |
    */

    'email_enabled' => env('NOTIFICATIONS_EMAIL_ENABLED', true),
    'sms_enabled'   => env('NOTIFICATIONS_SMS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Africa's Talking — SMS Provider
    |--------------------------------------------------------------------------
    |
    | Sandbox testing:
    |   - Username: sandbox
    |   - API key from: sandbox.africastalking.com
    |   - Messages appear in the AT simulator, NOT real phones
    |   - No number verification needed in sandbox
    |
    | Production:
    |   - Change AT_USERNAME to your real AT account username
    |   - Register a Sender ID at africastalking.com/sms
    |
    */

    'sms' => [
        'username'  => env('AT_USERNAME', 'sandbox'),
        'api_key'   => env('AT_API_KEY'),
        'sender_id' => env('AT_SENDER_ID', ''),
        'sandbox'   => env('AT_USERNAME', 'sandbox') === 'sandbox',
    ],

    /*
    |--------------------------------------------------------------------------
    | SendGrid — Email Provider
    |--------------------------------------------------------------------------
    |
    | Laravel's Mail facade uses MAIL_* env vars automatically via mail.php.
    | For local dev without real keys: set MAIL_MAILER=log in .env
    | — emails write to storage/logs/laravel.log instead of sending.
    |
    */

    'email' => [
        'from_address' => env('MAIL_FROM_ADDRESS'),
        'from_name'    => env('MAIL_FROM_NAME', env('APP_NAME')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-Event Defaults
    |--------------------------------------------------------------------------
    |
    | Fallback defaults for all notification events in the system.
    | Individual EnrollmentSetting rows override these per school per year.
    | When adding new types in future (fees, results, attendance),
    | add their defaults here — one place, consistent pattern.
    |
    */

    'defaults' => [
        'enrollment' => [
            'notify_on_submit'    => true,
            'notify_on_approval'  => true,
            'notify_on_rejection' => true,
            'notify_on_waitlist'  => true,
        ],
        // Future use:
        // 'fees'       => ['notify_on_due' => true, 'notify_on_overdue' => true],
        // 'results'    => ['notify_on_publish' => true],
        // 'attendance' => ['notify_on_absence' => true],
    ],

];
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file stores credentials for third-party services used by the app.
    | All values are pulled from .env — never hardcode keys here.
    |
    */

    // ── Existing Laravel defaults (keep these) ────────────────────────────────

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // ── Africa's Talking — SMS ────────────────────────────────────────────────
    // ✅ TESTED AND WORKING — sandbox mode confirmed via tinker
    // Sandbox: AT_USERNAME=sandbox, API key from sandbox.africastalking.com
    // Production: change AT_USERNAME to your real AT account username
    // Used by: App\Services\SmsService
    'africastalking' => [
        'username'  => env('AT_USERNAME', 'sandbox'),
        'api_key'   => env('AT_API_KEY'),
        'sender_id' => env('AT_SENDER_ID', ''),
    ],

    // ── Gmail SMTP — Email ────────────────────────────────────────────────────
    // ✅ TESTED AND WORKING — confirmed via tinker (SentMessage returned)
    // Uses Gmail App Password via standard MAIL_* env vars in .env
    // Laravel's Mail facade handles everything via config/mail.php automatically
    // No extra config needed here — entry kept for reference and upgrade path
    //
    // ⚠️  PRODUCTION UPGRADE PATH (when going live with real schools):
    // Replace Gmail with SendGrid + authenticated custom domain:
    //   MAIL_HOST=smtp.sendgrid.net
    //   MAIL_USERNAME=apikey
    //   MAIL_PASSWORD=SG.your_real_api_key
    //   MAIL_FROM_ADDRESS=noreply@yourdomain.co.ke
    // Then authenticate the domain in SendGrid to pass DMARC checks.
    'gmail' => [
        'address' => env('MAIL_FROM_ADDRESS'),
    ],

];
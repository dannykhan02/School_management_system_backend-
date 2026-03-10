<?php

namespace App\Services;

use AfricasTalking\SDK\AfricasTalking;
use Illuminate\Support\Facades\Log;

class SmsService
{
    protected mixed $sms       = null;
    protected bool  $isSandbox = true;

    public function __construct()
    {
        // env() reads directly from .env — more reliable than config()
        // when notifications.php config file might not be cached yet
        $username        = env('AT_USERNAME', 'sandbox');
        $apiKey          = env('AT_API_KEY');
        $this->isSandbox = ($username === 'sandbox');

        if ($username && $apiKey) {
            $at        = new AfricasTalking($username, $apiKey);
            $this->sms = $at->sms();
        }
    }

    /**
     * Send an SMS via Africa's Talking.
     *
     * Sandbox mode (AT_USERNAME=sandbox in .env):
     *   - Messages go to AT simulator at sandbox.africastalking.com/simulator
     *   - No real phone receives anything — completely free
     *   - Use this for all local development and testing
     *
     * Production mode:
     *   - Real SMS delivered to the number
     *   - Charges apply per message
     *
     * Fails silently with a log entry — SMS is best-effort.
     * Email is the primary reliable channel.
     *
     * @param  string  $to       E.164 format e.g. "+254712345678"
     * @param  string  $message  Keep under 160 chars for single SMS billing
     * @return bool
     */
    public function send(string $to, string $message): bool
    {
        // Feature flag — falls back to true if notifications.php not loaded yet
        $smsEnabled = config('notifications.channels.sms', true);

        if (! $smsEnabled) {
            Log::info("SMS channel disabled. Skipping send to {$to}");
            return false;
        }

        if (! $this->sms) {
            Log::warning("SMS not sent: Africa's Talking not initialised. Check AT_USERNAME and AT_API_KEY in .env");
            return false;
        }

        try {
            $params = [
                'to'      => $to,
                'message' => $message,
            ];

            $senderId = env('AT_SENDER_ID', '');
            if (! empty($senderId)) {
                $params['from'] = $senderId;
            }

            $result    = $this->sms->send($params);
            $recipient = $result['data']->SMSMessageData->Recipients[0] ?? null;

            if ($recipient && $recipient->status === 'Success') {
                Log::info("SMS sent via Africa's Talking to {$to}" . ($this->isSandbox ? ' [SANDBOX]' : ''));
                return true;
            }

            Log::warning("AT SMS to {$to} returned non-success status: " . json_encode($result));
            return false;

        } catch (\Exception $e) {
            // Never let an SMS failure crash the enrollment flow
            Log::error("Africa's Talking SMS failed to {$to}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Format any Kenyan phone number to E.164 (+254) format.
     *
     * Handles:
     *   0712345678    → +254712345678
     *   254712345678  → +254712345678
     *   +254712345678 → +254712345678 (already correct)
     */
    public function formatKenyanNumber(string $phone): string
    {
        $phone = preg_replace('/[\s\-\(\)]/', '', $phone);

        if (str_starts_with($phone, '+254')) return $phone;
        if (str_starts_with($phone, '254'))  return '+' . $phone;
        if (str_starts_with($phone, '0'))    return '+254' . substr($phone, 1);

        return $phone;
    }

    public function isSandbox(): bool
    {
        return $this->isSandbox;
    }
}
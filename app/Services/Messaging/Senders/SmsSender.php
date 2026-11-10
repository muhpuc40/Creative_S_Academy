<?php
namespace App\Services\Messaging\Senders;
use App\Models\SmsSetting;
use App\Services\Messaging\ChannelSender;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;


class SmsSender implements ChannelSender
{
    public function send(string $to, string $body, ?string $subject = null): void
    {
        $this->call($this->normalize($to), $body);
    }

    public function sendBulk(array $numbers, string $body): int
    {
        $numbers = array_values(array_unique(array_map(fn($n) => $this->normalize($n), $numbers)));

        if (!$numbers) {
            return 0;
        }

        $this->call(implode(',', $numbers), $body);

        return count($numbers);
    }

    private function call(string $numbers, string $message): void
    {
        $message = trim($message);

        $sms = SmsSetting::current();

        if (! $sms->sends()) {
            Log::channel('app_file')->info("SMS to {$numbers}: {$message}");
            return;
        }

        if (! $sms->api_key || ! $sms->sender_id) {
            throw new RuntimeException('SMS failed: the API key or sender ID is missing (System > SMS).');
        }

        $response = Http::timeout(20)->withQueryParameters([
            'api_key' => $sms->api_key,
            'type' => 'text',
            'number' => $numbers,
            'senderid' => $sms->sender_id,
            'message' => $message,
        ])->post($sms->url);

        if (!$response->successful() || (int) $response->json('response_code') !== 202) {
            throw new RuntimeException('SMS failed: ' . $response->body());
        }
    }

    /** Gateway codes that can come back from the balance API. */
    private const ERRORS = [
        1003 => 'a required field is missing',
        1005 => 'internal server error at the gateway',
        1011 => 'user not found (check the API key)',
        1018 => 'the gateway account is disabled',
        1031 => 'the gateway account is not verified',
        1032 => 'this server\'s IP address is not whitelisted at the gateway',
    ];

    /** The account balance in taka, from the gateway's balance API. */
    public function balance(): float
    {
        $sms = SmsSetting::current();

        if (! $sms->api_key) {
            throw new RuntimeException('Save the API key first.');
        }

        try {
            $response = Http::timeout(10)->asForm()->post($sms->balanceUrl(), ['api_key' => $sms->api_key]);
        } catch (HttpClientException) {
            throw new RuntimeException('Could not reach the SMS gateway. Try again in a minute.');
        }

        $json = $response->json();
        $balance = is_array($json) ? ($json['balance'] ?? null) : trim($response->body());

        if ($response->successful() && is_numeric($balance)) {
            return (float) $balance;
        }

        $code = is_array($json) ? (int) ($json['response_code'] ?? $json['error_code'] ?? 0) : 0;

        throw new RuntimeException($code
            ? 'The gateway refused: ' . (self::ERRORS[$code] ?? "code {$code}") . '.'
            : 'The gateway did not return a balance (HTTP ' . $response->status() . ').');
    }

    private function normalize(string $number): string
    {
        $number = preg_replace('/\D+/', '', $number);

        return str_starts_with($number, '01') ? '88' . $number : $number;
    }
}
<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class TelegramClient
{
    public function sendMessage(int|string $chatId, string $text): void
    {
        $this->http()->post('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ])->throw();
    }

    public function setWebhook(string $url, ?string $secretToken = null): array
    {
        $payload = ['url' => $url];

        if ($secretToken) {
            $payload['secret_token'] = $secretToken;
        }

        return $this->http()->post('setWebhook', $payload)->throw()->json();
    }

    private function http(): PendingRequest
    {
        $token = config('services.telegram.bot_token');

        if (!$token) {
            throw new \RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        return Http::baseUrl("https://api.telegram.org/bot{$token}")
            ->acceptJson()
            ->asJson()
            ->timeout(10);
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

class TelegramSetWebhook extends Command
{
    protected $signature = 'telegram:set-webhook';

    protected $description = 'Register the Telegram bot webhook URL.';

    public function handle(TelegramClient $telegram): int
    {
        $secret = config('services.telegram.webhook_secret');
        $url = rtrim((string) config('app.url'), '/').'/webhooks/telegram';

        $response = $telegram->setWebhook($url, $secret);

        $this->info(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}

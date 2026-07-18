<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramClient $telegram): JsonResponse
    {
        if (!$this->hasValidSecret($request)) {
            abort(403);
        }

        $update = $request->all();
        $message = $update['message'] ?? null;

        if ($message) {
            $chatId = $message['chat']['id'] ?? null;
            $text = trim((string) ($message['text'] ?? ''));

            if ($chatId && $text === '/start') {
                $telegram->sendMessage(
                    $chatId,
                    'Здравствуйте. Для входа отправьте номер договора и телефон.'
                );
            }
        }

        Log::info('Telegram update received', [
            'update_id' => $update['update_id'] ?? null,
        ]);

        return response()->json(['ok' => true]);
    }

    private function hasValidSecret(Request $request): bool
    {
        $secret = config('services.telegram.webhook_secret');

        if (!$secret) {
            return true;
        }

        return hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'));
    }
}

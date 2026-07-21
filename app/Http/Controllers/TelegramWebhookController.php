<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramBotService $bot): JsonResponse
    {
        if (!$this->hasValidSecret($request)) {
            abort(403);
        }

        $update = $request->all();
        $message = $update['message'] ?? null;
        $callbackQuery = $update['callback_query'] ?? null;

        if ($message) {
            $bot->handleMessage($message);
        }

        if ($callbackQuery) {
            $bot->handleCallbackQuery($callbackQuery);
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

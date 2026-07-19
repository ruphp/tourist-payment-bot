<?php

namespace App\Services\Telegram;

use App\Models\TelegramUser;
use App\Services\Uon\UonRequestService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

class TelegramBotService
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly UonRequestService $uonRequests,
    ) {
    }

    public function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'] ?? null;

        if (!$chatId) {
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));
        $user = $this->telegramUser($message);

        if ($text === '/start') {
            $this->start($user, $chatId);
            return;
        }

        if (in_array($text, ['/logout', 'Выйти'], true)) {
            $this->logout($user, $chatId);
            return;
        }

        if (in_array($text, ['/status', 'Статус'], true)) {
            $this->sendStatus($user, $chatId);
            return;
        }

        match ($user->state) {
            'awaiting_phone' => $this->handlePhone($user, $chatId, $text),
            'authorized' => $this->sendStatus($user, $chatId),
            default => $this->handleContractNumber($user, $chatId, $text),
        };
    }

    private function start(TelegramUser $user, int|string $chatId): void
    {
        $binding = $user->uonBinding;

        if ($binding) {
            $user->forceFill([
                'state' => 'authorized',
                'state_data' => [],
            ])->save();

            $this->telegram->sendMessage(
                $chatId,
                "Вы уже вошли по договору/заявке: ".$binding->contract_number."\nТелефон: ".$binding->phone."\n\nЧтобы перейти к другому договору, отправьте /logout и войдите заново.\n\nДля просмотра текущей информации отправьте /status."
            );
            return;
        }

        $user->forceFill([
            'state' => 'awaiting_contract_number',
            'state_data' => [],
        ])->save();

        $this->telegram->sendMessage(
            $chatId,
            "Здравствуйте.\n\nОтправьте номер договора или заявки."
        );
    }

    private function handleContractNumber(TelegramUser $user, int|string $chatId, string $text): void
    {
        if ($text === '') {
            $this->telegram->sendMessage($chatId, 'Отправьте номер договора или заявки.');
            return;
        }

        $user->forceFill([
            'state' => 'awaiting_phone',
            'state_data' => ['contract_number' => $text],
        ])->save();

        $this->telegram->sendMessage(
            $chatId,
            "Теперь отправьте телефон туриста, указанный в договоре.\n\nНапример: +7 999 123-45-67"
        );
    }

    private function handlePhone(TelegramUser $user, int|string $chatId, string $text): void
    {
        if (!$this->uonRequests->isConfigured()) {
            $this->telegram->sendMessage(
                $chatId,
                'Интеграция с U-ON еще не настроена. Нужен UON_API_KEY.'
            );
            return;
        }

        $contractNumber = (string) ($user->state_data['contract_number'] ?? '');

        if ($contractNumber === '') {
            $this->start($user, $chatId);
            return;
        }

        try {
            $binding = $this->uonRequests->authorize($user, $contractNumber, $text);
        } catch (RequestException $exception) {
            Log::error('U-ON authorization request failed', [
                'telegram_user_id' => $user->id,
                'status' => $exception->response->status(),
                'body' => $exception->response->body(),
            ]);

            $this->telegram->sendMessage(
                $chatId,
                $this->uonErrorMessage($exception)
            );
            return;
        } catch (\Throwable $exception) {
            Log::error('U-ON authorization failed', [
                'telegram_user_id' => $user->id,
                'exception' => $exception,
            ]);

            $this->telegram->sendMessage(
                $chatId,
                'Не удалось обратиться к U-ON. Попробуйте позже.'
            );
            return;
        }

        if (!$binding) {
            $user->forceFill([
                'state' => 'awaiting_contract_number',
                'state_data' => [],
            ])->save();

            $this->telegram->sendMessage(
                $chatId,
                "Не нашел заявку с таким номером и телефоном.\n\nПроверьте данные и отправьте номер договора еще раз."
            );
            return;
        }

        $user->forceFill([
            'state' => 'authorized',
            'state_data' => [],
        ])->save();

        $this->telegram->sendMessage($chatId, $this->uonRequests->formatSummary($binding));
    }

    private function sendStatus(TelegramUser $user, int|string $chatId): void
    {
        $binding = $user->uonBinding;

        if (!$binding) {
            $this->start($user, $chatId);
            return;
        }

        if (!$this->uonRequests->isConfigured()) {
            $this->telegram->sendMessage(
                $chatId,
                'Интеграция с U-ON еще не настроена. Нужен UON_API_KEY.'
            );
            return;
        }

        try {
            $binding = $this->uonRequests->refresh($binding);
            $this->telegram->sendMessage($chatId, $this->uonRequests->formatSummary($binding));
        } catch (RequestException $exception) {
            Log::error('U-ON status refresh request failed', [
                'telegram_user_id' => $user->id,
                'status' => $exception->response->status(),
                'body' => $exception->response->body(),
            ]);

            $this->telegram->sendMessage(
                $chatId,
                $this->uonErrorMessage($exception)
            );
        } catch (\Throwable $exception) {
            Log::error('U-ON status refresh failed', [
                'telegram_user_id' => $user->id,
                'exception' => $exception,
            ]);

            $this->telegram->sendMessage(
                $chatId,
                'Не удалось обновить данные из U-ON. Попробуйте позже.'
            );
        }
    }

    private function logout(TelegramUser $user, int|string $chatId): void
    {
        $user->uonBinding()->delete();
        $user->forceFill([
            'state' => 'awaiting_contract_number',
            'state_data' => [],
        ])->save();

        $this->telegram->sendMessage(
            $chatId,
            'Привязка сброшена. Отправьте номер договора или заявки, к которому хотите перейти.'
        );
    }

    private function telegramUser(array $message): TelegramUser
    {
        $from = $message['from'] ?? [];

        $telegramId = $from['id'] ?? $message['chat']['id'] ?? null;

        if (!$telegramId) {
            throw new \RuntimeException('Telegram user id is missing.');
        }

        return TelegramUser::query()->updateOrCreate(
            ['telegram_id' => $telegramId],
            [
                'username' => $from['username'] ?? null,
                'first_name' => $from['first_name'] ?? null,
                'last_name' => $from['last_name'] ?? null,
            ]
        );
    }

    private function uonErrorMessage(RequestException $exception): string
    {
        $body = $exception->response->body();

        if ($exception->response->status() === 406 && str_contains($body, 'API is not active')) {
            return 'API в U-ON еще не активирован. Нужно включить API в настройках U-ON и сохранить API-ключ.';
        }

        if (in_array($exception->response->status(), [401, 403], true)) {
            return 'U-ON не принял API-ключ или IP сервера. Нужно проверить ключ и разрешенный IP.';
        }

        return 'Не удалось обратиться к U-ON. Попробуйте позже.';
    }
}

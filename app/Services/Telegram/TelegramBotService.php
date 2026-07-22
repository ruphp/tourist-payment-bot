<?php

namespace App\Services\Telegram;

use App\Models\TelegramUser;
use App\Models\TochkaPayment;
use App\Services\Tochka\TochkaPaymentService;
use App\Services\Uon\UonRequestService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

class TelegramBotService
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly UonRequestService $uonRequests,
        private readonly TochkaPaymentService $tochkaPayments,
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
            'awaiting_payment_amount' => $this->handlePaymentAmount($user, $chatId, $text),
            'authorized' => $this->sendStatus($user, $chatId),
            default => $this->handleContractNumber($user, $chatId, $text),
        };
    }

    public function handleCallbackQuery(array $callbackQuery): void
    {
        $id = (string) ($callbackQuery['id'] ?? '');
        $message = $callbackQuery['message'] ?? [];
        $chatId = $message['chat']['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');

        if ($id !== '') {
            $this->telegram->answerCallbackQuery($id);
        }

        if (!$chatId) {
            return;
        }

        $user = $this->telegramUser([
            'from' => $callbackQuery['from'] ?? [],
            'chat' => $message['chat'] ?? [],
        ]);

        match ($data) {
            'status' => $this->sendStatus($user, $chatId),
            'logout' => $this->logout($user, $chatId),
            'pay' => $this->startPayment($user, $chatId),
            default => null,
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
                "Вы уже вошли по договору/заявке: ".$binding->contract_number."\nТелефон: ".$binding->phone,
                $this->statusKeyboard()
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
            "Теперь отправьте телефон туриста, указанный в договоре.\n\nНапример: 79991234567"
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

        $this->telegram->sendMessage(
            $chatId,
            'Подождите, идет проверка данных...'
        );

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

        $binding = $this->uonRequests->refresh($binding);

        $this->telegram->sendMessage($chatId, $this->uonRequests->formatSummary($binding), $this->statusKeyboard());
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
            $this->telegram->sendMessage($chatId, $this->uonRequests->formatSummary($binding), $this->statusKeyboard());
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

    private function startPayment(TelegramUser $user, int|string $chatId): void
    {
        $binding = $user->uonBinding;

        if (!$binding) {
            $this->start($user, $chatId);
            return;
        }

        if (!$this->tochkaPayments->isConfigured()) {
            $this->telegram->sendMessage($chatId, 'Оплата через бот еще не настроена.');
            return;
        }

        if (!$this->tochkaPayments->canAcceptNow()) {
            $this->telegram->sendMessage(
                $chatId,
                'Платежи принимаются с '.config('payments.accept_from', '07:00').' до '.config('payments.accept_until', '17:00').' по МСК.'
            );
            return;
        }

        if ($pending = $this->tochkaPayments->pendingPayment($binding)) {
            $this->sendPaymentLink($chatId, $pending);
            return;
        }

        try {
            $binding = $this->uonRequests->refresh($binding);
        } catch (\Throwable $exception) {
            Log::error('U-ON refresh before payment failed', [
                'telegram_user_id' => $user->id,
                'exception' => $exception,
            ]);

            $this->telegram->sendMessage($chatId, 'Не удалось обновить остаток по заявке. Попробуйте позже.');
            return;
        }

        $balance = $this->uonRequests->balanceRubles($binding);

        if ($balance <= 0) {
            $this->telegram->sendMessage($chatId, 'По заявке нет остатка к оплате.', $this->statusKeyboard());
            return;
        }

        $user->forceFill([
            'state' => 'awaiting_payment_amount',
            'state_data' => ['uon_binding_id' => $binding->id],
        ])->save();

        $this->telegram->sendMessage(
            $chatId,
            "Введите сумму оплаты в рублях.\n\nОстаток: ".$this->uonRequests->formatRubles($balance)
        );
    }

    private function handlePaymentAmount(TelegramUser $user, int|string $chatId, string $text): void
    {
        $binding = $user->uonBinding;

        if (!$binding) {
            $this->start($user, $chatId);
            return;
        }

        if (!$this->tochkaPayments->isConfigured()) {
            $user->forceFill(['state' => 'authorized', 'state_data' => []])->save();
            $this->telegram->sendMessage($chatId, 'Оплата через бот еще не настроена.');
            return;
        }

        if (!$this->tochkaPayments->canAcceptNow()) {
            $user->forceFill(['state' => 'authorized', 'state_data' => []])->save();
            $this->telegram->sendMessage(
                $chatId,
                'Платежи принимаются с '.config('payments.accept_from', '07:00').' до '.config('payments.accept_until', '17:00').' по МСК.',
                $this->statusKeyboard()
            );
            return;
        }

        $amount = $this->parseAmount($text);

        if ($amount === null) {
            $this->telegram->sendMessage($chatId, 'Введите сумму цифрами, например: 10000');
            return;
        }

        try {
            $binding = $this->uonRequests->refresh($binding);
        } catch (\Throwable $exception) {
            Log::error('U-ON refresh before payment amount failed', [
                'telegram_user_id' => $user->id,
                'exception' => $exception,
            ]);

            $this->telegram->sendMessage($chatId, 'Не удалось обновить остаток по заявке. Попробуйте позже.');
            return;
        }

        $balance = $this->uonRequests->balanceRubles($binding);

        if ($amount > $balance) {
            $this->telegram->sendMessage(
                $chatId,
                'Сумма больше остатка. Остаток: '.$this->uonRequests->formatRubles($balance)
            );
            return;
        }

        $this->telegram->sendMessage($chatId, 'Создаю ссылку на оплату...');

        try {
            $payment = $this->tochkaPayments->createLink($binding, $amount);
        } catch (\Throwable $exception) {
            Log::error('Tochka payment link creation failed', [
                'telegram_user_id' => $user->id,
                'uon_binding_id' => $binding->id,
                'exception' => $exception,
            ]);

            $this->telegram->sendMessage($chatId, 'Не удалось создать ссылку на оплату. Попробуйте позже.');
            return;
        }

        $user->forceFill(['state' => 'authorized', 'state_data' => []])->save();

        $this->sendPaymentLink($chatId, $payment);
    }

    private function sendPaymentLink(int|string $chatId, TochkaPayment $payment): void
    {
        if (!$payment->payment_url) {
            $this->telegram->sendMessage($chatId, 'Ссылка создана, но Точка не вернула URL. Сообщите менеджеру.');
            return;
        }

        $this->telegram->sendMessage(
            $chatId,
            "Ссылка на оплату создана.\n\nСумма: ".$this->uonRequests->formatRubles((float) $payment->amount)."\nПосле оплаты данные обновятся после подтверждения банка.",
            [
                'inline_keyboard' => [
                    [
                        ['text' => 'Оплатить через СБП', 'url' => $payment->payment_url],
                    ],
                    [
                        ['text' => 'Обновить данные', 'callback_data' => 'status'],
                    ],
                ],
            ]
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

    private function statusKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => 'Оплатить', 'callback_data' => 'pay'],
                    ['text' => 'Обновить данные', 'callback_data' => 'status'],
                    ['text' => 'Другой договор', 'callback_data' => 'logout'],
                ],
            ],
        ];
    }

    private function parseAmount(string $text): ?float
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($text));

        if (!is_numeric($normalized)) {
            return null;
        }

        $amount = round((float) $normalized, 2);

        return $amount > 0 ? $amount : null;
    }
}

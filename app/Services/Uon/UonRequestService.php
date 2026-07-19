<?php

namespace App\Services\Uon;

use App\Models\TelegramUser;
use App\Models\UonBinding;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class UonRequestService
{
    public function __construct(private readonly UonClient $client)
    {
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function authorize(TelegramUser $telegramUser, string $contractNumber, string $phone): ?UonBinding
    {
        $request = $this->findByContractNumber($contractNumber);

        if (!$request || !$this->phoneMatches($request, $phone)) {
            return null;
        }

        return UonBinding::query()->updateOrCreate(
            [
                'telegram_user_id' => $telegramUser->id,
            ],
            [
                'uon_request_id' => (string) $this->value($request, ['id', 'id_system', 'r_id']),
                'contract_number' => $contractNumber,
                'phone' => $phone,
                'uon_client_id' => $this->value($request, ['client_id']),
                'last_request_snapshot' => $request,
                'last_synced_at' => now(),
            ]
        );
    }

    public function refresh(UonBinding $binding): UonBinding
    {
        $request = $this->client->getRequest($binding->uon_request_id);

        if ($request) {
            $binding->forceFill([
                'last_request_snapshot' => $request,
                'last_synced_at' => now(),
            ])->save();
        }

        return $binding->refresh();
    }

    public function formatSummary(UonBinding $binding): string
    {
        $request = $binding->last_request_snapshot ?? [];

        $title = $this->tourTitle($request);
        $price = $this->number($this->value($request, ['calc_price']));
        $paid = $this->number($this->value($request, ['calc_client', 'calc_increase']));
        $balance = max(0, $price - $paid);
        $currency = $this->currencyLabel($request);
        $deadline = $this->paymentDeadline($binding->uon_request_id);

        $lines = [
            '<b>Заявка найдена</b>',
            'Договор/заявка: '.$this->e($binding->contract_number),
            'Тур: '.$this->e($title),
        ];

        if ($price > 0) {
            $lines[] = 'Стоимость: '.$this->money($price).' '.$this->e($currency);
        }

        $lines[] = 'Оплачено: '.$this->money($paid).' '.$this->e($currency);
        $lines[] = 'Остаток: '.$this->money($balance).' '.$this->e($currency);

        if ($deadline) {
            $lines[] = 'Оплатить до: '.$this->e($deadline);
        }

        $lines[] = '';
        $lines[] = 'Оплату через бот подключим после включения эквайринга Точки.';

        return implode("\n", $lines);
    }

    private function findByContractNumber(string $contractNumber): ?array
    {
        $number = trim($contractNumber);

        if (ctype_digit($number)) {
            $request = $this->client->getRequest($number);

            if ($request) {
                return $request;
            }
        }

        foreach (['r_id_internal', 'id_internal', 'reservation_number', 'r_id_system', 'id_system'] as $field) {
            $matches = $this->client->searchRequests([$field => $number]);

            if ($matches) {
                return $matches[0];
            }
        }

        return null;
    }

    private function phoneMatches(array $request, string $phone): bool
    {
        $expectedPhones = array_filter([
            $this->value($request, ['client_phone']),
            $this->value($request, ['client_phone_mobile']),
        ]);

        $given = $this->normalizePhone($phone);

        foreach ($expectedPhones as $expectedPhone) {
            $expected = $this->normalizePhone((string) $expectedPhone);

            if ($given && $expected && substr($given, -10) === substr($expected, -10)) {
                return true;
            }
        }

        return false;
    }

    private function paymentDeadline(string|int $requestId): ?string
    {
        $deadlines = $this->client->getDeadlines($requestId);

        foreach ($deadlines as $deadline) {
            $date = $this->value($deadline, ['date', 'deadline', 'date_pay', 'pay_date', 'datetime']);

            if ($date) {
                return $this->formatDate((string) $date);
            }
        }

        return null;
    }

    private function tourTitle(array $request): string
    {
        return (string) ($this->value($request, ['travel_type'])
            ?: $this->value($request, ['supplier_name'])
            ?: $this->value($request, ['reservation_number'])
            ?: 'тур');
    }

    private function currencyLabel(array $request): string
    {
        return (string) ($this->value($request, ['currency_code', 'currency'])
            ?: 'руб.');
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function number(mixed $value): float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function money(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }

    private function formatDate(string $date): string
    {
        try {
            return Carbon::parse($date)->format('d.m.Y');
        } catch (\Throwable) {
            return $date;
        }
    }

    private function value(array $source, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = Arr::get($source, $key);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

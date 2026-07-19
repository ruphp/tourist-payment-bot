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
        $request = $this->findByContractNumber($binding->contract_number)
            ?: $this->client->getRequest($binding->uon_request_id);

        if ($request && $this->hasUsefulFinancialData($request)) {
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

        $operator = $this->tourOperator($request);
        $reservationNumber = $this->reservationNumber($request);
        $country = $this->country($request);
        $hotel = $this->hotel($request);
        $dateBegin = $this->tourDate($request, ['date_begin', 'tour_date_begin', 'date_from']);
        $dateEnd = $this->tourDate($request, ['date_end', 'tour_date_end', 'date_to']);
        $price = $this->number($this->value($request, ['calc_price']));
        $paid = $this->number($this->value($request, ['calc_client', 'calc_increase']));
        $balance = max(0, $price - $paid);
        $currency = $this->currencyLabel($request);
        $tourCurrency = $this->tourCurrencySummary($request, $price, $paid, $balance);
        $deadline = $this->paymentDeadline($binding->uon_request_id);

        $lines = [
            '<b>Заявка найдена</b>',
            'Договор/заявка: '.$this->e($binding->contract_number),
            'Туроператор: '.$this->e($operator),
            'Номер бронирования: '.$this->e($reservationNumber),
            'Страна: '.$this->e($country),
            'Отель: '.$this->e($hotel),
            'Дата начала тура: '.$this->e($dateBegin),
            'Дата окончания тура: '.$this->e($dateEnd),
        ];

        if ($price > 0) {
            $lines[] = 'Стоимость: '.$this->money($price).' '.$this->e($currency);
        }

        if ($tourCurrency) {
            $lines[] = 'Стоимость в валюте тура: '.$this->money($tourCurrency['price']).' '.$this->e($tourCurrency['currency']);

            if ($tourCurrency['rate'] !== null) {
                $lines[] = 'Курс U-ON: '.$this->money($tourCurrency['rate']).' руб.';
            }

            if ($tourCurrency['paid'] !== null) {
                $lines[] = 'Оплачено в валюте: '.$this->money($tourCurrency['paid']).' '.$this->e($tourCurrency['currency']);
            }

            if ($tourCurrency['balance'] !== null) {
                $lines[] = 'Остаток в валюте тура: '.$this->money($tourCurrency['balance']).' '.$this->e($tourCurrency['currency']);
            }
        }

        $lines[] = 'Оплачено: '.$this->money($paid).' '.$this->e($currency);
        $lines[] = 'Остаток: '.$this->money($balance).' '.$this->e($currency);

        if ($deadline) {
            $lines[] = 'Оплатить до: '.$this->e($deadline);
        }

        $lines[] = '';
        $lines[] = $this->paymentWindowText();
        $lines[] = 'Чтобы посмотреть другой договор, отправьте /logout и войдите заново.';
        $lines[] = 'Оплату через бот подключим после включения эквайринга Точки.';

        return implode("\n", $lines);
    }

    private function findByContractNumber(string $contractNumber): ?array
    {
        $number = trim($contractNumber);

        foreach (['r_id_internal', 'id_internal', 'reservation_number', 'r_id_system', 'id_system'] as $field) {
            $matches = $this->client->searchRequests([$field => $number]);

            if ($matches) {
                return $matches[0];
            }
        }

        if (ctype_digit($number)) {
            $request = $this->client->getRequest($number);

            if ($request) {
                return $request;
            }
        }

        return null;
    }

    private function hasUsefulFinancialData(array $request): bool
    {
        return $this->number($this->value($request, ['calc_price'])) > 0
            || $this->number($this->value($request, ['calc_client', 'calc_increase'])) > 0
            || !empty($request['services']);
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

    private function tourOperator(array $request): string
    {
        return (string) ($this->value($request, ['supplier_name', 'operator_name', 'tour_operator'])
            ?: $this->valueFromServices($request, ['supplier_name', 'operator_name', 'tour_operator'])
            ?: 'не указано');
    }

    private function reservationNumber(array $request): string
    {
        return (string) ($this->value($request, ['reservation_number', 'booking_number', 'bron_number', 'supplier_booking_number'])
            ?: $this->valueFromServices($request, ['reservation_number', 'booking_number', 'bron_number', 'supplier_booking_number'])
            ?: 'не указано');
    }

    private function country(array $request): string
    {
        return (string) ($this->value($request, ['country', 'country_name', 'tour_country'])
            ?: $this->valueFromServices($request, ['country', 'country_name', 'tour_country'])
            ?: 'не указано');
    }

    private function hotel(array $request): string
    {
        return (string) ($this->value($request, ['hotel', 'hotel_name', 'hostel', 'placement'])
            ?: $this->valueFromServices($request, ['hotel', 'hotel_name', 'hostel', 'placement'])
            ?: 'не указано');
    }

    private function tourDate(array $request, array $keys): string
    {
        $date = $this->value($request, $keys)
            ?: $this->valueFromServices($request, $keys);

        return $date ? $this->formatDate((string) $date) : 'не указано';
    }

    private function currencyLabel(array $request): string
    {
        return (string) ($this->value($request, ['currency_code', 'currency'])
            ?: 'руб.');
    }

    private function tourCurrencySummary(array $request, float $rubPrice, float $rubPaid, float $rubBalance): ?array
    {
        $currency = $this->tourCurrencyLabel($request);

        if (!$currency) {
            return null;
        }

        $price = $this->tourCurrencyPrice($request);
        $rate = $this->tourCurrencyRate($request);

        if ($price <= 0 && $rate > 0 && $rubPrice > 0) {
            $price = $rubPrice / $rate;
        }

        if ($price <= 0) {
            return null;
        }

        $canConvertRubAmounts = $rate > 1;

        return [
            'currency' => $currency,
            'price' => $price,
            'rate' => $rate > 1 ? $rate : null,
            'paid' => $canConvertRubAmounts && $rubPaid > 0 ? $rubPaid / $rate : null,
            'balance' => $this->tourCurrencyBalance($price, $rubPaid, $rubBalance, $rate),
        ];
    }

    private function tourCurrencyBalance(float $tourCurrencyPrice, float $rubPaid, float $rubBalance, float $rate): ?float
    {
        if ($rate > 1) {
            return $rubBalance / $rate;
        }

        if ($rubPaid <= 0) {
            return $tourCurrencyPrice;
        }

        return null;
    }

    private function tourCurrencyLabel(array $request): ?string
    {
        $currency = (string) ($this->value($request, [
            'tour_currency',
            'tour_currency_code',
            'currency_tour',
            'currency_tour_code',
        ]) ?: '');

        $currency = $this->normalizeCurrencyLabel($currency);

        if ($currency && !$this->isRubCurrency($currency)) {
            return $currency;
        }

        foreach (($request['services'] ?? []) as $service) {
            if (!is_array($service)) {
                continue;
            }

            $currency = $this->normalizeCurrencyLabel(
                (string) ($this->value($service, ['currency_code', 'currency']) ?: '')
            );

            if ($currency && !$this->isRubCurrency($currency)) {
                return $currency;
            }
        }

        return null;
    }

    private function tourCurrencyPrice(array $request): float
    {
        $direct = $this->number($this->value($request, [
            'tour_price_currency',
            'price_currency',
            'currency_price',
            'price_tour_currency',
        ]));

        if ($direct > 0) {
            return $direct;
        }

        $total = 0.0;

        foreach (($request['services'] ?? []) as $service) {
            if (!is_array($service)) {
                continue;
            }

            $currency = $this->normalizeCurrencyLabel(
                (string) ($this->value($service, ['currency_code', 'currency']) ?: '')
            );

            if ($currency === '' || $this->isRubCurrency($currency)) {
                continue;
            }

            $total += $this->number($this->value($service, [
                'price',
                'cost',
                'sum',
                'amount',
                'client_price',
            ]));
        }

        return $total;
    }

    private function tourCurrencyRate(array $request): float
    {
        $rate = $this->number($this->value($request, [
            'koef',
            'rate',
            'currency_rate',
            'course',
        ]));

        if ($rate > 0) {
            return $rate;
        }

        foreach (($request['services'] ?? []) as $service) {
            if (!is_array($service)) {
                continue;
            }

            $rate = $this->number($this->value($service, [
                'koef',
                'rate',
                'currency_rate',
                'course',
            ]));

            if ($rate > 0) {
                return $rate;
            }
        }

        return 0.0;
    }

    private function isRubCurrency(string $currency): bool
    {
        return in_array(mb_strtolower(trim($currency)), ['643', 'rub', 'rur', 'руб', 'руб.', '₽'], true);
    }

    private function normalizeCurrencyLabel(string $currency): string
    {
        $currency = trim($currency);

        return match ($currency) {
            '643' => 'RUB',
            '840' => 'USD',
            '978' => 'EUR',
            default => $currency,
        };
    }

    private function paymentWindowText(): string
    {
        return sprintf(
            'Платежи принимаются с %s до %s по МСК. После %s оплату через бот создать нельзя.',
            config('payments.accept_from', '07:00'),
            config('payments.accept_until', '17:00'),
            config('payments.accept_until', '17:00'),
        );
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

    private function valueFromServices(array $request, array $keys): mixed
    {
        foreach (($request['services'] ?? []) as $service) {
            if (!is_array($service)) {
                continue;
            }

            $value = $this->value($service, $keys);

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

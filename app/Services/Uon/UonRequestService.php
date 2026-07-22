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
        $lines = [
            '<b>Заявка найдена</b>',
            '',
            '<b>Поставщик</b>',
            'Договор/заявка: '.$this->e($binding->contract_number),
            'Туроператор: '.$this->e($operator),
            'Номер бронирования: '.$this->e($reservationNumber),
            '',
            '<b>Параметры</b>',
            'Страна: '.$this->e($country),
            'Отель: '.$this->e($hotel),
            'Дата начала тура: '.$this->e($dateBegin),
            'Дата окончания тура: '.$this->e($dateEnd),
            '',
            '<b>Финансы</b>',
        ];

        if ($price > 0) {
            $lines[] = 'Стоимость: '.$this->money($price).' '.$this->e($currency);
        }

        if ($tourCurrency) {
            $lines[] = 'Стоимость в валюте тура: '.$this->money($tourCurrency['price']).' '.$this->e($tourCurrency['currency']);
        }

        $lines[] = 'Оплачено: '.$this->moneyWithTourCurrency($paid, $currency, $tourCurrency['paid'] ?? null, $tourCurrency['currency'] ?? null);
        $lines[] = 'Остаток: '.$this->moneyWithTourCurrency($balance, $currency, $tourCurrency['balance'] ?? null, $tourCurrency['currency'] ?? null);

        return implode("\n", $lines);
    }

    public function balanceRubles(UonBinding $binding): float
    {
        $request = $binding->last_request_snapshot ?? [];
        $price = $this->number($this->value($request, ['calc_price']));
        $paid = $this->number($this->value($request, ['calc_client', 'calc_increase']));

        return max(0, $price - $paid);
    }

    public function payableRubles(UonBinding $binding): float
    {
        $balance = $this->balanceRubles($binding);
        $request = $binding->last_request_snapshot ?? [];
        $price = $this->number($this->value($request, ['calc_price']));
        $paid = $this->number($this->value($request, ['calc_client', 'calc_increase']));
        $tourCurrency = $this->tourCurrencySummary($request, $price, $paid, $balance);

        if (($tourCurrency['balance'] ?? null) !== null && ($tourCurrency['rate'] ?? null) !== null) {
            return max(0, round($tourCurrency['balance'] * $tourCurrency['rate'], 2));
        }

        return $balance;
    }

    public function formatBalance(UonBinding $binding): string
    {
        $request = $binding->last_request_snapshot ?? [];
        $price = $this->number($this->value($request, ['calc_price']));
        $paid = $this->number($this->value($request, ['calc_client', 'calc_increase']));
        $balance = max(0, $price - $paid);
        $currency = $this->currencyLabel($request);
        $tourCurrency = $this->tourCurrencySummary($request, $price, $paid, $balance);
        $rate = $tourCurrency['rate'] ?? null;
        $tourBalance = $tourCurrency['balance'] ?? null;
        $tourCurrencyLabel = $tourCurrency['currency'] ?? null;
        $payable = $this->payableRubles($binding);

        return implode("\n", [
            '<b>Остаток</b>',
            'Долг: '.$this->moneyWithTourCurrency($balance, $currency, $tourBalance, $tourCurrencyLabel),
            'Курс оператора: '.($rate ? $this->money($rate).' руб.' : 'не найден'),
            'К доплате: '.$this->money($payable).' руб.',
        ]);
    }

    public function formatRubles(float $value): string
    {
        return $this->money($value).' руб.';
    }

    private function findByContractNumber(string $contractNumber): ?array
    {
        $number = trim($contractNumber);

        $fields = $this->contractNumberFields();

        foreach ($fields as $field) {
            $matches = $this->client->searchRequests([$field => $number]);

            foreach ($matches as $match) {
                if ($this->contractNumberMatches($match, $number)) {
                    return $match;
                }
            }
        }

        if (ctype_digit($number)) {
            $request = $this->client->getRequest($number);

            if ($request && $this->contractNumberMatches($request, $number)) {
                return $request;
            }
        }

        return null;
    }

    private function contractNumberMatches(array $request, string $number): bool
    {
        foreach ($this->contractNumberFields() as $field) {
            $value = $this->value($request, [$field]);

            if ($value !== null && $this->sameNumber((string) $value, $number)) {
                return true;
            }
        }

        return false;
    }

    private function contractNumberFields(): array
    {
        return [
            'r_id_internal',
            'id_internal',
            'reservation_number',
            'booking_number',
            'bron_number',
            'supplier_booking_number',
            'r_id_system',
            'id_system',
            'id',
            'r_id',
        ];
    }

    private function sameNumber(string $left, string $right): bool
    {
        return trim($left) === trim($right);
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
        $hotel = (string) ($this->value($request, ['hotel', 'hotel_name', 'hostel', 'placement'])
            ?: $this->valueFromServices($request, ['hotel', 'hotel_name', 'hostel', 'placement'])
            ?: 'не указано');

        return $this->normalizeHotelStars($hotel);
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

        if ($rate <= 1 && $rubPrice > 0) {
            $rate = $rubPrice / $price;
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

    private function moneyWithTourCurrency(float $rubAmount, string $rubCurrency, ?float $tourAmount, ?string $tourCurrency): string
    {
        $value = $this->money($rubAmount).' '.$this->e($rubCurrency);

        if ($tourAmount !== null && $tourCurrency) {
            $value .= ' / '.$this->money($tourAmount).' '.$this->e($tourCurrency);
        }

        return $value;
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

    private function normalizeHotelStars(string $hotel): string
    {
        $hotel = trim($hotel);

        if ($hotel === 'не указано') {
            return $hotel;
        }

        $hotel = preg_replace_callback('/(?<!\d)([1-5])\s*(?:\*{1,5}|★{1,5})(?!\*)/u', function (array $matches): string {
            return $matches[1].' *';
        }, $hotel) ?? $hotel;

        return preg_replace_callback('/(?<!\d)(\*{2,5}|★{2,5})(?!\*)/u', function (array $matches): string {
            return mb_strlen($matches[1]).' *';
        }, $hotel) ?? $hotel;
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

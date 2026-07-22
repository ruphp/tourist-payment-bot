<?php

namespace App\Services\Tochka;

use App\Models\TochkaPayment;
use App\Models\UonBinding;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TochkaPaymentService
{
    public function __construct(private readonly TochkaClient $client)
    {
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured()
            && filled(config('services.tochka.customer_code'))
            && filled(config('services.tochka.merchant_id'));
    }

    public function canAcceptNow(?Carbon $now = null): bool
    {
        $now = ($now ?: now())->copy()->timezone(config('payments.timezone', 'Europe/Moscow'));
        $from = $this->timeToday((string) config('payments.accept_from', '07:00'), $now);
        $until = $this->timeToday((string) config('payments.accept_until', '17:00'), $now);

        return $now->greaterThanOrEqualTo($from) && $now->lessThan($until);
    }

    public function pendingPayment(UonBinding $binding): ?TochkaPayment
    {
        $expiresAt = now()->subMinutes((int) config('services.tochka.payment_ttl', 60));

        return TochkaPayment::query()
            ->where('uon_binding_id', $binding->id)
            ->where('status', 'pending')
            ->where('created_at', '>=', $expiresAt)
            ->latest()
            ->first();
    }

    public function createLink(UonBinding $binding, float $amount): TochkaPayment
    {
        return DB::transaction(function () use ($binding, $amount): TochkaPayment {
            $binding = UonBinding::query()->whereKey($binding->id)->lockForUpdate()->firstOrFail();

            if ($pending = $this->pendingPayment($binding)) {
                return $pending;
            }

            $paymentLinkId = 'uon-'.$binding->uon_request_id.'-'.now('Europe/Moscow')->format('YmdHis').'-'.Str::lower(Str::random(6));

            $payload = [
                'amount' => round($amount, 2),
                'customerCode' => (string) config('services.tochka.customer_code'),
                'purpose' => 'Оплата по договору №'.$binding->contract_number.' о реализации туристического продукта',
                'paymentMode' => ['sbp'],
                'merchantId' => (string) config('services.tochka.merchant_id'),
                'ttl' => (int) config('services.tochka.payment_ttl', 60),
                'paymentLinkId' => $paymentLinkId,
            ];

            $response = $this->client->createPayment($payload)->throw();
            $data = $response->json();

            return TochkaPayment::query()->create([
                'telegram_user_id' => $binding->telegram_user_id,
                'uon_binding_id' => $binding->id,
                'uon_request_id' => $binding->uon_request_id,
                'contract_number' => $binding->contract_number,
                'amount' => $amount,
                'currency' => 'RUB',
                'status' => 'pending',
                'payment_link_id' => $paymentLinkId,
                'operation_id' => $this->findFirstValue($data, [
                    'Data.operationId',
                    'Data.paymentId',
                    'Data.id',
                    'operationId',
                    'paymentId',
                    'id',
                ]),
                'payment_url' => $this->findFirstValue($data, [
                    'Data.paymentLink',
                    'Data.paymentUrl',
                    'Data.url',
                    'Data.link',
                    'paymentLink',
                    'paymentUrl',
                    'url',
                    'link',
                ]),
                'tochka_payload' => $data,
            ]);
        });
    }

    private function timeToday(string $time, Carbon $now): Carbon
    {
        [$hour, $minute] = array_pad(explode(':', $time, 2), 2, 0);

        return $now->copy()->setTime((int) $hour, (int) $minute);
    }

    private function findFirstValue(mixed $data, array $keys): ?string
    {
        if (!is_array($data)) {
            return null;
        }

        foreach ($keys as $key) {
            $value = Arr::get($data, $key);

            if (is_scalar($value) && filled((string) $value)) {
                return (string) $value;
            }
        }

        return null;
    }
}

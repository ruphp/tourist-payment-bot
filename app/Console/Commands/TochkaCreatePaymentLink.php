<?php

namespace App\Console\Commands;

use App\Services\Tochka\TochkaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class TochkaCreatePaymentLink extends Command
{
    protected $signature = 'tochka:create-payment-link
        {amount : Amount in rubles, for example 10.00}
        {--purpose=Test tour payment : Payment purpose}
        {--payment-mode=sbp : Payment mode: sbp, card, tinkoff or comma-separated list}
        {--ttl=60 : Payment link lifetime in minutes}
        {--payment-link-id= : Unique payment link id}
        {--raw : Show raw response body}';

    protected $description = 'Create a Tochka acquiring payment link.';

    public function handle(TochkaClient $client): int
    {
        if (!$client->isConfigured()) {
            $this->error('TOCHKA_JWT_TOKEN is not configured.');

            return self::FAILURE;
        }

        $customerCode = config('services.tochka.customer_code');
        $merchantId = config('services.tochka.merchant_id');

        if (!$customerCode || !$merchantId) {
            $this->error('TOCHKA_CUSTOMER_CODE and TOCHKA_MERCHANT_ID must be configured.');

            return self::FAILURE;
        }

        $amount = $this->normalizeAmount((string) $this->argument('amount'));

        if ($amount === null) {
            $this->error('Amount must be greater than 0. Example: 10.00');

            return self::FAILURE;
        }

        $paymentLinkId = $this->option('payment-link-id')
            ?: 'bot-test-'.now('Europe/Moscow')->format('YmdHis').'-'.Str::lower(Str::random(6));

        $payload = [
            'amount' => $amount,
            'customerCode' => (string) $customerCode,
            'purpose' => (string) $this->option('purpose'),
            'paymentMode' => $this->paymentModes(),
            'merchantId' => (string) $merchantId,
            'ttl' => (int) $this->option('ttl'),
            'paymentLinkId' => $paymentLinkId,
        ];

        $response = $client->createPayment($payload);

        if (!$response->successful()) {
            $this->error('Tochka payment link request failed.');
            $this->line('HTTP status: '.$response->status());
            $this->line('Body: '.mb_strimwidth($response->body(), 0, 1500, '...'));

            return self::FAILURE;
        }

        if ($this->option('raw')) {
            $this->line($response->body());

            return self::SUCCESS;
        }

        $data = $response->json();
        $link = $this->findFirstValue($data, [
            'Data.paymentLink',
            'Data.paymentUrl',
            'Data.url',
            'Data.link',
            'paymentLink',
            'paymentUrl',
            'url',
            'link',
        ]);

        $operationId = $this->findFirstValue($data, [
            'Data.operationId',
            'Data.paymentId',
            'Data.id',
            'operationId',
            'paymentId',
            'id',
        ]);

        $this->info('OK: payment link created.');
        $this->line('paymentLinkId: '.$paymentLinkId);

        if ($operationId) {
            $this->line('operationId: '.$operationId);
        }

        if ($link) {
            $this->line('link: '.$link);
        } else {
            $this->warn('Payment link field was not recognized. Run with --raw.');
        }

        return self::SUCCESS;
    }

    private function normalizeAmount(string $amount): ?float
    {
        $value = (float) str_replace(',', '.', trim($amount));

        return $value > 0 ? round($value, 2) : null;
    }

    private function paymentModes(): array
    {
        return collect(explode(',', (string) $this->option('payment-mode')))
            ->map(fn (string $mode): string => trim($mode))
            ->filter()
            ->values()
            ->all();
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

<?php

namespace App\Console\Commands;

use App\Services\Tochka\TochkaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class TochkaRetailers extends Command
{
    protected $signature = 'tochka:retailers
        {--customer-code= : Tochka customerCode from tochka:customers}
        {--raw : Show raw response body}';

    protected $description = 'Show Tochka acquiring retailers and merchant ids.';

    public function handle(TochkaClient $client): int
    {
        if (!$client->isConfigured()) {
            $this->error('TOCHKA_JWT_TOKEN is not configured.');

            return self::FAILURE;
        }

        $customerCode = $this->option('customer-code') ?: config('services.tochka.customer_code');

        if (!$customerCode) {
            $this->error('TOCHKA_CUSTOMER_CODE is not configured.');
            $this->line('Run: php artisan tochka:customers');

            return self::FAILURE;
        }

        $response = $client->retailers((string) $customerCode);

        if (!$response->successful()) {
            $this->error('Tochka retailers request failed.');
            $this->line('HTTP status: '.$response->status());
            $this->line('Body: '.mb_strimwidth($response->body(), 0, 1000, '...'));

            return self::FAILURE;
        }

        if ($this->option('raw')) {
            $this->line($response->body());

            return self::SUCCESS;
        }

        $retailers = $this->extractRetailers($response->json());

        if (!$retailers) {
            $this->warn('Retailers not found in response.');
            $this->line('Run with --raw to inspect the response.');

            return self::SUCCESS;
        }

        $this->table(
            ['name', 'status', 'active', 'merchantId', 'terminalId', 'paymentModes', 'url'],
            collect($retailers)->map(fn (array $retailer): array => [
                Arr::get($retailer, 'name', ''),
                Arr::get($retailer, 'status', ''),
                $this->formatBool(Arr::get($retailer, 'isActive')),
                Arr::get($retailer, 'merchantId', ''),
                Arr::get($retailer, 'terminalId', ''),
                implode(', ', Arr::wrap(Arr::get($retailer, 'paymentModes', []))),
                Arr::get($retailer, 'url', ''),
            ])->all()
        );

        $this->info('For payments use a retailer with status REG and active yes.');

        return self::SUCCESS;
    }

    private function extractRetailers(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        foreach ([
            'Data.Retailer',
            'Data.Retailers',
            'Data.retailers',
            'Data',
            'retailers',
            'Retailers',
        ] as $key) {
            $value = Arr::get($data, $key);

            if (is_array($value)) {
                return array_is_list($value) ? $value : [$value];
            }
        }

        return [];
    }

    private function formatBool(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOL) ? 'yes' : 'no';
    }
}

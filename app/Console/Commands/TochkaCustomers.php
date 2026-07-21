<?php

namespace App\Console\Commands;

use App\Services\Tochka\TochkaClient;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class TochkaCustomers extends Command
{
    protected $signature = 'tochka:customers {--raw : Show raw response body}';

    protected $description = 'Show Tochka customers and customer codes.';

    public function handle(TochkaClient $client): int
    {
        if (!$client->isConfigured()) {
            $this->error('TOCHKA_JWT_TOKEN is not configured.');

            return self::FAILURE;
        }

        $response = $client->customers();

        if (!$response->successful()) {
            $this->error('Tochka customers request failed.');
            $this->line('HTTP status: '.$response->status());
            $this->line('Body: '.mb_strimwidth($response->body(), 0, 1000, '...'));

            return self::FAILURE;
        }

        if ($this->option('raw')) {
            $this->line($response->body());

            return self::SUCCESS;
        }

        $customers = $this->extractCustomers($response->json());

        if (!$customers) {
            $this->warn('Customers not found in response.');
            $this->line('Run with --raw to inspect the response.');

            return self::SUCCESS;
        }

        $this->table(
            ['customerCode', 'type', 'shortName', 'fullName', 'taxCode'],
            collect($customers)->map(fn (array $customer): array => [
                Arr::get($customer, 'customerCode', ''),
                Arr::get($customer, 'customerType', ''),
                Arr::get($customer, 'shortName', ''),
                Arr::get($customer, 'fullName', ''),
                Arr::get($customer, 'taxCode', ''),
            ])->all()
        );

        $this->info('Use customerCode from row with type Business.');

        return self::SUCCESS;
    }

    private function extractCustomers(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        foreach ([
            'Data.Customer',
            'Data.Customers',
            'Data.customers',
            'Data',
            'customers',
            'Customers',
        ] as $key) {
            $value = Arr::get($data, $key);

            if (is_array($value)) {
                return array_is_list($value) ? $value : [$value];
            }
        }

        return [];
    }
}

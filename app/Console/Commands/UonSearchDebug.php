<?php

namespace App\Console\Commands;

use App\Services\Uon\UonClient;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class UonSearchDebug extends Command
{
    protected $signature = 'uon:search-debug {number}';

    protected $description = 'Show compact U-ON search results for a contract/request number.';

    public function handle(UonClient $client): int
    {
        $number = (string) $this->argument('number');
        $fields = [
            'r_id_internal',
            'id_internal',
            'reservation_number',
            'booking_number',
            'bron_number',
            'supplier_booking_number',
            'r_id_system',
            'id_system',
        ];

        foreach ($fields as $field) {
            $this->info($field.' = '.$number);
            $matches = $client->searchRequests([$field => $number]);

            if (!$matches) {
                $this->line('  no matches');
                continue;
            }

            $this->table(
                ['id', 'r_id', 'id_system', 'r_id_internal', 'reservation_number', 'calc_price', 'currency'],
                collect($matches)->take(10)->map(fn (array $match): array => [
                    Arr::get($match, 'id', ''),
                    Arr::get($match, 'r_id', ''),
                    Arr::get($match, 'id_system', ''),
                    Arr::get($match, 'r_id_internal', ''),
                    Arr::get($match, 'reservation_number', ''),
                    Arr::get($match, 'calc_price', ''),
                    Arr::get($match, 'currency', ''),
                ])->all()
            );
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\UonBinding;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class UonInspectBinding extends Command
{
    protected $signature = 'uon:inspect-binding {contract?}';

    protected $description = 'Inspect saved U-ON request snapshot fields for debugging.';

    public function handle(): int
    {
        $query = UonBinding::query()->latest('updated_at');

        if ($contract = $this->argument('contract')) {
            $query->where('contract_number', $contract);
        }

        $binding = $query->first();

        if (!$binding) {
            $this->error('No U-ON binding found.');
            return self::FAILURE;
        }

        $snapshot = $binding->last_request_snapshot ?? [];

        $this->info('Contract: '.$binding->contract_number);
        $this->info('Request ID: '.$binding->uon_request_id);
        $this->line('');

        $this->table(['Field', 'Value'], $this->interestingFields($snapshot));

        $services = $snapshot['services'] ?? [];

        if (is_array($services)) {
            foreach (array_slice($services, 0, 5) as $index => $service) {
                if (!is_array($service)) {
                    continue;
                }

                $this->line('');
                $this->info('Service #'.($index + 1));
                $this->table(['Field', 'Value'], $this->interestingFields($service));
            }
        }

        return self::SUCCESS;
    }

    private function interestingFields(array $source): array
    {
        $keys = array_filter(array_keys($source), function (string|int $key): bool {
            $key = (string) $key;

            return str_contains($key, 'price')
                || str_contains($key, 'currency')
                || str_contains($key, 'koef')
                || str_contains($key, 'rate')
                || str_contains($key, 'course')
                || str_contains($key, 'calc')
                || str_contains($key, 'sum')
                || str_contains($key, 'pay');
        });

        return collect($keys)
            ->sort()
            ->map(fn (string|int $key): array => [
                (string) $key,
                $this->stringValue(Arr::get($source, $key)),
            ])
            ->values()
            ->all();
    }

    private function stringValue(mixed $value): string
    {
        if (is_array($value)) {
            return '[array '.count($value).']';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return mb_substr((string) $value, 0, 120);
    }
}

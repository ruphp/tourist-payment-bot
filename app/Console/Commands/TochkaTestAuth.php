<?php

namespace App\Console\Commands;

use App\Services\Tochka\TochkaClient;
use Illuminate\Console\Command;

class TochkaTestAuth extends Command
{
    protected $signature = 'tochka:test-auth';

    protected $description = 'Check Tochka JWT access with a safe read-only request.';

    public function handle(TochkaClient $client): int
    {
        if (!$client->isConfigured()) {
            $this->error('TOCHKA_JWT_TOKEN is not configured.');

            return self::FAILURE;
        }

        $response = $client->accounts();

        if ($response->successful()) {
            $data = $response->json();

            $this->info('OK: Tochka JWT works.');
            $this->line('HTTP status: '.$response->status());

            if (is_array($data)) {
                $this->line('Top-level keys: '.implode(', ', array_slice(array_keys($data), 0, 10)));
            }

            return self::SUCCESS;
        }

        $this->error('Tochka request failed.');
        $this->line('HTTP status: '.$response->status());

        $message = $response->json('message')
            ?? $response->json('error')
            ?? $response->json('errors.0.message');

        if ($message) {
            $this->line('Message: '.$message);
        } else {
            $this->line('Body: '.mb_strimwidth($response->body(), 0, 500, '...'));
        }

        return self::FAILURE;
    }
}

<?php

namespace App\Services\Tochka;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class TochkaClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.tochka.jwt_token'));
    }

    public function accounts(): Response
    {
        return $this->http()->get('open-banking/v1.0/accounts');
    }

    public function customers(): Response
    {
        return $this->http()->get('open-banking/v1.0/customers');
    }

    public function retailers(string $customerCode): Response
    {
        return $this->http()->get('acquiring/v1.0/retailers', [
            'customerCode' => $customerCode,
        ]);
    }

    private function http(): PendingRequest
    {
        $token = config('services.tochka.jwt_token');

        if (!$token) {
            throw new \RuntimeException('TOCHKA_JWT_TOKEN is not configured.');
        }

        return Http::baseUrl(rtrim((string) config('services.tochka.base_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->withToken($token)
            ->timeout(20);
    }
}

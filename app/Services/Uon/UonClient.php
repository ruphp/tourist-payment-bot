<?php

namespace App\Services\Uon;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class UonClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.uon.api_key'));
    }

    public function getRequest(string|int $requestId): ?array
    {
        return $this->unwrapRequest(
            $this->http()->get("request/{$requestId}.json")->json()
        );
    }

    public function searchRequests(array $query): array
    {
        $response = $this->http()->post('request/search.json', $query)->json();

        return $this->unwrapList($response);
    }

    public function getDeadlines(string|int $requestId): array
    {
        $response = $this->http()->get("request-deadline/{$requestId}.json")->json();

        return $this->unwrapList($response);
    }

    private function http(): PendingRequest
    {
        $key = config('services.uon.api_key');

        if (!$key) {
            throw new \RuntimeException('UON_API_KEY is not configured.');
        }

        $baseUrl = rtrim((string) config('services.uon.base_url'), '/')."/{$key}";

        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->retry(2, 300);
    }

    private function unwrapRequest(mixed $response): ?array
    {
        if (!is_array($response)) {
            return null;
        }

        foreach (['request', 'result', 'data'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return $response[$key];
            }
        }

        if (isset($response[0]) && is_array($response[0])) {
            return $response[0];
        }

        return isset($response['id']) || isset($response['id_system']) ? $response : null;
    }

    private function unwrapList(mixed $response): array
    {
        if (!is_array($response)) {
            return [];
        }

        foreach (['requests', 'request', 'result', 'data', 'items'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return $this->isList($response[$key]) ? $response[$key] : [$response[$key]];
            }
        }

        return $this->isList($response) ? $response : [];
    }

    private function isList(array $value): bool
    {
        return array_is_list($value);
    }
}

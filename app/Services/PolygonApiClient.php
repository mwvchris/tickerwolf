<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PolygonApiClient
{
    protected string $baseUrl;
    protected string $apiKey;
    protected int $timeout;
    protected int $maxRetries;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.polygon.base') ?? env('POLYGON_API_BASE', 'https://api.polygon.io'), '/');
        $this->apiKey = config('services.polygon.key') ?? env('POLYGON_API_KEY');
        $this->timeout = (int) (env('POLYGON_API_TIMEOUT', 30));
        $this->maxRetries = (int) (env('POLYGON_API_RETRIES', 3));
    }

    /**
     * Perform a GET request with built-in retries, rate-limit handling, and backoff.
     */
    public function get(string $endpointOrUrl, array $params = [])
    {
        $url = str_starts_with($endpointOrUrl, 'http')
            ? $endpointOrUrl
            : "{$this->baseUrl}/" . ltrim($endpointOrUrl, '/');

        // Ensure API key is always attached
        $params['apiKey'] = $params['apiKey'] ?? $this->apiKey;
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);

        $attempt = 0;
        $waitSeconds = 2;

        while (true) {
            $attempt++;
            try {
                $response = Http::timeout($this->timeout)->get($url);
            } catch (\Throwable $e) {
                Log::error("Polygon HTTP exception: {$e->getMessage()}", ['url' => $url]);
                if ($attempt >= $this->maxRetries) {
                    return response()->json(['error' => $e->getMessage()], 500);
                }
                sleep($waitSeconds);
                $waitSeconds *= 2;
                continue;
            }

            // Handle rate limit
            if ($response->status() === 429) {
                $retryAfter = (int) ($response->header('Retry-After') ?? $waitSeconds);
                Log::warning("Polygon API 429 - sleeping {$retryAfter}s");
                sleep($retryAfter);
                continue;
            }

            // Retry on 5xx
            if ($response->serverError() && $attempt < $this->maxRetries) {
                Log::warning("Polygon server error {$response->status()} — retrying in {$waitSeconds}s");
                sleep($waitSeconds);
                $waitSeconds *= 2;
                continue;
            }

            return $response;
        }
    }
}
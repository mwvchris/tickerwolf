<?php

namespace App\Services\LLMProviders;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\LLMContracts\LLMProviderInterface;
use RuntimeException;
use Throwable;

class OpenAiProvider implements LLMProviderInterface
{
    /**
     * Perform analysis using the OpenAI Responses API (v1/responses)
     * with provider-level hardening (retries, backoff, validation).
     */
    public function analyze(string $ticker, string $promptTemplate, array $context = []): array
    {
        $prompt = $this->renderPrompt(
            $promptTemplate,
            array_merge($context, ['ticker' => strtoupper($ticker)])
        );

        $apiKey   = config('services.openai.key');
        $endpoint = config('services.openai.endpoint', 'https://api.openai.com/v1/responses');
        $model    = config('services.openai.model', 'gpt-4.1-mini');

        if (empty($apiKey)) {
            throw new RuntimeException('Missing OpenAI API key.');
        }

        $payload = [
            'model' => $model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => 'You are a helpful financial analyst that provides concise, structured insights.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.2,
            'max_output_tokens' => 1200,
        ];

        $maxAttempts = 3;
        $delaySeconds = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::withToken($apiKey)
                    ->timeout(60)
                    ->acceptJson()
                    ->post($endpoint, $payload);

                $json = $response->json();

                Log::debug('OpenAI raw response', [
                    'provider' => 'openai',
                    'ticker'   => $ticker,
                    'attempt'  => $attempt,
                    'raw'      => $json,
                ]);

                // Handle non-2xx responses
                if ($response->failed()) {
                    $status = $response->status();
                    $message = $json['error']['message'] ?? 'Unknown API error';

                    // Retry automatically on transient or rate-limit errors
                    if (in_array($status, [408, 429, 500, 502, 503, 504], true)) {
                        Log::warning("Transient OpenAI error [{$status}]: {$message}. Retrying in {$delaySeconds}s…");
                        sleep($delaySeconds);
                        $delaySeconds *= 2; // Exponential backoff
                        continue;
                    }

                    throw new RuntimeException("OpenAI API error [{$status}]: {$message}");
                }

                // Validate structure before parsing
                if (!isset($json['output'][0]['content'][0]['text'])) {
                    throw new RuntimeException('Malformed API response: missing content.');
                }

                $content = trim($json['output'][0]['content'][0]['text'] ?? '');

                if ($content === '') {
                    throw new RuntimeException('Provider returned empty content.');
                }

                return [
                    'content' => $content,
                    'raw'     => $json,
                ];
            } catch (Throwable $e) {
                Log::error("OpenAI attempt {$attempt} failed: {$e->getMessage()}");

                if ($attempt < $maxAttempts) {
                    sleep($delaySeconds);
                    $delaySeconds *= 2;
                    continue;
                }

                throw new RuntimeException("OpenAI analysis failed after {$attempt} attempts: " . $e->getMessage(), 0, $e);
            }
        }

        // Should not reach here
        throw new RuntimeException('OpenAI analysis aborted after maximum retries.');
    }

    /**
     * Simple template interpolation.
     */
    protected function renderPrompt(string $template, array $data): string
    {
        foreach ($data as $k => $v) {
            $template = str_replace('{{' . $k . '}}', $v, $template);
        }
        return $template;
    }

    /**
     * Extracts structured JSON from a text blob.
     */
    public function extractStructuredFromText(string $text): ?array
    {
        $json = $this->findJson($text);
        return $json ? json_decode($json, true) : null;
    }

    /**
     * Finds JSON within a text body.
     */
    protected function findJson(string $text): ?string
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $possibleJson = substr($text, $start, $end - $start + 1);

        // Basic sanity check before decoding
        return str_contains($possibleJson, ':') ? $possibleJson : null;
    }
}
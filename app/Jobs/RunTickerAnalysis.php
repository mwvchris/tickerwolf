<?php

namespace App\Jobs;

use App\Models\TickerAnalysis;
use App\Services\LLMProviderFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;
use RuntimeException;

class RunTickerAnalysis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public TickerAnalysis $analysis;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Progressive backoff between retries (in seconds).
     */
    public function backoff(): array
    {
        return [60, 120, 300]; // 1 min, 2 min, 5 min
    }

    /**
     * Create a new job instance.
     */
    public function __construct(TickerAnalysis $analysis)
    {
        $this->analysis = $analysis;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->analysis->update(['status' => 'running']);
        $promptTemplate = str_replace(env('PROMPT_TICKER_REPLACE_STRING'), $this->analysis->ticker, config('prompts.analysis'));

        try {
            $provider = LLMProviderFactory::make($this->analysis->provider);
            $raw = $provider->analyze($this->analysis->ticker, $promptTemplate);

            // Detect rate limit responses (OpenAI, Gemini, etc.)
            if (
                isset($raw['error']['code']) &&
                str_contains(strtolower($raw['error']['code']), 'rate_limit')
            ) {
                Log::warning("Rate limit hit for {$this->analysis->provider}, retrying later.");
                throw new RuntimeException('Rate limit exceeded, will retry.');
            }

            Log::debug('LLM raw response', [
                'provider' => $this->analysis->provider,
                'ticker'   => $this->analysis->ticker,
                'raw'      => $raw,
            ]);

            /**
             * Handle multiple possible API response shapes.
             */
            $content =
                $raw['content']
                ?? ($raw['output'][0]['content'][0]['text'] ?? '')
                ?? ($raw['choices'][0]['message']['content'] ?? '')
                ?? ($raw['candidates'][0]['content']['parts'][0]['text'] ?? '')
                ?? '';

            if (empty(trim($content))) {
                throw new RuntimeException('Provider returned empty content.');
            }

            // Attempt structured extraction (safe)
            $structured = [];
            try {
                $structured = $provider->extractStructuredFromText($content) ?? [];
            } catch (Throwable $e) {
                Log::warning("Failed to extract structured data for {$this->analysis->ticker}: {$e->getMessage()}");
            }

            $summary = mb_substr(strip_tags($content), 0, 300);

            $this->analysis->update([
                'response_raw' => $raw,
                'summary'      => $summary,
                'structured'   => $structured,
                'status'       => 'completed',
                'completed_at' => now(),
            ]);

            // Cache results for 6 hours
            cache()->put(
                "ticker_analysis:{$this->analysis->ticker}:{$this->analysis->provider}",
                [
                    'analysis'   => $summary,
                    'structured' => $structured,
                    'model'      => $this->analysis->model,
                ],
                now()->addHours(6)
            );

            Log::info("Ticker analysis completed for {$this->analysis->ticker} ({$this->analysis->provider})");
        } catch (Throwable $e) {
            Log::error("Ticker analysis failed for {$this->analysis->ticker}: {$e->getMessage()}");

            $this->analysis->update([
                'status'       => 'failed',
                'response_raw' => ['error' => $e->getMessage()],
            ]);

            // Let Laravel handle backoff & retry automatically
            throw $e;
        } finally {
            // Safety: ensure status consistency
            if (!in_array($this->analysis->status, ['completed', 'failed'], true)) {
                $this->analysis->update(['status' => 'unknown']);
            }
        }
    }
}
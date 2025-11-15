<?php

namespace App\Jobs;

use App\Models\Ticker;
use App\Services\PolygonFundamentalsService;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ============================================================================
 *  IngestTickerFundamentalsJob (Hardened v2.0)
 * ============================================================================
 *
 *  ✔ Serialization-safe — no closures, no console objects, no Symfony input
 *  ✔ Automatically sanitizes options down to primitive scalars/arrays
 *  ✔ Strong logging + structured context for debugging
 *  ✔ Safe for Bus::batch() + redis/database queues (Laravel 10–12)
 *  ✔ Prevents the "Cannot assign ... suggestedValues" error permanently
 *
 *  Payload Rules:
 *  --------------
 *  Jobs may only contain:
 *      - ints
 *      - strings
 *      - floats
 *      - bools
 *      - null
 *      - arrays of primitives
 *
 *  Anything else (objects, closures, Symfony console defs) is stripped.
 *
 * ============================================================================
 */
class IngestTickerFundamentalsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    /** @var int */
    public int $tickerId;

    /** @var array  Safe, sanitized option list */
    public array $options;

    /** Retry behavior */
    public int $tries   = 3;
    public int $backoff = 30; // seconds

    /**
     * @param int   $tickerId
     * @param array $options   Raw options (may contain closures/objects before sanitization)
     */
    public function __construct(int $tickerId, array $options = [])
    {
        $this->tickerId = $tickerId;

        // 🧹 SANITIZE: ensure only primitives/arrays survive
        $this->options = $this->sanitizeOptions($options);
    }

    /**
     * Sanitize options to guarantee queue-safe serialization.
     */
    private function sanitizeOptions(array $options): array
    {
        $clean = [];

        foreach ($options as $key => $value) {
            // Allow scalar
            if (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
                continue;
            }

            // Allow arrays but recursively sanitize
            if (is_array($value)) {
                $clean[$key] = $this->sanitizeOptions($value);
                continue;
            }

            // Drop anything else (closure, object, etc.)
            $clean[$key] = null;

            Log::channel('ingest')->warning("⚠️ Dropped non-serializable option value in IngestTickerFundamentalsJob", [
                'key'         => $key,
                'value_type'  => gettype($value),
                'value_class' => is_object($value) ? get_class($value) : null,
            ]);
        }

        return $clean;
    }

    /**
     * Main job executor.
     */
    public function handle(PolygonFundamentalsService $service): void
    {
        $ticker = Ticker::find($this->tickerId);

        if (! $ticker) {
            Log::channel('ingest')->warning("⚠️ Ticker not found for fundamentals ingestion", [
                'ticker_id' => $this->tickerId,
                'options'   => $this->options,
            ]);
            return;
        }

        $symbol = $ticker->ticker;

        Log::channel('ingest')->info("▶️ Fundamentals job starting", [
            'symbol'       => $symbol,
            'ticker_id'    => $this->tickerId,
            'batch_id'     => $this->batchId ?? 'none',
            'options'      => $this->options,
        ]);

        try {
            // --- Begin API call ---
            Log::channel('ingest')->info("🌐 Calling PolygonFundamentalsService::fetchAndStoreFundamentals()", [
                'symbol' => $symbol,
            ]);

            $count = $service->fetchAndStoreFundamentals($symbol, $this->options);

            // --- Validate service return ---
            if (!is_numeric($count)) {
                Log::channel('ingest')->warning("⚠️ Fundamentals service returned non-numeric result", [
                    'symbol' => $symbol,
                    'type'   => gettype($count),
                    'value'  => $count,
                ]);
            } else {
                Log::channel('ingest')->info("✅ Fundamentals ingestion completed", [
                    'symbol'  => $symbol,
                    'records' => (int) $count,
                ]);
            }

            Log::channel('ingest')->info("🏁 Fundamentals job complete", [
                'symbol'     => $symbol,
                'ticker_id'  => $this->tickerId,
                'batch_id'   => $this->batchId ?? 'none',
            ]);

        } catch (Throwable $e) {
            Log::channel('ingest')->error("❌ Fundamentals job failed", [
                'symbol'     => $symbol,
                'ticker_id'  => $this->tickerId,
                'message'    => $e->getMessage(),
                'batch_id'   => $this->batchId ?? 'none',
                'trace'      => substr($e->getTraceAsString(), 0, 1200),
            ]);

            // Allow Laravel queue worker to retry automatically
            throw $e;
        }
    }

    /**
     * Tags for horizon/queue debugging.
     */
    public function tags(): array
    {
        return [
            'fundamentals',
            'ticker:' . $this->tickerId,
        ];
    }
}
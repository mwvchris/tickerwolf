<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service: PolygonTickerPriceHistoryService
 * -----------------------------------------
 * Coordinates fetching and storing of historical price data
 * for a given ticker symbol by delegating API retrieval to
 * PolygonPriceHistoryService and managing ingestion behavior
 * (logging, retries, normalization, and data persistence).
 *
 * This service adds robustness for ticker symbol casing issues
 * that arise with non-CS (Common Stock) instruments such as:
 *   - Preferred shares (e.g., ABRpD, AHTpF)
 *   - Units (e.g., XYZ.U)
 *   - ETFs and structured products
 *
 * Polygon’s /v2/aggs endpoint is case-sensitive for such symbols,
 * so this service preserves their mixed case to ensure valid responses.
 */
class PolygonTickerPriceHistoryService
{
    /**
     * The underlying low-level Polygon API interface.
     */
    protected PolygonPriceHistoryService $polygon;

    /**
     * The logging channel used for ingestion tracking.
     */
    protected string $logChannel;

    /**
     * Construct the service.
     */
    public function __construct(PolygonPriceHistoryService $polygon)
    {
        $this->polygon = $polygon;
        $this->logChannel = 'ingest';
    }

    /**
     * Fetch and store historical price bars for a given ticker.
     *
     * This method:
     *  - Normalizes ticker casing based on asset type
     *  - Requests historical aggregates from Polygon
     *  - Retries automatically with alternate casing if no results
     *  - Delegates persistence to PolygonPriceHistoryService::upsertBars()
     *  - Logs all key steps and results for visibility
     *
     * @param  object  $ticker      The Ticker model instance or object
     * @param  string  $rangeFrom   ISO date string for start of range (e.g. '2020-01-01')
     * @param  string  $rangeTo     ISO date string for end of range (e.g. '2025-11-03')
     */
    public function fetchAndStore(object $ticker, string $rangeFrom, string $rangeTo): void
    {
        $logger = Log::channel($this->logChannel);

        // Resolve symbol and sanity-check required attributes
        $symbol = $ticker->ticker ?? $ticker->symbol ?? null;

        if (empty($symbol) || empty($ticker->id)) {
            $logger->error('❌ Ticker object missing symbol or ID', ['ticker' => $ticker]);
            return;
        }

        // Normalize symbol according to asset type
        $normalizedSymbol = $this->normalizeSymbol($ticker);
        $tickerId = (int)$ticker->id;
        $resolution = '1d';
        $multiplier = 1;
        $timespan = 'day';

        $logger->info("📈 Fetching Polygon data for {$normalizedSymbol} ({$rangeFrom} → {$rangeTo})");

        try {
            // Attempt initial fetch
            $bars = $this->polygon->fetchAggregates($normalizedSymbol, $multiplier, $timespan, $rangeFrom, $rangeTo);

            // If Polygon returns nothing, retry with raw/original symbol casing
            if (empty($bars)) {
                $altSymbol = $symbol;
                if ($altSymbol !== $normalizedSymbol) {
                    $logger->warning("🔁 No results for {$normalizedSymbol}, retrying with alt case: {$altSymbol}");
                    $bars = $this->polygon->fetchAggregates($altSymbol, $multiplier, $timespan, $rangeFrom, $rangeTo);
                }
            }

            // Still no data? Likely illiquid or unsupported instrument
            if (empty($bars)) {
                $logger->warning("⚠️ No data returned for {$normalizedSymbol} ({$rangeFrom} → {$rangeTo}). Possibly illiquid or unsupported.");
                return;
            }

            // Persist to database
            $count = $this->polygon->upsertBars($tickerId, $normalizedSymbol, $resolution, $bars);

            $logger->info("✅ Stored {$count} bars for {$normalizedSymbol} ({$rangeFrom} → {$rangeTo})");
        } catch (Throwable $e) {
            // Catch and log unexpected runtime exceptions
            $logger->error("❌ Exception fetching/storing data for {$normalizedSymbol}: {$e->getMessage()}", [
                'ticker_id' => $ticker->id,
                'symbol'    => $normalizedSymbol,
                'trace'     => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Normalize ticker casing for Polygon endpoints.
     *
     * Polygon’s API is case-sensitive for non-common-stock tickers.
     * This helper:
     *   - Uppercases symbols for common stock (type 'CS')
     *   - Preserves mixed case for preferreds, units, ETFs, etc.
     *
     * Example:
     *   CS   → 'AAPL'
     *   PFD  → 'ABRpD'
     *   UNIT → 'XYZ.U'
     *
     * @param  object  $ticker
     * @return string  Normalized symbol string ready for Polygon API
     */
    protected function normalizeSymbol(object $ticker): string
    {
        $raw = trim($ticker->ticker ?? $ticker->symbol ?? '');

        // Detect asset type if available
        $type = strtoupper($ticker->type ?? $ticker->asset_type ?? '');

        // Only force-uppercase for standard common stock
        if ($type === 'CS') {
            return strtoupper($raw);
        }

        // Preserve case otherwise
        return $raw;
    }
}
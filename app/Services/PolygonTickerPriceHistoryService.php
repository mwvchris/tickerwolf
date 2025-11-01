<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

class PolygonTickerPriceHistoryService
{
    protected PolygonPriceHistoryService $polygon;
    protected string $logChannel;

    public function __construct(PolygonPriceHistoryService $polygon)
    {
        $this->polygon = $polygon;
        $this->logChannel = 'polygon';
    }

    /**
     * Fetch and store historical price bars for a given ticker.
     */
    public function fetchAndStore(object $ticker, string $rangeFrom, string $rangeTo): void
    {
        $logger = Log::channel($this->logChannel);

        // ✅ Use correct column from your model
        $symbol = $ticker->ticker ?? null;

        if (empty($symbol) || empty($ticker->id)) {
            $logger->error('Ticker object missing symbol or id', ['ticker' => $ticker]);
            return;
        }

        $symbol = strtoupper(trim($symbol));
        $tickerId = (int)$ticker->id;
        $resolution = '1d';
        $multiplier = 1;
        $timespan = 'day';

        $logger->info("📈 Fetching Polygon data for {$symbol} ({$rangeFrom} → {$rangeTo})");

        try {
            $bars = $this->polygon->fetchAggregates($symbol, $multiplier, $timespan, $rangeFrom, $rangeTo);

            if (empty($bars)) {
                $logger->warning("⚠️ No data returned for {$symbol} ({$rangeFrom} → {$rangeTo}).");
                return;
            }

            $count = $this->polygon->upsertBars($tickerId, $symbol, $resolution, $bars);
            $logger->info("✅ Stored {$count} bars for {$symbol} ({$rangeFrom} → {$rangeTo})");
        } catch (Throwable $e) {
            $logger->error("❌ Exception fetching/storing data for {$symbol}: {$e->getMessage()}");
        }
    }
}
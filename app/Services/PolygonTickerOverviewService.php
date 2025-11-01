<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use App\Models\Ticker;

class PolygonTickerOverviewService
{
    protected PolygonApiClient $client;
    protected array $tickerColumns;

    public function __construct(PolygonApiClient $client)
    {
        $this->client = $client;
        $this->tickerColumns = DB::getSchemaBuilder()->getColumnListing('tickers');
    }

    public function fetchAndUpsertOverview(Ticker $ticker): void
    {
        $endpoint = "/v3/reference/tickers/{$ticker->ticker}";
        $response = $this->client->get($endpoint);

        if (! $response->ok()) {
            $this->logFailure($ticker, "HTTP {$response->status()}");
            return;
        }

        $data = $response->json('results') ?? null;

        if (!$data) {
            $this->logFailure($ticker, 'No results returned');
            return;
        }

        $marketCap = is_numeric($data['market_cap'] ?? null) ? (int)$data['market_cap'] : null;

        DB::table('ticker_overviews')->upsert([[
            'ticker_id' => $ticker->id,
            'overview_date' => Carbon::now()->toDateString(),
            'active' => $data['active'] ?? null,
            'market_cap' => $marketCap,
            'primary_exchange' => $data['primary_exchange'] ?? null,
            'locale' => $data['locale'] ?? null,
            'status' => $data['status'] ?? 'unknown',
            'results_raw' => json_encode($data),
            'fetched_at' => now(),
            'updated_at' => now(),
        ]], ['ticker_id', 'overview_date'], [
            'active', 'market_cap', 'primary_exchange', 'locale',
            'status', 'results_raw', 'fetched_at', 'updated_at'
        ]);

        $updateData = array_intersect_key([
            'description' => $data['description'] ?? null,
            'homepage_url' => $data['homepage_url'] ?? null,
            'market_cap' => $marketCap,
            'total_employees' => $data['total_employees'] ?? null,
            'sic_code' => $data['sic_code'] ?? null,
            'branding_logo_url' => $data['branding']['logo_url'] ?? null,
            'branding_icon_url' => $data['branding']['icon_url'] ?? null,
        ], array_flip($this->tickerColumns));

        $ticker->fill($updateData);
        $ticker->save();
    }

    public function processBatch($tickers): void
    {
        foreach ($tickers as $ticker) {
            try {
                $this->fetchAndUpsertOverview($ticker);
            } catch (\Throwable $e) {
                $this->logFailure($ticker, $e->getMessage());
            }
        }
    }

    protected function logFailure(Ticker $ticker, string $reason): void
    {
        DB::table('failed_ticker_overviews')->insert([
            'ticker_id' => $ticker->id,
            'ticker' => $ticker->ticker,
            'reason' => $reason,
            'failed_at' => now(),
        ]);

        Log::warning("Failed overview for {$ticker->ticker}: {$reason}");
    }
}
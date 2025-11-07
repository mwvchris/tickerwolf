<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use App\Models\Ticker;

/**
 * Service: PolygonTickerOverviewService
 *
 * Responsibilities:
 *  - Fetch reference data from Polygon.io for each ticker
 *  - Upsert daily volatile data into ticker_overviews
 *  - Update stable ticker metadata in tickers
 *  - Normalize phone numbers and store structured address JSON
 *  - Handle graceful failures and detailed ingest logging
 */
class PolygonTickerOverviewService
{
    protected PolygonApiClient $client;

    public function __construct(PolygonApiClient $client)
    {
        $this->client = $client;
    }

    /**
     * Fetch and upsert Polygon.io overview data for a ticker.
     */
    public function fetchAndUpsertOverview(string|Ticker $ticker): void
    {
        //------------------------------------------------------------------
        // 1. Normalize ticker reference
        //------------------------------------------------------------------
        if ($ticker instanceof Ticker) {
            $symbol = $ticker->ticker;
            $tickerId = $ticker->id;
            $tickerModel = $ticker;
        } else {
            $symbol = trim($ticker); // 🔸 (keep lowercase as per your version)
            $tickerModel = Ticker::where('ticker', $symbol)->first();
            $tickerId = $tickerModel?->id;
        }

        if (! $tickerId) {
            Log::channel('ingest')->warning("⚠️ Unknown ticker skipped", ['symbol' => $symbol]);
            return;
        }

        //------------------------------------------------------------------
        // 2. API request
        //------------------------------------------------------------------
        $endpoint = "/v3/reference/tickers/{$symbol}";
        $response = $this->client->get($endpoint);

        if (! $response->ok()) {
            $this->logFailure($symbol, "HTTP {$response->status()}", $tickerId);
            return;
        }

        $data = $response->json('results') ?? null;
        if (! $data) {
            $this->logFailure($symbol, "No results returned", $tickerId);
            return;
        }

        //------------------------------------------------------------------
        // 3. Normalize & derive fields
        //------------------------------------------------------------------
        $marketCap = is_numeric($data['market_cap'] ?? null)
            ? (int) $data['market_cap']
            : null;

        $status = $data['status'] ?? (($data['active'] ?? false) ? 'active' : 'inactive');

        $currencyName = strtolower($data['currency_name'] ?? '');
        $currencySymbolMap = [
            'usd' => '$', 'eur' => '€', 'gbp' => '£',
            'jpy' => '¥', 'cad' => 'C$'
        ];
        $currencySymbol = $currencySymbolMap[$currencyName] ?? null;

        $formattedPhone = $this->normalizePhone($data['phone_number'] ?? null);

        $addressJson = isset($data['address']) && is_array($data['address'])
            ? json_encode($data['address'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        //------------------------------------------------------------------
        // 4. Upsert volatile snapshot into ticker_overviews
        //------------------------------------------------------------------
        DB::table('ticker_overviews')->upsert(
            [[
                'ticker_id'     => $tickerId,
                'overview_date' => Carbon::now()->toDateString(),
                'active'        => $data['active'] ?? null,
                'market_cap'    => $marketCap,
                'status'        => $status,
                'results_raw'   => json_encode($data),
                'created_at'    => now(),
                'updated_at'    => now(),
                'fetched_at'    => now(),
            ]],
            ['ticker_id', 'overview_date'],
            [
                'active', 'market_cap', 'status',
                'results_raw', 'fetched_at', 'updated_at'
            ]
        );

        //------------------------------------------------------------------
        // 5. Update static ticker metadata
        //------------------------------------------------------------------
        if ($tickerModel ?? false) {
            // Refresh columns live (avoid cached schema)
            $columns = Schema::getColumnListing('tickers');

            $updateData = [
                'name'                        => $data['name'] ?? null,
                'description'                 => $data['description'] ?? null,
                'homepage_url'                => $data['homepage_url'] ?? null,
                'list_date'                   => $data['list_date'] ?? null,
                'market'                      => $data['market'] ?? null,
                'primary_exchange'            => $data['primary_exchange'] ?? null,
                'type'                        => $data['type'] ?? null,
                'status'                      => $status,
                'active'                      => $data['active'] ?? null,
                'phone_number'                => $formattedPhone,
                'cik'                         => $data['cik'] ?? null,
                'composite_figi'              => $data['composite_figi'] ?? null,
                'share_class_figi'            => $data['share_class_figi'] ?? null,
                'ticker_root'                 => $data['ticker_root'] ?? null,
                'ticker_suffix'               => $data['ticker_suffix'] ?? null,
                'sic_code'                    => $data['sic_code'] ?? null,
                'sic_description'             => $data['sic_description'] ?? null,
                'share_class_shares_outstanding' => $data['share_class_shares_outstanding'] ?? null,
                'weighted_shares_outstanding' => $data['weighted_shares_outstanding'] ?? null,
                'total_employees'             => $data['total_employees'] ?? null,
                'round_lot'                   => $data['round_lot'] ?? null,
                'currency_name'               => $currencyName,
                'currency_symbol'             => $currencySymbol,
                'base_currency_name'          => $data['base_currency_name'] ?? null,
                'base_currency_symbol'        => $data['base_currency_symbol'] ?? null,
                'branding_logo_url'           => $data['branding']['logo_url'] ?? null,
                'branding_icon_url'           => $data['branding']['icon_url'] ?? null,
                'address_json'                => $addressJson,
            ];

            $filtered = array_intersect_key($updateData, array_flip($columns));
            $tickerModel->fill($filtered);
            $tickerModel->save();

            // Fallback write if somehow filtered out
            if (!empty($addressJson) && in_array('address_json', $columns)) {
                DB::table('tickers')->where('id', $tickerId)->update(['address_json' => $addressJson]);
            }
        }

        //------------------------------------------------------------------
        // 6. Log success
        //------------------------------------------------------------------
        Log::channel('ingest')->info("✅ Polygon overview upserted", [
            'symbol'       => $symbol,
            'ticker_id'    => $tickerId,
            'market_cap'   => $marketCap,
            'status'       => $status,
            'currency'     => $currencyName,
            'symbol_map'   => $currencySymbol,
            'address_json' => $addressJson !== null,
            'phone_normalized' => $formattedPhone ?? null,
        ]);
    }

    /**
     * Normalize phone numbers into (XXX) XXX-XXXX format if US-style.
     */
    protected function normalizePhone(?string $phone): ?string
    {
        if (empty($phone)) return null;
        $digits = preg_replace('/\D+/', '', $phone);

        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s',
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6)
            );
        }
        if (strlen($digits) === 11 && $digits[0] === '1') {
            return sprintf('(%s) %s-%s',
                substr($digits, 1, 3),
                substr($digits, 4, 3),
                substr($digits, 7)
            );
        }
        return $phone;
    }

    /**
     * Batch processor for multiple tickers.
     */
    public function processBatch(array $tickers): void
    {
        foreach ($tickers as $ticker) {
            try {
                $this->fetchAndUpsertOverview($ticker);
            } catch (\Throwable $e) {
                $symbol = $ticker instanceof Ticker ? $ticker->ticker : $ticker;
                $this->logFailure($symbol, $e->getMessage());
            }
        }
    }

    /**
     * Record a failed overview attempt.
     */
    protected function logFailure(string $symbol, string $reason, ?int $tickerId = null): void
    {
        try {
            DB::table('failed_ticker_overviews')->insert([
                'ticker_id' => $tickerId,
                'ticker'    => $symbol,
                'reason'    => $reason,
                'failed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('ingest')->error("Failed to record ingestion failure", [
                'symbol' => $symbol,
                'reason' => $reason,
                'error'  => $e->getMessage(),
            ]);
        }

        Log::channel('ingest')->warning("❌ Polygon overview fetch failed", [
            'symbol' => $symbol,
            'reason' => $reason,
        ]);
    }
}
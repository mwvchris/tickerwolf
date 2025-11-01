<?php

namespace App\Services;

use App\Models\Ticker;
use App\Models\TickerFundamental;
use App\Models\TickerFundamentalMetric;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Throwable;

class PolygonFundamentalsService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected int $timeout;
    protected int $maxRetries;
    protected string $logChannel;

    public function __construct()
    {
        $this->baseUrl    = rtrim(config('services.polygon.base') ?? env('POLYGON_API_BASE', 'https://api.polygon.io'), '/');
        $this->apiKey     = (string) (config('services.polygon.key') ?? env('POLYGON_API_KEY'));
        $this->timeout    = (int) (env('POLYGON_API_TIMEOUT', 30));
        $this->maxRetries = (int) (env('POLYGON_API_RETRIES', 3));
        $this->logChannel = 'ingest'; // unified channel for all debug info
    }

    public function fetchAndStoreFundamentals(string $ticker, array $options = []): int
    {
        $symbol = strtoupper(trim($ticker));
        $tickerModel = Ticker::where('ticker', $symbol)->first();

        if (! $tickerModel) {
            Log::channel($this->logChannel)->warning("⚠️ Unknown ticker, skipping fundamentals ingestion", ['symbol' => $symbol]);
            return 0;
        }

        // 🔧 Respect Polygon's hard limit (max 100 per page)
        $limit = isset($options['limit']) && (int)$options['limit'] > 0 ? (int)$options['limit'] : 100;
        if ($limit > 100) $limit = 100;

        $params = [
            'ticker'    => $symbol,
            'limit'     => $limit,
            'order'     => in_array(strtolower($options['order'] ?? 'desc'), ['asc', 'desc'], true)
                ? strtolower($options['order'])
                : 'desc',
            'timeframe' => $options['timeframe'] ?? 'quarterly',
            'sort'      => $options['sort'] ?? 'filing_date',
        ];

        // ✅ Add Polygon filing_date filters if provided via command options
        foreach (['filing_date.gte', 'filing_date.gt', 'filing_date.lte', 'filing_date.lt'] as $key) {
            if (!empty($options[$key])) {
                $params[$key] = $options[$key];
            }
        }

        $endpoint = '/vX/reference/financials';
        $nextUrl  = $this->buildUrl("{$this->baseUrl}{$endpoint}", $params);

        Log::channel($this->logChannel)->info("🌍 Fetching fundamentals from Polygon", [
            'symbol' => $symbol,
            'url' => $nextUrl,
            'params' => $params,
        ]);

        $totalInserted = 0;
        $page = 0;

        while ($nextUrl) {
            $page++;
            $json = $this->safeGetJson($nextUrl);

            if (! $json) {
                Log::channel($this->logChannel)->warning("⚠️ Null or invalid JSON response", ['symbol' => $symbol, 'page' => $page]);
                break;
            }

            // Log snippet of the raw JSON for inspection
            Log::channel($this->logChannel)->debug("🧾 Raw JSON snippet", [
                'symbol' => $symbol,
                'page' => $page,
                'sample' => substr(json_encode(array_slice($json, 0, 5)), 0, 800),
            ]);

            $status  = $json['status'] ?? 'unknown';
            $results = $json['results'] ?? [];

            Log::channel($this->logChannel)->info("📄 Page summary", [
                'symbol' => $symbol,
                'page' => $page,
                'status' => $status,
                'results_count' => is_countable($results) ? count($results) : 0,
                'has_next_url' => isset($json['next_url']),
            ]);

            if (!is_array($results) || count($results) === 0) {
                Log::channel($this->logChannel)->warning("⚠️ No results returned for fundamentals", [
                    'symbol' => $symbol,
                    'status' => $status,
                    'json_keys' => array_keys($json ?? []),
                ]);
                break;
            }

            DB::beginTransaction();
            try {
                foreach ($results as $item) {
                    $topId = $this->upsertTopline($tickerModel, $symbol, $item);
                    if ($topId) {
                        $this->upsertMetrics($tickerModel, $symbol, $item, $topId);
                    }
                }
                DB::commit();
                $totalInserted += count($results);

                Log::channel($this->logChannel)->info("💾 DB commit successful", [
                    'symbol' => $symbol,
                    'page' => $page,
                    'inserted_count' => count($results),
                ]);

            } catch (Throwable $e) {
                DB::rollBack();
                Log::channel($this->logChannel)->error("❌ DB transaction failed", [
                    'symbol' => $symbol,
                    'page' => $page,
                    'error' => $e->getMessage(),
                    'trace' => substr($e->getTraceAsString(), 0, 500),
                ]);
                break;
            }

            $nextUrl = $json['next_url'] ?? null;
            if ($nextUrl) {
                $nextUrl = $this->appendApiKey($nextUrl);
                usleep(250_000);
            }
        }

        Log::channel($this->logChannel)->info("✅ Fundamentals ingestion complete", [
            'symbol' => $symbol,
            'total_inserted' => $totalInserted,
        ]);

        return $totalInserted;
    }

    protected function upsertTopline(Ticker $tickerModel, string $symbol, array $item): ?int
    {
        $unique = [
            'ticker_id'     => $tickerModel->id,
            'ticker'        => $symbol,
            'end_date'      => Arr::get($item, 'end_date'),
            'fiscal_period' => Arr::get($item, 'fiscal_period'),
            'fiscal_year'   => Arr::get($item, 'fiscal_year'),
        ];

        $balance  = Arr::get($item, 'financials.balance_sheet', []);
        $income   = Arr::get($item, 'financials.income_statement', []);
        $cashflow = Arr::get($item, 'financials.cash_flow_statement', []);

        $values = [
            'cik'                    => Arr::get($item, 'cik'),
            'company_name'           => Arr::get($item, 'company_name'),
            'timeframe'              => Arr::get($item, 'timeframe'),
            'status'                 => Arr::get($item, 'status'),
            'start_date'             => Arr::get($item, 'start_date'),
            'filing_date'            => Arr::get($item, 'filing_date'),
            'source_filing_url'      => Arr::get($item, 'source_filing_url'),
            'source_filing_file_url' => Arr::get($item, 'source_filing_file_url'),

            'total_assets'      => $this->num(Arr::get($balance, 'assets.value')),
            'total_liabilities' => $this->num(Arr::get($balance, 'liabilities.value')),
            'equity'            => $this->num(Arr::get($balance, 'equity.value')),
            'net_income'        => $this->num(Arr::get($income, 'net_income_loss.value')),
            'revenue'           => $this->num(Arr::get($income, 'revenues.value')),
            'operating_income'  => $this->num(Arr::get($income, 'operating_income_loss.value')),
            'gross_profit'      => $this->num(Arr::get($income, 'gross_profit.value')),
            'eps_basic'         => $this->num(Arr::get($income, 'basic_earnings_per_share.value'), 4),
            'eps_diluted'       => $this->num(Arr::get($income, 'diluted_earnings_per_share.value'), 4),

            'balance_sheet'        => $balance,
            'income_statement'     => $income,
            'cash_flow_statement'  => $cashflow,
            'comprehensive_income' => Arr::get($item, 'financials.comprehensive_income'),

            'raw'         => $item,
            'fetched_at'  => now(),
            'updated_at'  => now(),
            'created_at'  => now(),
        ];

        try {
            $existingId = TickerFundamental::query()->where($unique)->value('id');
            if ($existingId) {
                TickerFundamental::where('id', $existingId)->update($values);
                return (int)$existingId;
            }

            $record = TickerFundamental::create(array_merge($unique, $values));
            return $record->id ?? null;
        } catch (Throwable $e) {
            Log::channel($this->logChannel)->error("❌ Failed to upsert topline", [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
            ]);
            return null;
        }
    }

    protected function upsertMetrics(Ticker $tickerModel, string $symbol, array $item, ?int $topId): int
    {
        $financials = Arr::get($item, 'financials', []);
        if (empty($financials)) return 0;

        $endDate = Arr::get($item, 'end_date');
        $period  = Arr::get($item, 'fiscal_period');
        $year    = Arr::get($item, 'fiscal_year');
        $rows = [];
        $now = now()->toDateTimeString();

        foreach (['balance_sheet','income_statement','cash_flow_statement','comprehensive_income'] as $statement) {
            $section = Arr::get($financials, $statement, []);
            foreach ($section as $key => $node) {
                if (!is_array($node)) continue;
                $value = Arr::get($node, 'value');
                if (!is_numeric($value) && $value !== 0) continue;

                $rows[] = [
                    'ticker_id'      => $tickerModel->id,
                    'ticker'         => $symbol,
                    'fundamental_id' => $topId,
                    'statement'      => $statement,
                    'line_item'      => $key,
                    'label'          => Arr::get($node, 'label'),
                    'unit'           => Arr::get($node, 'unit'),
                    'display_order'  => Arr::get($node, 'order'),
                    'value'          => $this->num($value),
                    'end_date'       => $endDate,
                    'fiscal_period'  => $period,
                    'fiscal_year'    => $year,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }
        }

        if (empty($rows)) return 0;

        try {
            TickerFundamentalMetric::upsert(
                $rows,
                ['ticker_id', 'end_date', 'statement', 'line_item'],
                ['label','unit','display_order','value','fiscal_period','fiscal_year','updated_at']
            );

            Log::channel($this->logChannel)->debug("📈 Inserted fundamental metrics", [
                'symbol' => $symbol,
                'count' => count($rows),
            ]);

            return count($rows);
        } catch (Throwable $e) {
            Log::channel($this->logChannel)->error("❌ Failed to upsert metrics", [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
            ]);
            return 0;
        }
    }

    protected function safeGetJson(string $url): ?array
    {
        $attempt = 0;
        $wait = 2;

        while ($attempt < $this->maxRetries) {
            $attempt++;
            try {
                $resp = Http::timeout($this->timeout)->get($url);

                if ($resp->successful()) {
                    $data = $resp->json();
                    Log::channel($this->logChannel)->debug("✅ HTTP success", [
                        'url' => $url,
                        'attempt' => $attempt,
                        'status' => $resp->status(),
                    ]);
                    return (array) $data;
                }

                Log::channel($this->logChannel)->error("❌ Polygon HTTP error", [
                    'url' => $url,
                    'status' => $resp->status(),
                    'body_snippet' => substr($resp->body(), 0, 500),
                ]);

                if ($resp->status() === 429) {
                    $retryAfter = (int)($resp->header('Retry-After') ?? $wait);
                    Log::channel($this->logChannel)->warning("Rate limited, retrying...", [
                        'retry_after' => $retryAfter,
                    ]);
                    sleep($retryAfter);
                    continue;
                }

                return null;
            } catch (Throwable $e) {
                Log::channel($this->logChannel)->error("HTTP exception", [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                sleep($wait);
                $wait *= 2;
            }
        }

        return null;
    }

    // ✅ FIXED: Prevent encoding dots in query keys like "filing_date.gte"
    protected function buildUrl(string $base, array $params): string
    {
        $params['apiKey'] = $this->apiKey;

        // Build the query string manually to avoid encoding dots in keys
        $query = collect($params)->map(function ($v, $k) {
            return $k . '=' . urlencode((string) $v);
        })->implode('&');

        return $base . (str_contains($base, '?') ? '&' : '?') . $query;
    }

    protected function appendApiKey(string $url): string
    {
        return str_contains($url, 'apiKey=') ? $url : $url . (str_contains($url, '?') ? '&' : '?') . 'apiKey=' . urlencode($this->apiKey);
    }

    protected function num($v, int $scale = 2): ?float
    {
        if ($v === null || $v === '' || (!is_numeric($v) && $v !== 0 && $v !== '0')) {
            return null;
        }
        return round((float)$v, $scale);
    }
}
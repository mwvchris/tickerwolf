<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Model: Ticker
 * --------------------------------------------------------------------------
 * Represents a single tradable instrument (stock, ETF, crypto, etc.)
 * as defined by Polygon.io’s /v3/reference/tickers endpoint.
 *
 * Encapsulates:
 *  - Core reference metadata
 *  - Relationships to market data (prices, indicators, fundamentals, news, analysis)
 *  - Formatting helpers (percent change, market cap, currency)
 *  - Chart helpers for building series and down-sampling large ranges
 */
class Ticker extends Model
{
    use HasFactory;

    /** Table */
    protected $table = 'tickers';

    /** Mass assignable attributes */
    protected $fillable = [
        // --- Core fields from Polygon.io /v3/reference/tickers ---
        'ticker',
        'name',
        'market',
        'locale',
        'primary_exchange',
        'type',
        'status',
        'active',
        'currency_symbol',
        'currency_name',
        'base_currency_symbol',
        'base_currency_name',
        'cik',
        'composite_figi',
        'share_class_figi',
        'last_updated_utc',
        'delisted_utc',
        'raw',

        // --- Enriched / Overview metadata ---
        'description',
        'homepage_url',
        'list_date',
        'phone_number',
        'total_employees',
        'sic_code',
        'sic_description',
        'share_class_shares_outstanding',
        'weighted_shares_outstanding',
        'ticker_root',
        'ticker_suffix',
        'round_lot',
        'branding_logo_url',
        'branding_icon_url',
        'address_json',
    ];

    /** Casts for clean data types */
    protected $casts = [
        'active'                         => 'boolean',
        'raw'                            => 'array',
        'last_updated_utc'               => 'datetime',
        'delisted_utc'                   => 'datetime',
        'list_date'                      => 'date',
        'total_employees'                => 'integer',
        'share_class_shares_outstanding' => 'integer',
        'weighted_shares_outstanding'    => 'integer',
        'round_lot'                      => 'integer',
        'address_json'                   => 'array',
    ];

    public $timestamps = true;

    /* ----------------------------------------------------------------------
     | Relationships
     | ---------------------------------------------------------------------- */

    public function overviews()      { return $this->hasMany(TickerOverview::class, 'ticker_id'); }
    public function overview()       { return $this->hasOne(TickerOverview::class, 'ticker_id')->latestOfMany(); }
    public function fundamentals()   { return $this->hasMany(TickerFundamental::class, 'ticker_id'); }
    public function priceHistories() { return $this->hasMany(TickerPriceHistory::class, 'ticker_id'); }
    public function indicators()     { return $this->hasMany(TickerIndicator::class, 'ticker_id'); }
    public function analyses()       { return $this->hasMany(TickerAnalysis::class, 'ticker', 'ticker'); }
    public function analysis()       { return $this->hasOne(TickerAnalysis::class, 'ticker', 'ticker')->latestOfMany(); }
    public function newsItems()      { return $this->hasMany(TickerNewsItem::class, 'ticker_id'); }

    /* ----------------------------------------------------------------------
     | Derived Accessors & Helpers (display)
     | ---------------------------------------------------------------------- */

    /** SEO-friendly slug */
    public function getSlugAttribute($value): string
    {
        return $value ?: Str::slug($this->name ?? $this->ticker);
    }

    /** Company location from address JSON */
    public function getLocationAttribute(): ?string
    {
        $a = $this->address_json;
        if (! $a || !is_array($a)) return null;
        $city  = $a['city']  ?? null;
        $state = $a['state'] ?? null;
        return $city && $state ? "{$city}, {$state}" : ($city ?? $state ?? null);
    }

    /** Formatted employee count like “154,000” */
    public function getFormattedEmployeeCountAttribute(): ?string
    {
        return $this->total_employees ? number_format($this->total_employees) : null;
    }

    /** Latest closing price row */
    public function latestPrice(): ?\App\Models\TickerPriceHistory
    {
        return $this->priceHistories()->latest('t')->first();
    }

    /** Latest indicators row */
    public function latestIndicators(): ?\App\Models\TickerIndicator
    {
        return $this->indicators()->latest('t')->first();
    }

    /**
     * Fully-qualified branding logo URL.
     * In local/dev we append ?apiKey=... (safe for development).
     * In production: proxy/cache images via your own backend or S3/CDN (don’t expose API keys).
     */
    public function getLogoUrlAttribute(): ?string
    {
        $url = $this->branding_logo_url;
        if (! $url) return null;

        // Already has apiKey
        if (str_contains($url, 'apiKey=')) return $url;

        if (app()->environment('local') && Config::get('services.polygon.api_key')) {
            $url .= (str_contains($url, '?') ? '&' : '?')
                . 'apiKey=' . Config::get('services.polygon.api_key');
        }
        return $url;
    }

    /** Fully-qualified branding icon URL (same rules as logo_url) */
    public function getIconUrlAttribute(): ?string
    {
        $url = $this->branding_icon_url;
        if (! $url) return null;

        if (str_contains($url, 'apiKey=')) return $url;

        if (app()->environment('local') && Config::get('services.polygon.api_key')) {
            $url .= (str_contains($url, '?') ? '&' : '?')
                . 'apiKey=' . Config::get('services.polygon.api_key');
        }
        return $url;
    }

    /* ----------------------------------------------------------------------
     | Analytical Helpers
     | ---------------------------------------------------------------------- */

    /** Percent change over last N days using closing prices */
    public function percentChange(int $days): ?float
    {
        $latest = $this->latestPrice()?->c;
        $past = $this->priceHistories()
            ->where('t', '<=', now()->subDays($days))
            ->orderByDesc('t')
            ->value('c');

        if ($latest === null || $past === null || $past == 0) return null;
        return (($latest - $past) / $past) * 100;
    }

    /** Market cap formatted like $1.23 B, $456.78 M */
    public function formattedMarketCap(): string
    {
        $value = $this->overview?->market_cap ?? null;
        if ($value === null) return '—';
        if ($value >= 1_000_000_000) return '$' . number_format($value / 1_000_000_000, 2) . ' B';
        if ($value >= 1_000_000)     return '$' . number_format($value / 1_000_000, 2) . ' M';
        return '$' . number_format($value, 0);
    }

    /** Utility: currency formatting (kept simple here, can delegate to a helper later) */
    public function formatCurrency(?float $value): string
    {
        if ($value === null) return '—';
        $symbol = $this->currency_symbol ?: '$';
        return $symbol . number_format($value, 2);
    }

    /** A compact set of commonly used metrics for header cards/dashboards */
    public function metricsSummary(): array
    {
        return [
            'price'         => $this->latestPrice()?->c ?? null,
            'change_5d'     => $this->percentChange(5),
            'change_30d'    => $this->percentChange(30),
            'market_cap'    => $this->overview?->market_cap,
            'volume_latest' => $this->priceHistories()->latest('t')->value('v'),
            'employees'     => $this->total_employees,
            'location'      => $this->location,
        ];
    }

    /* ----------------------------------------------------------------------
     | Chart Helpers (aggregation + down-sampling)
     | ----------------------------------------------------------------------
     | We centralize series building here so controllers/blades stay thin.
     | Returned data is light and ready for JSON to feed ApexCharts.
     */

    /**
     * Get daily OHLCV between $start and $end (inclusive), ascending by time.
     * Returns a collection of:
     *   [ 't' => 'YYYY-MM-DD', 'o' => float|null, 'h' => float|null,
     *     'l' => float|null, 'c' => float|null, 'v' => int|null ]
     */
    public function ohlcvBetween($start, $end = null)
    {
        $start = $start ? Carbon::parse($start) : null;
        $end   = $end   ? Carbon::parse($end)   : null;

        $q = $this->priceHistories()->where('resolution', '1d');
        if ($start) $q->where('t', '>=', $start);
        if ($end)   $q->where('t', '<=', $end);

        return $q->orderBy('t', 'asc')
            ->get(['t', 'o', 'h', 'l', 'c', 'v'])
            ->map(function ($row) {
                $date = Carbon::parse($row->t)->toDateString(); // YYYY-MM-DD
                return [
                    't' => $date,
                    'o' => $row->o !== null ? (float) $row->o : null,
                    'h' => $row->h !== null ? (float) $row->h : null,
                    'l' => $row->l !== null ? (float) $row->l : null,
                    'c' => $row->c !== null ? (float) $row->c : null,
                    'v' => $row->v !== null ? (int) $row->v : null,
                ];
            })
            ->values();
    }

    /**
     * Down-sample a daily time series by keeping points at least $stepDays apart.
     * Always keeps the first and last points to preserve bounds.
     *
     * @param  \Illuminate\Support\Collection  $series  items like ['t'=>'YYYY-MM-DD','c'=>float]
     * @param  int $stepDays (e.g. 3 or 7)
     * @return \Illuminate\Support\Collection
     */
    public static function downsampleEveryNDays($series, int $stepDays)
    {
        if ($series->count() <= 2) return $series;

        $out = collect();
        $lastKept = null;

        foreach ($series as $i => $row) {
            $date = Carbon::parse($row['t'])->startOfDay();
            if ($i === 0) {
                $out->push($row);
                $lastKept = $date;
                continue;
            }
            if ($date->diffInDays($lastKept) >= $stepDays) {
                $out->push($row);
                $lastKept = $date;
            }
        }

        // Ensure last point included
        if ($out->last() !== $series->last()) {
            $out->push($series->last());
        }

        return $out->values();
    }

    /**
     * Build a chart-ready "price only" series for a named timeframe.
     *
     * Returns:
     * [
     *   'points' => [ ['x' => '2025-01-01', 'y' => 123.45], ... ],
     *   'meta'   => [ 'range' => '1M', 'stepDays' => 1|3|7 ]
     * ]
     *
     * Timeframe rules:
     *  - 1D, 5D, 1M, 6M : daily points
     *  - YTD, 1Y       : default daily, but can be downsampled to every 3 days if requested
     *  - 5Y, MAX       : every 7 days
     */
    public function buildPriceSeriesForRange(string $range, bool $favorSparseForLargeRanges = true): array
    {
        $now = Carbon::now();
        $start = null;
        $stepDays = 1;

        switch (strtoupper($range)) {
            case '1D':
                $start = $now->copy()->subDays(1);
                $stepDays = 1;
                break;
            case '5D':
            case '1W':
                $start = $now->copy()->subDays(7);
                $stepDays = 1;
                break;
            case '1M':
                $start = $now->copy()->subDays(30);
                $stepDays = 1;
                break;
            case '6M':
                $start = $now->copy()->subMonthsNoOverflow(6);
                $stepDays = 1;
                break;
            case 'YTD':
                $start = Carbon::create($now->year, 1, 1, 0, 0, 0);
                $stepDays = $favorSparseForLargeRanges ? 3 : 1;
                break;
            case '1Y':
                $start = $now->copy()->subYear();
                $stepDays = $favorSparseForLargeRanges ? 2 : 1;
                break;
            case '5Y':
                $start = $now->copy()->subYears(5);
                $stepDays = 5;
                break;
            case 'MAX':
            default:
                $start = null; // all history
                $stepDays = 7;
                break;
        }

        $series = $this->ohlcvBetween($start, $now);

        if ($stepDays > 1) {
            // downsample based on 't' (date) spacing
            $series = self::downsampleEveryNDays($series, $stepDays);
        }

        return [
            // Lightweight `{x,y}` data for ApexCharts (x will be treated as datetime)
            'points' => $series->map(fn($row) => ['x' => $row['t'], 'y' => $row['c']])->values()->all(),
            'meta'   => ['range' => strtoupper($range), 'stepDays' => $stepDays],
        ];
    }

    /* ----------------------------------------------------------------------
     | Scopes
     | ---------------------------------------------------------------------- */

    public function scopeActive($query) { return $query->where('active', true); }

    public function scopeBySymbol($query, string $symbol)
    {
        return $query->whereRaw('LOWER(ticker) = ?', [strtolower($symbol)]);
    }
}
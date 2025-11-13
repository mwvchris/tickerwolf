<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * Model: Ticker
 * -----------------------------------------------------------------------------
 * Represents a single tradable instrument (stock, ETF, crypto, etc.)
 * as defined by Polygon.io’s /v3/reference/tickers endpoint.
 *
 * Encapsulates:
 *  - Core reference metadata
 *  - Relationships to market data (prices, indicators, fundamentals, news, analysis)
 *  - Formatting helpers (percent change, market cap, currency)
 *  - Chart helpers for building series and down-sampling large ranges
 *  - Daily/header stats accessors (open, high, low, prevClose, volume, 52-wk range, etc.)
 *  - Market session helpers (regular vs. extended) for header labels
 * -----------------------------------------------------------------------------
 */
class Ticker extends Model
{
    use HasFactory;

    /** @var string */
    protected $table = 'tickers';

    /**
     * Mass assignable attributes.
     *
     * @var array<int, string>
     */
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

    /**
     * Attribute casting.
     *
     * @var array<string, string>
     */
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

    /** @var bool */
    public $timestamps = true;

    /* ----------------------------------------------------------------------
     | Relationships
     | ---------------------------------------------------------------------- */

    public function overviews()
    {
        return $this->hasMany(TickerOverview::class, 'ticker_id');
    }

    public function overview()
    {
        return $this->hasOne(TickerOverview::class, 'ticker_id')->latestOfMany();
    }

    public function fundamentals()
    {
        return $this->hasMany(TickerFundamental::class, 'ticker_id');
    }

    public function priceHistories()
    {
        return $this->hasMany(TickerPriceHistory::class, 'ticker_id');
    }

    public function indicators()
    {
        return $this->hasMany(TickerIndicator::class, 'ticker_id');
    }

    public function analyses()
    {
        return $this->hasMany(TickerAnalysis::class, 'ticker', 'ticker');
    }

    public function analysis()
    {
        return $this->hasOne(TickerAnalysis::class, 'ticker', 'ticker')->latestOfMany();
    }

    public function newsItems()
    {
        return $this->hasMany(TickerNewsItem::class, 'ticker_id');
    }

    /* ----------------------------------------------------------------------
     | Derived Accessors & Helpers (display)
     | ---------------------------------------------------------------------- */

    /**
     * SEO-friendly slug.
     *
     * @return string
     *
     * @example
     * <a href="{{ route('tickers.show', [$ticker->ticker, $ticker->slug]) }}">
     *   {{ $ticker->clean_display_name ?? $ticker->name }}
     * </a>
     */
    public function getSlugAttribute($value): string
    {
        return $value ?: Str::slug($this->name ?? $this->ticker);
    }

    /**
     * Company location derived from address JSON (e.g., "Austin, TX").
     *
     * @return string|null
     *
     * @example
     * <span class="text-slate-400">{{ $ticker->location ?? '—' }}</span>
     */
    public function getLocationAttribute(): ?string
    {
        $a = $this->address_json;
        if (! $a || ! is_array($a)) {
            return null;
        }

        $city  = $a['city']  ?? null;
        $state = $a['state'] ?? null;

        return $city && $state ? "{$city}, {$state}" : ($city ?? $state ?? null);
    }

    /**
     * Formatted employee count like “154,000”.
     *
     * @return string|null
     *
     * @example
     * <span>{{ $ticker->formatted_employee_count ?? '—' }}</span>
     */
    public function getFormattedEmployeeCountAttribute(): ?string
    {
        return $this->total_employees ? number_format($this->total_employees) : null;
    }

    /**
     * Latest daily price row (t, o, h, l, c, v).
     *
     * @return \App\Models\TickerPriceHistory|null
     *
     * @example
     * {{ optional($ticker->latestPrice())->c ?? '—' }}
     */
    public function latestPrice(): ?\App\Models\TickerPriceHistory
    {
        return $this->priceHistories()->latest('t')->first();
    }

    /**
     * Previous daily price row (yesterday) used for prevClose/changes.
     *
     * @return \App\Models\TickerPriceHistory|null
     *
     * @example
     * {{ optional($ticker->previousPrice())->c ?? '—' }}
     */
    public function previousPrice(): ?\App\Models\TickerPriceHistory
    {
        $rows = $this->priceHistories()
            ->orderBy('t', 'desc')
            ->limit(2)
            ->get();

        // index 0 == latest, 1 == previous
        return $rows->get(1);
    }

    /**
     * Latest indicators row.
     *
     * @return \App\Models\TickerIndicator|null
     *
     * @example
     * {{ optional($ticker->latestIndicators())->rsi ?? '—' }}
     */
    public function latestIndicators(): ?\App\Models\TickerIndicator
    {
        return $this->indicators()->latest('t')->first();
    }

    /**
     * Fully-qualified branding logo URL (Polygon, adds apiKey in local).
     *
     * @return string|null
     *
     * @example
     * @if ($ticker->logo_url)
     *   <img src="{{ $ticker->logo_url }}" alt="{{ $ticker->clean_display_name ?? $ticker->name }} logo" class="h-8 w-8 rounded" />
     * @endif
     */
    public function getLogoUrlAttribute(): ?string
    {
        $url = $this->branding_logo_url;
        if (! $url) {
            return null;
        }

        if (str_contains($url, 'apiKey=')) {
            return $url;
        }

        if (app()->environment('local') && Config::get('services.polygon.key')) {
            $url .= (str_contains($url, '?') ? '&' : '?')
                . 'apiKey=' . Config::get('services.polygon.key');
        }

        return $url;
    }

    /**
     * Fully-qualified branding icon URL (Polygon, adds apiKey in local).
     *
     * @return string|null
     *
     * @example
     * @if ($ticker->icon_url)
     *   <img src="{{ $ticker->icon_url }}" alt="{{ $ticker->clean_display_name ?? $ticker->name }} icon" class="h-5 w-5 rounded" />
     * @endif
     */
    public function getIconUrlAttribute(): ?string
    {
        $url = $this->branding_icon_url;
        if (! $url) {
            return null;
        }

        if (str_contains($url, 'apiKey=')) {
            return $url;
        }

        if (app()->environment('local') && Config::get('services.polygon.key')) {
            $url .= (str_contains($url, '?') ? '&' : '?')
                . 'apiKey=' . Config::get('services.polygon.key');
        }

        return $url;
    }

    /**
     * Clean base company name (removes trailing instrument descriptors).
     * Example: "Arch Capital Group Ltd. Depositary Shares..." → "Arch Capital Group Ltd"
     *
     * @return string|null
     *
     * @example
     * <h1 class="text-xl font-semibold">
     *   {{ $ticker->clean_base_name ?? $ticker->name }}
     * </h1>
     */
    public function getCleanBaseNameAttribute(): ?string
    {
        $name = trim($this->name ?? '');
        if ($name === '') {
            return null;
        }

        // Remove known suffixes but leave identifiers like "Series F" or "Class A"
        $patterns = [
            '/\b(common|ordinary)\s+stock\b/i',
            '/\b(preferred|redeemable|depositary|depository)\s+shares?\b/i',
            '/\bwarrants?\b/i',
            '/\betf\b/i',
            '/\btrust\s+units?\b/i',
            '/\bnotes?\b/i',
            '/\bincome\s+strategy\b/i',
            '/\bfund\b/i',
            '/\bexchange[-\s]*traded\s+fund\b/i',
        ];

        $cleaned = preg_replace($patterns, '', $name);
        $cleaned = preg_replace('/\s{2,}/', ' ', $cleaned);
        $cleaned = trim($cleaned, " \t\n\r\0\x0B-");
        $cleaned = preg_replace('/[,\.\-]+$/', '', $cleaned);

        // Normalize punctuation spacing and capitalization
        $cleaned = preg_replace('/\s*,\s*/', ', ', $cleaned);
        $cleaned = preg_replace('/\s*\.\s*/', '. ', $cleaned);
        $cleaned = preg_replace('/\s{2,}/', ' ', $cleaned);

        return ucfirst($cleaned);
    }

    /**
     * Clean display name that preserves distinguishing identifiers (Series, Class).
     *
     * @return string|null
     *
     * @example
     * <h1 class="text-xl font-semibold">
     *   {{ $ticker->clean_display_name ?? $ticker->name }}
     * </h1>
     */
    public function getCleanDisplayNameAttribute(): ?string
    {
        $name = trim($this->name ?? '');
        if ($name === '') {
            return null;
        }

        $base = $this->clean_base_name ?? $name;

        // Attempt to extract unique identifiers (Series, Class, etc.)
        $matches = [];
        preg_match('/(Series\s+[A-Z0-9]+|Class\s+[A-Z0-9]+)/i', $name, $matches);
        $suffix = $matches[1] ?? null;

        // Detect preferred or warrant instruments
        $isPreferred  = stripos($name, 'preferred') !== false;
        $isWarrant    = stripos($name, 'warrant') !== false;
        $isDepositary = stripos($name, 'depositary') !== false;

        $descriptor = '';
        if ($isPreferred) {
            $descriptor = 'Preferred';
        } elseif ($isWarrant) {
            $descriptor = 'Warrant';
        } elseif ($isDepositary) {
            $descriptor = 'Depositary Shares';
        }

        // Compose readable short display version
        $display = $base;
        if ($suffix || $descriptor) {
            $display .= ' – ' . trim(($suffix ?? '') . ' ' . ($descriptor ?? ''));
        }

        // Clean up double spaces and punctuation
        $display = preg_replace('/\s{2,}/', ' ', $display);

        return trim($display, " \t\n\r\0\x0B-");
    }

    /* ----------------------------------------------------------------------
     | Exchange Translators for Polygon.io `primary_exchange`
     | ---------------------------------------------------------------------- */

    /**
     * Descriptive, human-friendly exchange name (e.g., "NASDAQ Stock Market").
     *
     * @return string|null
     *
     * @example
     * <span class="text-slate-400">
     *   {{ $ticker->exchange_name ?? $ticker->primary_exchange }}
     * </span>
     */
    public function getExchangeNameAttribute(): ?string
    {
        $code = strtoupper(trim($this->primary_exchange ?? ''));
        if ($code === '') {
            return null;
        }

        $map = [
            // 🇺🇸 U.S. Exchanges
            'XNYS'     => 'New York Stock Exchange',
            'XNAS'     => 'NASDAQ Stock Market',
            'XASE'     => 'NYSE American',
            'ARCX'     => 'NYSE Arca',
            'BATS'     => 'Cboe BZX Exchange',
            'OTC'      => 'OTC Markets',
            'OTC LINK' => 'OTC Markets',
            'OTCM'     => 'OTC Markets',
            'OTCBB'    => 'OTC Bulletin Board',

            // 🇨🇦 Canada
            'XTSE'     => 'Toronto Stock Exchange',
            'XTSX'     => 'TSX Venture Exchange',
            'CSE'      => 'Canadian Securities Exchange',

            // 🇬🇧 United Kingdom / 🇪🇺 Europe
            'XLON'     => 'London Stock Exchange',
            'IOB'      => 'LSE – International Order Book',
            'XETR'     => 'Deutsche Börse (XETRA)',
            'XFRA'     => 'Frankfurt Stock Exchange',
            'XBER'     => 'Berlin Stock Exchange',
            'XMUN'     => 'Munich Stock Exchange',
            'XPAR'     => 'Euronext Paris',

            // 🇯🇵 Japan
            'XTKS'     => 'Tokyo Stock Exchange',
            'XJAS'     => 'JASDAQ',

            // 🇭🇰 Hong Kong
            'XHKG'     => 'Hong Kong Stock Exchange',

            // 🇮🇳 India
            'XBOM'     => 'Bombay Stock Exchange',
            'XNSE'     => 'National Stock Exchange of India',

            // 🇦🇺 Australia
            'XASX'     => 'Australian Securities Exchange',

            // 🇨🇭 Switzerland
            'XSWX'     => 'SIX Swiss Exchange',

            // 🇸🇬 Singapore
            'XSES'     => 'Singapore Exchange',

            // 🇨🇳 China
            'XSHG'     => 'Shanghai Stock Exchange',
            'XSHE'     => 'Shenzhen Stock Exchange',

            // 🇰🇷 South Korea
            'XKRX'     => 'Korea Exchange',

            // 🇧🇷 Brazil
            'BVMF'     => 'B3 – Brasil Bolsa Balcão',

            // 🇮🇹 Italy
            'XMIL'     => 'Borsa Italiana',
        ];

        $normalized = preg_replace('/[^A-Z0-9 ]/', '', $code);

        return $map[$normalized]
            ?? $map[str_replace(['.', '-', '_'], '', $normalized)]
            ?? $code;
    }

    /**
     * Short exchange code for UI tags (e.g., "NASDAQ", "NYSE").
     *
     * @return string|null
     *
     * @example
     * <span class="badge">{{ $ticker->exchange_short ?? $ticker->primary_exchange }}</span>
     */
    public function getExchangeShortAttribute(): ?string
    {
        $code = strtoupper(trim($this->primary_exchange ?? ''));
        if ($code === '') {
            return null;
        }

        $map = [
            'XNYS'     => 'NYSE',
            'XNAS'     => 'NASDAQ',
            'XASE'     => 'AMEX',
            'ARCX'     => 'ARCA',
            'BATS'     => 'CBOE',
            'OTC'      => 'OTC',
            'OTC LINK' => 'OTC',

            // Canada
            'XTSE' => 'TSX',
            'XTSX' => 'TSXV',
            'CSE'  => 'CSE',

            // UK / EU
            'XLON' => 'LSE',
            'XETR' => 'XETRA',
            'XFRA' => 'FWB',
            'XPAR' => 'EURONEXT',

            // Asia-Pacific
            'XTKS' => 'TSE',
            'XHKG' => 'HKEX',
            'XASX' => 'ASX',
            'XNSE' => 'NSE',
            'XBOM' => 'BSE',

            // Other
            'XSWX' => 'SIX',
            'XSES' => 'SGX',
            'XSHG' => 'SSE',
            'XSHE' => 'SZSE',
            'XKRX' => 'KRX',
            'BVMF' => 'B3',
            'XMIL' => 'MIL',
        ];

        $normalized = preg_replace('/[^A-Z0-9 ]/', '', $code);

        return $map[$normalized]
            ?? $map[str_replace(['.', '-', '_'], '', $normalized)]
            ?? $code;
    }

    /**
     * Flag indicating whether this is a U.S. exchange.
     *
     * @return bool
     *
     * @example
     * @if ($ticker->is_us_exchange)
     *   <span class="badge badge-success">US</span>
     * @endif
     */
    public function getIsUsExchangeAttribute(): bool
    {
        return in_array($this->exchange_short, [
            'NYSE', 'NASDAQ', 'AMEX', 'ARCA', 'CBOE', 'OTC',
        ], true);
    }

    /* ----------------------------------------------------------------------
     | Daily/Header Stats (derived from price_histories)
     | ---------------------------------------------------------------------- */

    /**
     * Today's open price (from latest row's "o").
     *
     * @return float|null
     *
     * @example
     * {{ $ticker->day_open !== null ? \App\Helpers\FormatHelper::currency($ticker->day_open) : '—' }}
     */
    public function getDayOpenAttribute(): ?float
    {
        return $this->latestPrice()?->o;
    }

    /**
     * Today's high price (from latest row's "h").
     *
     * @return float|null
     *
     * @example
     * {{ \App\Helpers\FormatHelper::currency($ticker->day_high) }}
     */
    public function getDayHighAttribute(): ?float
    {
        return $this->latestPrice()?->h;
    }

    /**
     * Today's low price (from latest row's "l").
     *
     * @return float|null
     *
     * @example
     * {{ \App\Helpers\FormatHelper::currency($ticker->day_low) }}
     */
    public function getDayLowAttribute(): ?float
    {
        return $this->latestPrice()?->l;
    }

    /**
     * Previous close (yesterday's "c"), used for day change.
     *
     * @return float|null
     *
     * @example
     * {{ \App\Helpers\FormatHelper::currency($ticker->prev_close) }}
     */
    public function getPrevCloseAttribute(): ?float
    {
        return $this->previousPrice()?->c;
    }

    /**
     * Latest close (today's "c").
     *
     * @return float|null
     *
     * @example
     * <span class="text-2xl font-semibold">
     *   {{ \App\Helpers\FormatHelper::currency($ticker->last_close) }}
     * </span>
     */
    public function getLastCloseAttribute(): ?float
    {
        return $this->latestPrice()?->c;
    }

    /**
     * Day change absolute (last_close - prev_close).
     *
     * @return float|null
     *
     * @example
     * {{ \App\Helpers\FormatHelper::signedCurrencyChange($ticker->day_change_abs) }}
     */
    public function getDayChangeAbsAttribute(): ?float
    {
        $last = $this->last_close;
        $prev = $this->prev_close;

        if ($last === null || $prev === null) {
            return null;
        }

        return $last - $prev;
    }

    /**
     * Day change percent vs. previous close.
     *
     * @return float|null
     *
     * @example
     * <span class="{{ ($ticker->day_change_pct ?? 0) >= 0 ? 'text-emerald-500' : 'text-rose-500' }}">
     *   {{ \App\Helpers\FormatHelper::percent($ticker->day_change_pct) }}
     * </span>
     */
    public function getDayChangePctAttribute(): ?float
    {
        $last = $this->last_close;
        $prev = $this->prev_close;

        if ($last === null || $prev === null || $prev == 0.0) {
            return null;
        }

        return (($last - $prev) / $prev) * 100;
    }

    /**
     * Latest daily volume (v).
     *
     * @return int|null
     *
     * @example
     * {{ \App\Helpers\FormatHelper::humanVolume($ticker->volume_latest) }}
     */
    public function getVolumeLatestAttribute(): ?int
    {
        $v = $this->priceHistories()->latest('t')->value('v');

        return $v !== null ? (int) $v : null;
    }

    /**
     * Average daily volume over the last 30 trading days.
     *
     * @return float|null
     *
     * @example
     * {{ \App\Helpers\FormatHelper::humanVolume($ticker->avg_volume_30d) }}
     */
    public function getAvgVolume30dAttribute(): ?float
    {
        return $this->averageVolume(30);
    }

    /**
     * Compute average volume over the last $days non-null rows.
     *
     * @param  int  $days
     * @return float|null
     */
    public function averageVolume(int $days = 30): ?float
    {
        $rows = $this->priceHistories()
            ->whereNotNull('v')
            ->orderBy('t', 'desc')
            ->limit($days)
            ->pluck('v');

        if ($rows->count() === 0) {
            return null;
        }

        return (float) ($rows->sum() / $rows->count());
    }

    /**
     * 52-week high (max "h" over last ~252 trading days).
     *
     * @return float|null
     *
     * @example
     * {{ \App\Helpers\FormatHelper::currency($ticker->high_52w) }}
     */
    public function getHigh52wAttribute(): ?float
    {
        return $this->priceHistories()
            ->orderBy('t', 'desc')
            ->limit(252)
            ->max('h');
    }

    /**
     * 52-week low (min "l" over last ~252 trading days).
     *
     * @return float|null
     *
     * @example
     * {{ \App\Helpers\FormatHelper::currency($ticker->low_52w) }}
     */
    public function getLow52wAttribute(): ?float
    {
        return $this->priceHistories()
            ->orderBy('t', 'desc')
            ->limit(252)
            ->min('l');
    }

    /* ----------------------------------------------------------------------
     | Market Session Helpers (Option C)
     | ----------------------------------------------------------------------
     | Regular session: used for “At close” label.
     | Extended session: used for “After hours / Pre-market” label.
     | For now we synthesize extended timestamp as “close + 3h” so the UI
     | wiring is ready; later you can plug in true intraday feeds.
     | ---------------------------------------------------------------------- */

    /**
     * Get the timezone used for U.S. market session labels.
     *
     * @return \DateTimeZone
     */
    protected function marketTimezone(): \DateTimeZone
    {
        // You can later make this exchange-specific if you support
        // non-US exchanges with local session times.
        return new \DateTimeZone('America/New_York');
    }

    /**
     * Return structured info for the regular session close time.
     *
     * @return array<string, mixed>|null
     *
     * @example
     * @php($regular = $ticker->regularSessionStats())
     * <span class="text-xs text-slate-500">
     *   {{ $regular['label'] ?? 'At close: —' }}
     * </span>
     */
    public function regularSessionStats(): ?array
    {
        $latest = $this->latestPrice();
        if (! $latest || ! $latest->t) {
            return null;
        }

        $dt = Carbon::parse($latest->t)->setTimezone($this->marketTimezone());

        return [
            'timestamp' => $dt,
            'label'     => sprintf(
                'At close: %s',
                $dt->format('M j, g:i:s A T')
            ),
        ];
    }

    /**
     * Return structured info for an extended session snapshot.
     *
     * For now we synthesize a plausible timestamp (“close + 3h”) so the UI
     * can be wired in; when you have real pre-market/after-hours prices,
     * you can source both price & timestamp from those feeds.
     *
     * @return array<string, mixed>|null
     *
     * @example
     * @php($extended = $ticker->extendedSessionStats())
     * @if($extended)
     *   <span class="text-xs text-slate-500">
     *     {{ $extended['label'] }}
     *   </span>
     * @endif
     */
    public function extendedSessionStats(): ?array
    {
        $latest = $this->latestPrice();
        if (! $latest || ! $latest->t) {
            return null;
        }

        $dt = Carbon::parse($latest->t)
            ->setTimezone($this->marketTimezone())
            ->addHours(3); // synthetic placeholder

        return [
            'timestamp' => $dt,
            'label'     => sprintf(
                'After hours: %s',
                $dt->format('M j, g:i:s A T')
            ),
        ];
    }

    /* ----------------------------------------------------------------------
     | Analytical Helpers
     | ---------------------------------------------------------------------- */

    /**
     * Percent change over last N days using closing prices.
     *
     * @param  int  $days
     * @return float|null
     *
     * @example
     * {{ \App\Helpers\FormatHelper::percent($ticker->percentChange(5)) }}
     */
    public function percentChange(int $days): ?float
    {
        $latest = $this->latestPrice()?->c;

        $past = $this->priceHistories()
            ->where('t', '<=', now()->subDays($days))
            ->orderByDesc('t')
            ->value('c');

        if ($latest === null || $past === null || $past == 0) {
            return null;
        }

        return (($latest - $past) / $past) * 100;
    }

    /**
     * Market cap formatted like "$1.23 B".
     *
     * @return string
     *
     * @example
     * {{ $ticker->formattedMarketCap() }}
     */
    public function formattedMarketCap(): string
    {
        $value = $this->overview?->market_cap ?? null;

        if ($value === null) {
            return '—';
        }
        if ($value >= 1_000_000_000) {
            return '$' . number_format($value / 1_000_000_000, 2) . ' B';
        }
        if ($value >= 1_000_000) {
            return '$' . number_format($value / 1_000_000, 2) . ' M';
        }

        return '$' . number_format($value, 0);
    }

    /**
     * Simple currency formatting using the model's currency symbol if present.
     *
     * @param  float|null  $value
     * @return string
     *
     * @example
     * {{ $ticker->formatCurrency($ticker->last_close) }}
     */
    public function formatCurrency(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        $symbol = $this->currency_symbol ?: '$';

        return $symbol . number_format($value, 2);
    }

    /**
     * Compact metrics summary (useful for JSON cards, debugging, etc.).
     *
     * @return array<string, mixed>
     *
     * @example
     * {{ \Illuminate\Support\Js::from($ticker->metricsSummary()) }}
     */
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
     | ---------------------------------------------------------------------- */

    /**
     * Get daily OHLCV between $start and $end (inclusive), ascending by time.
     *
     * @param  \DateTimeInterface|string|null  $start
     * @param  \DateTimeInterface|string|null  $end
     * @return \Illuminate\Support\Collection<int, array{
     *     t:string,
     *     o:?float,
     *     h:?float,
     *     l:?float,
     *     c:?float,
     *     v:?int
     * }>
     */
    public function ohlcvBetween($start, $end = null)
    {
        $start = $start ? Carbon::parse($start) : null;
        $end   = $end   ? Carbon::parse($end)   : null;

        $q = $this->priceHistories()->where('resolution', '1d');

        if ($start) {
            $q->where('t', '>=', $start);
        }
        if ($end) {
            $q->where('t', '<=', $end);
        }

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
     * Down-sample a daily time series by keeping roughly every Nth point.
     * Always keeps first & last elements.
     *
     * @param  \Illuminate\Support\Collection  $series
     * @param  int  $stepDays
     * @return \Illuminate\Support\Collection
     */
    public static function downsampleEveryNDays($series, int $stepDays)
    {
        $count = $series->count();
        if ($count <= 2 || $stepDays <= 1) {
            return $series->values();
        }

        $step = max(1, (int) round($stepDays));

        $out = collect();

        foreach ($series->values() as $i => $row) {
            if ($i === 0 || $i % $step === 0 || $i === $count - 1) {
                $out->push($row);
            }
        }

        return $out->values();
    }

    /**
     * Build a chart-ready "price only" series for a named timeframe.
     *
     * @param  string  $range
     * @param  bool    $favorSparseForLargeRanges
     * @return array{
     *     points: array<int, array{x:string,y:?float}>,
     *     meta: array<string, mixed>
     * }
     *
     * @example
     * {{-- Controller already prepares $chartSeries[...] --}}
     * <x-json :data="$chartSeries['1M']" />
     */
    public function buildPriceSeriesForRange(string $range, bool $favorSparseForLargeRanges = true): array
    {
        $now      = Carbon::now();
        $start    = null;
        $stepDays = 1;

        switch (strtoupper($range)) {
            case '1D':
                $start    = $now->copy()->subDays(1);
                $stepDays = 1;
                break;

            case '5D':
            case '1W':
                $start    = $now->copy()->subDays(7);
                $stepDays = 1;
                break;

            case '1M':
                $start    = $now->copy()->subDays(30);
                $stepDays = 1;
                break;

            case '6M':
                $start    = $now->copy()->subMonthsNoOverflow(6);
                $stepDays = 1;
                break;

            case 'YTD':
                $start    = Carbon::create($now->year, 1, 1, 0, 0, 0);
                $stepDays = $favorSparseForLargeRanges ? 3 : 1;
                break;

            case '1Y':
                $start    = $now->copy()->subYear();
                $stepDays = $favorSparseForLargeRanges ? 3 : 1;
                break;

            case '5Y':
                $start    = $now->copy()->subYears(5);
                $stepDays = 5;
                break;

            case 'MAX':
            default:
                $start    = null; // all history
                $stepDays = 7;
                break;
        }

        $series = $this->ohlcvBetween($start, $now);

        if ($stepDays > 1) {
            $series = self::downsampleEveryNDays($series, $stepDays);
        }

        return [
            'points' => $series->map(fn ($row) => ['x' => $row['t'], 'y' => $row['c']])->values()->all(),
            'meta'   => [
                'range'    => strtoupper($range),
                'stepDays' => $stepDays,
                'count'    => $series->count(),
            ],
        ];
    }

    /* ----------------------------------------------------------------------
     | Scopes
     | ---------------------------------------------------------------------- */

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeBySymbol($query, string $symbol)
    {
        return $query->whereRaw('LOWER(ticker) = ?', [strtolower($symbol)]);
    }
}
<?php

namespace App\Services;

use App\Models\Ticker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * ============================================================================
 *  Service: PolygonRealtimePriceService
 * ============================================================================
 *
 * Provides fast, cached 1-minute intraday (delayed) price snapshots via:
 *    - Polygon Aggregates API (/v2/aggs/ticker/{}/range/1/minute/...)
 *    - Redis sliding TTL cache (configurable via polygon.intraday_cache_ttl)
 *
 * Core design:
 *   - ❌ Do NOT store intraday bars in MariaDB (avoids billions of rows)
 *   - ✅ Cache compact snapshots in Redis
 *   - ✅ Use PolygonPriceHistoryService for HTTP fetch + retry logic
 *   - ✅ Provide controller-friendly access for header stats, LLM prompts, charts
 *
 * Snapshot format example:
 *   [
 *     'symbol'              => 'AAPL',
 *     'as_of'               => '2025-11-12T19:45:00Z',
 *     'session'             => 'after',
 *     'session_label'       => 'After hours',
 *     'session_time_human'  => 'Nov 12, 3:45 PM ET',
 *     'last_price'          => 192.34,
 *     'prev_close'          => 191.02,
 *     'change_abs'          => 1.32,
 *     'change_pct'          => 0.69,
 *     'day_high'            => 193.10,
 *     'day_low'             => 189.50,
 *     'volume'              => 12453000,
 *     'bars'                => [ ... 1m bars over last N hours ... ],
 *   ]
 *
 * Redis key pattern (with default prefix):
 *     {redis_prefix}:snap:{SYMBOL}:{YYYY-MM-DD}
 *
 * TTLs:
 *   - Controlled by polygon.intraday_cache_ttl (e.g. several days)
 *   - Prefetch job runs every minute, keeping keys warm on active days
 *
 * Extended-hours support:
 *   - We request 1-minute bars with extended=true over a rolling window
 *     (configurable via polygon.intraday_max_age_minutes).
 *   - Session classification covers:
 *       • pre       04:00–09:30 ET   (Pre-market)
 *       • regular   09:30–16:00 ET   (Cash session)
 *       • after     16:00–20:00 ET   (After-hours)
 *       • overnight 20:00–04:00 ET   (24/5 / overnight trading)
 *       • closed    all other / weekends / holidays
 *
 * Weekend / holiday fallback:
 *   - If Polygon returns NO bars for the requested window (weekend, holiday,
 *     symbol inactive, etc.), we automatically fall back to the most recent
 *     cached snapshot in Redis (e.g. Friday after-hours) and re-cache it under
 *     today’s key. This ensures:
 *       • Friday after-hours prices show all weekend
 *       • Old snapshot is used until fresh intraday data becomes available
 *
 * NOTE (Option A contract):
 *   - This service now calls Polygon via PolygonPriceHistoryService using
 *     EPOCH-MILLISECOND timestamps (as strings) for {from}/{to}, but the
 *     PolygonPriceHistoryService signature is unchanged and continues to be
 *     used by your daily-history subsystem without modification.
 *
 * TODO (future):
 *   - Add Alpaca (or other real-time feed) as a secondary provider; preserve
 *     this interface so controllers and views remain unchanged.
 * ============================================================================
 */
class PolygonRealtimePriceService
{
    /**
     * Underlying Polygon aggregates service.
     *
     * @var \App\Services\PolygonPriceHistoryService
     */
    protected PolygonPriceHistoryService $historyService;

    /** @var string Redis key prefix (e.g. "intraday") */
    protected string $redisPrefix;

    /** @var int Cache TTL in seconds */
    protected int $cacheTtl;

    /** @var string Market timezone (e.g. America/New_York) */
    protected string $marketTimezone;

    /** @var int Delay built into your Polygon plan (minutes) */
    protected int $intradayDelayMinutes;

    /** @var int Maximum age of intraday data to fetch (in minutes) */
    protected int $maxAgeMinutes;

    /** @var string Polygon intraday timespan (usually "minute") */
    protected string $intradayTimespan;

    /** @var int Polygon intraday multiplier (usually 1) */
    protected int $intradayMultiplier;

    /**
     * Constructor — loads config and injects core history service.
     */
    public function __construct(PolygonPriceHistoryService $historyService)
    {
        $this->historyService        = $historyService;
        $this->redisPrefix           = config('polygon.intraday_redis_prefix', 'intraday');
        $this->cacheTtl              = (int) config('polygon.intraday_cache_ttl', 900);
        $this->marketTimezone        = config('polygon.market_timezone', 'America/New_York');
        $this->intradayDelayMinutes  = (int) config('polygon.intraday_delay_minutes', 15);
        $this->maxAgeMinutes         = (int) config('polygon.intraday_max_age_minutes', 1440);
        $this->intradayTimespan      = config('polygon.intraday_timespan', 'minute');
        $this->intradayMultiplier    = (int) config('polygon.intraday_multiplier', 1);
    }

    /**
     * ----------------------------------------------------------------------
     *  Fetch or read a cached intraday snapshot for a single ticker.
     * ----------------------------------------------------------------------
     *
     * Weekend / holiday semantics:
     *   - If NO bars are returned for the requested epoch window, we look back
     *     over the last few days in Redis (e.g. Friday) and re-use the most
     *     recent snapshot.
     *   - That fallback snapshot is also cached under today’s key so repeated
     *     calls don’t hammer Polygon.
     *
     * @param  \App\Models\Ticker  $ticker
     * @param  bool                $forceRefresh
     * @return array|null
     *
     * @example
     *   $snapshot = app(PolygonRealtimePriceService::class)
     *       ->getIntradaySnapshotForTicker($ticker);
     */
    public function getIntradaySnapshotForTicker(Ticker $ticker, bool $forceRefresh = false): ?array
    {
        $logger   = Log::channel('ingest');
        $symbol   = $ticker->ticker;
        $nowNy    = Carbon::now($this->marketTimezone);
        $todayNy  = $nowNy->toDateString();
        $todayKey = $this->snapshotKey($symbol, $todayNy);

        // ------------------------------------------------------------------
        // 1️⃣ Try Redis for TODAY first (unless force-refresh requested)
        // ------------------------------------------------------------------
        if (! $forceRefresh) {
            $cachedToday = $this->getCachedSnapshotForKey($todayKey);
            if ($cachedToday !== null) {
                // __empty sentinel means "no data, don't keep asking"
                if (isset($cachedToday['__empty']) && $cachedToday['__empty'] === true) {
                    return null;
                }

                return $cachedToday;
            }
        }

        $logger->info("📡 Fetching intraday snapshot for {$symbol}", [
            'symbol' => $symbol,
            'date'   => $todayNy,
        ]);

        // ------------------------------------------------------------------
        // 2️⃣ Build epoch-millisecond window for Polygon aggregates
        // ------------------------------------------------------------------
        // We pull a rolling window of bars that covers:
        //   • pre-market
        //   • regular hours
        //   • after-hours
        //   • overnight (for 24/5 symbols)
        //
        // Example: with maxAgeMinutes = 1440, we request ~last 24h of bars
        // ending "now" (in UTC), expressed as epoch milliseconds.
        $nowUtc       = Carbon::now('UTC');
        $toEpochMs    = (string) $nowUtc->valueOf();                                // e.g. 1731862800000
        $fromEpochMs  = (string) $nowUtc->copy()->subMinutes($this->maxAgeMinutes)
                                      ->valueOf();                                  // e.g. 1731776400000

        // NOTE (Option A):
        //   fetchAggregates() still accepts strings for $from/$to.
        //   We are simply switching those strings from ISO dates to Epoch ms.
        $bars = $this->historyService->fetchAggregates(
            $symbol,
            $this->intradayMultiplier,
            $this->intradayTimespan,
            $fromEpochMs,
            $toEpochMs,
            [
                'adjusted' => 'true',
                'sort'     => 'asc',
                // 🔥 Enable extended-hours bars (pre, after, overnight)
                'extended' => 'true',
                // Safety limit; Polygon caps anyway, but this is cheap insurance
                'limit'    => 50000,
            ]
        );

        // ------------------------------------------------------------------
        // 3️⃣ NO bars? Fallback to most recent cached snapshot (Fri, etc.)
        // ------------------------------------------------------------------
        if (empty($bars)) {
            $logger->warning("⚠️ No intraday bars from Polygon for {$symbol}", [
                'symbol' => $symbol,
                'from'   => $fromEpochMs,
                'to'     => $toEpochMs,
                'date'   => $todayNy,
            ]);

            // Look back N days for a non-empty snapshot (e.g. Friday AH)
            $fallback = $this->findMostRecentCachedSnapshot($symbol, $nowNy, 5);

            if ($fallback !== null) {
                // Also alias it under TODAY’s key so repeated calls are cheap
                Redis::setex($todayKey, $this->cacheTtl, json_encode($fallback));

                $logger->info("🔁 Using fallback intraday snapshot for {$symbol}", [
                    'symbol'           => $symbol,
                    'fallback_as_of'   => $fallback['as_of'] ?? null,
                    'fallback_session' => $fallback['session'] ?? null,
                    'cached_under'     => $todayKey,
                ]);

                return $fallback;
            }

            // No bars AND no historical snapshot → cache empty sentinel
            Redis::setex($todayKey, $this->cacheTtl, json_encode(['__empty' => true]));

            $logger->warning("🚫 No intraday data or fallback snapshot for {$symbol}", [
                'symbol' => $symbol,
                'date'   => $todayNy,
            ]);

            return null;
        }

        // ------------------------------------------------------------------
        // 4️⃣ Normalize minute bars, compute rolling-day stats
        // ------------------------------------------------------------------
        $mapped    = [];
        $dayHigh   = null;
        $dayLow    = null;
        $dayVolume = 0;

        foreach ($bars as $b) {
            // Defensive: skip malformed entries
            if (! isset($b['t'])) {
                continue;
            }

            // Polygon returns ms since epoch in 't'
            $tsUtc = Carbon::createFromTimestampMsUTC((int) $b['t']);

            $o = $b['o'] ?? null;
            $h = $b['h'] ?? null;
            $l = $b['l'] ?? null;
            $c = $b['c'] ?? null;
            $v = $b['v'] ?? null;

            $mapped[] = [
                't' => $tsUtc->toIso8601String(),
                'o' => $o !== null ? (float) $o : null,
                'h' => $h !== null ? (float) $h : null,
                'l' => $l !== null ? (float) $l : null,
                'c' => $c !== null ? (float) $c : null,
                'v' => $v !== null ? (int) $v : null,
            ];

            if ($h !== null) {
                $dayHigh = $dayHigh === null ? (float) $h : max($dayHigh, (float) $h);
            }
            if ($l !== null) {
                $dayLow = $dayLow === null ? (float) $l : min($dayLow, (float) $l);
            }
            if ($v !== null) {
                $dayVolume += (int) $v;
            }
        }

        if (empty($mapped)) {
            // Extremely defensive: we had bars but couldn’t map them — treat as empty
            Redis::setex($todayKey, $this->cacheTtl, json_encode(['__empty' => true]));

            $logger->warning("⚠️ No normalized intraday bars for {$symbol}", [
                'symbol' => $symbol,
                'from'   => $fromEpochMs,
                'to'     => $toEpochMs,
            ]);

            return null;
        }

        // Latest bar in our rolling window
        $latest    = end($mapped);
        $asOfIso   = $latest['t'];
        $lastPrice = $latest['c'] ?? null;

        // Previous close from DB
        $prevClose = $ticker->prev_close ?? null;
        $changeAbs = null;
        $changePct = null;

        if ($lastPrice !== null && $prevClose !== null && $prevClose != 0.0) {
            $changeAbs = (float) $lastPrice - (float) $prevClose;
            $changePct = ($changeAbs / (float) $prevClose) * 100;
        }

        // Session classification (extended-hours aware)
        $session = $this->classifySession($asOfIso);

        // ------------------------------------------------------------------
        // 5️⃣ Build snapshot
        // ------------------------------------------------------------------
        $snapshot = [
            'symbol'             => $symbol,
            'as_of'              => $asOfIso,
            'session'            => $session['code'],
            'session_label'      => $session['label'],
            'session_time_human' => $session['time_human'],
            'last_price'         => $lastPrice,
            'prev_close'         => $prevClose,
            'change_abs'         => $changeAbs,
            'change_pct'         => $changePct,
            'day_high'           => $dayHigh,
            'day_low'            => $dayLow,
            'volume'             => $dayVolume,
            // Full set of extended-hours minute bars (for 1D chart & analytics)
            'bars'               => $mapped,
        ];

        // ------------------------------------------------------------------
        // 6️⃣ Cache to Redis under TODAY’s key
        // ------------------------------------------------------------------
        Redis::setex($todayKey, $this->cacheTtl, json_encode($snapshot));

        $logger->info("✅ Cached intraday snapshot for {$symbol}", [
            'symbol'   => $symbol,
            'session'  => $snapshot['session'],
            'cacheKey' => $todayKey,
            'count'    => count($mapped),
        ]);

        return $snapshot;
    }

    /**
     * Warm cache for multiple tickers (prefetch job).
     *
     * @param  iterable<Ticker> $tickers
     * @param  bool             $forceRefresh
     * @return void
     */
    public function warmIntradayForTickers(iterable $tickers, bool $forceRefresh = true): void
    {
        foreach ($tickers as $ticker) {
            $this->getIntradaySnapshotForTicker($ticker, $forceRefresh);
        }
    }

    /**
     * Build Redis key for daily intraday snapshots.
     *
     * @param  string $symbol
     * @param  string $date
     * @return string
     */
    protected function snapshotKey(string $symbol, string $date): string
    {
        // Example: intraday:snap:AAPL:2025-11-15
        return "{$this->redisPrefix}:snap:{$symbol}:{$date}";
    }

    /**
     * Retrieve and decode a cached snapshot for a raw Redis key.
     *
     * @param  string $key
     * @return array|null
     */
    protected function getCachedSnapshotForKey(string $key): ?array
    {
        $cached = Redis::get($key);
        if ($cached === null) {
            return null;
        }

        $decoded = json_decode($cached, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Find the most recent non-empty cached snapshot for a symbol by
     * walking backwards from a reference date up to $lookbackDays.
     *
     * Used for:
     *   - Weekend / holiday fallback (Friday AH → weekend)
     *   - Early Monday before fresh intraday data arrives
     *
     * @param  string         $symbol
     * @param  \Carbon\Carbon $referenceDate in market TZ
     * @param  int            $lookbackDays
     * @return array|null
     */
    protected function findMostRecentCachedSnapshot(string $symbol, Carbon $referenceDate, int $lookbackDays = 5): ?array
    {
        for ($i = 1; $i <= $lookbackDays; $i++) {
            $date = $referenceDate->copy()->subDays($i)->toDateString();
            $key  = $this->snapshotKey($symbol, $date);

            $snapshot = $this->getCachedSnapshotForKey($key);

            if ($snapshot === null) {
                continue;
            }

            if (isset($snapshot['__empty']) && $snapshot['__empty'] === true) {
                continue;
            }

            return $snapshot;
        }

        return null;
    }

    /**
     * Determine session bucket based on timestamp (extended-hours aware):
     *   • overnight 20:00–04:00
     *   • pre       04:00–09:30
     *   • regular   09:30–16:00
     *   • after     16:00–20:00
     *   • closed    otherwise
     *
     * @param  string $isoTimestamp
     * @return array{code:string,label:string,time_human:string}
     */
    protected function classifySession(string $isoTimestamp): array
    {
        $dt    = Carbon::parse($isoTimestamp)->setTimezone($this->marketTimezone);
        $hhmm  = (int) $dt->format('Hi');
        $label = 'Market closed';
        $code  = 'closed';

        // Overnight: 20:00–04:00 (24/5 trading window)
        if ($hhmm >= 2000 || $hhmm < 400) {
            $code  = 'overnight';
            $label = 'Overnight session';
        }
        // Pre-market: 04:00–09:30
        elseif ($hhmm >= 400 && $hhmm < 930) {
            $code  = 'pre';
            $label = 'Pre-market';
        }
        // Regular session: 09:30–16:00
        elseif ($hhmm >= 930 && $hhmm < 1600) {
            $code  = 'regular';
            $label = 'Regular session';
        }
        // After-hours: 16:00–20:00
        elseif ($hhmm >= 1600 && $hhmm < 2000) {
            $code  = 'after';
            $label = 'After hours';
        }

        return [
            'code'       => $code,
            'label'      => $label,
            'time_human' => $dt->format('M j, g:i A T'),
        ];
    }
}
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
 *    - Redis sliding TTL cache (~15 minutes by default)
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
 *     'bars'                => [ ... 1m bars ... ],
 *   ]
 *
 * Redis key pattern:
 *     intraday:snap:{SYMBOL}:{YYYY-MM-DD}
 *
 * TTLS (Option A):
 *   - 15-minute TTL (matches Polygon delayed data)
 *   - Prefetch job runs every minute, keeping keys warm
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

    /** @var string Redis key prefix */
    protected string $redisPrefix;

    /** @var int Cache TTL in seconds */
    protected int $cacheTtl;

    /** @var string Market timezone (e.g. America/New_York) */
    protected string $marketTimezone;

    /** @var int Delay built into your Polygon plan (minutes) */
    protected int $intradayDelayMinutes;

    /** @var int Maximum age of intraday data to fetch (in minutes) */
    protected int $maxAgeMinutes;

    /**
     * Constructor — loads config and injects core history service.
     */
    public function __construct(PolygonPriceHistoryService $historyService)
    {
        $this->historyService       = $historyService;
        $this->redisPrefix          = config('polygon.intraday_redis_prefix', 'intraday');
        $this->cacheTtl             = (int) config('polygon.intraday_cache_ttl', 900);
        $this->marketTimezone       = config('polygon.market_timezone', 'America/New_York');
        $this->intradayDelayMinutes = (int) config('polygon.intraday_delay_minutes', 15);
        $this->maxAgeMinutes        = (int) config('polygon.intraday_max_age_minutes', 1440);
    }

    /**
     * ----------------------------------------------------------------------
     *  Fetch or read a cached intraday snapshot for a single ticker.
     * ----------------------------------------------------------------------
     *
     * @param  \App\Models\Ticker  $ticker
     * @param  bool                $forceRefresh
     * @return array|null
     *
     * @example
     *   $snapshot = app(PolygonRealtimePriceService::class)
     *       ->getIntradaySnapshotForTicker($ticker);
     *
     *   if ($snapshot['session'] !== 'regular') {
     *       // Show after-hours/pre-market banner
     *   }
     */
    public function getIntradaySnapshotForTicker(Ticker $ticker, bool $forceRefresh = false): ?array
    {
        $symbol  = $ticker->ticker;
        $todayNy = Carbon::now($this->marketTimezone)->toDateString();
        $key     = $this->snapshotKey($symbol, $todayNy);

        $logger = Log::channel('ingest');

        // -------------------------------------------------------------
        // 1️⃣ Try Redis first (unless force-refresh requested)
        // -------------------------------------------------------------
        if (!$forceRefresh) {
            $cached = Redis::get($key);
            if ($cached !== null) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    if (isset($decoded['__empty'])) {
                        return null;
                    }
                    return $decoded;
                }
            }
        }

        $logger->info("📡 Fetching intraday snapshot for {$symbol}", [
            'symbol' => $symbol,
            'date'   => $todayNy,
        ]);

        // -------------------------------------------------------------
        // 2️⃣ Fetch intraday bars (Polygon)
        // -------------------------------------------------------------
        // Only fetch today's bars (delayed to match your plan)
        $bars = $this->historyService->fetchAggregates(
            $symbol,
            1,        // minute multiplier
            'minute', // timespan
            $todayNy, // from
            $todayNy, // to
            ['adjusted' => 'true']
        );

        if (empty($bars)) {
            Redis::setex($key, $this->cacheTtl, json_encode(['__empty' => true]));
            $logger->warning("⚠️ No intraday bars for {$symbol}");
            return null;
        }

        // -------------------------------------------------------------
        // 3️⃣ Normalize minute bars, compute daily stats
        // -------------------------------------------------------------
        $mapped = [];
        $dayHigh   = null;
        $dayLow    = null;
        $dayVolume = 0;

        foreach ($bars as $b) {
            if (!isset($b['t'])) continue;

            $ts = Carbon::createFromTimestampMsUTC((int) $b['t']);

            $o = $b['o'] ?? null;
            $h = $b['h'] ?? null;
            $l = $b['l'] ?? null;
            $c = $b['c'] ?? null;
            $v = $b['v'] ?? null;

            $mapped[] = [
                't' => $ts->toIso8601String(),
                'o' => $o !== null ? (float) $o : null,
                'h' => $h !== null ? (float) $h : null,
                'l' => $l !== null ? (float) $l : null,
                'c' => $c !== null ? (float) $c : null,
                'v' => $v !== null ? (int) $v : null,
            ];

            if ($h !== null) {
                $dayHigh = $dayHigh === null ? $h : max($dayHigh, $h);
            }
            if ($l !== null) {
                $dayLow = $dayLow === null ? $l : min($dayLow, $l);
            }
            if ($v !== null) {
                $dayVolume += (int) $v;
            }
        }

        if (empty($mapped)) {
            Redis::setex($key, $this->cacheTtl, json_encode(['__empty' => true]));
            return null;
        }

        $latest   = end($mapped);
        $asOfIso  = $latest['t'];
        $lastPrice = $latest['c'] ?? null;

        // Previous close from DB
        $prevClose = $ticker->prev_close ?? null;
        $changeAbs = null;
        $changePct = null;

        if ($lastPrice !== null && $prevClose !== null && $prevClose != 0.0) {
            $changeAbs = (float) $lastPrice - (float) $prevClose;
            $changePct = ($changeAbs / (float) $prevClose) * 100;
        }

        // Session classification
        $session = $this->classifySession($asOfIso);

        // -------------------------------------------------------------
        // 4️⃣ Build snapshot
        // -------------------------------------------------------------
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
            'bars'               => $mapped,
        ];

        // -------------------------------------------------------------
        // 5️⃣ Cache to Redis
        // -------------------------------------------------------------
        Redis::setex($key, $this->cacheTtl, json_encode($snapshot));

        $logger->info("✅ Cached intraday snapshot for {$symbol}", [
            'symbol'   => $symbol,
            'session'  => $snapshot['session'],
            'cacheKey' => $key,
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
        return "{$this->redisPrefix}:snap:{$symbol}:{$date}";
    }

    /**
     * Determine session bucket based on timestamp:
     *   • pre      4:00–09:30
     *   • regular  09:30–16:00
     *   • after    16:00–20:00
     *   • closed   otherwise
     *
     * @param  string $isoTimestamp
     * @return array{code:string,label:string,time_human:string}
     */
    protected function classifySession(string $isoTimestamp): array
    {
        $dt = Carbon::parse($isoTimestamp)->setTimezone($this->marketTimezone);
        $hhmm = (int) $dt->format('Hi');

        if ($hhmm >= 400 && $hhmm < 930) {
            $code = 'pre';
            $label = 'Pre-market';
        } elseif ($hhmm >= 930 && $hhmm < 1600) {
            $code = 'regular';
            $label = 'Regular session';
        } elseif ($hhmm >= 1600 && $hhmm <= 2000) {
            $code = 'after';
            $label = 'After hours';
        } else {
            $code = 'closed';
            $label = 'Market closed';
        }

        return [
            'code'        => $code,
            'label'       => $label,
            'time_human'  => $dt->format('M j, g:i A T'),
        ];
    }
}
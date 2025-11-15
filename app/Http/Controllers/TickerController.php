<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Ticker;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use App\Helpers\FormatHelper;
use App\Services\PolygonRealtimePriceService;

/**
 * Controller: TickerController
 * -----------------------------------------------------------------------------
 * Renders the Ticker Profile Page and coordinates data aggregation between
 * Eloquent models and Blade views.
 *
 * Responsibilities:
 *  - Load relevant ticker data and relationships (overview, fundamentals,
 *    indicators, price histories, news, analysis)
 *  - Prepare both detailed OHLCV arrays AND lightweight `{x,y}` series
 *    for ApexCharts (with optional down-sampling)
 *  - Compute high-level header stats to match major finance portals
 *  - Enrich with intraday (1-minute delayed) snapshots from Redis/Polygon
 * -----------------------------------------------------------------------------
 */
class TickerController extends Controller
{
    /**
     * Display a single ticker’s profile page.
     *
     * @param  \Illuminate\Http\Request              $request
     * @param  string                                $symbol   The ticker symbol (e.g. "AAPL")
     * @param  string|null                           $slug     Optional SEO-friendly slug
     * @param  \App\Services\PolygonRealtimePriceService  $realtimeService
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function show(
        Request $request,
        string $symbol,
        ?string $slug = null,
        PolygonRealtimePriceService $realtimeService
    ) {
        // ------------------------------------------------------------------
        // Normalize & Retrieve Ticker
        // ------------------------------------------------------------------
        $symbol = strtoupper(trim($symbol));

        $ticker = Ticker::query()
            ->with([
                'overview',
                'fundamentals'   => fn ($q) => $q->orderByDesc('end_date')->limit(4),
                'priceHistories' => fn ($q) => $q->orderBy('t'),
                'indicators'     => fn ($q) => $q->orderByDesc('t')->limit(50),
                'analysis'       => fn ($q) => $q->latest('created_at'),
                'newsItems'      => fn ($q) => $q->orderByDesc('published_utc')->limit(10),
            ])
            ->bySymbol($symbol)
            ->firstOrFail();

        // ------------------------------------------------------------------
        // Canonical Slug Enforcement (SEO-friendly route)
        // ------------------------------------------------------------------
        $canonicalSlug = $ticker->slug ?? Str::slug($ticker->name ?? $ticker->ticker);
        if ($slug !== $canonicalSlug) {
            return redirect()->route('tickers.show', [
                'symbol' => $symbol,
                'slug'   => $canonicalSlug,
            ]);
        }

        // ------------------------------------------------------------------
        // Detailed OHLCV arrays for 1D, 1W, 1M, 6M, 1Y, 5Y (for cards/analytics)
        // ------------------------------------------------------------------
        $now = Carbon::now();
        $priceHistoryRecords = $ticker->priceHistories()
            ->where('t', '>=', $now->copy()->subYears(5))
            ->orderBy('t', 'asc')
            ->get();

        $rangeConfig = [
            '1D' => ['threshold' => $now->copy()->subDay(),     'fallback' => 1],
            '1W' => ['threshold' => $now->copy()->subWeek(),    'fallback' => 5],
            '1M' => ['threshold' => $now->copy()->subMonth(),   'fallback' => 22],
            '6M' => ['threshold' => $now->copy()->subMonths(6), 'fallback' => 126],
            '1Y' => ['threshold' => $now->copy()->subYear(),    'fallback' => 252],
            '5Y' => ['threshold' => $now->copy()->subYears(5),  'fallback' => 1260],
        ];

        $serializeOHLCV = function ($collection) {
            return $collection->map(fn ($p) => [
                'date'   => \Illuminate\Support\Carbon::parse($p->t)->toDateString(),
                'open'   => $p->o !== null ? (float) $p->o : null,
                'high'   => $p->h !== null ? (float) $p->h : null,
                'low'    => $p->l !== null ? (float) $p->l : null,
                'close'  => $p->c !== null ? (float) $p->c : null,
                'volume' => $p->v !== null ? (int) $p->v : null,
            ])->values();
        };

        $chartData = [];
        foreach ($rangeConfig as $label => $config) {
            if ($priceHistoryRecords->isEmpty()) {
                $chartData[$label] = [];
                continue;
            }
            $subset = $priceHistoryRecords->filter(fn ($r) => $r->t >= $config['threshold']);
            if ($subset->isEmpty()) {
                $subset = $priceHistoryRecords->take(-$config['fallback']);
            }
            $chartData[$label] = $serializeOHLCV($subset);
        }

        // ------------------------------------------------------------------
        // Lightweight `{x,y}` series for ApexCharts (with down-sampling rules)
        // ------------------------------------------------------------------
        $favorSparseForLargeRanges = true;
        $ranges = ['1D', '1W', '1M', '6M', '1Y', '5Y'];

        $chartSeries = [];
        foreach ($ranges as $range) {
            $series = $ticker->buildPriceSeriesForRange($range, $favorSparseForLargeRanges);
            $chartSeries[$range] = $series['points'];
        }

        // ------------------------------------------------------------------
        // Override 1W series with raw hourly bars
        // ------------------------------------------------------------------
        $chartSeries['1W'] = $ticker->hourlyPriceSeriesForOneWeek();

        // ------------------------------------------------------------------
        // Intraday (1-minute) snapshot via Redis/Polygon
        // ------------------------------------------------------------------
        $intradaySnapshot = $realtimeService->getIntradaySnapshotForTicker($ticker);

        // Close time label from the latest *daily* bar (TickerPriceHistory)
        $latestDaily   = $ticker->latestPrice();
        $closeTimeLabel = $latestDaily
            ? Carbon::parse($latestDaily->t)
                ->setTimezone(config('polygon.market_timezone', 'America/New_York'))
                ->format('M j, g:i A T')
            : null;

        // Intraday session labels from snapshot (only if Polygon returned bars)
        $intradaySessionLabel = $intradaySnapshot['session_label']      ?? null;
        $intradayTimeLabel    = $intradaySnapshot['session_time_human'] ?? null;
        $intradaySessionCode  = $intradaySnapshot['session']            ?? null;

        // ------------------------------------------------------------------
        // 1D Chart: use intraday (Redis) if available, otherwise
        //          fall back to last 24 hours of hourly bars.
        // ------------------------------------------------------------------
        $intradaySeries = $ticker->buildIntradaySeries($intradaySnapshot);

        if (empty($intradaySeries)) {
            // Fallback: use last 24 hours of hourly bars
            $intradaySeries = $ticker->priceHistories()
                ->where('resolution', '1h')
                ->where('t', '>=', now()->subHours(24))
                ->orderBy('t')
                ->get()
                ->map(fn ($p) => [
                    'x' => \Illuminate\Support\Carbon::parse($p->t)->toIso8601String(),
                    'y' => (float) $p->c,
                ])
                ->values()
                ->all();
        }
        // Set final 1D dataset
        $chartSeries['1D'] = $intradaySeries;

        // ------------------------------------------------------------------
        // Header Stats: match expectations from Google/TradingView/Perplexity
        // ------------------------------------------------------------------
        $overview = $ticker->overview; // may be null for some tickers

        $headerStats = [
            // Price & daily change (based on EOD daily data)
            'lastPrice'     => FormatHelper::currency($ticker->last_close),
            'changeAbs'     => FormatHelper::signedCurrencyChange($ticker->day_change_abs),
            'changePct'     => FormatHelper::percent($ticker->day_change_pct),

            // Close-time metadata (EOD, daily-level)
            // Example: "At close: Nov 12, 4:00 PM ET"
            'closeTimeLabel' => $closeTimeLabel
                ? 'At close: ' . $closeTimeLabel
                : 'At close: —',

            // Intraday / After-hours / Pre-market metadata
            // Example: "After hours: Nov 12, 7:45 PM ET"
            'intradaySessionCode'  => $intradaySessionCode,
            'intradaySessionLabel' => $intradaySessionLabel,        // "Pre-market", "After hours", etc.
            'intradayTimeLabel'    => $intradayTimeLabel            // "Nov 12, 7:45 PM ET"
                ? ($intradaySessionLabel . ': ' . $intradayTimeLabel)
                : null,

            // Intraday price & change (when available)
            'intradayLastPrice' => $intradaySnapshot['last_price'] ?? null,
            'intradayChangeAbs' => $intradaySnapshot['change_abs'] ?? null,
            'intradayChangePct' => $intradaySnapshot['change_pct'] ?? null,

            // Day ranges & previous close
            'prevClose'     => FormatHelper::currency($ticker->prev_close),
            'open'          => FormatHelper::currency($ticker->day_open),
            'dayHigh'       => FormatHelper::currency($ticker->day_high),
            'dayLow'        => FormatHelper::currency($ticker->day_low),

            // Volume (latest & avg)
            'volume'        => FormatHelper::humanVolume($ticker->volume_latest),
            'avgVolume'     => FormatHelper::humanVolume($ticker->avg_volume_30d),

            // 52-week range
            'high52w'       => FormatHelper::currency($ticker->high_52w),
            'low52w'        => FormatHelper::currency($ticker->low_52w),

            // Market cap & shares
            'marketCap'     => $overview ? FormatHelper::compactCurrency($overview->market_cap ?? null) : '—',
            'sharesOut'     => $overview ? FormatHelper::compactNumber($overview->weighted_shares_outstanding ?? null) : '—',

            // Meta
            'exchange'      => $ticker->exchange_short ?? $ticker->primary_exchange,
            'name'          => $ticker->clean_display_name ?? $ticker->name,
            'logoUrl'       => $ticker->logo_url,
            'iconUrl'       => $ticker->icon_url,
        ];

        // ------------------------------------------------------------------
        // Compile Data for View
        // ------------------------------------------------------------------
        return view('pages.tickers.show', [
            'ticker'           => $ticker,
            'overview'         => $overview,
            'fundamental'      => $ticker->fundamentals->first(),
            'chartData'        => $chartData,
            'chartSeries'      => $chartSeries,
            'latestIndicators' => $ticker->latestIndicators(),
            'headerStats'      => $headerStats,
            'intradaySnapshot' => $intradaySnapshot, // optional if you want more detail in Blade
            'analysis'         => $ticker->analysis,
            'newsItems'        => $ticker->newsItems,
        ]);
    }
}
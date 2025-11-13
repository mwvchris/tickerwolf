<?php

namespace App\Http\Controllers;

use App\Helpers\FormatHelper;
use App\Models\Ticker;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
 * -----------------------------------------------------------------------------
 */
class TickerController extends Controller
{
    /**
     * Display a single ticker’s profile page.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string                    $symbol  The ticker symbol (e.g. "AAPL")
     * @param  string|null               $slug    Optional SEO-friendly slug
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function show(Request $request, string $symbol, ?string $slug = null)
    {
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
            ->where('t', '>=', $now->copy()->subYears(5)) // cap to last 5Y for page
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

            $subset = $priceHistoryRecords->filter(
                fn ($r) => $r->t >= $config['threshold']
            );

            if ($subset->isEmpty()) {
                $subset = $priceHistoryRecords->take(-$config['fallback']);
            }

            $chartData[$label] = $serializeOHLCV($subset);
        }

        // ------------------------------------------------------------------
        // Lightweight `{x,y}` series for ApexCharts (with down-sampling rules)
        // ------------------------------------------------------------------
        $favorSparseForLargeRanges = true;
        $ranges                    = ['1D', '1W', '1M', '6M', '1Y', '5Y'];

        $chartSeries = [];

        foreach ($ranges as $range) {
            $series              = $ticker->buildPriceSeriesForRange($range, $favorSparseForLargeRanges);
            $chartSeries[$range] = $series['points'];
        }

        // ------------------------------------------------------------------
        // Header Stats: regular vs. extended session (Option C)
        // ------------------------------------------------------------------
        $overview        = $ticker->overview;
        $regularSession  = $ticker->regularSessionStats();
        $extendedSession = $ticker->extendedSessionStats(); // currently synthetic timestamp

        $headerStats = [
            // Regular session block (close)
            'regular' => [
                'priceRaw'    => $ticker->last_close,
                'price'       => FormatHelper::currency($ticker->last_close),
                'changeAbsRaw'=> $ticker->day_change_abs,
                'changeAbs'   => FormatHelper::signedCurrencyChange($ticker->day_change_abs),
                'changePctRaw'=> $ticker->day_change_pct,
                'changePct'   => FormatHelper::percent($ticker->day_change_pct),
                'timestamp'   => $regularSession['timestamp'] ?? null,
                'label'       => $regularSession['label'] ?? 'At close: —',
            ],

            // Extended session block (pre-market / after hours)
            // For now we only provide the label & timestamp; when you have
            // extended prices you can wire them into `priceRaw` + changes.
            'extended' => [
                'priceRaw'     => null,
                'price'        => '—',
                'changeAbsRaw' => null,
                'changeAbs'    => '—',
                'changePctRaw' => null,
                'changePct'    => '—',
                'timestamp'    => $extendedSession['timestamp'] ?? null,
                'label'        => $extendedSession['label'] ?? 'After hours: —',
            ],

            // Day ranges & previous close
            'prevClose'  => FormatHelper::currency($ticker->prev_close),
            'open'       => FormatHelper::currency($ticker->day_open),
            'dayHigh'    => FormatHelper::currency($ticker->day_high),
            'dayLow'     => FormatHelper::currency($ticker->day_low),

            // Volume (latest & avg)
            'volume'     => FormatHelper::humanVolume($ticker->volume_latest),
            'avgVolume'  => FormatHelper::humanVolume($ticker->avg_volume_30d),

            // 52-week range
            'high52w'    => FormatHelper::currency($ticker->high_52w),
            'low52w'     => FormatHelper::currency($ticker->low_52w),

            // Market cap & shares
            'marketCap'  => $overview
                ? FormatHelper::compactCurrency($overview->market_cap ?? null)
                : '—',
            'sharesOut'  => $overview
                ? FormatHelper::compactNumber($overview->weighted_shares_outstanding ?? null)
                : '—',

            // Meta
            'exchange'   => $ticker->exchange_short ?? $ticker->primary_exchange,
            'name'       => $ticker->clean_display_name ?? $ticker->name,
            'logoUrl'    => $ticker->logo_url,
            'iconUrl'    => $ticker->icon_url,
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
            'analysis'         => $ticker->analysis,
            'newsItems'        => $ticker->newsItems,
        ]);
    }
}
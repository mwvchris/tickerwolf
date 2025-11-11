<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Ticker;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

/**
 * Controller: TickerController
 * --------------------------------------------------------------------------
 * Renders the Ticker Profile Page and coordinates data aggregation between
 * Eloquent models and Blade views.
 *
 * Responsibilities:
 *  - Load relevant ticker data and relationships (overview, fundamentals,
 *    indicators, price histories, news, analysis)
 *  - Prepare both detailed OHLCV arrays (for cards/analytics) AND lightweight
 *    `{x,y}` series for fast ApexCharts rendering with down-sampling
 *  - Compute high-level metrics (price, percent changes, market cap formatting)
 */
class TickerController extends Controller
{
    /**
     * Display a single ticker’s profile page.
     *
     * @param  Request      $request
     * @param  string       $symbol    The ticker symbol (e.g. "AAPL")
     * @param  string|null  $slug      Optional SEO-friendly slug
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
                'priceHistories' => fn ($q) => $q->orderBy('t'), // ascending for transform later
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
            $subset = $priceHistoryRecords->filter(fn ($r) => $r->t >= $config['threshold']);
            if ($subset->isEmpty()) {
                $subset = $priceHistoryRecords->take(-$config['fallback']);
            }
            $chartData[$label] = $serializeOHLCV($subset);
        }

        // ------------------------------------------------------------------
        // Lightweight `{x,y}` series for ApexCharts (with down-sampling rules)
        // - 1D, 1W, 1M, 6M: daily
        // - YTD, 1Y: daily by default, or 3-day down-sample if needed
        // - 5Y: 7-day down-sample
        // ------------------------------------------------------------------
        $favorSparseForLargeRanges = true; // enable 3-day stepping for YTD/1Y
        $ranges = ['1D', '1W', '1M', '6M', '1Y', '5Y']; // keep Blade consistent

        $chartSeries = [];
        foreach ($ranges as $range) {
            $series = $ticker->buildPriceSeriesForRange($range, $favorSparseForLargeRanges);
            // Only send the light `{x,y}` array to the chart modules:
            $chartSeries[$range] = $series['points']; // e.g., [['x' => '2025-01-01', 'y' => 123.45], ...]
        }

        // ------------------------------------------------------------------
        // Compute High-Level Metrics (header cards, percent change, etc.)
        // ------------------------------------------------------------------
        $latestPrice     = $ticker->latestPrice()?->c ?? null;
        $priceChange5d   = $ticker->percentChange(5);
        $priceChange30d  = $ticker->percentChange(30);
        $formattedMarket = $ticker->formattedMarketCap();

        // ------------------------------------------------------------------
        // Compile Data for View
        // - chartData: detailed OHLCV arrays (for cards/analytics)
        // - chartSeries: lightweight {x,y} arrays (for fast Apex charts)
        // ------------------------------------------------------------------
        return view('pages.tickers.show', [
            'ticker'           => $ticker,
            'overview'         => $ticker->overview,
            'fundamental'      => $ticker->fundamentals->first(),
            'chartData'        => $chartData,
            'chartSeries'      => $chartSeries,
            'latestIndicators' => $ticker->latestIndicators(),
            'latestPrice'      => $latestPrice,
            'priceChange5d'    => $priceChange5d,
            'priceChange30d'   => $priceChange30d,
            'formattedMarket'  => $formattedMarket,
            'analysis'         => $ticker->analysis,
            'newsItems'        => $ticker->newsItems,
        ]);
    }
}
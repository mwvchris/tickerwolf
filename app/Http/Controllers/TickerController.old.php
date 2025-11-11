<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Ticker;
use App\Models\TickerAnalysis;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class TickerController extends Controller
{
    /**
     * Show the ticker profile page via Inertia.
     */
    public function show(Request $request, string $symbol, ?string $slug = null): Response|RedirectResponse
    {
        $ticker = Ticker::whereRaw('upper(ticker) = ?', [strtoupper($symbol)])
            ->with(['overviews' => function ($q) {
                // eager-load the latest overview first (we'll pick the latest)
                $q->orderByDesc('fetched_at')->limit(1);
            }])
            ->firstOrFail();

        // Canonical slug derived from DB (or fallback)
        $canonicalSlug = $ticker->slug ?? Str::slug($ticker->name ?? $ticker->ticker);

        // Normalize and redirect only if needed
        if ($slug !== $canonicalSlug) {
            return redirect()->route('tickers.show', [
                'symbol' => strtoupper($ticker->ticker),
                'slug'   => $canonicalSlug,
            ], 301);
        }

        // ==========================================
        // Fetch most recent completed + valid AI analysis (<= 24h old)
        // ==========================================
        $latestAnalysis = TickerAnalysis::where('ticker', strtoupper($ticker->ticker))
            ->where('status', 'completed')
            ->whereNotNull('response_raw')
            ->whereRaw("(response_raw != '' AND response_raw != '[]')")
            ->where('requested_at', '>=', now()->subDay())
            ->orderByDesc('completed_at')
            ->first();

        $analysisContent = null;

        if ($latestAnalysis) {
            $raw = $latestAnalysis->response_raw;

            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $response = is_array($decoded) ? $decoded : [];
            } elseif (is_array($raw)) {
                $response = $raw;
            } else {
                $response = [];
            }

            $analysisContent = $response['content']
                ?? $response['summary']
                ?? ($latestAnalysis->summary ?? $latestAnalysis->output ?? null);
        }

        // ==========================================
        // Latest overview snapshot
        // ==========================================
        $latestOverview = $ticker->overviews->first() ?? null;

        // Determine logo (prefer ticker.branding_logo_url then overview payload)
        $logoUrl = $ticker->branding_logo_url
            ?? optional($latestOverview)->results_raw['branding']['logo_url'] ?? null
            ?? null;

        // Friendly values
        $formattedMarketCap = $ticker->formatted_market_cap ?? ($latestOverview?->formatted_market_cap ?? null);
        $listDate = $ticker->list_date ? $ticker->list_date->toDateString() : ($latestOverview?->overview_date?->toDateString() ?? null);
        $employees = $ticker->total_employees ?? ($latestOverview->results_raw['total_employees'] ?? null);
        $phone = $ticker->phone_number ?? ($latestOverview->results_raw['phone_number'] ?? null);
        $sic = $ticker->sic_description ?? ($latestOverview->results_raw['sic_description'] ?? null);

        // Prepare quick stats (server-side) — you can add/remove entries here
        $quickStats = [
            [
                'label' => 'Market Cap',
                'value' => $formattedMarketCap ?? '—',
            ],
            [
                'label' => 'Employees',
                'value' => $employees ? number_format($employees) : '—',
            ],
            [
                'label' => 'List Date',
                'value' => $listDate ?? '—',
            ],
            [
                'label' => 'SIC',
                'value' => $sic ?? '—',
            ],
        ];

        // Send the enriched payload to the Vue page
        return Inertia::render('tickers/Show', [
            'ticker' => [
                'id'               => $ticker->id,
                'ticker'           => $ticker->ticker,
                'name'             => $ticker->name,
                'slug'             => $canonicalSlug,
                'market'           => $ticker->market ?? null,
                'locale'           => $ticker->locale ?? null,
                'primary_exchange' => $ticker->primary_exchange ?? null,
                'currency_name'    => $ticker->currency_name ?? null,
                'type'             => $ticker->type ?? null,
                'active'           => (bool) $ticker->active,
                // overview fields
                'description'      => $ticker->description ?? ($latestOverview->results_raw['description'] ?? null),
                'homepage_url'     => $ticker->homepage_url ?? ($latestOverview->results_raw['homepage_url'] ?? null),
                'market_cap'       => $ticker->market_cap ?? ($latestOverview->market_cap ?? null),
                'formatted_market_cap' => $formattedMarketCap ?? null,
                'total_employees'  => $employees,
                'phone_number'     => $phone,
                'list_date'        => $listDate,
                'sic_description'  => $sic,
                'branding_logo_url'=> $logoUrl,
            ],
            'quickStats' => $quickStats,
            'user' => [
                'authenticated' => Auth::check(),
                'loginUrl'      => route('login', [], false),
            ],
            'latestAnalysis' => $latestAnalysis ? [
                'provider'  => $latestAnalysis->provider,
                'content'   => $analysisContent,
                'completed' => optional($latestAnalysis->completed_at)->toIso8601String(),
            ] : null,
        ]);
    }
}
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use App\Models\TickerAnalysis;
use App\Jobs\RunTickerAnalysis;

class TickerAnalysisController extends Controller
{
    /**
     * Handle an AI analysis request for a given ticker.
     */
    public function requestAnalysis(Request $request)
    {
        $validated = $request->validate([
            'ticker'   => 'required|string|max:10',
            'provider' => 'required|string|in:openai,gemini,grok',
        ]);

        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $ticker   = strtoupper($validated['ticker']);
        $provider = strtolower($validated['provider']);

        /**
         * 1️⃣  Rate limiting per user
         * -----------------------------------------
         * Uses atomic increments to avoid race conditions.
         * Defaults to 500 requests per hour per user, configurable via .env.
         */
        $limit   = config('app.ticker_analysis_rate_limit', 500);
        $window  = config('app.ticker_analysis_rate_window', 60); // minutes
        $rateKey = "ticker_analysis_rate:{$user->id}";

        $count = Cache::add($rateKey, 1, now()->addMinutes($window)) 
            ? 1 
            : Cache::increment($rateKey);

        if ($count > $limit) {
            $ttl = Cache::getExpiration($rateKey) 
                ? now()->addMinutes($window)->toDateTimeString() 
                : now()->addMinutes($window)->toDateTimeString();

            return response()->json([
                'message'   => 'Rate limit exceeded',
                'limit'     => $limit,
                'used'      => $count,
                'remaining' => max(0, $limit - $count),
                'resets_at' => $ttl,
            ], 429);
        }

        /**
         * 2️⃣  Check for cached analysis
         * -----------------------------------------
         * Return most recent cached response if found.
         */
        $cacheKey = "ticker_analysis:{$ticker}:{$provider}";
        if ($cached = Cache::get($cacheKey)) {
            return response()->json([
                'cached'     => true,
                'structured' => $cached,
                'analysis'   => $cached['analysis'] ?? ($cached['summary'] ?? ''),
                'message'    => 'Returning cached analysis.',
            ]);
        }

        /**
         * 3️⃣  Queue a new analysis job
         * -----------------------------------------
         * Creates a database record and dispatches async job.
         */
        $analysis = TickerAnalysis::create([
            'ticker'       => $ticker,
            'provider'     => $provider,
            'user_id'      => $user->id,
            'model'        => config("services.$provider.model"),
            'status'       => 'pending',
            'requested_at' => now(),
        ]);

        RunTickerAnalysis::dispatch($analysis)->onQueue('ticker_analysis');

        return response()->json([
            'analysis_id' => $analysis->id,
            'status'      => 'queued',
            'message'     => 'Analysis request queued successfully.',
        ]);
    }

    /**
     * Return details for a specific analysis request.
     */
    public function show($id)
    {
        $analysis = TickerAnalysis::findOrFail($id);
        $this->authorize('view', $analysis);

        return response()->json([
            'id'           => $analysis->id,
            'ticker'       => $analysis->ticker,
            'provider'     => $analysis->provider,
            'status'       => $analysis->status,
            'summary'      => $analysis->summary,
            'structured'   => $analysis->structured,
            'model'        => $analysis->model,
            'created_at'   => $analysis->created_at,
            'completed_at' => $analysis->completed_at,
        ]);
    }
}
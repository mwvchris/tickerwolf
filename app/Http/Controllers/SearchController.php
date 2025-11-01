<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Ticker;
use Illuminate\Support\Str;

class SearchController extends Controller
{
    /**
     * Optionally show a search page (if you want a GET search page)
     */
    public function searchForm(Request $request)
    {
        return view('search');
    }

    /**
     * Perform search: simple lookup by exact ticker symbol, otherwise try name fuzzy match.
     * Then redirect to canonical ticker page.
     */
    public function performSearch(Request $request)
    {
        $q = trim($request->input('q', ''));

        if (empty($q)) {
            return redirect()->route('home')->with('error', 'Please enter a ticker or name.');
        }

        // If the user input looks like a ticker (all alpha-numeric, <=6 chars), try exact match first
        $maybeTicker = preg_match('/^[A-Za-z0-9\.\-]{1,7}$/', $q);

        if ($maybeTicker) {
            $ticker = Ticker::whereRaw('upper(ticker) = ?', [strtoupper($q)])->first();
            if ($ticker) {
                return redirect()->route('tickers.show', ['symbol' => $ticker->ticker, 'slug' => $ticker->slug]);
            }
        }

        // Fallback: try name search (simple LIKE, you can use full-text or Elastic later)
        $ticker = Ticker::where('name', 'like', '%' . $q . '%')->first();

        if ($ticker) {
            return redirect()->route('tickers.show', ['symbol' => $ticker->ticker, 'slug' => $ticker->slug]);
        }

        // No exact matches; redirect back with message
        return redirect()->back()->with('error', 'Ticker not found. Try the exact symbol, e.g., "AAPL" or a company name.');
    }
}
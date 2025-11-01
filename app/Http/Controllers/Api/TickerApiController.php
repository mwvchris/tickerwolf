<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Ticker;

class TickerApiController extends Controller
{
    public function search(Request $request)
    {
        $q = $request->query('q', '');
        if (empty($q)) {
            return response()->json([]);
        }

        $results = Ticker::where('ticker', 'like', $q . '%')
            ->orWhere('name', 'like', '%' . $q . '%')
            ->limit(10)
            ->get(['ticker', 'name', 'slug']);

        return response()->json($results);
    }
}
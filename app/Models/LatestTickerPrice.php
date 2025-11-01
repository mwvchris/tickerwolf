<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LatestTickerPrice extends Model
{
    protected $table = 'latest_ticker_prices';

    // This model is read-only (it's a SQL VIEW)
    public $timestamps = false;
    protected $guarded = [];

    public static function forTicker(string $ticker)
    {
        return static::where('ticker', $ticker)->first();
    }

    // Optional: make it cast values properly
    protected $casts = [
        't' => 'datetime',
        'o' => 'float', // open
        'h' => 'float', // high
        'l' => 'float', // low
        'c' => 'float', // close
        'v' => 'float', // volume
    ];
}
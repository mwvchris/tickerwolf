<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TickerCorrelation
 *
 * Stores pairwise correlation metrics between two tickers on a given date.
 */
class TickerCorrelation extends Model
{
    protected $fillable = [
        'ticker_id_a',
        'ticker_id_b',
        'as_of_date',
        'corr',
        'beta',
        'r2',
    ];

    protected $casts = [
        'as_of_date' => 'date',
        'corr' => 'float',
        'beta' => 'float',
        'r2' => 'float',
    ];

    public $timestamps = true;
}

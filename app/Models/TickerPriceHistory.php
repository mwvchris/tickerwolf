<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TickerPriceHistory extends Model
{
    use HasFactory;

    protected $table = 'ticker_price_histories';

    protected $fillable = [
        'ticker_id',
        'o',
        'h',
        'l',
        'c',
        'v',
        'vw',
        't',
        'year',
    ];

    protected $casts = [
        't' => 'datetime',
        'o' => 'float',
        'h' => 'float',
        'l' => 'float',
        'c' => 'float',
        'v' => 'float',
        'vw' => 'float',
        'year' => 'integer',
    ];

    public function ticker()
    {
        return $this->belongsTo(Ticker::class);
    }

    /**
     * Scope for filtering by year.
     */
    public function scopeYear($query, int $year)
    {
        return $query->where('year', $year);
    }
}
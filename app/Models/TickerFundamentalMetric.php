<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TickerFundamentalMetric extends Model
{
    use HasFactory;

    protected $table = 'ticker_fundamentals_financials';

    protected $fillable = [
        'ticker_id', 'fundamental_id', 'ticker', 'statement', 'line_item',
        'label', 'unit', 'display_order', 'value',
        'end_date', 'fiscal_period', 'fiscal_year',
    ];

    protected $casts = [
        'value' => 'float',
        'display_order' => 'integer',
        'end_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function ticker()
    {
        return $this->belongsTo(Ticker::class);
    }

    public function fundamental()
    {
        return $this->belongsTo(TickerFundamental::class, 'fundamental_id');
    }
}
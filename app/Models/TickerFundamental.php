<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TickerFundamental extends Model
{
    use HasFactory;

    protected $table = 'ticker_fundamentals';

    // This table is ingestion-only; easiest is to allow all mass assignment:
    protected $guarded = [];

    protected $casts = [
        'start_date'            => 'date',
        'end_date'              => 'date',
        'filing_date'           => 'date',
        'balance_sheet'         => 'array',
        'income_statement'      => 'array',
        'cash_flow_statement'   => 'array',
        'comprehensive_income'  => 'array',
        'raw'                   => 'array',
        'fetched_at'            => 'datetime',
    ];

    public function ticker()
    {
        return $this->belongsTo(Ticker::class);
    }

    public function metrics()
    {
        return $this->hasMany(TickerFundamentalMetric::class, 'fundamental_id');
    }
}
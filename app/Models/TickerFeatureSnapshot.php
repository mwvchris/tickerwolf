<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Class TickerFeatureSnapshot
 *
 * Represents a compact JSON "feature vector" of all computed indicators and analytics
 * for a given ticker and date. Serves as an AI-ready, ML-friendly dataset structure.
 *
 * Example:
 *   {
 *     "sma_20": 142.54,
 *     "ema_12": 144.21,
 *     "rsi_14": 63.2,
 *     "macd": 0.84,
 *     "atr_14": 1.13,
 *     "boll_20_upper": 146.2,
 *     "boll_20_lower": 138.9,
 *     "beta_60": 1.07,
 *     "sharpe_60": 0.94
 *   }
 *
 * Each record corresponds to a unique (ticker_id, t) pair.
 */
class TickerFeatureSnapshot extends Model
{
    protected $table = 'ticker_feature_snapshots';

    protected $fillable = [
        'ticker_id',
        't',
        'indicators',
        'embedding',
    ];

    protected $casts = [
        't' => 'date',
        'indicators' => 'array',
        'embedding' => 'array',
    ];

    /**
     * Relationships
     */
    public function ticker()
    {
        return $this->belongsTo(Ticker::class);
    }
}
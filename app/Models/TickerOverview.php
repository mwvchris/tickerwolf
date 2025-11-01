<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TickerOverview extends Model
{
    use HasFactory;

    /**
     * Table name (explicit for clarity).
     */
    protected $table = 'ticker_overviews';

    /**
     * Mass assignable attributes.
     */
    protected $fillable = [
        'ticker_id',
        'overview_date',
        'active',
        'market_cap',
        'primary_exchange',
        'locale',
        'status',
        'results_raw',
        'fetched_at',
    ];

    /**
     * Attribute casting.
     */
    protected $casts = [
        'active' => 'boolean',
        'market_cap' => 'integer',
        'results_raw' => 'array',
        'overview_date' => 'date',
        'fetched_at' => 'datetime',
    ];

    /**
     * Relationship: belongs to a ticker.
     */
    public function ticker()
    {
        return $this->belongsTo(Ticker::class);
    }

    /**
     * Scope: fetch only the latest overview for each ticker.
     */
    public function scopeLatestForEachTicker($query)
    {
        // Note: This is DB-driver specific; for MySQL, we can use a subquery
        return $query->whereIn('id', function ($sub) {
            $sub->selectRaw('MAX(id)')
                ->from('ticker_overviews')
                ->groupBy('ticker_id');
        });
    }

    /**
     * Accessors
     */

    // Nicely formatted market cap for display
    public function getFormattedMarketCapAttribute(): ?string
    {
        if (! $this->market_cap) {
            return null;
        }

        $value = $this->market_cap;
        if ($value >= 1_000_000_000) {
            return '$' . number_format($value / 1_000_000_000, 2) . 'B';
        } elseif ($value >= 1_000_000) {
            return '$' . number_format($value / 1_000_000, 2) . 'M';
        }

        return '$' . number_format($value);
    }

    // Derived ticker symbol for convenience
    public function getSymbolAttribute(): ?string
    {
        return optional($this->ticker)->ticker;
    }

    /**
     * Utility: Create or update overview record from a Polygon API response.
     * 
     * @param  \App\Models\Ticker  $ticker
     * @param  array  $polygonData  Raw JSON data from Polygon API
     * @return static
     */
    public static function fromPolygonResponse(Ticker $ticker, array $polygonData): self
    {
        $parsed = [
            'ticker_id'        => $ticker->id,
            'overview_date'    => now()->toDateString(),
            'active'           => $polygonData['active'] ?? null,
            'market_cap'       => $polygonData['market_cap'] ?? null,
            'primary_exchange' => $polygonData['primary_exchange'] ?? null,
            'locale'           => $polygonData['locale'] ?? null,
            'status'           => $polygonData['status'] ?? null,
            'results_raw'      => $polygonData,
            'fetched_at'       => now(),
        ];

        // Upsert for ticker + overview_date uniqueness
        return static::updateOrCreate(
            ['ticker_id' => $ticker->id, 'overview_date' => $parsed['overview_date']],
            $parsed
        );
    }
}
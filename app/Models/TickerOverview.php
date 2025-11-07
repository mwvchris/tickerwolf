<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Model: TickerOverview
 *
 * Represents a daily snapshot of a ticker’s volatile attributes
 * (market cap, active status, etc.) fetched from Polygon.io.
 *
 * This table stores time-variant metrics and raw API responses
 * for auditing and reprocessing historical data.
 */
class TickerOverview extends Model
{
    use HasFactory;

    /**
     * Explicit table name for clarity.
     */
    protected $table = 'ticker_overviews';

    /**
     * Mass-assignable attributes.
     *
     * Only include fields that actually exist in the ticker_overviews table
     * (after schema cleanup).
     */
    protected $fillable = [
        'ticker_id',
        'overview_date',
        'active',
        'market_cap',
        'status',
        'results_raw',
        'fetched_at',
        'created_at',
        'updated_at',
    ];

    /**
     * Attribute casting for correct data types.
     */
    protected $casts = [
        'active'        => 'boolean',
        'market_cap'    => 'integer',
        'results_raw'   => 'array',
        'overview_date' => 'date',
        'fetched_at'    => 'datetime',
    ];

    /**
     * Relationships
     * -----------------------------------------------------------------
     */

    /**
     * Each overview belongs to a single ticker.
     */
    public function ticker()
    {
        return $this->belongsTo(Ticker::class);
    }

    /**
     * Scopes
     * -----------------------------------------------------------------
     */

    /**
     * Scope: fetch only the latest overview for each ticker.
     *
     * Note:
     * Uses a subquery for MySQL/MariaDB to retrieve the latest ID per ticker.
     */
    public function scopeLatestForEachTicker(Builder $query): Builder
    {
        return $query->whereIn('id', function ($sub) {
            $sub->selectRaw('MAX(id)')
                ->from('ticker_overviews')
                ->groupBy('ticker_id');
        });
    }

    /**
     * Accessors
     * -----------------------------------------------------------------
     */

    /**
     * Nicely formatted market cap for display (e.g., $1.25B).
     */
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

    /**
     * Derived convenience accessor for ticker symbol.
     */
    public function getSymbolAttribute(): ?string
    {
        return optional($this->ticker)->ticker;
    }

    /**
     * Utility: Create or update overview record from a Polygon API response.
     *
     * @param  \App\Models\Ticker  $ticker
     * @param  array  $polygonData  Raw Polygon.io results
     * @return static
     */
    public static function fromPolygonResponse(Ticker $ticker, array $polygonData): self
    {
        $parsed = [
            'ticker_id'     => $ticker->id,
            'overview_date' => now()->toDateString(),
            'active'        => $polygonData['active'] ?? null,
            'market_cap'    => $polygonData['market_cap'] ?? null,
            'status'        => $polygonData['status'] ?? (
                ($polygonData['active'] ?? false) ? 'active' : 'inactive'
            ),
            'results_raw'   => $polygonData,
            'fetched_at'    => now(),
        ];

        return static::updateOrCreate(
            ['ticker_id' => $ticker->id, 'overview_date' => $parsed['overview_date']],
            $parsed
        );
    }
}
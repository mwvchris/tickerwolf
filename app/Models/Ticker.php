<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ticker extends Model
{
    use HasFactory;

    /**
     * Mass-assignable fields.
     * Includes core Polygon fields + newly added overview fields.
     */
    protected $fillable = [
        // --- Core fields from Polygon /v3/reference/tickers ---
        'ticker',
        'name',
        'market',
        'locale',
        'primary_exchange',
        'type',
        'status',
        'active',
        'currency_symbol',
        'currency_name',
        'base_currency_symbol',
        'base_currency_name',
        'cik',
        'composite_figi',
        'share_class_figi',
        'last_updated_utc',
        'delisted_utc',
        'raw',

        // --- Overview fields from Polygon /v3/reference/tickers/{ticker} ---
        'description',
        'homepage_url',
        'list_date',
        'market_cap',
        'phone_number',
        'total_employees',
        'sic_code',
        'sic_description',
        'share_class_shares_outstanding',
        'weighted_shares_outstanding',
        'ticker_root',
        'ticker_suffix',
        'round_lot',
        'branding_logo_url',
        'branding_icon_url',
    ];

    /**
     * Attribute casting.
     */
    protected $casts = [
        'active' => 'boolean',
        'raw' => 'array',
        'last_updated_utc' => 'datetime',
        'delisted_utc' => 'datetime',
        'list_date' => 'date',
        'market_cap' => 'integer',
        'total_employees' => 'integer',
        'share_class_shares_outstanding' => 'integer',
        'weighted_shares_outstanding' => 'integer',
        'round_lot' => 'integer',
    ];

    /**
     * Timestamps enabled.
     */
    public $timestamps = true;

    /**
     * Relationships
     */

    // Each ticker can have multiple Polygon overview snapshots
    public function overviews()
    {
        return $this->hasMany(TickerOverview::class);
    }

    // Each ticker can have multiple AI/analysis records (future-proofing)
    public function analyses()
    {
        return $this->hasMany(TickerAnalysis::class);
    }

    /**
     * Accessors
     */

    // Generate a lowercase slug for routing (e.g. /ticker/aapl)
    public function getSlugAttribute(): string
    {
        return strtolower($this->ticker);
    }

    // Return a formatted market cap (e.g. $1.23B)
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
     * Scopes
     */

    // Scope: only active tickers
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    // Scope: find ticker by symbol (case-insensitive)
    public function scopeBySymbol($query, string $symbol)
    {
        return $query->whereRaw('LOWER(ticker) = ?', [strtolower($symbol)]);
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model: Ticker
 *
 * Represents a single financial instrument reference entity
 * (stock, ETF, crypto, etc.) as defined by Polygon.io’s /v3/reference/tickers.
 *
 * This table holds *static or slowly changing* metadata such as company info,
 * identifiers, and branding.
 */
class Ticker extends Model
{
    use HasFactory;

    /**
     * Mass-assignable fields.
     *
     * Includes core Polygon reference fields + enriched metadata fields.
     * Note: `market_cap` has been removed (belongs to ticker_overviews).
     */
    protected $fillable = [
        // --- Core fields from Polygon.io /v3/reference/tickers ---
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

        // --- Enriched / Overview metadata ---
        'description',
        'homepage_url',
        'list_date',
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
        'address_json',
    ];

    /**
     * Attribute casting for consistent data handling.
     */
    protected $casts = [
        'active'                       => 'boolean',
        'raw'                          => 'array',
        'last_updated_utc'             => 'datetime',
        'delisted_utc'                 => 'datetime',
        'list_date'                    => 'date',
        'total_employees'              => 'integer',
        'share_class_shares_outstanding' => 'integer',
        'weighted_shares_outstanding'  => 'integer',
        'round_lot'                    => 'integer',
        'address_json'                 => 'array',
    ];

    /**
     * Enable automatic timestamps.
     */
    public $timestamps = true;

    /**
     * Relationships
     * -----------------------------------------------------------------
     */

    /**
     * Each ticker can have multiple daily overview snapshots.
     */
    public function overviews()
    {
        return $this->hasMany(TickerOverview::class);
    }

    /**
     * Each ticker can have multiple AI/analysis records.
     */
    public function analyses()
    {
        return $this->hasMany(TickerAnalysis::class);
    }

    /**
     * Accessors
     * -----------------------------------------------------------------
     */

    /**
     * Generate a lowercase slug for SEO-friendly routing.
     * Example: /ticker/aapl
     */
    public function getSlugAttribute(): string
    {
        return strtolower($this->ticker);
    }

    /**
     * Derived convenience accessor for company city/state from address_json.
     */
    public function getLocationAttribute(): ?string
    {
        if (! $this->address_json || !is_array($this->address_json)) {
            return null;
        }

        $city = $this->address_json['city'] ?? null;
        $state = $this->address_json['state'] ?? null;

        if ($city && $state) {
            return "{$city}, {$state}";
        }

        return $city ?? $state ?? null;
    }

    /**
     * Scopes
     * -----------------------------------------------------------------
     */

    /**
     * Scope: only active tickers.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope: find a ticker by symbol (case-insensitive).
     */
    public function scopeBySymbol($query, string $symbol)
    {
        return $query->whereRaw('LOWER(ticker) = ?', [strtolower($symbol)]);
    }

    /**
     * Utility: formatted display for employee count (e.g., "154,000").
     */
    public function getFormattedEmployeeCountAttribute(): ?string
    {
        return $this->total_employees
            ? number_format($this->total_employees)
            : null;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class TickerNewsItem extends Model
{
    use HasFactory;

    /**
     * This table is ingestion-only, so we’ll guard nothing to simplify updates.
     * If you later expose a writable API endpoint, switch to $fillable for safety.
     */
    protected $guarded = [];

    /**
     * Attribute casting.
     * - Arrays are automatically converted to JSON for storage.
     * - Dates use Carbon for easy formatting.
     */
    protected $casts = [
        'published_utc' => 'datetime',
        'tickers_list'  => 'array',
        'keywords'      => 'array',
        'insights'      => 'array',
        'raw'           => 'array',
    ];

    /**
     * Relationships
     */
    public function ticker()
    {
        return $this->belongsTo(Ticker::class);
    }

    /**
     * Query Scopes
     */

    // Limit to recent news items (default: past 7 days)
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('published_utc', '>=', now()->subDays($days));
    }

    // Limit to a specific ticker symbol
    public function scopeForTicker($query, string $symbol)
    {
        return $query->where('ticker', strtoupper($symbol));
    }

    // Filter by sentiment
    public function scopeWithSentiment($query, ?string $sentiment = null)
    {
        if ($sentiment) {
            return $query->where('insight_sentiment', strtolower($sentiment));
        }
        return $query->whereNotNull('insight_sentiment');
    }

    /**
     * Accessors
     */

    // Human-readable date
    public function getPublishedDateAttribute(): ?string
    {
        return $this->published_utc
            ? Carbon::parse($this->published_utc)->toDateString()
            : null;
    }

    // Short summary preview
    public function getSummaryExcerptAttribute(): ?string
    {
        return $this->summary
            ? mb_strimwidth($this->summary, 0, 140, '…')
            : null;
    }

    // Display publisher name fallback
    public function getPublisherDisplayAttribute(): ?string
    {
        return $this->publisher_name ?? 'Unknown Source';
    }
}
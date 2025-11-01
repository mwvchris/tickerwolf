<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class TickerIndicator extends Model
{
    use HasFactory;

    protected $table = 'ticker_indicators';

    protected $fillable = [
        'ticker_id',
        'resolution',
        't',
        'indicator',
        'value',
        'meta',
    ];

    protected $casts = [
        't' => 'datetime',
        'meta' => 'array',
        'value' => 'float',
    ];

    public function ticker()
    {
        return $this->belongsTo(Ticker::class);
    }

    /** Scope: by symbol (joins ticker) */
    public function scopeForTicker($query, string $symbol)
    {
        return $query->whereHas('ticker', fn($q) => $q->where('ticker', strtoupper($symbol)));
    }

    /** Scope: by indicator name */
    public function scopeIndicator($query, string $name)
    {
        return $query->where('indicator', $name);
    }

    /** Scope: recent N trading days */
    public function scopeSince($query, string $date)
    {
        return $query->where('t', '>=', $date);
    }

    public function getDateAttribute(): ?string
    {
        return $this->t ? Carbon::parse($this->t)->toDateString() : null;
    }
}

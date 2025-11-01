<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TickerAnalysis extends Model
{
    protected $fillable = [
        'ticker','user_id','provider','model','request_payload','response_raw',
        'summary','structured','status','requested_at','completed_at',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_raw' => 'array',
        'structured' => 'array',
        'requested_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user() { return $this->belongsTo(User::class); }
}
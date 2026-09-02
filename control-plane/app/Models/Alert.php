<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    protected $guarded = [];

    protected $casts = [
        'context' => 'array',
        'acknowledged_at' => 'datetime',
    ];

    /** @return BelongsTo<Node, Alert> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function scopeOpen($q)
    {
        return $q->whereNull('acknowledged_at');
    }
}

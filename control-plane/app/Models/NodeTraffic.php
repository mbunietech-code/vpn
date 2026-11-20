<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** One node's network traffic for one UTC day (see NodeController::health). */
class NodeTraffic extends Model
{
    protected $table = 'node_traffic';

    protected $guarded = [];

    protected $casts = [
        'day' => 'date',
        'rx_bytes' => 'integer',
        'tx_bytes' => 'integer',
        'peak_rx_mbps' => 'float',
        'peak_tx_mbps' => 'float',
    ];

    /**
     * Stored as plain 'Y-m-d'. The `date` cast alone would write
     * 'Y-m-d 00:00:00' on SQLite, so lookups by day string (firstOrCreate,
     * peakTxMbpsToday) never matched and the second report of the day hit
     * the unique (node_id, day) index.
     */
    protected function day(): Attribute
    {
        return Attribute::make(set: fn ($value) => Carbon::parse($value)->toDateString());
    }

    /** @return BelongsTo<Node, NodeTraffic> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}

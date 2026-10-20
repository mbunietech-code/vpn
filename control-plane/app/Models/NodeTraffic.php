<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /** @return BelongsTo<Node, NodeTraffic> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}

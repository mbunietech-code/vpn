<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One customer session (credential × source IP × listener) in one 5-minute
 * bucket, as reported by the node agent. See TrafficRecorder.
 */
class TrafficSample extends Model
{
    protected $guarded = [];

    protected $casts = [
        'bucket_at' => 'datetime',
        'categories' => 'array',
        'bytes_up' => 'integer',
        'bytes_down' => 'integer',
        'peak_up_mbps' => 'float',
        'peak_down_mbps' => 'float',
        'last_up_mbps' => 'float',
        'last_down_mbps' => 'float',
        'connections' => 'integer',
    ];

    /** @return BelongsTo<Peer, TrafficSample> */
    public function peer(): BelongsTo
    {
        return $this->belongsTo(Peer::class);
    }

    /** @return BelongsTo<Node, TrafficSample> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return BelongsTo<Subscription, TrafficSample> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Device, TrafficSample> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}

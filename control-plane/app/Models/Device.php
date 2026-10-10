<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    protected $guarded = [];

    /** A connected device that hasn't sent a heartbeat for this long is offline. */
    public const ONLINE_WINDOW_MINUTES = 5;

    protected $casts = [
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
        'connected_at' => 'datetime',
        'vpn_connected' => 'boolean',
    ];

    public function isOnline(): bool
    {
        return $this->vpn_connected
            && $this->last_seen_at?->gt(now()->subMinutes(self::ONLINE_WINDOW_MINUTES));
    }

    /** @param \Illuminate\Database\Eloquent\Builder<Device> $query */
    public function scopeOnline($query)
    {
        return $query->where('vpn_connected', true)
            ->where('last_seen_at', '>', now()->subMinutes(self::ONLINE_WINDOW_MINUTES));
    }

    /** @return BelongsTo<Subscription, Device> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}

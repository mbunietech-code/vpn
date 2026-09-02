<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Node extends Model
{
    protected $guarded = [];

    protected $casts = [
        'health' => 'array',
        'last_health_at' => 'datetime',
        'api_secret' => 'encrypted',
    ];

    protected $hidden = ['api_secret'];

    /** @return HasMany<Peer> */
    public function peers(): HasMany
    {
        return $this->hasMany(Peer::class);
    }

    public function bumpPeerVersion(): void
    {
        $this->increment('peer_version');
    }

    public function hasCapacity(): bool
    {
        return $this->peers()->where('status', 'active')->count() < $this->capacity;
    }

    public function isUsable(): bool
    {
        return in_array($this->status, ['online', 'degraded'], true);
    }
}

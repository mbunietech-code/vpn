<?php

namespace App\Models;

use App\Services\ProvisioningService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Node extends Model
{
    /** Statuses only an admin sets; agent health never overrides them. */
    public const ADMIN_STATUSES = ['draining', 'disabled'];

    public const STATUSES = ['provisioning', 'online', 'degraded', 'offline', 'draining', 'disabled'];

    protected $guarded = [];

    protected $casts = [
        'health' => 'array',
        'last_health_at' => 'datetime',
        'api_secret' => 'encrypted',
        'openvpn_config' => 'encrypted',
        'openvpn_username' => 'encrypted',
        'openvpn_password' => 'encrypted',
    ];

    protected $hidden = ['api_secret', 'openvpn_config', 'openvpn_username', 'openvpn_password'];

    protected static function booted(): void
    {
        // A node that becomes usable (first health report, back from offline,
        // or created online) gets peers for every subscription that is already
        // active - otherwise users who paid before it existed never reach it.
        static::created(function (Node $node) {
            if ($node->isUsable()) {
                app(ProvisioningService::class)->syncAllActive();
            }
        });

        static::updated(function (Node $node) {
            if ($node->wasChanged('status') && $node->isUsable()
                && ! in_array($node->getOriginal('status'), ['online', 'degraded'], true)) {
                app(ProvisioningService::class)->syncAllActive();
            }
        });
    }

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

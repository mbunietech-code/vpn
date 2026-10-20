<?php

namespace App\Models;

use App\Services\ProvisioningService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
        // OpenVPN peers came later; leave them out so capacity means what it did.
        return $this->peers()->where('status', 'active')->where('protocol', '!=', 'openvpn')->count() < $this->capacity;
    }

    public function isUsable(): bool
    {
        return in_array($this->status, ['online', 'degraded'], true);
    }

    /** @return HasMany<NodeTraffic> */
    public function traffic(): HasMany
    {
        return $this->hasMany(NodeTraffic::class);
    }

    /** First day of the current data-quota cycle (the provider's billing day). */
    public function quotaCycleStart(?Carbon $now = null): Carbon
    {
        $now = ($now ?? now())->copy()->startOfDay();
        $day = max(1, min(28, (int) ($this->quota_reset_day ?: 1)));
        $start = $now->copy()->day($day);

        return $start->greaterThan($now) ? $start->subMonthNoOverflow() : $start;
    }

    /**
     * Bytes in + out this quota cycle. Providers differ on what they count, so
     * both directions: the conservative reading.
     */
    public function cycleBytes(): int
    {
        return (int) $this->traffic()
            ->where('day', '>=', $this->quotaCycleStart()->toDateString())
            ->sum(DB::raw('rx_bytes + tx_bytes'));
    }

    /** Share of the monthly quota used this cycle (0-100+), or null without a quota. */
    public function quotaPercent(): ?float
    {
        if (! $this->monthly_quota_gb) {
            return null;
        }

        return round($this->cycleBytes() / ($this->monthly_quota_gb * 1e9) * 100, 1);
    }

    /** Busiest send rate (Mbps) the agent saw today. */
    public function peakTxMbpsToday(): float
    {
        return (float) $this->traffic()->where('day', now()->toDateString())->value('peak_tx_mbps');
    }

    /** Subscriptions that can use this node right now (active, not expired). */
    public function activeSubscriptionCount(): int
    {
        return $this->peers()
            ->where('status', 'active')
            ->whereHas('subscription', fn ($q) => $q->where('status', 'active')->where('expires_at', '>', now()))
            ->distinct()
            ->count('subscription_id');
    }
}

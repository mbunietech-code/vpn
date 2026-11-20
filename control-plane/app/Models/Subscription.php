<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Subscription extends Model
{
    /** Admins' VPN "ends" here: in practice never (see SubscriptionAdmin::ensureAdminAccess). */
    public const UNLIMITED_UNTIL = '2099-12-31 23:59:59';

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'reminded_3d_for' => 'datetime',
        'reminded_1d_for' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Subscription $s) {
            $s->sub_token ??= Str::random(48);
        });
    }

    /** @return BelongsTo<User, Subscription> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Peer> */
    public function peers(): HasMany
    {
        return $this->hasMany(Peer::class);
    }

    /** @return HasMany<Device> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function plan(): ?Plan
    {
        return Plan::where('code', $this->plan_code)->first();
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->expires_at?->isFuture();
    }

    /** No time limit (an admin's). */
    public function isUnlimited(): bool
    {
        return $this->expires_at !== null && $this->expires_at->gte(self::UNLIMITED_UNTIL);
    }

    /** For the app: no date when there is no limit, so it shows no countdown. */
    public function expiresAtForApp(): ?string
    {
        return $this->isUnlimited() ? null : $this->expires_at?->toIso8601String();
    }

    public function rotateToken(): void
    {
        $this->update(['sub_token' => Str::random(48)]);
    }
}

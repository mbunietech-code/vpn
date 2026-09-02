<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    /** @return BelongsTo<User, Invoice> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): ?Plan
    {
        return Plan::where('code', $this->plan_code)->first();
    }

    public function isPayable(): bool
    {
        return $this->status === 'pending'
            && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function markPaid(?string $providerRef = null): void
    {
        $this->update([
            'status' => 'paid',
            'provider_ref' => $providerRef ?? $this->provider_ref,
            'paid_at' => now(),
        ]);
    }
}

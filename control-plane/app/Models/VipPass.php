<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One purchase on a VIP event page: N devices, one code. The code signs the
 * app in on up to N phones/PCs with no account; the pass ends with the event.
 */
class VipPass extends Model
{
    protected $guarded = [];

    protected $casts = [
        'blocked' => 'boolean',
        'activated_at' => 'datetime',
        'devices' => 'integer',
    ];

    /** No 0/O, 1/I/L: codes are read aloud and typed on phones. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected static function booted(): void
    {
        static::creating(function (VipPass $pass) {
            $pass->code ??= self::newCode();
            $pass->page_token ??= Str::random(40);
        });
    }

    public static function newCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (static::where('code', $code)->exists());

        return $code;
    }

    /** "vip-7k3m 9qx2" / "MVPN-VIP:7K3M9QX2" (copied by the pass page) → "7K3M9QX2". */
    public static function normalizeCode(string $input): string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');

        foreach (['MVPNVIP', 'VIP'] as $prefix) {
            if (str_starts_with($code, $prefix) && strlen($code) === strlen($prefix) + 8) {
                return substr($code, strlen($prefix));
            }
        }

        return $code;
    }

    /** What the pass page puts on the clipboard; the app finds it on first start. */
    public function clipboardTag(): string
    {
        return 'MVPN-VIP:'.$this->code;
    }

    /** @return BelongsTo<VipEvent, VipPass> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(VipEvent::class, 'vip_event_id');
    }

    /** @return BelongsTo<User, VipPass> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Subscription, VipPass> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Invoice, VipPass> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** "7K3M-9QX2" */
    public function displayCode(): string
    {
        return substr($this->code, 0, 4).'-'.substr($this->code, 4);
    }

    /**
     * What the buyer and the admin see:
     * pending (not paid yet) | review (Alipay screenshot sent) | active |
     * blocked (this pass off) | off (whole event off) | ended | cancelled
     */
    public function state(): string
    {
        if ($this->status === 'cancelled') {
            return 'cancelled';
        }
        if ($this->status !== 'active') {
            return $this->invoice?->status === 'pending_review' && $this->invoice?->proof_path ? 'review' : 'pending';
        }
        if ($this->event?->hasEnded()) {
            return 'ended';
        }
        if ($this->blocked) {
            return 'blocked';
        }
        if ($this->event && ! $this->event->access_enabled) {
            return 'off';
        }

        return 'active';
    }

    public function usable(): bool
    {
        return $this->state() === 'active';
    }

    /** Devices that signed in with this code (not counting removed ones). */
    public function devicesUsed(): int
    {
        return $this->subscription_id
            ? Device::where('subscription_id', $this->subscription_id)->whereNull('revoked_at')->count()
            : 0;
    }

    public function pageUrl(): string
    {
        return route('vip.pass', $this->page_token);
    }

    /** Opens the installed app straight into the VPN with this code. */
    public function appLink(): string
    {
        return 'mvpn://vip?code='.$this->code;
    }
}

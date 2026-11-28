<?php

namespace App\Models;

use App\Payments\PaymentManager;
use App\Services\VipPasses;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An event (e.g. a conference in China) that sells VPN access per device
 * from a QR code. Every pass ends at `ends_at`; `access_enabled` switches
 * all of them off or on at once.
 */
class VipEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'ends_at' => 'datetime',
        'sales_open' => 'boolean',
        'access_enabled' => 'boolean',
        'providers' => 'array',
    ];

    /** Plan code on the subscriptions and invoices of every VIP pass. */
    public const PLAN_CODE = 'vip';

    /** Hosted checkouts a VIP page can offer, in the order they are shown. */
    public const PROVIDERS = ['stripe', 'pesapal', 'clickpesa'];

    protected static function booted(): void
    {
        // End time or the master switch changed: every pass follows at once.
        static::saved(function (VipEvent $event) {
            if ($event->wasChanged(['ends_at', 'access_enabled'])) {
                app(VipPasses::class)->syncEvent($event);
            }
        });
    }

    /** @return HasMany<VipPass> */
    public function passes(): HasMany
    {
        return $this->hasMany(VipPass::class);
    }

    public function hasEnded(): bool
    {
        return $this->ends_at === null || $this->ends_at->isPast();
    }

    /** Can a guest buy a pass right now? */
    public function isSelling(): bool
    {
        return $this->sales_open && $this->access_enabled && ! $this->hasEnded();
    }

    public function priceCents(string $currency): int
    {
        return (int) match ($currency) {
            'cny' => $this->price_cny_cents,
            'tzs' => $this->price_tzs_cents,
            default => $this->price_usd_cents,
        };
    }

    public function priceLabel(string $currency = 'cny'): string
    {
        return self::money($this->priceCents($currency), $currency);
    }

    /** 2500, cny → "¥25";  900000, tzs → "TSh 9,000";  350, usd → "$3.50". */
    public static function money(int $cents, string $currency): string
    {
        return \App\Services\RevenueSummary::money($cents, $currency);
    }

    /**
     * Payment options for the public page: hosted checkouts that have keys
     * and a price in their currency, then manual QR methods (Alipay, ...).
     *
     * @return array<int,array{key:string,label:string,currency:string,amount_cents:int,method_id:?int}>
     */
    public function paymentOptions(): array
    {
        $allowed = $this->providers ?: null;
        $available = app(PaymentManager::class)->available();
        $out = [];

        foreach (self::PROVIDERS as $p) {
            if (! in_array($p, $available, true) || ($allowed && ! in_array($p, $allowed, true))) {
                continue;
            }
            $currency = PaymentManager::currencyFor($p);
            if ($this->priceCents($currency) <= 0) {
                continue;
            }
            $out[] = [
                'key' => $p,
                'label' => PaymentManager::LABELS[$p] ?? $p,
                'currency' => $currency,
                'amount_cents' => $this->priceCents($currency),
                'method_id' => null,
            ];
        }

        // "manual:7" ticks one QR method; plain "manual" (older events) ticks all.
        PaymentMethod::where('is_active', true)->orderBy('sort')->get()
            ->filter(fn (PaymentMethod $m) => ! $allowed
                || in_array('manual', $allowed, true)
                || in_array('manual:'.$m->id, $allowed, true))
            ->each(function (PaymentMethod $m) use (&$out) {
                $currency = in_array($m->currency, ['cny', 'usd', 'tzs'], true) ? $m->currency : 'cny';
                if ($this->priceCents($currency) <= 0) {
                    return;
                }
                $out[] = [
                    'key' => 'manual:'.$m->id,
                    'label' => $m->label,
                    'currency' => $currency,
                    'amount_cents' => $this->priceCents($currency),
                    'method_id' => $m->id,
                ];
            });

        return $out;
    }

    /** "25 Dec 2026, 18:00 (China time)" in the event's own time zone. */
    public function endsLabel(): string
    {
        if (! $this->ends_at) {
            return '';
        }
        $tz = $this->timezone ?: 'Asia/Shanghai';
        $zone = $tz === 'Asia/Shanghai' ? 'China time' : $tz;

        return $this->ends_at->copy()->timezone($tz)->format('j M Y, H:i').' ('.$zone.')';
    }

    public function publicUrl(): string
    {
        return route('vip.event', $this->slug);
    }
}

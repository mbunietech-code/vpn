<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $guarded = [];

    protected $casts = [
        'node_scope' => 'array',
        'is_active' => 'boolean',
    ];

    public function priceCents(string $currency): int
    {
        return match ($currency) {
            'cny' => $this->price_cny_cents,
            'tzs' => $this->price_tzs_cents,
            default => $this->price_usd_cents,
        };
    }

    /** Display strings for the app, e.g. "¥28" / "$3.99" / "TSh 9,000". */
    public function display(): array
    {
        $out = [
            'usd' => '$' . number_format($this->price_usd_cents / 100, 2),
            'cny' => '¥' . number_format($this->price_cny_cents / 100, $this->price_cny_cents % 100 ? 2 : 0),
        ];
        if ($this->price_tzs_cents > 0) {
            $out['tzs'] = 'TSh ' . number_format($this->price_tzs_cents / 100, 0);
        }

        return $out;
    }
}

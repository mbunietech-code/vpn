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
        return $currency === 'cny' ? $this->price_cny_cents : $this->price_usd_cents;
    }

    /** Display strings for the app, e.g. "¥28" / "$3.99". */
    public function display(): array
    {
        return [
            'usd' => '$' . number_format($this->price_usd_cents / 100, 2),
            'cny' => '¥' . number_format($this->price_cny_cents / 100, $this->price_cny_cents % 100 ? 2 : 0),
        ];
    }
}

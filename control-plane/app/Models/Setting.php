<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Runtime-editable credentials, managed from the Filament admin panel so the
 * owner never has to SSH in to change an API key. Values are encrypted at rest
 * and applied over config() by SettingsServiceProvider on every boot.
 */
class Setting extends Model
{
    protected $guarded = [];

    protected $casts = [
        'value' => 'encrypted',
        'is_secret' => 'boolean',
    ];

    public const CACHE_KEY = 'mvpn.settings';

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /** @return array<string,string|null> key => value */
    public static function map(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()
                ->get(['key', 'value'])
                ->pluck('value', 'key')
                ->toArray());
        } catch (\Throwable) {
            return []; // table not migrated yet
        }
    }

    public static function value(string $key, ?string $default = null): ?string
    {
        $v = static::map()[$key] ?? null;

        return ($v === null || $v === '') ? $default : $v;
    }
}

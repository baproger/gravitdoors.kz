<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Пары ключ-значение для настроек, которые владелец меняет сам.
 * Читается часто (код цеха — на каждый запрос планшета), поэтому кэшируется.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    public const WORKSHOP_CODE = 'workshop_access_code';

    public static function get(string $key, ?string $default = null): ?string
    {
        return Cache::rememberForever(
            "setting.{$key}",
            fn () => static::query()->where('key', $key)->value('value'),
        ) ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);

        Cache::forget("setting.{$key}");
    }

    protected static function booted(): void
    {
        static::saved(fn (Setting $setting) => Cache::forget("setting.{$setting->key}"));
        static::deleted(fn (Setting $setting) => Cache::forget("setting.{$setting->key}"));
    }

    /** Код планшета цеха. Если не задан — генерируется при первом обращении. */
    public static function workshopCode(): string
    {
        $code = static::get(self::WORKSHOP_CODE);

        if (blank($code)) {
            $code = (string) random_int(100000, 999999);
            static::put(self::WORKSHOP_CODE, $code);
        }

        return $code;
    }

    public static function rotateWorkshopCode(): string
    {
        $code = (string) random_int(100000, 999999);
        static::put(self::WORKSHOP_CODE, $code);

        return $code;
    }
}

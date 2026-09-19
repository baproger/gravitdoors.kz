<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Пары ключ-значение для настроек, которые владелец меняет сам.
 *
 * Читается часто (код цеха — на каждый запрос планшета), поэтому кэшируется.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Setting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Setting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Setting query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Setting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Setting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Setting whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Setting whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Setting whereValue($value)
 *
 * @mixin \Eloquent
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    public const WORKSHOP_CODE = 'workshop_access_code';

    public const MANAGER_BONUS_PERCENT = 'manager_bonus_percent';

    public const MANAGER_BONUS_AUTO_APPROVE = 'manager_bonus_auto_approve';

    /** Процент менеджеру от суммы закрытой сделки. По умолчанию 2 %. */
    public static function managerBonusPercent(): float
    {
        return max(0.0, (float) static::get(self::MANAGER_BONUS_PERCENT, '2'));
    }

    /** Утверждать автобонус сразу или отдавать администратору. */
    public static function managerBonusAutoApprove(): bool
    {
        return static::get(self::MANAGER_BONUS_AUTO_APPROVE, '0') === '1';
    }

    /**
     * Прочитанные за запрос значения: символ валюты и ставки спрашивают на каждой
     * строке таблицы, и без этой копии каждый раз шло бы чтение кэша.
     *
     * @var array<string, string|null>
     */
    private static array $memo = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (! array_key_exists($key, self::$memo)) {
            self::$memo[$key] = Cache::rememberForever(
                "setting.{$key}",
                fn () => static::query()->where('key', $key)->value('value'),
            );
        }

        return self::$memo[$key] ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);

        self::forget($key);
    }

    /** Сбросить копию в памяти — для тестов и после записи. */
    public static function flushMemo(): void
    {
        self::$memo = [];
    }

    private static function forget(string $key): void
    {
        unset(self::$memo[$key]);
        Cache::forget("setting.{$key}");
    }

    protected static function booted(): void
    {
        static::saved(fn (Setting $setting) => self::forget($setting->key));
        static::deleted(fn (Setting $setting) => self::forget($setting->key));
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

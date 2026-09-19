<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Роль сотрудника — строка справочника, а не значение перечня.
 *
 * Что роль может — решает реестр прав (`AccessControl`) по её коду. Здесь
 * только то, чем роли отличаются между собой помимо прав: как называется, каким
 * цветом рисуется и два признака поведения, на которые завязаны автоматизации.
 *
 * @property string $code
 * @property string $name
 * @property string|null $hint
 * @property string $color
 * @property bool $does_surveys
 * @property bool $is_factory_staff
 * @property bool $is_system
 * @property bool $is_active
 * @property int $sort
 */
class Role extends Model
{
    /** Длина кода ограничена колонками `users.role` и `role_permissions.role`. */
    public const CODE_MAX = 32;

    public const NAME_MAX = 60;

    protected $fillable = [
        'code', 'name', 'hint', 'color',
        'does_surveys', 'is_factory_staff', 'is_system', 'is_active', 'sort',
    ];

    /**
     * Справочник в памяти запроса.
     *
     * Роль спрашивают на каждой проверке прав, а их десятки за отрисовку.
     * PHP-FPM не переносит статику между запросами — устареть копия не может.
     *
     * @var Collection<string, self>|null
     */
    private static ?Collection $memo = null;

    protected function casts(): array
    {
        return [
            'does_surveys' => 'boolean',
            'is_factory_staff' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role', 'code');
    }

    /**
     * Все роли по порядку, включая скрытые: на скрытой могут остаться сотрудники.
     *
     * @return Collection<string, self>
     */
    public static function cached(): Collection
    {
        return self::$memo ??= self::query()->orderBy('sort')->orderBy('id')->get()->keyBy('code');
    }

    public static function byCode(?string $code): ?self
    {
        return $code === null ? null : self::cached()->get($code);
    }

    /** Роли, которые предлагаются в карточке сотрудника. @return Collection<string, self> */
    public static function assignable(): Collection
    {
        return self::cached()->filter(fn (self $role): bool => $role->is_active);
    }

    /**
     * Коды ролей с признаком поведения — для `whereIn('role', …)`.
     *
     * @return list<string>
     */
    public static function codesWith(string $flag): array
    {
        return self::cached()
            ->filter(fn (self $role): bool => (bool) $role->{$flag})
            ->keys()
            ->all();
    }

    public static function forgetCache(): void
    {
        self::$memo = null;
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Директор — хозяин системы; опознаётся по коду, он заморожен с первого релиза. */
    public function isAdmin(): bool
    {
        return $this->code === UserRole::Admin->value;
    }

    public function doesSurveys(): bool
    {
        return $this->does_surveys;
    }

    public function isFactoryStaff(): bool
    {
        return $this->is_factory_staff;
    }

    public function getLabel(): string
    {
        return $this->name;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    /**
     * Удалить можно только придуманную роль, на которой никто не работает:
     * у базовых семи на коде завязаны автоматизации, а чужие сотрудники после
     * удаления остались бы с ролью, которой нет.
     */
    public function canBeDeleted(): bool
    {
        return ! $this->is_system && $this->users()->count() === 0;
    }
}

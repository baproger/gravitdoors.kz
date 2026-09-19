<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Единственный источник истины о доступе.
 *
 * Все экраны и политики спрашивают уровень здесь, а не сравнивают роль сама
 * с собой: иначе матрица в настройках расходилась бы с поведением системы.
 * Отличия от рекомендованных значений лежат в `role_permissions` и читаются
 * одним запросом на процесс — доступ проверяется десятки раз за отрисовку.
 */
final class AccessControl
{
    private const CACHE_KEY = 'access.matrix';

    /**
     * Копия матрицы в памяти запроса.
     *
     * Кэш на сервере лежит в файле или базе, а права проверяются сотни раз за
     * отрисовку канбана: без этой копии каждая проверка была бы чтением кэша.
     * PHP-FPM не переносит статические свойства между запросами, поэтому
     * устаревшей копия быть не может.
     *
     * @var array<string, array<string, string>>|null
     */
    private static ?array $memo = null;

    /** Уровень роли по конкретному праву. */
    public static function level(UserRole $role, Permission $permission): AccessLevel
    {
        // Директор — хозяин системы: настройками его запереть нельзя.
        if ($role === UserRole::Admin) {
            return AccessLevel::Full;
        }

        $override = self::overrides()[$role->value][$permission->value] ?? null;

        if ($override !== null) {
            $level = AccessLevel::tryFrom($override);

            // Право могло потерять уровень при обновлении системы — тогда рекомендованный.
            if ($level !== null && $permission->supports($level)) {
                return $level;
            }
        }

        return $permission->default($role);
    }

    public static function levelFor(?User $user, Permission $permission): AccessLevel
    {
        if (! $user || ! $user->is_active) {
            return AccessLevel::None;
        }

        return self::level($user->role, $permission);
    }

    /** Хватает ли пользователю прав. По умолчанию достаточно «чтения». */
    public static function allows(?User $user, Permission $permission, AccessLevel $min = AccessLevel::Read): bool
    {
        return self::levelFor($user, $permission)->atLeast($min);
    }

    /** То же для текущего пользователя — короткая форма для экранов. */
    public static function can(Permission $permission, AccessLevel $min = AccessLevel::Read): bool
    {
        return self::allows(auth()->user(), $permission, $min);
    }

    /** Текущему пользователю доступны только свои записи. */
    public static function ownOnly(Permission $permission, ?User $user = null): bool
    {
        return self::levelFor($user ?? auth()->user(), $permission)->isOwnOnly();
    }

    /** Уровень текущего пользователя — когда нужен не «да/нет», а сам режим. */
    public static function current(Permission $permission): AccessLevel
    {
        return self::levelFor(auth()->user(), $permission);
    }

    public static function set(UserRole $role, Permission $permission, AccessLevel $level, ?User $actor = null): void
    {
        if ($role === UserRole::Admin) {
            throw ValidationException::withMessages([
                'role' => 'Директор всегда имеет полный доступ: иначе систему можно запереть без единого хозяина.',
            ]);
        }

        if (! $permission->supports($level)) {
            throw ValidationException::withMessages([
                'level' => "Уровень «{$level->getLabel()}» не применим к праву «{$permission->getLabel()}».",
            ]);
        }

        if ($level === $permission->default($role)) {
            // Совпало с рекомендованным — храним пустоту, а не копию значения по умолчанию.
            RolePermission::query()->where('role', $role->value)->where('permission', $permission->value)->delete();
        } else {
            RolePermission::query()->updateOrCreate(
                ['role' => $role->value, 'permission' => $permission->value],
                ['level' => $level->value, 'updated_by' => $actor?->id],
            );
        }

        self::flush();
    }

    /** Вернуть роль к рекомендованным значениям. */
    public static function reset(UserRole $role): void
    {
        RolePermission::query()->where('role', $role->value)->delete();

        self::flush();
    }

    /**
     * Вся матрица для экрана настроек.
     *
     * @return array<string, array<string, AccessLevel>> роль → право → уровень
     */
    public static function matrix(): array
    {
        $matrix = [];

        foreach (UserRole::cases() as $role) {
            foreach (Permission::cases() as $permission) {
                $matrix[$role->value][$permission->value] = self::level($role, $permission);
            }
        }

        return $matrix;
    }

    /** Отличается ли уровень от рекомендованного — для подсветки в матрице. */
    public static function isOverridden(UserRole $role, Permission $permission): bool
    {
        return $role !== UserRole::Admin
            && isset(self::overrides()[$role->value][$permission->value]);
    }

    public static function flush(): void
    {
        self::$memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, array<string, string>> роль → право → уровень-строка
     */
    private static function overrides(): array
    {
        return self::$memo ??= Cache::rememberForever(self::CACHE_KEY, function (): array {
            $map = [];

            // Без приведения к enum: строка могла остаться от удалённого права,
            // и падать из-за этого на каждой странице система не должна.
            foreach (RolePermission::query()->toBase()->get(['role', 'permission', 'level']) as $row) {
                $map[(string) $row->role][(string) $row->permission] = (string) $row->level;
            }

            return $map;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Models\User;
use App\Services\AccessControl;

class UserPolicy
{
    /** Список сотрудников — инструмент руководителя и кадров. */
    public function viewAny(User $user): bool
    {
        return AccessControl::allows($user, Permission::SettingsEmployees);
    }

    /**
     * Свою карточку открывает любой сотрудник: там его выработка и зарплата.
     * Чужую — тот, кому открыт раздел сотрудников.
     */
    public function view(User $user, User $target): bool
    {
        return $user->is($target) || $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return AccessControl::allows($user, Permission::SettingsEmployees, AccessLevel::Full);
    }

    public function update(User $user, User $target): bool
    {
        return $this->create($user);
    }

    /** Себя удалить нельзя — иначе легко остаться без единственного хозяина системы. */
    public function delete(User $user, User $target): bool
    {
        return $this->create($user) && $user->isNot($target);
    }

    public function deleteAny(User $user): bool
    {
        return $this->create($user);
    }

    /** Оклад, ставка и бонусный процент — отдельное право. */
    public function viewFinance(User $user, User $target): bool
    {
        return $user->is($target) || AccessControl::allows($user, Permission::EmployeesFinance);
    }

    public function updateFinance(User $user, User $target): bool
    {
        return AccessControl::allows($user, Permission::EmployeesFinance, AccessLevel::Full);
    }
}

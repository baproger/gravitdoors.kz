<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    /** Список сотрудников — инструмент руководителя. */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager], true);
    }

    /**
     * Свою карточку открывает любой сотрудник: там его выработка и зарплата.
     * Чужую — только руководство.
     */
    public function view(User $user, User $target): bool
    {
        return $user->is($target) || $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function update(User $user, User $target): bool
    {
        return $user->role === UserRole::Admin;
    }

    /** Себя удалить нельзя — иначе легко остаться без единственного администратора. */
    public function delete(User $user, User $target): bool
    {
        return $user->role === UserRole::Admin && $user->isNot($target);
    }

    public function deleteAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}

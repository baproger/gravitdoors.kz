<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\DoorOption;
use App\Models\User;

/** Прайс — это деньги: правит администратор, смотрит ещё и менеджер. */
class DoorOptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->seesMoney();
    }

    public function view(User $user, DoorOption $option): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function update(User $user, DoorOption $option): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function delete(User $user, DoorOption $option): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function deleteAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function reorder(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}

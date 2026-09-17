<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Debt;
use App\Models\User;

class DebtPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->seesMoney();
    }

    public function view(User $user, Debt $debt): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role->seesMoney();
    }

    public function update(User $user, Debt $debt): bool
    {
        return $user->role->seesMoney() && ! $debt->status->isClosed();
    }

    public function delete(User $user, Debt $debt): bool
    {
        return $user->role === UserRole::Admin && $debt->canBeDeleted();
    }

    public function deleteAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    /** Платит по долгам администратор: это подтверждённый расход. */
    public function pay(User $user, Debt $debt): bool
    {
        return $user->role === UserRole::Admin && ! $debt->status->isClosed();
    }
}

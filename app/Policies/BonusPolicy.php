<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Bonus;
use App\Models\User;

/** Бонусы предлагают продажи и администратор; утверждает администратор. */
class BonusPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->seesMoney();
    }

    public function view(User $user, Bonus $bonus): bool
    {
        return $this->viewAny($user) || $user->id === $bonus->user_id;
    }

    public function create(User $user): bool
    {
        return $user->role->seesMoney();
    }

    public function update(User $user, Bonus $bonus): bool
    {
        if ($user->role === UserRole::Admin) {
            return ! $bonus->isApproved();
        }

        return $user->role === UserRole::Manager && ! $bonus->isApproved() && $bonus->created_by === $user->id;
    }

    public function delete(User $user, Bonus $bonus): bool
    {
        return $user->role === UserRole::Admin && $bonus->canBeDeleted();
    }

    public function deleteAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function approve(User $user, Bonus $bonus): bool
    {
        return $user->role === UserRole::Admin;
    }
}

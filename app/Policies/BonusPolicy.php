<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Models\Bonus;
use App\Models\User;
use App\Services\AccessControl;

class BonusPolicy
{
    public function viewAny(User $user): bool
    {
        return AccessControl::allows($user, Permission::FinanceBonuses);
    }

    /** Свой бонус сотрудник видит всегда — он часть его зарплаты. */
    public function view(User $user, Bonus $bonus): bool
    {
        return $this->viewAny($user) || $user->id === $bonus->user_id;
    }

    public function create(User $user): bool
    {
        return AccessControl::allows($user, Permission::FinanceBonuses, AccessLevel::Full);
    }

    /** Утверждённый бонус уже в ведомости: его снимают с утверждения, а не правят. */
    public function update(User $user, Bonus $bonus): bool
    {
        return $this->create($user) && ! $bonus->isApproved();
    }

    public function delete(User $user, Bonus $bonus): bool
    {
        return $this->create($user) && $bonus->canBeDeleted();
    }

    public function deleteAny(User $user): bool
    {
        return $this->create($user);
    }

    public function approve(User $user, Bonus $bonus): bool
    {
        return $this->create($user)
            && AccessControl::allows($user, Permission::FinanceApprove, AccessLevel::Full);
    }
}

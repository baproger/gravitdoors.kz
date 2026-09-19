<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Models\Debt;
use App\Models\User;
use App\Services\AccessControl;

class DebtPolicy
{
    public function viewAny(User $user): bool
    {
        return AccessControl::allows($user, Permission::FinanceDebts);
    }

    public function view(User $user, Debt $debt): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return AccessControl::allows($user, Permission::FinanceDebts, AccessLevel::Full);
    }

    public function update(User $user, Debt $debt): bool
    {
        return $this->create($user) && ! $debt->status->isClosed();
    }

    public function delete(User $user, Debt $debt): bool
    {
        return $this->create($user) && $debt->canBeDeleted();
    }

    public function deleteAny(User $user): bool
    {
        return $this->create($user);
    }

    /** Платёж по долгу — это подтверждённый расход и списание со счёта. */
    public function pay(User $user, Debt $debt): bool
    {
        return $this->create($user)
            && ! $debt->status->isClosed()
            && AccessControl::allows($user, Permission::FinanceApprove, AccessLevel::Full);
    }
}

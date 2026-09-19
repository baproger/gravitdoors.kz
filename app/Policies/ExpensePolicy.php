<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Models\Expense;
use App\Models\User;
use App\Services\AccessControl;

/**
 * Расходы. Подтверждение — отдельное право: сняв его, директор оставляет
 * проверку за собой, а раздел остаётся доступным для ввода.
 */
class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return AccessControl::allows($user, Permission::FinanceExpenses);
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return AccessControl::allows($user, Permission::FinanceExpenses, AccessLevel::Full);
    }

    /** Подтверждённый расход — уже деньги в отчёте: его отклоняют, а не правят. */
    public function update(User $user, Expense $expense): bool
    {
        return $this->create($user) && ! $expense->isApproved();
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->create($user) && $expense->canBeDeleted();
    }

    public function deleteAny(User $user): bool
    {
        return $this->create($user);
    }

    public function approve(User $user, Expense $expense): bool
    {
        return $this->create($user)
            && AccessControl::allows($user, Permission::FinanceApprove, AccessLevel::Full);
    }
}

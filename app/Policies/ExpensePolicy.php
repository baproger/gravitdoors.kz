<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Expense;
use App\Models\User;

/**
 * Расходы видят и вносят продажи и администратор; подтверждает — администратор.
 * Менеджер правит только свои неподтверждённые записи.
 */
class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->seesMoney();
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role->seesMoney();
    }

    public function update(User $user, Expense $expense): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        return $user->role === UserRole::Manager
            && ! $expense->isApproved()
            && ($expense->user_id === null || $expense->user_id === $user->id);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $user->role === UserRole::Admin && $expense->canBeDeleted();
    }

    public function deleteAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    /** Подтверждение и отклонение — только администратор: это признание денег потраченными. */
    public function approve(User $user, Expense $expense): bool
    {
        return $user->role === UserRole::Admin;
    }
}

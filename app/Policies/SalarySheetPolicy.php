<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Models\SalarySheet;
use App\Models\User;
use App\Services\AccessControl;

class SalarySheetPolicy
{
    public function viewAny(User $user): bool
    {
        return AccessControl::allows($user, Permission::FinanceSalarySheets);
    }

    /** Свою ведомость сотрудник видит на странице «Моя зарплата». */
    public function view(User $user, SalarySheet $sheet): bool
    {
        return $this->viewAny($user) || $user->id === $sheet->user_id;
    }

    /** Удержания и авансы правятся только в черновике. */
    public function update(User $user, SalarySheet $sheet): bool
    {
        return AccessControl::allows($user, Permission::FinanceSalarySheets, AccessLevel::Full)
            && $sheet->isDraft();
    }

    public function approve(User $user, SalarySheet $sheet): bool
    {
        return AccessControl::allows($user, Permission::FinanceSalarySheets, AccessLevel::Full)
            && AccessControl::allows($user, Permission::FinanceApprove, AccessLevel::Full);
    }

    public function pay(User $user, SalarySheet $sheet): bool
    {
        return $this->approve($user, $sheet);
    }
}

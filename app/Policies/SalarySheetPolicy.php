<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\SalarySheet;
use App\Models\User;

/** Ведомость видят продажи и администратор; утверждает и выплачивает администратор. */
class SalarySheetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->seesMoney();
    }

    public function view(User $user, SalarySheet $sheet): bool
    {
        return $this->viewAny($user) || $user->id === $sheet->user_id;
    }

    public function update(User $user, SalarySheet $sheet): bool
    {
        return $user->role === UserRole::Admin && $sheet->isDraft();
    }

    public function approve(User $user, SalarySheet $sheet): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function pay(User $user, SalarySheet $sheet): bool
    {
        return $user->role === UserRole::Admin;
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ProductionLog;
use App\Models\User;

class ProductionLogPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProductionLog $log): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role !== UserRole::Worker;
    }

    public function update(User $user, ProductionLog $log): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Master], true);
    }

    public function delete(User $user, ProductionLog $log): bool
    {
        return $user->role === UserRole::Admin;
    }
}

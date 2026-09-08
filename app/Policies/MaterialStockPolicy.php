<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\MaterialStock;
use App\Models\User;

class MaterialStockPolicy
{
    /** Остатки видит и цех: без них нельзя понять, чем закрывать наряд. */
    public function viewAny(User $user): bool
    {
        return $user->role !== UserRole::Worker;
    }

    public function view(User $user, MaterialStock $material): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Manager, UserRole::Master], true);
    }

    public function update(User $user, MaterialStock $material): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, MaterialStock $material): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function deleteAny(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }
}
